<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Lifecycle;

use Carbon\CarbonInterval;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\Tickets\Contracts\TicketLifecycle as TicketLifecycleContract;
use RoundlyConsulting\Tickets\Enums\Automation;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * The shipped ticket lifecycle: seven states in five status categories and nine transitions.
 *
 * Hosts swap it with `tickets.lifecycle` — a class extending this one, or any lifecycle
 * definition implementing {@see TicketLifecycleContract} whose states enum implements
 * `Contracts\TicketState`. Not final on purpose: extending it and adjusting `define()` is the
 * smallest way to change a rule.
 */
class TicketLifecycle extends LifecycleDefinition implements TicketLifecycleContract
{
    public const string OPEN = 'open';

    public const string START = 'start';

    public const string WAIT = 'wait';

    public const string HOLD = 'hold';

    public const string RESUME = 'resume';

    public const string RESOLVE = 'resolve';

    public const string REOPEN = 'reopen';

    public const string CLOSE = 'close';

    public const string DISMISS = 'dismiss';

    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->label('Ticket');

        $lifecycle->states(TicketStatus::class)
            ->initial(TicketStatus::New)
            ->terminal(TicketStatus::Closed);

        // Auto-close: lifecycle's sweep applies `close` once the TTL passes. The closure is
        // evaluated when a ticket enters Resolved (a config change affects later resolutions
        // only); `off` returns null — never expires.
        $lifecycle->state(TicketStatus::Resolved)
            ->stamps('resolved_at')
            ->ttl(static fn (): ?CarbonInterval => TicketsConfig::autoCloseAfter())
            ->expiresVia(self::CLOSE);
        $lifecycle->state(TicketStatus::Closed)->stamps('closed_at');

        $lifecycle->transition(self::OPEN)
            ->from(TicketStatus::New)
            ->to(TicketStatus::Open)
            ->allowSystem();

        $lifecycle->transition(self::START)
            ->from(TicketStatus::New, TicketStatus::Open, TicketStatus::Pending, TicketStatus::OnHold)
            ->to(TicketStatus::InProgress);

        $lifecycle->transition(self::WAIT)
            ->from(TicketStatus::New, TicketStatus::Open, TicketStatus::InProgress)
            ->to(TicketStatus::Pending);

        $lifecycle->transition(self::HOLD)
            ->from(TicketStatus::New, TicketStatus::Open, TicketStatus::InProgress)
            ->to(TicketStatus::OnHold)
            ->requiresReason();

        $lifecycle->transition(self::RESUME)
            ->from(TicketStatus::Pending, TicketStatus::OnHold)
            ->to(TicketStatus::Open)
            ->allowSystem();

        $lifecycle->transition(self::RESOLVE)
            ->from(TicketStatus::New, TicketStatus::Open, TicketStatus::InProgress, TicketStatus::Pending, TicketStatus::OnHold)
            ->to(TicketStatus::Resolved)
            ->rules(['resolution' => ['nullable', 'string', 'max:5000']]);

        $lifecycle->transition(self::REOPEN)
            ->from(TicketStatus::Resolved)
            ->to(TicketStatus::Open)
            ->allowSystem();

        $lifecycle->transition(self::CLOSE)
            ->from(TicketStatus::Resolved)
            ->to(TicketStatus::Closed)
            ->allowSystem();

        // Covers Resolved too, so a resolved ticket can still be merged (or dismissed as
        // spam). It shares resolved → closed with `close`, which lifecycle:validate reports
        // as one `ambiguous_target` warning — harmless, tickets never calls transitionTo().
        $lifecycle->transition(self::DISMISS)
            ->from(TicketStatus::New, TicketStatus::Open, TicketStatus::InProgress, TicketStatus::Pending, TicketStatus::OnHold, TicketStatus::Resolved)
            ->to(TicketStatus::Closed)
            ->requiresReason()
            ->allowSystem();
    }

    public function automation(Automation $hook): ?string
    {
        return match ($hook) {
            Automation::Assigned => self::OPEN,
            Automation::RequesterReplied => self::RESUME,
            Automation::RequesterReopened => self::REOPEN,
            Automation::AutoClose => self::CLOSE,
            Automation::Merged => self::DISMISS,
        };
    }
}
