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
 * Takes tags off a ticket, by name or model. Idempotent: an unknown name or a tag the ticket
 * does not carry changes nothing.
 */
final readonly class DetachTagsAction
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
            $resolved = $this->resolve->execute($ticket, $tags, create: false);

            return $this->tags->execute($ticket, add: [], remove: $resolved, actor: $actor, system: $system);
        });
    }
}
