<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Listeners;

use BackedEnum;
use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Tickets\Actions\Internal\BumpGuestAccessAction;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Contracts\ConversationLock;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Events\TicketClosed;
use RoundlyConsulting\Tickets\Events\TicketReopened;
use RoundlyConsulting\Tickets\Events\TicketResolved;
use RoundlyConsulting\Tickets\Events\TicketStatusChanged;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketStates;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * After commit, once per history row: `TicketStatusChanged`, then the category-entry events
 * (`TicketResolved`, `TicketClosed`, `TicketReopened`). The `initial` row is ignored — the
 * `created` activity covers it. Rollback and adoption rows never fire
 * `LifecycleTransitioning`, so their activity, conversation lock and close-time guest link
 * revocation are written here, after commit — the only hook lifecycle offers for them.
 *
 * @internal
 */
final readonly class OnTicketTransitioned
{
    use RevokesGuestAccessOnClose;
    use SyncsConversationLock;

    public function __construct(
        private RecordActivityAction $record,
        private ConversationLock $lock,
        private BumpGuestAccessAction $bump,
        private Dispatcher $events,
    ) {}

    public function handle(LifecycleTransitioned $event): void
    {
        $ticket = $event->subject;

        if (! $ticket instanceof Ticket || ! is_a($ticket, TicketModels::ticket()) || $event->lifecycle !== 'status'
            || $event->kind === TransitionKind::Initial) {
            return;
        }

        if ($event->kind === TransitionKind::Rollback || $event->kind === TransitionKind::Adopted) {
            TicketTransaction::run($ticket, function () use ($ticket, $event): void {
                $this->record->execute($ticket, ActivityType::StatusChanged, $event->actor, array_filter([
                    'transition' => $event->transition,
                    'from' => $event->from === null ? null : TicketStates::stored($event->from),
                    'to' => TicketStates::stored($event->to),
                    'kind' => $event->kind->value,
                ], static fn (mixed $value): bool => $value !== null), system: $event->system);

                $this->syncConversationLock($this->lock, $ticket, $event->from, $event->to);
                $this->revokeGuestAccessOnClose($this->bump, $ticket, $event->from, $event->to, $event->actor, $event->system);
            });
        }

        $this->events->dispatch(new TicketStatusChanged($ticket, $event->transition, $event->from, $event->to, $event->actor, $event->system));

        foreach ($this->categoryEvents($ticket, $event->from, $event->to, $event) as $categoryEvent) {
            $this->events->dispatch($categoryEvent);
        }
    }

    /**
     * @return list<TicketResolved|TicketClosed|TicketReopened>
     */
    private function categoryEvents(Ticket $ticket, BackedEnum|string|null $from, BackedEnum|string $to, LifecycleTransitioned $event): array
    {
        $before = TicketStates::category($from);
        $after = TicketStates::category($to);

        if ($after === $before) {
            return [];
        }

        return match (true) {
            $after === StatusCategory::Resolved => [new TicketResolved($ticket, $event->actor)],
            $after === StatusCategory::Closed => [new TicketClosed($ticket, $event->actor)],
            ($before === StatusCategory::Resolved || $before === StatusCategory::Closed) && $after?->isUnresolved() === true => [new TicketReopened($ticket, $event->actor)],
            default => [],
        };
    }
}
