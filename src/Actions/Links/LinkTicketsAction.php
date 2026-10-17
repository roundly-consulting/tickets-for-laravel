<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Links;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Events\TicketLinked;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLinkException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketLink;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\LinkGraph;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Links `$ticket` to `$related`: it `relates_to`, `duplicates`, `blocks` or is the
 * `parent_of` the other. Refused: a self link, a cross-project link with
 * `tickets.links.cross_project` off, a `blocks` / `parent_of` cycle and a second parent.
 * Locking only the two tickets would not stop three concurrent inserts (A→B, B→C, C→A) from
 * closing a cycle, so those two types first serialise on the `lock:links` advisory row; both
 * tickets are then locked in primary-key order. The same link again is a no-op; a symmetric
 * `relates_to` is stored once, lower key first.
 */
final readonly class LinkTicketsAction
{
    public function __construct(
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, Ticket $related, LinkType $type, ?Model $actor = null, bool $system = false): TicketLink
    {
        $actor = ActorResolver::resolve($actor, $system);

        if ($ticket->is($related)) {
            throw InvalidTicketLinkException::self();
        }

        return TicketTransaction::run($ticket, function () use ($ticket, $related, $type, $actor, $system): TicketLink {
            if ($type->isAcyclic()) {
                AdvisoryLock::acquire(AdvisoryLock::LINKS);
            }

            TicketLocker::lockInOrder($ticket, $related);

            if (! TicketsConfig::crossProjectLinks()
                && ($ticket->project_type !== $related->project_type || (string) $ticket->project_id !== (string) $related->project_id)) {
                throw InvalidTicketLinkException::crossProject();
            }

            [$from, $to] = LinkGraph::stored($ticket, $related, $type);
            $existing = LinkGraph::find($from, $to, $type);

            if ($existing !== null) {
                return $existing;
            }

            if ($type === LinkType::ParentOf
                && TicketLink::query()->where('related_ticket_id', $to->getKey())->where('type', $type->value)->exists()) {
                throw InvalidTicketLinkException::secondParent($to->reference);
            }

            if ($type->isAcyclic() && LinkGraph::reaches($to, $from, $type)) {
                throw InvalidTicketLinkException::cycle($type);
            }

            $link = TicketLink::query()->create([
                'ticket_id' => $from->getKey(),
                'related_ticket_id' => $to->getKey(),
                'type' => $type,
                'created_by_type' => $actor?->getMorphClass(),
                'created_by_id' => $actor?->getKey(),
                'created_at' => CarbonImmutable::now(),
            ]);

            $this->record->execute($from, ActivityType::Linked, $actor, ['link' => $type->value, 'ticket' => $to->reference], $system);
            $this->record->execute($to, ActivityType::Linked, $actor, ['link' => $type->inverse(), 'ticket' => $from->reference], $system);

            $this->events->dispatch(new TicketLinked($from, $to, $type, $actor));

            return $link;
        });
    }
}
