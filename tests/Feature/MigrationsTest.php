<?php

declare(strict_types=1);

use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\TicketsServiceProvider;

$migrations = __DIR__.'/../../database/migrations';

/**
 * Ten files, ten FK edges: categories.parent, tickets.category, tickets.merged_into,
 * fields.category, ticket_tag.ticket, ticket_tag.tag, watchers.ticket, links.ticket,
 * links.related, activities.ticket.
 */
it('creates every foreign key target before the table that references it', function () use ($migrations): void {
    expect($migrations)->toHaveRunnableMigrationOrder(foreignKeys: 10);
});

it('never auto-loads its migrations — the host publishes them', function (): void {
    expect(TicketsServiceProvider::class)->toNotAutoLoadMigrations();
});

it('publishes its migrations timestamp-injected into the host', function (): void {
    expect(TicketsServiceProvider::class)->toPublishMigrationsTimestamped('tickets-migrations', 10);
});

it('migrates forward only — no migration defines down()', function () use ($migrations): void {
    $files = glob($migrations.'/*.php') ?: [];

    expect($files)->toHaveCount(10);

    foreach ($files as $file) {
        expect((string) file_get_contents($file))->not->toContain('function down(');
    }
});

it('applies its migrations on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 10);
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available')->group('pgsql');

it('rejects a child-before-parent order on postgres', function () use ($migrations): void {
    expect($migrations)->toRejectBrokenOrderOnConnection(fn (array $files): array => array_reverse($files), 'pgsql');
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available')->group('pgsql');

it('applies its migrations on mysql', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('mysql', migrations: 10);
})->skip(fn (): bool => ! test()->connectionAvailable('mysql'), 'no mysql connection available')->group('mysql');

it('rejects a child-before-parent order on mysql', function () use ($migrations): void {
    expect($migrations)->toRejectBrokenOrderOnConnection(fn (array $files): array => array_reverse($files), 'mysql');
})->skip(fn (): bool => ! test()->connectionAvailable('mysql'), 'no mysql connection available')->group('mysql');

/**
 * The driver-truth pin: a leg that quietly stayed on SQLite fails here instead of passing
 * as a "postgres" or "mysql" run.
 */
it('runs on the driver the environment declares', function (): void {
    expect(DatabaseDriver::current()->value)->toBe(DriverMatrix::driver());
});

/**
 * The jsonb locale maps, the jsonb `meta`, the lifecycle-owned status and the self FK are
 * what the engines render differently — a round-trip proves the tables are usable, not
 * merely creatable.
 */
it('round-trips a ticket and its category on the configured engine', function (): void {
    $parent = TicketCategory::factory()->create(['name' => ['en' => 'Hardware', 'sk' => 'Hardvér']]);
    $child = TicketCategory::factory()->under($parent)->create(['name' => ['en' => 'Printers']]);
    $ticket = Ticket::factory()->create(['category_id' => $child->id, 'meta' => ['source' => 'kiosk', 'tier' => 2]]);
    $duplicate = Ticket::factory()->create(['merged_into_id' => $ticket->id]);

    $fresh = Ticket::query()->findOrFail($ticket->id);

    expect($fresh->status)->toBe(TicketStatus::New)
        ->and($fresh->meta['tier'] ?? null)->toBe(2)
        ->and($fresh->meta['source'] ?? null)->toBe('kiosk')
        ->and($fresh->category?->parent?->getTranslation('name', 'sk'))->toBe('Hardvér')
        ->and($fresh->category?->slug)->toBe('printers')
        ->and($duplicate->fresh()?->mergedInto?->is($ticket))->toBeTrue();
});
