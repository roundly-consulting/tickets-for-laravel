<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Tickets\Tests\Fixtures\HttpTestCase;
use RoundlyConsulting\Tickets\Tests\Fixtures\SwappedModelsTestCase;
use RoundlyConsulting\Tickets\Tests\Fixtures\UlidKeyTestCase;
use RoundlyConsulting\Tickets\Tests\Fixtures\UuidKeyTestCase;
use RoundlyConsulting\Tickets\Tests\TestCase;

// Explicit directories, never a blanket `->in(__DIR__)`: Pest binds a test case per
// directory and errors on a blanket-vs-specific collision. The arch file is bound too, so
// `swappableModelsAreNotFinal` can read the config defaults.
uses(TestCase::class)->in('Arch', 'Feature', 'Unit', 'RealEngine');

// The HTTP API: its routes load at boot, so they are switched on before it.
uses(HttpTestCase::class)->in('Http');

// The model-swap proofs need `tickets.models.*` pointed at host subclasses before boot.
uses(SwappedModelsTestCase::class)->in('ModelSwap');

// The key-type legs: the migrations read the key types to pick their columns, so each
// non-default leg sets them before the providers boot — a base case per key type.
uses(UuidKeyTestCase::class)->in('KeyTypes/UuidKeyTest.php');
uses(UlidKeyTestCase::class)->in('KeyTypes/UlidKeyTest.php');

/**
 * A json payload with its object keys sorted, recursively — jsonb (pgsql) and MySQL's json
 * reorder object keys, so a strict comparison sorts both sides first rather than falling
 * back to a loose `toEqual`.
 *
 * @param  array<array-key, mixed>|null  $value
 * @return array<array-key, mixed>|null
 */
function keySorted(?array $value): ?array
{
    if ($value === null) {
        return null;
    }

    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map(static fn (mixed $item): mixed => is_array($item) ? keySorted($item) : $item, $value);
}

/**
 * The host's agent ability, as an app defines it: a user flagged `is_agent`.
 */
function defineAgents(): void
{
    Gate::define('tickets.agent', static fn (Model $user): bool => (bool) $user->getAttribute('is_agent'));
}

require_once __DIR__.'/Http/Helpers.php';
