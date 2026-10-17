<?php

declare(strict_types=1);

use RoundlyConsulting\Tickets\Tests\Fixtures\UlidKeyTestCase;
use RoundlyConsulting\Tickets\Tests\Fixtures\UuidKeyTestCase;
use RoundlyConsulting\Tickets\Tests\TestCase;

// Explicit directories, never a blanket `->in(__DIR__)`: Pest binds a test case per
// directory and errors on a blanket-vs-specific collision. The arch file is bound too, so
// `swappableModelsAreNotFinal` can read the config defaults.
uses(TestCase::class)->in('Arch', 'Feature', 'Unit');

// The key-type legs: the migrations read the key types to pick their columns, so each
// non-default leg sets them before the providers boot — a base case per key type.
uses(UuidKeyTestCase::class)->in('KeyTypes/UuidKeyTest.php');
uses(UlidKeyTestCase::class)->in('KeyTypes/UlidKeyTest.php');
