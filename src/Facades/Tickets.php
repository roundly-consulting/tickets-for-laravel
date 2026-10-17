<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Tickets\Testing\TicketsFake;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * The ticketing API. Sugar over {@see TicketsManager} — inject the manager for the same API,
 * or call the actions directly.
 *
 * @method static \RoundlyConsulting\Tickets\Builders\TicketBuilder make()
 * @method static \RoundlyConsulting\Tickets\Models\Ticket create(\RoundlyConsulting\Tickets\DataTransferObjects\CreateTicketData $data)
 * @method static \RoundlyConsulting\Tickets\Models\Ticket|null find(string $reference)
 * @method static \RoundlyConsulting\Tickets\Models\Ticket findOrFail(string $reference)
 * @method static \RoundlyConsulting\Tickets\Handles\TicketHandle for(\RoundlyConsulting\Tickets\Models\Ticket|string $ticket)
 * @method static \RoundlyConsulting\Tickets\Accessors\Categories categories()
 * @method static \RoundlyConsulting\Tickets\Accessors\Fields fields()
 * @method static \RoundlyConsulting\Tickets\Accessors\Guests guests()
 * @method static void assertCreated(\Closure|null $callback = null)
 * @method static void assertNothingCreated()
 * @method static void assertNothingHappened()
 *
 * @see TicketsManager
 */
final class Tickets extends Facade
{
    /**
     * Swap the manager for a recording fake — behind the facade and in the container, so an
     * injected TicketsManager is faked too. Every call still runs; each one is recorded.
     */
    public static function fake(): TicketsFake
    {
        $fake = app(TicketsFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return TicketsManager::class;
    }
}
