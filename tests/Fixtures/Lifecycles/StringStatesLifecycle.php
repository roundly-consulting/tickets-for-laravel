<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles;

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\Tickets\Contracts\TicketLifecycle;
use RoundlyConsulting\Tickets\Enums\Automation;

/**
 * Plain string states: no status categories at all.
 */
final class StringStatesLifecycle extends LifecycleDefinition implements TicketLifecycle
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(['todo', 'done'])->initial('todo')->terminal('done');
        $lifecycle->transition('finish')->from('todo')->to('done')->allowSystem();
    }

    public function automation(Automation $hook): ?string
    {
        return null;
    }
}
