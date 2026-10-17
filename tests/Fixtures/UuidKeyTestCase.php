<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures;

use RoundlyConsulting\Tickets\Tests\TestCase;

/**
 * A uuid installation, set before the providers boot and the migrations run: every key the
 * graph aligns (tickets inbound + outbound, lifecycle subjects and actors, comments,
 * attributes) is uuid.
 */
abstract class UuidKeyTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'tickets.primary_key_type' => 'uuid',
            'tickets.key_type' => 'uuid',
            'lifecycle.key_type' => 'uuid',
            'lifecycle.actor_key_type' => 'uuid',
            'comments.key_type' => 'uuid',
            'attributes.key_type' => 'uuid',
        ];
    }
}
