<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * `tickets:install` publishes the config and every migration a ticketing installation needs —
 * into a throwaway config/ and database/ ({@see InstallTestCase}).
 *
 * @return list<string> the published migration file names, in order
 */
function installedMigrations(): array
{
    $files = glob(database_path('migrations/*.php')) ?: [];
    sort($files);

    return array_map(static fn (string $path): string => (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path)), $files);
}

it('publishes into the sandbox, never the shared skeleton', function (): void {
    expect(config_path('tickets.php'))->toContain('tickets-install-')
        ->and(database_path('migrations'))->toContain('tickets-install-');
});

it('publishes the config and every migration — each package\'s in its own order — and prints the next steps', function (): void {
    expect(Artisan::call('tickets:install'))->toBe(0);

    $output = Artisan::output();
    $migrations = installedMigrations();
    $ours = array_values(array_filter($migrations, static fn (string $name): bool => str_contains($name, 'ticket')));

    expect(is_file(config_path('tickets.php')))->toBeTrue()
        ->and($migrations)->toHaveCount(28)
        ->and($migrations)->toContain('create_media_table.php', 'create_likes_table.php', 'create_reports_table.php', '0001_create_approvals_table.php',
            '0001_create_lifecycle_states_table.php', 'create_attributes_table.php', 'create_attribute_revisions_table.php')
        ->and($ours)->toBe([
            '0001_create_ticket_categories_table.php',
            '0002_create_ticket_sequences_table.php',
            '0003_create_ticket_guests_table.php',
            '0004_create_tickets_table.php',
            '0005_create_ticket_fields_table.php',
            '0006_create_ticket_tags_table.php',
            '0007_create_ticket_tag_table.php',
            '0008_create_ticket_watchers_table.php',
            '0009_create_ticket_links_table.php',
            '0010_create_ticket_activities_table.php',
        ])
        ->and(array_search('add_meta_to_comments_table.php', $migrations, true))->toBeGreaterThan(array_search('create_comments_table.php', $migrations, true))
        ->and(array_search('0004_create_lifecycle_quota_locks_table.php', $migrations, true))->toBeGreaterThan(array_search('0001_create_lifecycle_states_table.php', $migrations, true));

    expect($output)->toContain('Published tickets-config')->toContain('Published comments-migrations')
        ->toContain('tickets.primary_key_type')->toContain('lifecycle.actor_key_type')->toContain('comments.key_type')->toContain('bigint')
        ->toContain('before running the migrations')
        ->toContain('php artisan migrate')
        ->toContain("Gate::define('tickets.agent'")
        ->toContain("Schedule::command('tickets:sweep')->everyFiveMinutes();")
        ->toContain("Schedule::command('lifecycle:sweep')->everyMinute();")
        ->toContain('php artisan tickets:doctor')
        ->toContain('--tag=tickets-routes');
});

it('leaves the route file alone — publishing it is a step of its own', function (): void {
    Artisan::call('tickets:install');

    expect(is_file(base_path('routes/tickets.php')))->toBeFalse();
});

it('runs again harmlessly, and overwrites with --force', function (): void {
    expect(Artisan::call('tickets:install'))->toBe(0);

    file_put_contents(config_path('tickets.php'), "<?php return ['edited' => true];\n");
    $first = installedMigrations();

    expect(Artisan::call('tickets:install'))->toBe(0)
        ->and((string) file_get_contents(config_path('tickets.php')))->toContain('edited')
        ->and(installedMigrations())->toBe($first);

    expect(Artisan::call('tickets:install', ['--force' => true]))->toBe(0)
        ->and((string) file_get_contents(config_path('tickets.php')))->not->toContain('edited')
        ->and(installedMigrations())->toBe($first);
});

it('shows a key type it cannot read as INVALID', function (): void {
    config()->set('comments.key_type', 'integer');

    expect(Artisan::call('tickets:install'))->toBe(0)
        ->and(Artisan::output())->toMatch('/comments\.key_type.*INVALID/');
});
