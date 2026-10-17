<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures;

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * A lifecycle definition that does not implement the tickets contract.
 */
final class PlainLifecycle extends LifecycleDefinition
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(['draft', 'done'])->initial('draft')->terminal('done');
        $lifecycle->transition('finish')->from('draft')->to('done');
    }
}
