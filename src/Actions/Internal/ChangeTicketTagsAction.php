<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketTagged;
use RoundlyConsulting\Tickets\Events\TicketUntagged;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\TicketLocker;

/**
 * Puts tags on and takes tags off a ticket, inside the calling action's transaction and with
 * the ticket row locked: only what really changes is written, and one activity entry holds
 * the diff (`tagged` when anything was added, else `untagged`), with `TicketTagged` /
 * `TicketUntagged` after commit. Nothing changed, nothing recorded.
 *
 * @internal
 */
final readonly class ChangeTicketTagsAction
{
    public function __construct(
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    /**
     * @param  array<int, TicketTag>  $add  keyed by tag id
     * @param  array<int, TicketTag>  $remove  keyed by tag id
     */
    public function execute(Ticket $ticket, array $add, array $remove, ?Model $actor, bool $system): Ticket
    {
        TicketLocker::lock($ticket);

        $current = array_flip(array_map(intval(...), $ticket->tags()->pluck($ticket->tags()->getRelated()->getQualifiedKeyName())->all()));
        $add = array_diff_key($add, $current);
        $remove = array_intersect_key($remove, $current);

        if ($add === [] && $remove === []) {
            return $ticket;
        }

        if ($add !== []) {
            $ticket->tags()->attach(array_keys($add), ['created_at' => CarbonImmutable::now()]);
        }

        if ($remove !== []) {
            $ticket->tags()->detach(array_keys($remove));
        }

        $ticket->unsetRelation('tags');

        $this->record->execute($ticket, $add === [] ? ActivityType::Untagged : ActivityType::Tagged, $actor, [
            'added' => self::slugs($add),
            'removed' => self::slugs($remove),
        ], $system);

        if ($add !== []) {
            $this->events->dispatch(new TicketTagged($ticket, array_values($add), $actor));
        }

        if ($remove !== []) {
            $this->events->dispatch(new TicketUntagged($ticket, array_values($remove), $actor));
        }

        return $ticket;
    }

    /**
     * @param  array<int, TicketTag>  $tags
     * @return list<string>
     */
    private static function slugs(array $tags): array
    {
        return array_values(array_map(static fn (TicketTag $tag): string => $tag->slug, $tags));
    }
}
