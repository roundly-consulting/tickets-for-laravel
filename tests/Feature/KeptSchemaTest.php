<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Support\MigratedSchema;

/**
 * The suite's own harness: a worker migrates once and resets the data between tests
 * (tests/Support/KeepsMigratedSchema). These pin the two promises that make that safe — the next
 * test starts where a fresh migrate would, and a test that changed the schema is never reused.
 */
describe('kept_schema', function (): void {
    it('runs the test outside any transaction', function (): void {
        expect(DB::transactionLevel())->toBe(0);
    });

    it('empties what a test wrote and rewinds every key counter', function (): void {
        $schema = MigratedSchema::capture(DB::connection(), 'pin');

        $user = User::query()->create(['name' => 'Ada']);
        Tickets::make()->subject('Printer jammed')->requester($user)->asSystem()->create();

        // A rolled-back insert still spends a key on a real engine — on a table left empty.
        rescue(static fn () => DB::transaction(static function (): void {
            TicketCategory::factory()->create();

            throw new RuntimeException('roll back');
        }), report: false);

        expect(TicketCategory::query()->count())->toBe(0)
            ->and($schema->reset())->toBeTrue()
            ->and(User::query()->count())->toBe(0)
            ->and(Ticket::query()->count())->toBe(0)
            ->and(User::query()->create(['name' => 'Bo'])->getKey())->toBe(1)
            ->and(TicketCategory::factory()->create()->getKey())->toBe(1)
            ->and(Tickets::make()->subject('Again')->requester(User::query()->firstOrFail())->asSystem()->create()->reference)->toBe('TCK-1');
    });

    it('refuses to reset a schema the test changed', function (Closure $change): void {
        $schema = MigratedSchema::capture(DB::connection(), 'pin');

        $change();

        expect($schema->reset())->toBeFalse();
    })->with([
        'a new column' => [fn () => Schema::table('users', static fn (Blueprint $table) => $table->string('nickname')->nullable())],
        'a new index' => [fn () => Schema::table('users', static fn (Blueprint $table) => $table->index('email'))],
        'a dropped table' => [fn () => Schema::drop('ticket_watchers')],
        'a deleted migrations row' => [fn () => DB::table('migrations')->where('id', 1)->delete()],
    ]);

    it('refuses to reset an in-memory database whose foreign keys a test left off', function (): void {
        $schema = MigratedSchema::capture(DB::connection(), 'pin');

        Schema::disableForeignKeyConstraints();

        expect($schema->reset())->toBeFalse();
    })->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'a real engine scopes the switch to the session, which closes');
});
