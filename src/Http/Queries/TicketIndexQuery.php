<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Queries;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\QueryBuilder\AllowedFilter;
use RoundlyConsulting\QueryBuilder\AllowedSort;
use RoundlyConsulting\QueryBuilder\QueryBuilder;
use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Exceptions\UnknownMorphAliasException;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Support\Messages;
use RoundlyConsulting\Tickets\Support\MorphAliases;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketVisibility;
use Throwable;

/**
 * `GET /tickets` on query-builder-for-laravel: the tickets the viewer may see (`visibleTo`),
 * filtered and sorted only by what this allow-list names — anything else is a 400 (under the
 * host's `query-builder.mode.unknown_filter = reject`). Every filter is one of the Ticket query
 * scopes, so the PHP API and the HTTP API filter identically.
 *
 * Filters (`filter[name]=value`, a comma list where it says so):
 *
 *  - `status` (list) `withStatus`; `status_category` (list) `withStatusCategory`;
 *  - `priority` (list) `withPriority`; `category` (list) `inCategory`, with
 *    `category_descendants=1` its subcategories too;
 *  - `assignee` (list of `alias:key`, `me`, `none`) `assignedTo` / `unassigned`;
 *  - `requester` (list of `alias:key`, `me`) `requestedBy` — `alias:key` is an agent's (for
 *    anyone else it matches only themselves); `project` (`alias:key`, `none`) `inProject`;
 *    `tag` (list of slugs) `taggedWith`, agents only; `watching=me` `watchedBy`;
 *  - `overdue=1` `overdue`; `due_from` / `due_to` / `created_from` / `created_to` (inclusive)
 *    `dueFrom` / `dueTo` / `createdFrom` / `createdTo`; `channel` (list) `viaChannel`;
 *    `merged` (boolean) `merged`; `search` `search`;
 *  - `fields.<key>` (list) `whereField`, and for number, decimal, date and datetime fields
 *    `fields.<key>.from` / `fields.<key>.to` `whereFieldBetween` — active, unencrypted fields
 *    only, and for anyone but an agent only the requester-visible ones;
 *  - `trashed` (`with`, `only`) — for whoever holds the manage ability.
 *
 * The agent-only filters (`tag`, `requester=alias:key`, a field that is not requester-visible)
 * match only tickets the agent scope lets the viewer work on: a ticket they see as its requester
 * or a watcher never answers for a fact its resource hides from them.
 *
 * Sorts: `created_at`, `updated_at`, `due_at`, `number`, `priority` (the enum weights) and, for
 * agents, `last_activity_at` (a note moves it — so a ticket outside the agent scope sorts by its
 * `updated_at`, {@see LastActivitySort}). The default is `-last_activity_at` for agents and
 * `-created_at` otherwise.
 *
 * @internal
 */
final class TicketIndexQuery
{
    public static function for(Request $request, Viewer $viewer, bool $manage = false): QueryBuilder
    {
        $filters = [...self::filters($request, $viewer), ...self::fieldFilters($viewer)];

        if ($manage) {
            $filters[] = AllowedFilter::trashed();
        }

        $sorts = ['created_at', 'updated_at', 'due_at', 'number', AllowedSort::custom('priority', new PrioritySort)];

        if ($viewer->isAgent()) {
            $sorts[] = AllowedSort::custom('last_activity_at', new LastActivitySort($viewer));
        }

        // The allow-list first: the first call forwarded to the builder applies the request.
        return QueryBuilder::for(TicketModels::ticket(), $request)
            ->allowedFilters(...$filters)
            ->allowedSorts(...$sorts)
            ->defaultSort($viewer->isAgent() ? '-last_activity_at' : '-created_at')
            ->scopes(['visibleTo' => [$viewer->model]])
            ->with(TicketEagerLoads::relations())
            ->withCount(TicketEagerLoads::counts($viewer));
    }

    /**
     * @return list<AllowedFilter>
     */
    private static function filters(Request $request, Viewer $viewer): array
    {
        $filters = [
            self::scope('status', static fn (array $values): array => ['withStatus' => $values]),
            self::scope('status_category', static fn (array $values): array => ['withStatusCategory' => array_values(array_filter(
                array_map(StatusCategory::tryFrom(...), $values),
            ))], emptyMatchesNothing: true),
            self::scope('priority', static fn (array $values): array => ['withPriority' => $values]),
            AllowedFilter::callback('category', static function (Builder $query, mixed $value) use ($request): void {
                $descendants = filter_var(self::raw($request, 'category_descendants'), FILTER_VALIDATE_BOOLEAN);
                $ids = array_values(array_filter(array_map(static fn (string $id): int|false => filter_var($id, FILTER_VALIDATE_INT), self::values($value)), is_int(...)));

                self::any($query, array_map(static fn (int $id): array => ['inCategory' => [$id, $descendants]], $ids));
            }),
            AllowedFilter::callback('category_descendants', static function (): void {}),
            AllowedFilter::callback('assignee', static function (Builder $query, mixed $value) use ($viewer): void {
                $scopes = [];

                foreach (self::values($value) as $item) {
                    $scopes[] = match ($item) {
                        'none' => ['unassigned' => []],
                        'me' => ['assignedTo' => [$viewer->model]],
                        default => self::morphScope('assignedTo', MorphAliases::ASSIGNABLES, $item),
                    };
                }

                self::any($query, $scopes);
            }),
            AllowedFilter::callback('requester', static function (Builder $query, mixed $value) use ($viewer): void {
                self::any($query, array_map(static function (string $item) use ($viewer): ?Closure {
                    $scope = $item === 'me' ? ['requestedBy' => [$viewer->model]] : self::morphScope('requestedBy', MorphAliases::REQUESTERS, $item);

                    if ($scope === null) {
                        return null;
                    }

                    // Who requested a ticket is an agent's to know — anyone may ask for themselves.
                    $self = $item === 'me' || $viewer->is($scope['requestedBy'][0]);

                    return static function (Builder $one) use ($scope, $self, $viewer): void {
                        $one->scopes($scope);

                        if (! $self) {
                            self::asAgent($one, $viewer);
                        }
                    };
                }, self::values($value)));
            }),
            AllowedFilter::callback('project', static function (Builder $query, mixed $value): void {
                self::any($query, array_map(
                    static fn (string $item): ?array => $item === 'none' ? ['inProject' => [null]] : self::morphScope('inProject', MorphAliases::PROJECTS, $item),
                    self::values($value),
                ));
            }),
            AllowedFilter::callback('watching', static function (Builder $query, mixed $value) use ($viewer): void {
                self::any($query, self::values($value) === ['me'] ? [['watchedBy' => [$viewer->model]]] : []);
            }),
            AllowedFilter::callback('overdue', static function (Builder $query, mixed $value): void {
                if ($value === true) {
                    $query->scopes(['overdue']);
                }
            }, booleans: true),
            self::date('due_from', 'dueFrom'),
            self::date('due_to', 'dueTo'),
            self::date('created_from', 'createdFrom'),
            self::date('created_to', 'createdTo'),
            self::scope('channel', static fn (array $values): array => ['viaChannel' => $values]),
            AllowedFilter::callback('merged', static function (Builder $query, mixed $value): void {
                if (is_bool($value)) {
                    $query->scopes(['merged' => [$value]]);
                }
            }, booleans: true),
            AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                // A comma is part of the phrase, not a list separator.
                $query->scopes(['search' => [implode(',', self::values($value))]]);
            }),
        ];

        // Tags are agent-only labels: not even a filter for anyone else (400).
        if ($viewer->isAgent()) {
            $filters[] = AllowedFilter::callback('tag', static function (Builder $query, mixed $value) use ($viewer): void {
                $query->scopes(['taggedWith' => self::values($value)]);
                self::asAgent($query, $viewer);
            });
        }

        return $filters;
    }

    /**
     * `fields.<key>`, and `.from` / `.to` for range types — built per request from the active,
     * unencrypted, filterable fields the viewer may see.
     *
     * @return list<AllowedFilter>
     */
    private static function fieldFilters(Viewer $viewer): array
    {
        $fields = TicketModels::field()::query()->where('is_active', true)->get()->groupBy('key');
        $filters = [];

        foreach ($fields as $key => $definitions) {
            $key = (string) $key;

            if ($definitions->contains(static fn (TicketField $field): bool => $field->encrypted || $field->type === FieldType::Multiselect)
                || (! $viewer->isAgent() && $definitions->contains(static fn (TicketField $field): bool => ! $field->requester_visible))) {
                continue;
            }

            // An agent-only field answers only where the viewer works on the ticket as an agent.
            $agentOnly = $definitions->contains(static fn (TicketField $field): bool => ! $field->requester_visible);
            $narrow = static function (Builder $query) use ($agentOnly, $viewer): void {
                if ($agentOnly) {
                    self::asAgent($query, $viewer);
                }
            };

            $filters[] = AllowedFilter::callback('fields.'.$key, static function (Builder $query, mixed $value) use ($key, $narrow): void {
                self::any($query, array_map(static fn (string $item): array => ['whereField' => [$key, $item]], self::values($value)));
                $narrow($query);
            });

            $ranged = $definitions->every(static fn (TicketField $field): bool => in_array($field->type, [FieldType::Number, FieldType::Decimal, FieldType::Date, FieldType::DateTime], true));

            if ($ranged) {
                $filters[] = AllowedFilter::callback('fields.'.$key.'.from', static function (Builder $query, mixed $value) use ($key, $narrow): void {
                    $query->scopes(['whereFieldBetween' => [$key, self::single($value), null]]);
                    $narrow($query);
                });
                $filters[] = AllowedFilter::callback('fields.'.$key.'.to', static function (Builder $query, mixed $value) use ($key, $narrow): void {
                    $query->scopes(['whereFieldBetween' => [$key, null, self::single($value)]]);
                    $narrow($query);
                });
            }
        }

        return $filters;
    }

    /**
     * A filter whose (list) value is handed to one scope.
     *
     * @param  Closure(list<string>): array<string, list<mixed>>  $scope
     */
    private static function scope(string $name, Closure $scope, bool $emptyMatchesNothing = false): AllowedFilter
    {
        return AllowedFilter::callback($name, static function (Builder $query, mixed $value) use ($scope, $emptyMatchesNothing): void {
            $scopes = $scope(self::values($value));

            if ($emptyMatchesNothing && array_values($scopes)[0] === []) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->scopes($scopes);
        });
    }

    private static function date(string $name, string $scope): AllowedFilter
    {
        return AllowedFilter::callback($name, static function (Builder $query, mixed $value) use ($name, $scope): void {
            try {
                $at = CarbonImmutable::parse(self::single($value));
            } catch (Throwable) {
                throw ValidationException::withMessages(['filter.'.$name => [Messages::get('http.invalid_date', ['filter' => $name])]]);
            }

            $query->scopes([$scope => [$at]]);
        });
    }

    /**
     * The rows any of the scope calls match — none for no calls (a null call matches nothing).
     * A call is scope arguments, or a closure that narrows one OR-ed branch itself.
     *
     * @param  Builder<Model>  $query
     * @param  list<array<string, list<mixed>>|Closure(Builder<Model>): void|null>  $scopes
     */
    private static function any(Builder $query, array $scopes): void
    {
        $query->where(static function (Builder $any) use ($scopes): void {
            $any->whereRaw('1 = 0');

            foreach (array_filter($scopes) as $scope) {
                $any->orWhere(static function (Builder $one) use ($scope): void {
                    $scope instanceof Closure ? $scope($one) : $one->scopes($scope);
                });
            }
        });
    }

    /**
     * Only the tickets the viewer works on as an agent — the agent ability, narrowed by the agent
     * scope ({@see TicketVisibility::agentTickets()}); nothing for anyone else.
     *
     * @param  Builder<Model>  $query
     */
    private static function asAgent(Builder $query, Viewer $viewer): void
    {
        if (! $viewer->isAgent()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $keys = TicketVisibility::agentTickets($viewer->model);

        if ($keys !== null) {
            $query->whereIn($query->getModel()->getQualifiedKeyName(), $keys);
        }
    }

    /**
     * One scope call for an `alias:key` value: the alias must be on the allow-list (a class name
     * never is — 422), and a key matching no record matches no ticket (null).
     *
     * @return array<string, list<mixed>>|null
     */
    private static function morphScope(string $scope, string $kind, string $value): ?array
    {
        $reference = MorphReference::parse($value) ?? throw UnknownMorphAliasException::alias($kind, $value);
        $class = MorphAliases::map($kind)[$reference->type] ?? throw UnknownMorphAliasException::alias($kind, $reference->type);
        $model = MorphAliases::find($class, $reference->id);

        return $model === null ? null : [$scope => [$model]];
    }

    /**
     * @return list<string>
     */
    private static function values(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $values), static fn (string $item): bool => $item !== ''));
    }

    private static function single(mixed $value): string
    {
        return implode(',', self::values($value));
    }

    private static function raw(Request $request, string $filter): mixed
    {
        $filters = $request->query('filter');

        return is_array($filters) ? ($filters[$filter] ?? null) : null;
    }
}
