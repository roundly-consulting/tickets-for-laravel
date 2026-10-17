<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Support\Racer;

/**
 * Two sessions at once on a real engine (pgsql, mysql). The parent holds its transaction
 * open while a forked racer runs the competing write, then commits; the racer must wait on
 * the lock and decide on the committed state.
 */
describe('reference_allocation_is_race_free', function (): void {
    it('hands two concurrent creates in one project distinct, gap-free numbers', function (): void {
        $user = User::query()->create(['name' => 'Ada']);
        $project = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
        $racer = new Racer;

        DB::transaction(function () use ($user, $project, $racer): void {
            Tickets::make()->subject('parent')->requester($user)->project($project)->asSystem()->create();

            $racer->run(static fn (): string => Tickets::make()->subject('racer')
                ->requester(User::on('racer')->findOrFail($user->id))
                ->project(Project::on('racer')->findOrFail($project->id))
                ->asSystem()
                ->create()->reference)->waitUntilBlockedOrDone();
        });

        expect($racer->outcome())->toBe('SUP-2')
            ->and(Ticket::query()->orderBy('number')->pluck('reference')->all())->toBe(['SUP-1', 'SUP-2']);
    })->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

    it('creates the sequence row once when the first two tickets race', function (): void {
        $user = User::query()->create(['name' => 'Ada']);
        $racer = new Racer;

        DB::transaction(function () use ($user, $racer): void {
            Tickets::make()->subject('parent')->requester($user)->asSystem()->create();

            $racer->run(static fn (): string => Tickets::make()->subject('racer')
                ->requester(User::on('racer')->findOrFail($user->id))
                ->asSystem()
                ->create()->reference)->waitUntilBlockedOrDone();
        });

        expect($racer->outcome())->toBe('TCK-2')
            ->and(Ticket::query()->pluck('number')->sort()->values()->all())->toBe([1, 2]);
    })->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
});

describe('guest_resolution', function (): void {
    it('resolves two concurrent first tickets from one new guest to one guest', function (): void {
        $racer = new Racer;
        $fields = ['name' => 'Jo', 'email' => 'jo@acme.test'];

        $parentGuest = DB::transaction(function () use ($fields, $racer): TicketGuest {
            $guest = Tickets::guests()->resolve($fields);

            $racer->run(static fn (): string => (string) Tickets::guests()->resolve($fields)->getKey())->waitUntilBlockedOrDone();

            return $guest;
        });

        expect($racer->outcome())->toBe((string) $parentGuest->getKey())
            ->and(TicketGuest::query()->count())->toBe(1);
    })->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
});

describe('advisory_locks', function (): void {
    it('lets only one of two crossing category moves through', function (): void {
        $a = TicketCategory::factory()->create();
        $b = TicketCategory::factory()->create();
        $racer = new Racer;

        DB::transaction(function () use ($a, $b, $racer): void {
            Tickets::categories()->move($a, $b);

            $racer->run(static function () use ($a, $b): string {
                try {
                    Tickets::categories()->move(TicketCategory::on('racer')->findOrFail($b->id), $a->id);

                    return 'moved';
                } catch (InvalidCategoryException) {
                    return 'cycle';
                }
            })->waitUntilBlockedOrDone();
        });

        expect($racer->outcome())->toBe('cycle')
            ->and($a->fresh()?->parent_id)->toBe($b->id)
            ->and($b->fresh()?->parent_id)->toBeNull();
    })->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

    it('keeps a NULL-scoped category slug unique under concurrent creates', function (): void {
        $racer = new Racer;

        DB::transaction(function () use ($racer): void {
            Tickets::categories()->make('Billing')->create();

            $racer->run(static fn (): string => Tickets::categories()->make('Billing')->create()->slug)->waitUntilBlockedOrDone();
        });

        expect($racer->outcome())->not->toBe('billing')
            ->and(TicketCategory::query()->pluck('slug')->unique()->count())->toBe(2);
    })->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
});
