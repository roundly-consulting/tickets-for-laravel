<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tags;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\ChangeTicketTagsAction;
use RoundlyConsulting\Tickets\Actions\Internal\ResolveTagsAction;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Tags a ticket — by name or model; an unknown name is created (`tickets.tags.free_form`).
 * Idempotent: a tag already on the ticket changes nothing, and nothing new means no activity
 * and no event.
 */
final readonly class AttachTagsAction
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
            $resolved = $this->resolve->execute($ticket, $tags);

            return $this->tags->execute($ticket, add: $resolved, remove: [], actor: $actor, system: $system);
        });
    }
}
