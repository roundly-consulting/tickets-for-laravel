<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures;

use RoundlyConsulting\Tickets\Tests\TestCase;

/**
 * A ulid installation — see {@see UuidKeyTestCase}.
 */
abstract class UlidKeyTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'tickets.primary_key_type' => 'ulid',
            'tickets.key_type' => 'ulid',
            'lifecycle.key_type' => 'ulid',
            'lifecycle.actor_key_type' => 'ulid',
            'comments.key_type' => 'ulid',
            'attributes.key_type' => 'ulid',
        ];
    }
}
