<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles;

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\Tickets\Contracts\TicketLifecycle;
use RoundlyConsulting\Tickets\Enums\Automation;

/**
 * A swapped host lifecycle with no tickets code in it: none of the default transition
 * names, a way back out of Closed, and two automations switched off.
 */
final class HostLifecycle extends LifecycleDefinition implements TicketLifecycle
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(HostStatus::class)->initial(HostStatus::Triage);

        $lifecycle->transition('pick')->from(HostStatus::Triage)->to(HostStatus::Working)->allowSystem();
        $lifecycle->transition('finish')->from(HostStatus::Triage, HostStatus::Working)->to(HostStatus::Done)->allowSystem();
        $lifecycle->transition('revive')->from(HostStatus::Done)->to(HostStatus::Triage)->allowSystem();
    }

    public function automation(Automation $hook): ?string
    {
        return match ($hook) {
            Automation::Assigned => 'pick',
            Automation::Merged => 'finish',
            default => null,
        };
    }
}
