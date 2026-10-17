<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tags;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\ChangeTicketTagsAction;
use RoundlyConsulting\Tickets\Actions\Internal\ResolveTagsAction;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Makes a ticket's tags exactly this set — by name or model; unknown names are created
 * (`tickets.tags.free_form`). One activity entry holds the whole diff; the same set again
 * changes nothing.
 */
final readonly class SyncTagsAction
{
    public function __construct(
        private ResolveTagsAction $resolve,
        private ChangeTicketTagsAction $tags,
    ) {}

    /**
     * @param  list<string|TicketTag>  $tags
     */
    public function execute(Ticket $ticket, array $tags, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $tags, $actor, $system): Ticket {
            $wanted = $this->resolve->execute($ticket, $tags);

            TicketLocker::lock($ticket);

            $current = $ticket->tags()->get()->keyBy(static fn (TicketTag $tag): int => (int) $tag->getKey())->all();

            return $this->tags->execute(
                $ticket,
                add: array_diff_key($wanted, $current),
                remove: array_diff_key($current, $wanted),
                actor: $actor,
                system: $system,
            );
        });
    }
}
