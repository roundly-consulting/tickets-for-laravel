<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

it('keeps the API off when a published routes array drops the switch', function (): void {
    $names = collect(Route::getRoutes()->getRoutes())->map(static fn ($route): string => (string) $route->getName())
        ->filter(static fn (string $name): bool => str_starts_with($name, 'tickets.'));

    expect(config()->has('tickets.routes.enabled'))->toBeFalse()
        ->and(config('tickets.routes.prefix'))->toBe('admin-api')
        ->and($names)->toHaveCount(0)
        ->and(TicketsConfig::routesEnabled())->toBeFalse()
        ->and(RateLimiter::limiter('tickets-api'))->toBeNull();

    $this->getJson('/admin-api/tickets')->assertNotFound();
});
