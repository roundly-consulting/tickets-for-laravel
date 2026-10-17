<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Tickets\DataTransferObjects\ExampleTicketsData;
use RoundlyConsulting\Tickets\Testing\TicketsFake;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * Keep one `@method static` line per public manager method — FacadeTest's
 * `toDocumentItsRoot()` fails on a missing, stale or miscounted line.
 *
 * @method static string example(ExampleTicketsData $data)
 * @method static void assertExampleCalled(\Closure|null $callback = null)
 * @method static void assertNothingCalled()
 *
 * @see TicketsManager
 */
final class Tickets extends Facade
{
    /**
     * Swap the manager for a recording fake — behind the facade and in the container, so an
     * injected TicketsManager is faked too.
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
