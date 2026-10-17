<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Fields;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\CategoryTree;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * The custom fields that apply to a ticket: active fields that are global or of the ticket's
 * project, and either for every category or for the ticket's category or one of its
 * ancestors — ordered by position. Memoised per (project, category) for the current request
 * or job (a scoped binding, so an Octane worker starts each request empty); every write to a
 * field or a category move flushes it.
 */
final class FieldSetResolver
{
    /** @var array<string, array<string, FieldDefinition>> */
    private array $resolved = [];

    /**
     * @return array<string, FieldDefinition> keyed by field key
     */
    public function forTicket(Ticket $ticket): array
    {
        return $this->forScope($ticket->project_type, $ticket->project_id, $ticket->category_id);
    }

    /**
     * @return array<string, FieldDefinition> keyed by field key
     */
    public function forScope(?string $projectType, int|string|null $projectId, ?int $categoryId): array
    {
        $memo = $projectType.'|'.$projectId.'|'.$categoryId;

        return $this->resolved[$memo] ??= $this->resolve($projectType, $projectId, $categoryId);
    }

    /**
     * The raw attribute definitions of a ticket's fields — the array shape attributes-for-laravel
     * reads (a dependency-mandated shape, like `casts()`).
     *
     * @return array<string, array<string, mixed>>
     */
    public function attributeDefinitions(Ticket $ticket): array
    {
        return array_map(static fn (FieldDefinition $field): array => $field->attributeDefinition(), $this->forTicket($ticket));
    }

    /**
     * The keys of every live field the ticket's scope covers, inactive ones included — what the
     * ticket's stored values may still belong to. Never memoised.
     *
     * @return list<string>
     */
    public function knownKeys(Ticket $ticket): array
    {
        return array_keys($this->resolve($ticket->project_type, $ticket->project_id, $ticket->category_id, activeOnly: false));
    }

    public function flush(): void
    {
        $this->resolved = [];
    }

    /**
     * @return array<string, FieldDefinition>
     */
    private function resolve(?string $projectType, int|string|null $projectId, ?int $categoryId, bool $activeOnly = true): array
    {
        $category = $categoryId === null ? null : TicketModels::category()::withTrashed()->find($categoryId);
        $chain = $category === null ? [] : array_map(
            static fn (TicketCategory $node): int => (int) $node->getKey(),
            CategoryTree::chain($category),
        );

        $fields = TicketModels::field()::query()
            ->when($activeOnly, static fn (Builder $active): Builder => $active->where('is_active', true))
            ->where(static fn (Builder $scope): Builder => $projectType === null
                ? $scope->whereNull('project_type')
                : $scope->whereNull('project_type')->orWhere(static fn (Builder $own): Builder => $own
                    ->where('project_type', $projectType)->where('project_id', $projectId)))
            ->where(static fn (Builder $scope): Builder => $scope->whereNull('category_id')->orWhereIn('category_id', $chain))
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $definitions = [];

        foreach ($fields as $field) {
            $definitions[$field->key] = FieldDefinition::from($field);
        }

        return $definitions;
    }
}
