<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Tickets\Actions\Due\SweepDueDatesAction;
use RoundlyConsulting\Tickets\DataTransferObjects\SweepResult;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketDueSoon;
use RoundlyConsulting\Tickets\Events\TicketOverdue;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\DueFlags;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\TicketsManager;

beforeEach(function (): void {
    defineAgents();
    CarbonImmutable::setTestNow('2030-01-01 09:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function dueTicket(string $due = '2030-01-01 12:00:00'): Ticket
{
    return Tickets::make()->subject('Renew the certificate')->requester(User::query()->create(['name' => 'Ada']))
        ->due(CarbonImmutable::parse($due))->asSystem()->create();
}

function sweepAt(string $now): SweepResult
{
    CarbonImmutable::setTestNow($now);

    return Tickets::sweep();
}

describe('overdue_fires_once', function (): void {
    it('flags a ticket past its due date once, however often the sweep runs', function (): void {
        Event::fake([TicketOverdue::class]);
        $ticket = dueTicket();

        $first = sweepAt('2030-01-01 12:00:01');
        $again = sweepAt('2030-01-01 12:05:00');

        $entry = $ticket->activities()->where('type', ActivityType::Overdue->value)->sole();

        expect($first->overdue)->toBe(1)
            ->and($again->overdue)->toBe(0)
            ->and($ticket->fresh()?->overdue_notified_at?->toDateTimeString())->toBe('2030-01-01 12:00:01')
            ->and($entry->is_system)->toBeTrue()
            ->and($entry->actor_type)->toBeNull()
            ->and(keySorted($entry->properties))->toBe(['due_at' => '2030-01-01T12:00:00+00:00']);

        Event::assertDispatchedTimes(TicketOverdue::class, 1);
        Event::assertDispatched(TicketOverdue::class, static fn (TicketOverdue $event): bool => $event->ticket->is($ticket)
            && $event->dueAt->toDateTimeString() === '2030-01-01 12:00:00');
    });

    it('lets only one of two sweeps that read the same ticket flag it', function (): void {
        Event::fake([TicketOverdue::class]);
        $ticket = dueTicket();
        CarbonImmutable::setTestNow('2030-01-01 12:30:00');

        // Both sweeps read the ticket before either writes: the second one's read is stale.
        $seenBySecond = Ticket::query()->findOrFail($ticket->getKey());

        expect(Tickets::sweep()->overdue)->toBe(1)
            ->and(DueFlags::claim($seenBySecond, DueFlags::OVERDUE, CarbonImmutable::now()))->toBeFalse();

        Event::assertDispatchedTimes(TicketOverdue::class, 1);
    });

    it('counts nothing for a ticket another sweep flagged between this one\'s read and write', function (): void {
        Event::fake([TicketOverdue::class, TicketDueSoon::class]);
        $overdue = dueTicket('2030-01-01 08:00:00');
        $soon = dueTicket('2030-01-01 10:00:00');

        // The competing sweep lands right after this one read each ticket.
        Ticket::retrieved(static function (Ticket $read): void {
            Ticket::query()->whereKey($read->getKey())->toBase()->update([
                DueFlags::OVERDUE => '2030-01-01 09:00:00',
                DueFlags::DUE_SOON => '2030-01-01 09:00:00',
            ]);
        });

        expect(Tickets::sweep())->toEqual(new SweepResult(0, 0))
            ->and($overdue->activities()->where('type', ActivityType::Overdue->value)->exists())->toBeFalse()
            ->and($soon->fresh()?->due_soon_notified_at?->toDateTimeString())->toBe('2030-01-01 09:00:00');

        Event::assertNotDispatched(TicketOverdue::class);
        Event::assertNotDispatched(TicketDueSoon::class);
    });

    it('re-arms when the due date moves, and fires again for the new one', function (): void {
        Event::fake([TicketOverdue::class]);
        $ticket = dueTicket();
        sweepAt('2030-01-01 12:30:00');

        Tickets::for($ticket)->asSystem()->due(CarbonImmutable::parse('2030-01-02 12:00:00'));

        expect($ticket->fresh()?->overdue_notified_at)->toBeNull()
            ->and(sweepAt('2030-01-01 18:00:00')->overdue)->toBe(0)
            ->and(sweepAt('2030-01-02 12:00:01')->overdue)->toBe(1)
            ->and($ticket->activities()->where('type', ActivityType::Overdue->value)->count())->toBe(2);

        Event::assertDispatchedTimes(TicketOverdue::class, 2);
    });

    it('never flags the moved date from a sweep that read the old one', function (): void {
        Event::fake([TicketOverdue::class]);
        $ticket = dueTicket();
        CarbonImmutable::setTestNow('2030-01-01 12:30:00');

        // The sweep read the ticket while it was due at noon…
        $stale = Ticket::query()->findOrFail($ticket->getKey());

        // …then an agent moved the date (which clears the flags) before its write landed.
        Tickets::for($ticket)->asSystem()->due(CarbonImmutable::parse('2030-01-05 12:00:00'));

        expect(DueFlags::claim($stale, DueFlags::OVERDUE, CarbonImmutable::now()))->toBeFalse()
            ->and($ticket->fresh()?->overdue_notified_at)->toBeNull();

        Event::assertNotDispatched(TicketOverdue::class);
    });

    it('never flags a ticket resolved or deleted after the sweep read it', function (): void {
        $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $resolved = dueTicket();
        $deleted = dueTicket();
        CarbonImmutable::setTestNow('2030-01-01 12:30:00');
        $staleResolved = Ticket::query()->findOrFail($resolved->getKey());
        $staleDeleted = Ticket::query()->findOrFail($deleted->getKey());

        Tickets::for($resolved)->by($agent)->resolve();
        Tickets::for($deleted)->asSystem()->delete();

        expect(DueFlags::claim($staleResolved, DueFlags::OVERDUE, CarbonImmutable::now()))->toBeFalse()
            ->and(DueFlags::claim($staleDeleted, DueFlags::OVERDUE, CarbonImmutable::now()))->toBeFalse()
            ->and(DueFlags::claim(Ticket::query()->findOrFail(dueTicket()->getKey())->forceFill(['due_at' => null])->syncOriginal(), DueFlags::OVERDUE, CarbonImmutable::now()))->toBeFalse();
    });

    it('sweeps unresolved tickets only — waiting counts, resolved and closed do not', function (): void {
        Event::fake([TicketOverdue::class]);
        $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $waiting = dueTicket();
        $resolved = dueTicket();
        Tickets::for($waiting)->by($agent)->wait();
        Tickets::for($resolved)->by($agent)->resolve();
        dueTicket()->delete();
        Tickets::make()->subject('No due date')->requester(User::query()->create())->asSystem()->create();

        expect(sweepAt('2030-01-01 13:00:00')->overdue)->toBe(1);

        Event::assertDispatched(TicketOverdue::class, static fn (TicketOverdue $event): bool => $event->ticket->is($waiting));
    });

    it('walks every ticket in id-ordered chunks', function (): void {
        Event::fake([TicketOverdue::class]);

        foreach (range(1, 205) as $ignored) {
            Ticket::factory()->inStatus('open')->create(['due_at' => '2030-01-01 08:00:00']);
        }

        expect(Tickets::sweep()->overdue)->toBe(205);

        Event::assertDispatchedTimes(TicketOverdue::class, 205);
    });
});

describe('due soon', function (): void {
    it('flags a ticket entering the window once, then overdue once', function (): void {
        Event::fake([TicketDueSoon::class, TicketOverdue::class]);
        $ticket = dueTicket();

        $early = sweepAt('2030-01-01 07:59:59');
        $soon = sweepAt('2030-01-01 08:00:00');
        $again = sweepAt('2030-01-01 11:00:00');
        $late = sweepAt('2030-01-01 12:00:01');

        expect([$early->dueSoon, $soon->dueSoon, $again->dueSoon, $late->dueSoon])->toBe([0, 1, 0, 0])
            ->and($late->overdue)->toBe(1)
            ->and($late->total())->toBe(1)
            ->and($ticket->fresh()?->due_soon_notified_at?->toDateTimeString())->toBe('2030-01-01 08:00:00');

        Event::assertDispatchedTimes(TicketDueSoon::class, 1);
        Event::assertDispatched(TicketDueSoon::class, static fn (TicketDueSoon $event): bool => $event->ticket->is($ticket)
            && $event->dueAt->toDateTimeString() === '2030-01-01 12:00:00');
    });

    it('flags a ticket the sweep reaches late as overdue only', function (): void {
        Event::fake([TicketDueSoon::class, TicketOverdue::class]);
        dueTicket();

        expect(sweepAt('2030-01-01 13:00:00'))->toEqual(new SweepResult(1, 0));

        Event::assertNotDispatched(TicketDueSoon::class);
    });

    it('follows the configured window, and flags nothing as due soon with it off', function (): void {
        Event::fake([TicketDueSoon::class]);
        dueTicket('2030-01-02 09:00:00');

        expect(sweepAt('2030-01-01 10:00:00')->dueSoon)->toBe(0);

        config()->set('tickets.due.soon_window', 'off');
        expect(sweepAt('2030-01-02 08:00:00')->dueSoon)->toBe(0);

        config()->set('tickets.due.soon_window', 'P1D');
        expect(sweepAt('2030-01-02 08:00:00')->dueSoon)->toBe(1);
    });

    it('refuses a junk window', function (): void {
        config()->set('tickets.due.soon_window', 'four hours');

        Tickets::sweep();
    })->throws(InvalidTicketsConfigurationException::class, 'tickets.due.soon_window');

    it('reads a blank window as the default', function (): void {
        Event::fake([TicketDueSoon::class]);
        config()->set('tickets.due.soon_window', '');
        dueTicket();

        expect(sweepAt('2030-01-01 08:30:00')->dueSoon)->toBe(1);
    });
});

describe('the sweep surface', function (): void {
    it('runs the same sweep through the facade, an injected manager and the action', function (): void {
        dueTicket('2030-01-01 08:00:00');
        dueTicket('2030-01-01 08:30:00');
        dueTicket('2030-01-01 08:45:00');
        CarbonImmutable::setTestNow('2030-01-01 08:15:00');

        expect(Tickets::sweep())->toEqual(new SweepResult(1, 2))
            ->and(app(TicketsManager::class)->sweep())->toEqual(new SweepResult(0, 0));

        CarbonImmutable::setTestNow('2030-01-01 09:00:00');

        expect(app(SweepDueDatesAction::class)->execute())->toEqual(new SweepResult(2, 0));
    });

    it('records the sweep in the fake, with its result', function (): void {
        $fake = Tickets::fake();
        dueTicket('2030-01-01 08:00:00');

        expect(fn () => $fake->assertSwept())->toThrow(ExpectationFailedException::class, 'none was recorded');
        $fake->assertNothingSwept();

        Artisan::call('tickets:sweep');

        $fake->assertSwept();
        $fake->assertSwept(static fn (SweepResult $result): bool => $result->overdue === 1);

        expect(fn () => $fake->assertSwept(static fn (SweepResult $result): bool => $result->dueSoon === 1))->toThrow(ExpectationFailedException::class, 'none matched')
            ->and(fn () => $fake->assertNothingSwept())->toThrow(ExpectationFailedException::class, 'were recorded');
    });

    it('prints what it flagged', function (string $locale, array $expected): void {
        app()->setLocale($locale);
        dueTicket('2030-01-01 08:00:00');
        dueTicket('2030-01-01 08:30:00');
        dueTicket('2030-01-01 10:00:00');

        expect(Artisan::call('tickets:sweep'))->toBe(0);

        $output = Artisan::output();

        foreach ($expected as $line) {
            expect($output)->toContain($line);
        }
    })->with([
        'english' => ['en', ['2 tickets became overdue.', '1 ticket is due soon.']],
        'slovak' => ['sk', ['2 tikety sú po termíne.', '1 tiketu sa blíži termín.']],
    ]);
});
