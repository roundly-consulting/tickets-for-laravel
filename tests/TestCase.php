<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Tickets\TicketsServiceProvider;

abstract class TestCase extends PackageTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [TicketsServiceProvider::class];
    }

    // No migrationSources() override — the base case defaults to []. Add one once the
    // package ships migrations: return [TicketsServiceProvider::class] (never a
    // literal filename) once ->hasMigrations() is wired in the provider.
}
