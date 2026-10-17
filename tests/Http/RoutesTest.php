<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Tickets\Http\Middleware\EnsureGuestTicketAccess;

/**
 * The whole HTTP surface, pinned: every route by method, URI and name.
 */
const TICKETS_ROUTES = [
    'GET|HEAD api/ticket-categories tickets.admin.categories.index',
    'POST api/ticket-categories tickets.admin.categories.store',
    'GET|HEAD api/ticket-categories/{category} tickets.admin.categories.show',
    'PATCH api/ticket-categories/{category} tickets.admin.categories.update',
    'DELETE api/ticket-categories/{category} tickets.admin.categories.destroy',
    'POST api/ticket-categories/{category}/move tickets.admin.categories.move',
    'GET|HEAD api/ticket-fields tickets.admin.fields.index',
    'POST api/ticket-fields tickets.admin.fields.store',
    'GET|HEAD api/ticket-fields/{field} tickets.admin.fields.show',
    'PATCH api/ticket-fields/{field} tickets.admin.fields.update',
    'DELETE api/ticket-fields/{field} tickets.admin.fields.destroy',
    'GET|HEAD api/ticket-tags tickets.admin.tags.index',
    'POST api/ticket-tags tickets.admin.tags.store',
    'PATCH api/ticket-tags/{tag} tickets.admin.tags.update',
    'DELETE api/ticket-tags/{tag} tickets.admin.tags.destroy',
    'GET|HEAD api/tickets tickets.index',
    'POST api/tickets tickets.store',
    'POST api/tickets/bulk tickets.bulk',
    'GET|HEAD api/tickets/guest/{ticket} tickets.guest.show',
    'GET|HEAD api/tickets/guest/{ticket}/attachments/{media} tickets.guest.attachment',
    'POST api/tickets/guest/{ticket}/replies tickets.guest.reply',
    'POST api/tickets/guest/{ticket}/transitions/{transition} tickets.guest.transition',
    'GET|HEAD api/tickets/{ticket} tickets.show',
    'PATCH api/tickets/{ticket} tickets.update',
    'DELETE api/tickets/{ticket} tickets.destroy',
    'GET|HEAD api/tickets/{ticket}/activity tickets.activity',
    'PUT api/tickets/{ticket}/assignee tickets.assignee.update',
    'DELETE api/tickets/{ticket}/assignee tickets.assignee.destroy',
    'GET|HEAD api/tickets/{ticket}/attachments tickets.attachments.index',
    'POST api/tickets/{ticket}/attachments tickets.attachments.store',
    'GET|HEAD api/tickets/{ticket}/attachments/{media} tickets.attachments.show',
    'DELETE api/tickets/{ticket}/attachments/{media} tickets.attachments.destroy',
    'GET|HEAD api/tickets/{ticket}/comments tickets.comments.index',
    'PATCH api/tickets/{ticket}/comments/{comment} tickets.comments.update',
    'DELETE api/tickets/{ticket}/comments/{comment} tickets.comments.destroy',
    'GET|HEAD api/tickets/{ticket}/fields tickets.fields.index',
    'PUT api/tickets/{ticket}/fields tickets.fields.update',
    'GET|HEAD api/tickets/{ticket}/links tickets.links.index',
    'POST api/tickets/{ticket}/links tickets.links.store',
    'DELETE api/tickets/{ticket}/links/{link} tickets.links.destroy',
    'POST api/tickets/{ticket}/merge tickets.merge',
    'POST api/tickets/{ticket}/notes tickets.notes.store',
    'POST api/tickets/{ticket}/replies tickets.replies.store',
    'POST api/tickets/{ticket}/restore tickets.restore',
    'PUT api/tickets/{ticket}/tags tickets.tags.sync',
    'GET|HEAD api/tickets/{ticket}/transitions tickets.transitions.index',
    'POST api/tickets/{ticket}/transitions/{transition} tickets.transitions.store',
    'POST api/tickets/{ticket}/watch tickets.watch',
    'DELETE api/tickets/{ticket}/watch tickets.unwatch',
    'GET|HEAD api/tickets/{ticket}/watchers tickets.watchers.index',
    'POST api/tickets/{ticket}/watchers tickets.watchers.store',
    'DELETE api/tickets/{ticket}/watchers/{watcher} tickets.watchers.destroy',
];

/**
 * The package routes, as `route:list` renders them.
 *
 * @return list<string>
 */
function routesRendered(): array
{
    Artisan::call('route:list', ['--json' => true, '--name' => 'tickets.']);

    /** @var list<array{method: string, uri: string, name: string}> $routes */
    $routes = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return array_map(static fn (array $route): string => $route['method'].' '.$route['uri'].' '.$route['name'], $routes);
}

/**
 * Load the package's route file again, alone, under the current config.
 */
function routesReloaded(): void
{
    Route::setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/tickets.php';
    Route::getRoutes()->refreshNameLookups();
}

it('serves exactly the documented routes, and route:list renders every one', function (): void {
    $rendered = routesRendered();

    expect($rendered)->toHaveCount(52)
        ->and($rendered)->toEqualCanonicalizing(TICKETS_ROUTES);
});

it('guards the agent and requester routes with routes.middleware and the guest routes with the link check', function (): void {
    $show = Route::getRoutes()->getByName('tickets.show');
    $guest = Route::getRoutes()->getByName('tickets.guest.show');

    expect($show)->toBeInstanceOf(RoutingRoute::class)
        ->and($show?->middleware())->toBe(['api', 'auth', 'throttle:tickets-api'])
        ->and($guest?->middleware())->toBe(['api', 'throttle:tickets-guest', EnsureGuestTicketAccess::class]);
});

it('takes its prefix and middleware from config', function (): void {
    config()->set('tickets.routes.prefix', '/helpdesk/v1/');
    config()->set('tickets.routes.middleware', ['api', 'auth:sanctum']);
    routesReloaded();

    expect(Route::getRoutes()->getByName('tickets.index')?->uri())->toBe('helpdesk/v1/tickets')
        ->and(Route::getRoutes()->getByName('tickets.index')?->middleware())->toBe(['api', 'auth:sanctum']);

    config()->set('tickets.routes.prefix', '/');
    routesReloaded();

    expect(Route::getRoutes()->getByName('tickets.index')?->uri())->toBe('tickets');
});

it('serves no guest routes with guests.enabled off', function (): void {
    config()->set('tickets.guests.enabled', 'off');
    routesReloaded();

    expect(Route::has('tickets.index'))->toBeTrue()
        ->and(Route::has('tickets.guest.show'))->toBeFalse()
        ->and(count(Route::getRoutes()->getRoutes()))->toBe(48);
});

it('only binds references, record keys and uuids', function (string $uri): void {
    $this->actingAs(apiUser())->getJson($uri)->assertNotFound()->assertJsonMissingPath('code');
})->with([
    '/api/tickets/tck-1',
    '/api/tickets/TCK-1/comments/abc',
    '/api/tickets/TCK-1/attachments/not-a-uuid',
    '/api/ticket-categories/abc',
]);

it('renders the routes as ON in about', function (): void {
    expect('tickets')->toLeakNoSecrets([], mustRender: ['Routes', 'ON', 'Guest access']);
});

it('reports the API on in the doctor, and warns when no auth middleware guards it', function (): void {
    $read = static function (): array {
        Artisan::call('tickets:doctor', ['--json' => true]);

        /** @var array{checks: list<array{section: string, code: string, level: string}>} $output */
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $api = array_values(array_filter($output['checks'], static fn (array $check): bool => $check['section'] === 'api'));

        return array_combine(array_column($api, 'code'), array_column($api, 'level'));
    };

    expect($read())->toBe(['policy' => 'ok', 'routes' => 'ok']);

    config()->set('tickets.routes.middleware', ['api']);

    expect($read())->toBe(['policy' => 'ok', 'routes' => 'warning']);
});
