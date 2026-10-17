<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Exceptions\UnknownMorphAliasException;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Support\Durations;
use RoundlyConsulting\Tickets\Support\GuestFields;
use RoundlyConsulting\Tickets\Support\MorphAliases;
use RoundlyConsulting\Tickets\Support\Optional;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Tests\Fixtures\IntPriority;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

it('parses positive ISO-8601 durations only', function (string $value, ?int $seconds): void {
    expect(Durations::parse($value)?->totalSeconds)->toBe($seconds === null ? null : (float) $seconds);
})->with([
    ['P2D', 172800],
    ['PT4H', 14400],
    ['P1DT12H', 129600],
    ['PT30M', 1800],
    ['P0D', null],
    ['P', null],
    ['PT', null],
    ['2 days', null],
    ['-P1D', null],
    ['P1H', null],
]);

it('returns a CarbonInterval', function (): void {
    expect(Durations::parse('P1W'))->toBeInstanceOf(CarbonInterval::class);
});

it('tells a given DTO value from the not-given sentinel', function (): void {
    expect(Optional::given(new Optional))->toBeFalse()
        ->and(Optional::given(null))->toBeTrue()
        ->and(Optional::given('x'))->toBeTrue();
});

it('resolves allow-listed morph aliases and nothing else', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $team = Team::query()->create(['name' => 'Hardware']);
    $project = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
    $guest = TicketGuest::factory()->create();

    expect(MorphAliases::resolve(MorphAliases::REQUESTERS, 'user', $user->id)->is($user))->toBeTrue()
        ->and(MorphAliases::resolve(MorphAliases::REQUESTERS, 'guest', $guest->id)->is($guest))->toBeTrue()
        ->and(MorphAliases::resolve(MorphAliases::ASSIGNABLES, 'team', (string) $team->id)->is($team))->toBeTrue()
        ->and(MorphAliases::resolve(MorphAliases::PROJECTS, 'project', $project->id)->is($project))->toBeTrue()
        ->and(MorphAliases::aliasFor(MorphAliases::ASSIGNABLES, $team))->toBe('team')
        ->and(MorphAliases::aliasFor(MorphAliases::PROJECTS, $user))->toBeNull()
        ->and(MorphAliases::map(MorphAliases::REQUESTERS))->toBe(['user' => User::class, 'guest' => TicketGuest::class]);
});

it('refuses a class name, an unknown alias, a missing row or a class without its contract', function (Closure $resolve, string $message): void {
    expect($resolve)->toThrow(UnknownMorphAliasException::class, $message);
})->with([
    'class name' => [fn () => MorphAliases::resolve(MorphAliases::REQUESTERS, User::class, 1), 'not an allowed'],
    'unknown alias' => [fn () => MorphAliases::resolve(MorphAliases::ASSIGNABLES, 'robot', 1), 'not an allowed'],
    'missing row' => [fn () => MorphAliases::resolve(MorphAliases::REQUESTERS, 'user', 999), 'No user'],
    'unknown list' => [fn () => MorphAliases::map('watchers'), 'no alias list'],
    'assignable without Assignee' => [function () {
        config()->set('tickets.assignables', ['project' => Project::class]);

        return MorphAliases::resolve(MorphAliases::ASSIGNABLES, 'project', 1);
    }, 'not an allowed'],
    'project without TicketProject' => [function () {
        config()->set('tickets.projects', ['team' => Team::class]);

        return MorphAliases::resolve(MorphAliases::PROJECTS, 'team', 1);
    }, 'not an allowed'],
]);

it('refuses a malformed alias list', function (mixed $value): void {
    config()->set('tickets.requesters', $value);

    MorphAliases::map(MorphAliases::REQUESTERS);
})->with([
    'not an array' => ['user'],
    'bad alias' => [['User' => User::class]],
    'list' => [[User::class]],
    'not a model' => [['user' => stdClass::class]],
])->throws(InvalidTicketsConfigurationException::class);

it('reads an unset alias list as empty', function (): void {
    config()->set('tickets.projects', null);

    expect(MorphAliases::map(MorphAliases::PROJECTS))->toBe([]);
});

it('reads the channel settings strictly', function (): void {
    config()->set('tickets.channels', 'web, email');
    config()->set('tickets.default_channel', null);

    expect(TicketsConfig::channels())->toBe(['web', 'email'])
        ->and(TicketsConfig::defaultChannel())->toBe('web');

    config()->set('tickets.channels', ['Web Form']);

    expect(fn () => TicketsConfig::channels())->toThrow(InvalidTicketsConfigurationException::class, '[tickets.channels]');

    config()->set('tickets.channels', ['web']);
    config()->set('tickets.default_channel', 'fax');

    expect(fn () => TicketsConfig::defaultChannel())->toThrow(InvalidTicketsConfigurationException::class, '[tickets.default_channel]');
});

it('reads the remaining settings strictly', function (string $key, mixed $value, Closure $read): void {
    config()->set($key, $value);

    expect($read)->toThrow(InvalidTicketsConfigurationException::class, "[{$key}]");
})->with([
    'default priority' => ['tickets.priority.default', 'critical', fn () => TicketsConfig::defaultPriority()],
    'max depth' => ['tickets.categories.max_depth', 0, fn () => TicketsConfig::maxCategoryDepth()],
    'activity' => ['tickets.activity.enabled', 'sometimes', fn () => TicketsConfig::activityEnabled()],
    'actor from auth' => ['tickets.actor.from_auth', 'maybe', fn () => TicketsConfig::actorFromAuth()],
    'actor guard' => ['tickets.actor.guard', 5, fn () => TicketsConfig::actorGuard()],
    'allowed rules' => ['tickets.fields.allowed_rules', 5, fn () => TicketsConfig::allowedFieldRules()],
    'require one identifying' => ['tickets.guests.require_one_identifying', 'sometimes', fn () => GuestFields::requireOneIdentifying()],
]);

it('refuses an int-backed priority enum', function (): void {
    config()->set('tickets.priority.enum', IntPriority::class);

    TicketsConfig::priorityEnum();
})->throws(InvalidTicketsConfigurationException::class, 'string-backed');

it('orders primary keys numerically, and uuid or ulid keys as text', function (): void {
    expect(TicketLocker::compareKeys(9, 10))->toBe(-1)
        ->and(TicketLocker::compareKeys('01J2B', '01J2A'))->toBeGreaterThan(0)
        ->and(TicketLocker::compareKeys(null, 'a'))->toBeLessThan(0);
});
