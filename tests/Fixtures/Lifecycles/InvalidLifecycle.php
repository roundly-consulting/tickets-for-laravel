<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles;

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\Tickets\Contracts\TicketLifecycle;
use RoundlyConsulting\Tickets\Enums\Automation;
use RoundlyConsulting\Tickets\Enums\TicketStatus;

/**
 * A definition lifecycle-for-laravel itself refuses (no initial state).
 */
final class InvalidLifecycle extends LifecycleDefinition implements TicketLifecycle
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(TicketStatus::class);
    }

    public function automation(Automation $hook): ?string
    {
        return null;
    }
}
