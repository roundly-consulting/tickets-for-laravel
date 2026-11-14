<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Listeners;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\Tickets\Actions\Internal\BumpGuestAccessAction;
use RoundlyConsulting\Tickets\Actions\Internal\GuardTicketStartAction;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Actions\Internal\WriteFieldValuesAction;
use RoundlyConsulting\Tickets\Contracts\ConversationLock;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\DeferredFields;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketStates;
use RoundlyConsulting\Tickets\Support\TransitionContext;

/**
 * Inside the transition's transaction, right before lifecycle writes the state (so both are
 * atomic with it): the blocked-start guard (`tickets.links.blockers_prevent_start`), the
 * required custom fields on leaving a deferred state (`tickets.fields.defer_required_in`), the
 * `status_changed` activity, the `last_activity_at` bump, the
 * conversation lock and — entering Closed with `tickets.guests.revoke_on_close` — the guest
 * link revocation. Fires for user transitions, expiries and scheduled runs — rollbacks and
 * adoptions fire only `LifecycleTransitioned` and are handled there.
 *
 * @internal
 */
final readonly class OnTicketTransitioning
{
    use RevokesGuestAccessOnClose;
    use SyncsConversationLock;

    public function __construct(
        private RecordActivityAction $record,
        private ConversationLock $lock,
        private BumpGuestAccessAction $bump,
        private GuardTicketStartAction $guardStart,
        private WriteFieldValuesAction $fields,
    ) {}

    public function handle(LifecycleTransitioning $event): void
    {
        $ticket = $event->subject;

        if (! $ticket instanceof Ticket || ! is_a($ticket, TicketModels::ticket()) || $event->lifecycle !== 'status') {
            return;
        }

        // First, so a refused start writes nothing before the transaction rolls back.
        $this->guardStart->execute($ticket, $event->from, $event->to);

        $context = TransitionContext::current();

        // Leaving a deferred state: the required fields fall due, owed as the mover owes them
        // (the system — automations, expiries — owes every one).
        if (DeferredFields::leaving($event->from, $event->to)) {
            $this->fields->assertRequiredFilled($ticket, $event->system ? null : $event->actor ?? $context['actor'] ?? null);
        }

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
        $this->revokeGuestAccessOnClose($this->bump, $ticket, $event->from, $event->to, $event->actor ?? $context['actor'] ?? null, $event->system);
    }
}
