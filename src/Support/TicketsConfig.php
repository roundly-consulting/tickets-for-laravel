<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use BackedEnum;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Tickets\Contracts\Priority;
use RoundlyConsulting\Tickets\Contracts\TicketLifecycle as TicketLifecycleContract;
use RoundlyConsulting\Tickets\Enums\TicketPriority;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLifecycleException;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Lifecycle\TicketLifecycle;

/**
 * Validated readers for the `tickets.*` settings the toolkit's `Config` cannot express on
 * its own (class contracts, durations). A bad value throws naming the key.
 */
final class TicketsConfig
{
    /**
     * The ticket lifecycle definition class (`tickets.lifecycle`).
     *
     * @return class-string<LifecycleDefinition&TicketLifecycleContract>
     */
    public static function lifecycle(): string
    {
        $class = Config::using(InvalidTicketLifecycleException::class)->string('tickets.lifecycle', TicketLifecycle::class);

        if (! is_subclass_of($class, LifecycleDefinition::class) || ! is_subclass_of($class, TicketLifecycleContract::class)) {
            throw InvalidTicketLifecycleException::notADefinition($class);
        }

        return $class;
    }

    /**
     * The priority enum (`tickets.priority.enum`): a string-backed enum implementing
     * {@see Priority}.
     *
     * @return class-string<BackedEnum&Priority>
     */
    public static function priorityEnum(): string
    {
        $class = Config::using(InvalidTicketsConfigurationException::class)->string('tickets.priority.enum', TicketPriority::class);

        if (! enum_exists($class) || ! is_subclass_of($class, BackedEnum::class) || ! is_subclass_of($class, Priority::class)) {
            throw InvalidTicketsConfigurationException::invalid('tickets.priority.enum', 'a backed enum implementing '.Priority::class);
        }

        return $class;
    }
}
