<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * The `php artisan about` section. The config holds no secrets, so the pin's weight is
 * `mustRender`: it proves the capture is real before anything else is trusted.
 */
it('renders its about section', function (): void {
    expect('tickets')->toLeakNoSecrets([], mustRender: [
        'Primary key type', 'Key type', 'bigint', 'Lifecycle', 'TicketLifecycle', 'Priority enum', 'TicketPriority',
        'Routes', 'OFF', 'Guest access', 'Notifications', 'ON (mail)',
    ]);
});

it('renders notifications on with their channels, or off', function (): void {
    config()->set('tickets.notifications.channels', ['mail', 'database']);

    expect('tickets')->toLeakNoSecrets([], mustRender: ['Notifications', 'ON (mail, database)']);

    config()->set('tickets.notifications.enabled', 'off');
    Artisan::call('about', ['--only' => 'tickets']);

    expect(Artisan::output())->toMatch('/Notifications\W+OFF/');
});

it('renders a broken setting as INVALID instead of failing the whole command', function (string $key, mixed $value, string $label): void {
    config()->set($key, $value);

    Artisan::call('about', ['--only' => 'tickets']);

    expect(Artisan::output())->toMatch('/'.$label.'\W+INVALID\b/');
})->with([
    'key type' => ['tickets.key_type', 'uiid', 'Key type'],
    'primary key type' => ['tickets.primary_key_type', 'uiid', 'Primary key type'],
    'lifecycle' => ['tickets.lifecycle', stdClass::class, 'Lifecycle'],
    'priority enum' => ['tickets.priority.enum', stdClass::class, 'Priority enum'],
    'routes' => ['tickets.routes.enabled', 'maybe', 'Routes'],
    'guest access' => ['tickets.guests.enabled', 'maybe', 'Guest access'],
    'notifications' => ['tickets.notifications.enabled', 'maybe', 'Notifications'],
]);
