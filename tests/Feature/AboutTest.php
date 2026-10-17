<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Tickets\Facades\Tickets;

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

/**
 * S-14 — `about` renders settings only. A guest's fields live in attributes rows, and no
 * value of one may reach the section.
 */
it('keeps every guest field value out of the about section', function (): void {
    Tickets::guests()->resolve(['name' => 'Jo Privacy', 'email' => 'jo.privacy@acme.test', 'phone' => '+421 900 123 456']);

    expect('tickets')->toLeakNoSecrets(
        ['Jo Privacy', 'jo.privacy@acme.test', '+421900123456', '900 123 456'],
        mustRender: ['Guest access', 'ON'],
    );
});
