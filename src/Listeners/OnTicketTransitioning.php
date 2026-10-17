<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Listeners;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Contracts\ConversationLock;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketStates;
use RoundlyConsulting\Tickets\Support\TransitionContext;

/**
 * Inside the transition's transaction, right before lifecycle writes the state (so both are
 * atomic with it): the `status_changed` activity, the `last_activity_at` bump and the
 * conversation lock. Fires for user transitions, expiries and scheduled runs — rollbacks and
 * adoptions fire only `LifecycleTransitioned` and are handled there.
 *
 * @internal
 */
final readonly class OnTicketTransitioning
{
    use SyncsConversationLock;

    public function __construct(
        private RecordActivityAction $record,
        private ConversationLock $lock,
    ) {}

    public function handle(LifecycleTransitioning $event): void
    {
        $ticket = $event->subject;

        if (! $ticket instanceof Ticket || ! is_a($ticket, TicketModels::ticket()) || $event->lifecycle !== 'status') {
            return;
        }

        $context = TransitionContext::current();

        $this->record->execute($ticket, ActivityType::StatusChanged, $event->actor ?? $context['actor'] ?? null, array_filter([
            'transition' => $event->transition,
            'from' => $event->from === null ? null : TicketStates::stored($event->from),
            'to' => TicketStates::stored($event->to),
            'automation' => $context['automation']->value ?? null,
        ], static fn (mixed $value): bool => $value !== null), system: $event->system);

        // A plain query, not a save: lifecycle owns this row's state write, and bumping the
        // activity clock must not count as an Eloquent update of the ticket.
        $now = CarbonImmutable::now();
        $ticket->newQueryWithoutScopes()->whereKey($ticket->getKey())->toBase()->update(['last_activity_at' => $now]);
        $ticket->setAttribute('last_activity_at', $now)->syncOriginalAttribute('last_activity_at');

        $this->syncConversationLock($this->lock, $ticket, $event->from, $event->to);
    }
}
