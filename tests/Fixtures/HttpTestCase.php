<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures;

use RoundlyConsulting\Tickets\Tests\TestCase;

/**
 * The HTTP API on: the routes load at boot, so the switch is set before the providers boot.
 */
abstract class HttpTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [...parent::configBeforeBoot(), 'tickets.routes.enabled' => 'on'];
    }
}
