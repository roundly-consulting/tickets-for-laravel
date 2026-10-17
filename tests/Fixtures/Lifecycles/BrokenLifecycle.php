<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles;

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\Tickets\Contracts\TicketLifecycle;
use RoundlyConsulting\Tickets\Enums\Automation;
use RoundlyConsulting\Tickets\Enums\TicketStatus;

/**
 * Compiles, but breaks the tickets contract four ways: an automation naming a missing
 * transition, one without system context, one that cannot leave a state it must, and one
 * landing in the wrong category.
 */
final class BrokenLifecycle extends LifecycleDefinition implements TicketLifecycle
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(TicketStatus::class)->initial(TicketStatus::New)->terminal(TicketStatus::Closed);

        $lifecycle->transition('open')->from(TicketStatus::New)->to(TicketStatus::Open);
        $lifecycle->transition('resume')->from(TicketStatus::Pending)->to(TicketStatus::Open)->allowSystem();
        $lifecycle->transition('reopen')->from(TicketStatus::Resolved)->to(TicketStatus::Closed)->allowSystem();
        $lifecycle->transition('progress')->from(TicketStatus::Open)->to(TicketStatus::InProgress);
        $lifecycle->transition('park')->from(TicketStatus::InProgress)->to(TicketStatus::OnHold);
        $lifecycle->transition('resolve')->from(TicketStatus::OnHold)->to(TicketStatus::Resolved);
    }

    public function automation(Automation $hook): ?string
    {
        return match ($hook) {
            Automation::Assigned => 'open',
            Automation::RequesterReplied => 'resume',
            Automation::RequesterReopened => 'reopen',
            Automation::AutoClose => null,
            Automation::Merged => 'vanish',
        };
    }
}
