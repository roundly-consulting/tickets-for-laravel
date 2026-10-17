<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\NamedSetting;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\UuidUser;

/**
 * `tickets:doctor`, section by section (§10). The lifecycle section is pinned in
 * LifecycleContractTest, the api section in RoutesTest / RoutesOffTest.
 *
 * @return array{exit: int, checks: list<array{section: string, code: string, level: string, message: string}>}
 */
function doctorReport(?string $section = null): array
{
    $exit = Artisan::call('tickets:doctor', ['--json' => true]);

    /** @var array{ok: bool, checks: list<array{section: string, code: string, level: string, message: string}>} $output */
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($output['ok'])->toBe($exit === 0);

    $checks = $section === null
        ? $output['checks']
        : array_values(array_filter($output['checks'], static fn (array $check): bool => $check['section'] === $section));

    return ['exit' => $exit, 'checks' => $checks];
}

/**
 * @return array<string, string> code => level of one section (the last check of a code wins)
 */
function doctorLevels(string $section): array
{
    $checks = doctorReport($section)['checks'];

    return array_combine(array_column($checks, 'code'), array_column($checks, 'level'));
}

/**
 * @return list<string> the messages of one section
 */
function doctorMessages(string $section): array
{
    return array_column(doctorReport($section)['checks'], 'message');
}

it('runs every check §10 names', function (): void {
    $pairs = array_map(static fn (array $check): string => $check['section'].'.'.$check['code'], doctorReport()['checks']);

    expect($pairs)->toContain(
        'keys.alignment',
        'lifecycle.contract',
        'lifecycle.conveniences',
        'priority.enum',
        'authorization.abilities',
        'aliases.maps',
        'guests.fields',
        'conversation.meta',
        'attachments.disk',
        'attachments.temporary_urls',
        'api.policy',
        'api.routes',
        'api.guest_links',
        'notifications.enabled',
        'notifications.types',
        'due.schedule',
    );
});

it('passes a configured installation', function (): void {
    defineAgents();
    Gate::define('tickets.assign', static fn (): bool => true);
    Gate::define('tickets.manage', static fn (): bool => true);
    config()->set('tickets.notifications.guests', 'off');

    $report = doctorReport();

    expect($report['exit'])->toBe(0)
        ->and(array_filter($report['checks'], static fn (array $check): bool => $check['level'] === 'error'))->toBe([]);
});

it('prints a readable report', function (): void {
    expect(Artisan::call('tickets:doctor'))->toBe(1)
        ->and(Artisan::output())->toContain('[OK] keys: Every key type lines up: bigint.')
        ->toContain('[ERROR] api:')
        ->toContain('[WARNING] authorization:');
});

describe('key types', function (): void {
    it('reports every setting that disagrees', function (): void {
        config()->set('lifecycle.key_type', 'uuid');
        config()->set('comments.key_type', 'ulid');

        $report = doctorReport('keys');
        $messages = array_column($report['checks'], 'message');

        expect($report['exit'])->toBe(1)
            ->and(array_column($report['checks'], 'level'))->toBe(['error', 'error', 'error'])
            ->and($messages[0])->toBe('tickets.primary_key_type is bigint, but lifecycle.key_type is uuid: they must be the same, or the columns and lookups will not match.')
            ->and($messages[1])->toContain('comments.key_type is ulid')
            ->and($messages[2])->toStartWith('tickets.key_type is bigint, but comments.key_type is ulid');
    });

    it('reports a setting that is no key type', function (): void {
        config()->set('attributes.key_type', 'integer');

        expect(doctorMessages('keys'))->toBe(['The setting [attributes.key_type] is not a key type (bigint, uuid or ulid).']);
    });

    it('warns when the user model is keyed otherwise', function (): void {
        config()->set('auth.providers.users.model', UuidUser::class);

        expect(doctorLevels('keys'))->toBe(['alignment' => 'ok', 'user_model' => 'warning'])
            ->and(doctorMessages('keys')[1])->toContain(UuidUser::class)->toContain('keyed by uuid, but tickets.key_type is bigint');

        config()->set('auth.providers.users.model', User::class);
        expect(doctorLevels('keys'))->toBe(['alignment' => 'ok']);

        // A ulid user model, against a bigint setting.
        config()->set('tickets.primary_key_type', 'ulid');
        config()->set('auth.providers.users.model', TicketGuest::class);
        expect(doctorMessages('keys'))->toContain('Your user model ['.TicketGuest::class.'] is keyed by ulid, but tickets.key_type is bigint: requesters, assignees and actors would not be stored right.');
    });

    it('says nothing of a user model it cannot read', function (string $model): void {
        config()->set('auth.providers.users.model', $model);

        expect(doctorLevels('keys'))->toBe(['alignment' => 'ok']);
    })->with([
        'not a model' => [stdClass::class],
        'a string key that is no unique id' => [NamedSetting::class],
    ]);
});

describe('unknown_priority_is_tolerated', function (): void {
    it('counts the tickets whose stored priority the enum no longer has', function (): void {
        $ticket = Tickets::make()->subject('a')->requester(User::query()->create())->asSystem()->create();
        Tickets::make()->subject('b')->requester(User::query()->create())->asSystem()->create();

        expect(doctorLevels('priority'))->toBe(['enum' => 'ok']);

        Ticket::query()->whereKey($ticket->getKey())->update(['priority' => 'critical']);

        expect(doctorLevels('priority'))->toBe(['enum' => 'ok', 'stored' => 'warning'])
            ->and(doctorMessages('priority')[1])->toStartWith('1 ticket carries a priority');
    });

    it('reports a priority enum or default that cannot be used', function (string $key, string $value): void {
        config()->set($key, $value);

        $report = doctorReport('priority');

        expect($report['exit'])->toBe(1)
            ->and(array_column($report['checks'], 'level', 'code'))->toBe(['enum' => 'error']);
    })->with([
        'enum' => ['tickets.priority.enum', stdClass::class],
        'default' => ['tickets.priority.default', 'critical'],
    ]);
});

describe('abilities', function (): void {
    it('warns about the abilities nobody defined, and passes once they are', function (): void {
        defineAgents();

        expect(doctorLevels('authorization'))->toBe(['abilities' => 'warning'])
            ->and(doctorMessages('authorization')[0])->toContain('tickets.assign, tickets.manage')->not->toContain('tickets.agent,');

        Gate::define('tickets.assign', static fn (): bool => true);
        Gate::define('tickets.manage', static fn (): bool => true);

        expect(doctorLevels('authorization'))->toBe(['abilities' => 'ok']);
    });

    it('reports a broken ability setting', function (): void {
        config()->set('tickets.authorization.abilities.agent', ['tickets.agent']);

        expect(doctorLevels('authorization'))->toBe(['abilities' => 'error']);
    });
});

describe('alias lists', function (): void {
    it('reports a class missing its contract, and a list that is no map', function (): void {
        config()->set('tickets.assignables', ['user' => User::class, 'document' => Document::class]);
        config()->set('tickets.projects', ['document' => Document::class]);
        config()->set('tickets.watchables', 'everyone');

        $report = doctorReport('aliases');

        expect($report['exit'])->toBe(1)
            ->and(array_column($report['checks'], 'code'))->toBe(['assignables', 'watchables', 'projects'])
            ->and($report['checks'][0]['message'])->toContain('[document]')->toContain('Assignee')
            ->and($report['checks'][2]['message'])->toContain('TicketProject');
    });

    it('passes usable lists', function (): void {
        expect(doctorLevels('aliases'))->toBe(['maps' => 'ok']);
    });
});

describe('guest fields', function (): void {
    it('lists the fields, and reports a broken definition', function (): void {
        expect(doctorMessages('guests'))->toBe(['Guest fields: name, email, phone.']);

        config()->set('tickets.guests.fields.email.encrypted', true);

        expect(doctorLevels('guests'))->toBe(['fields' => 'error']);
    });
});

describe('attachment download URLs', function (): void {
    it('passes with media-library\'s stream route on', function (): void {
        expect(doctorLevels('attachments'))->toBe(['disk' => 'ok', 'temporary_urls' => 'ok']);
    });

    it('reports every attachment disk that mints no temporary URLs once the stream route is off', function (): void {
        // Both disks spelled out: whether the skeleton's `local` disk serves signed URLs differs
        // between Laravel 12 and 13.
        config()->set('media.stream.enabled', 'off');
        config()->set('filesystems.disks.vault', ['driver' => 'local', 'root' => sys_get_temp_dir(), 'serve' => false]);
        config()->set('filesystems.disks.archive', ['driver' => 'local', 'root' => sys_get_temp_dir(), 'serve' => false]);
        config()->set('tickets.attachments.disk', 'vault');
        config()->set('comments.media.private_disk', 'archive');

        expect(doctorLevels('attachments'))->toBe(['disk' => 'ok', 'temporary_urls' => 'warning'])
            ->and(doctorMessages('attachments')[1])->toContain('the disks vault, archive mint no temporary URLs');

        config()->set('tickets.routes.enabled', 'on');

        expect(doctorLevels('attachments'))->toBe(['disk' => 'ok', 'temporary_urls' => 'error']);
    });

    it('passes disks that mint their own, and fails one that does not exist', function (): void {
        config()->set('media.stream.enabled', 'off');
        config()->set('filesystems.disks.served', ['driver' => 'local', 'root' => sys_get_temp_dir(), 'serve' => true]);
        config()->set('tickets.attachments.disk', 'served');
        config()->set('comments.media.private_disk', 'served');

        expect(doctorLevels('attachments'))->toBe(['disk' => 'ok', 'temporary_urls' => 'ok'])
            ->and(doctorMessages('attachments')[1])->toBe('Attachment download URLs: the disks served mint temporary URLs of their own.');

        config()->set('tickets.attachments.disk', 'nowhere');

        expect(doctorMessages('attachments')[1])->toContain('the disks nowhere mint no temporary URLs');
    });
});

describe('notifications', function (): void {
    it('reports them on with their channels, and off', function (): void {
        config()->set('tickets.notifications.channels', ['mail', 'database']);

        expect(doctorMessages('notifications'))->toBe(['Notifications are on: mail, database.', 'Every notification type is usable; switched off: —.']);

        config()->set('tickets.notifications.enabled', 'off');
        config()->set('tickets.notifications.types.mentioned', false);
        config()->set('tickets.notifications.types.due_soon', 'off');

        expect(doctorMessages('notifications'))->toBe(['Notifications are off (tickets.notifications.enabled).', 'Every notification type is usable; switched off: mentioned, due_soon.']);
    });

    it('reports a type class, a url template and a switch that cannot be used', function (): void {
        config()->set('tickets.notifications.types.replied', stdClass::class);
        config()->set('tickets.notifications.types.merged', 'App\\Notifications\\Missing');
        config()->set('tickets.notifications.url', 'not a url');

        $report = doctorReport('notifications');

        expect($report['exit'])->toBe(1)
            ->and(array_column($report['checks'], 'level'))->toBe(['ok', 'error', 'error', 'error'])
            ->and($report['checks'][1]['message'])->toContain('tickets.notifications.types.replied')
            ->and($report['checks'][3]['message'])->toContain('tickets.notifications.url');

        config()->set('tickets.notifications.enabled', 'maybe');

        expect(doctorLevels('notifications'))->toBe(['enabled' => 'error']);
    });
});

describe('due dates', function (): void {
    it('reminds of the two scheduled sweeps', function (): void {
        expect(doctorMessages('due'))->toBe(['Schedule tickets:sweep every five minutes (overdue and due soon) and lifecycle:sweep every minute (auto-close).']);
    });

    it('reports a due-soon window or auto-close duration that cannot be used', function (): void {
        config()->set('tickets.due.soon_window', 'soon');
        config()->set('tickets.auto_close.after', 'a week');

        expect(doctorLevels('due'))->toBe(['soon_window' => 'error', 'auto_close' => 'error', 'schedule' => 'ok']);
    });
});
