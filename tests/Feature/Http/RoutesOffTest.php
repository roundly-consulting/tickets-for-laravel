<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Tickets\Contracts\GuestLinkGenerator;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;

it('ships the API off: no route is served by default', function (): void {
    expect(config('tickets.routes.enabled'))->toBe('off')
        ->and(Route::has('tickets.index'))->toBeFalse()
        ->and(collect(Route::getRoutes()->getRoutes())->filter(static fn ($route): bool => str_starts_with((string) $route->getName(), 'tickets.')))->toBeEmpty();
});

it('reads a blank TICKETS_ROUTES as off, never as the route switch’s blank-means-on', function (string $value, string $expected): void {
    putenv('TICKETS_ROUTES='.$value);
    $config = require __DIR__.'/../../../config/tickets.php';
    putenv('TICKETS_ROUTES');

    expect($config['routes']['enabled'])->toBe($expected);
})->with([
    'blank' => ['', 'off'],
    'on' => ['on', 'on'],
]);

it('refuses to mint a guest link without the guest routes', function (): void {
    $ticket = Tickets::make()->subject('A')->guest(['name' => 'Jo', 'email' => 'jo@acme.test'])->asSystem()->create();

    expect(fn () => Tickets::for($ticket)->guestLink())->toThrow(InvalidTicketsConfigurationException::class, 'tickets.routes.enabled');
});

it('renders the routes as OFF in about', function (): void {
    Artisan::call('about', ['--only' => 'tickets']);

    expect(Artisan::output())->toMatch('/Routes\W+OFF/')->toMatch('/Guest access\W+OFF/');
});

/**
 * @return array<string, string> code => level of the doctor's api section
 */
function routesOffDoctor(): array
{
    Artisan::call('tickets:doctor', ['--json' => true]);

    /** @var array{checks: list<array{section: string, code: string, level: string}>} $output */
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $api = array_values(array_filter($output['checks'], static fn (array $check): bool => $check['section'] === 'api'));

    return array_combine(array_column($api, 'code'), array_column($api, 'level'));
}

it('reports the API off, and the default guest links unusable without it', function (): void {
    // Guests are notified by default, and their mails carry those links: an error.
    expect(routesOffDoctor())->toBe(['policy' => 'ok', 'routes' => 'ok', 'guest_links' => 'error']);

    // Nobody to mail them to — guests unnotified, or notifications off, or every type that
    // reaches the requester side switched off: back to a warning.
    config()->set('tickets.notifications.guests', 'off');
    expect(routesOffDoctor())->toBe(['policy' => 'ok', 'routes' => 'ok', 'guest_links' => 'warning']);

    config()->set('tickets.notifications.guests', 'on');
    config()->set('tickets.notifications.enabled', 'off');
    expect(routesOffDoctor())->toBe(['policy' => 'ok', 'routes' => 'ok', 'guest_links' => 'warning']);

    config()->set('tickets.notifications.enabled', 'on');
    config()->set('tickets.notifications.types', [
        'created' => false, 'replied' => false, 'status_changed' => false, 'merged' => false,
    ]);
    expect(routesOffDoctor())->toBe(['policy' => 'ok', 'routes' => 'ok', 'guest_links' => 'warning']);

    // A broken notification setting is the notifications section's error, not this one's.
    config()->set('tickets.notifications.types.created', stdClass::class);
    expect(routesOffDoctor())->toBe(['policy' => 'ok', 'routes' => 'ok', 'guest_links' => 'warning']);

    app()->bind(GuestLinkGenerator::class, static fn () => new class implements GuestLinkGenerator
    {
        public function for(Ticket $ticket, Model $recipient): string
        {
            return 'https://helpdesk.test';
        }
    });

    expect(routesOffDoctor())->toBe(['policy' => 'ok', 'routes' => 'ok']);
});

it('reports a policy setting that registers nothing as an error', function (): void {
    config()->set('tickets.authorization.policy', stdClass::class);

    expect(routesOffDoctor()['policy'])->toBe('error');
});
