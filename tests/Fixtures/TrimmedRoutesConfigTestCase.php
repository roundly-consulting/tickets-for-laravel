<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures;

use RoundlyConsulting\Tickets\Tests\TestCase;

/**
 * A host whose published `config/tickets.php` sets only the part of `routes` it changes. The
 * config merge is shallow, so `routes.enabled` is gone from the merged config.
 */
abstract class TrimmedRoutesConfigTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [...parent::configBeforeBoot(), 'tickets.routes' => ['prefix' => 'admin-api']];
    }
}
