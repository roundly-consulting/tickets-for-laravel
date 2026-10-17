<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

/**
 * The suite keeps its migrated schema between tests (TestCase::keepsMigratedSchema(), from
 * testing-for-laravel). These pin what tickets relies on, through tickets' own API: every test
 * runs outside a transaction and starts where a fresh migrate leaves it — whichever test ran
 * before it in the worker.
 */
describe('kept_schema', function (): void {
    it('runs the test outside any transaction', function (): void {
        expect(DB::transactionLevel())->toBe(0);
    });

    it('starts every test on empty tables with every key counter rewound', function (): void {
        expect(User::query()->count())->toBe(0);

        $user = User::query()->create(['name' => 'Ada']);

        expect($user->getKey())->toBe(1)
            ->and(TicketCategory::factory()->create()->getKey())->toBe(1)
            ->and(Tickets::make()->subject('Printer jammed')->requester($user)->asSystem()->create()->reference)->toBe('TCK-1');

        // A rolled-back insert still spends a key on a real engine — on a table left empty.
        rescue(static fn () => DB::transaction(static function (): void {
            TicketCategory::factory()->create();

            throw new RuntimeException('roll back');
        }), report: false);
    })->with(['first', 'second', 'third']);
});
