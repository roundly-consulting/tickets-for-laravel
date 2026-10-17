<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Contracts;

use RoundlyConsulting\Tickets\Enums\Automation;

/**
 * Implemented by a ticket lifecycle definition (a lifecycle-for-laravel
 * `LifecycleDefinition`). It names the transition each package automation applies.
 */
interface TicketLifecycle
{
    /**
     * The transition the automation applies, or null to switch it off. The transition must
     * allow system context (`allowSystem()` / `systemOnly()`): automations run `asSystem()`.
     */
    public function automation(Automation $hook): ?string;
}
