<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

beforeEach(fn () => defineAgents());

function mcTicket(Model $requester, string $subject = 'Printer jammed'): Ticket
{
    return Tickets::make()->subject($subject)->requester($requester)->asSystem()->create();
}

function mcWatch(Ticket $ticket, Model $watcher): void
{
    TicketWatcher::query()->create(['ticket_id' => $ticket->getKey(), 'watcher_type' => $watcher->getMorphClass(), 'watcher_id' => $watcher->getKey()]);
}

describe('my tickets', function (): void {
    it('lists, eager-loads and counts the tickets a user watches — live ones only', function (): void {
        config()->set('tickets.watchers.auto.requester', 'off');
        $ada = User::query()->create(['name' => 'Ada']);
        $cc = User::query()->create(['name' => 'Cc']);
        $first = mcTicket($ada, 'First');
        $second = mcTicket($ada, 'Second');
        $gone = mcTicket($ada, 'Gone');
        mcTicket($ada, 'Unwatched');

        foreach ([$first, $second, $gone] as $ticket) {
            mcWatch($ticket, $cc);
        }

        Tickets::for($gone)->asSystem()->delete();

        $loaded = User::query()->with('watchedTickets')->withCount('watchedTickets')->findOrFail($cc->id);

        expect($cc->watchedTickets->pluck('subject')->sort()->values()->all())->toBe(['First', 'Second'])
            ->and($loaded->watched_tickets_count)->toBe(2)
            ->and($loaded->relationLoaded('watchedTickets'))->toBeTrue()
            ->and($loaded->watchedTickets->first())->toBeInstanceOf(Ticket::class)
            ->and($ada->watchedTickets)->toHaveCount(0)
            ->and($ada->requestedTickets)->toHaveCount(3);
    });

    it('lists the tickets a guest requested and the ones they are CC\'d on', function (): void {
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
        $filed = Tickets::make()->subject('From Jo')->guest(['name' => 'Jo', 'email' => 'jo@acme.test'])->asSystem()->create();
        $cced = mcTicket(User::query()->create(['name' => 'Ada']), 'Jo in CC');
        mcWatch($cced, $guest);

        $loaded = TicketGuest::query()->withCount(['requestedTickets', 'watchedTickets'])->findOrFail($guest->getKey());

        expect($guest->requestedTickets->modelKeys())->toBe([$filed->getKey()])
            ->and($guest->watchedTickets->pluck('subject')->sort()->values()->all())->toBe(['From Jo', 'Jo in CC'])
            ->and($loaded->getAttribute('requested_tickets_count'))->toBe(1)
            ->and($loaded->getAttribute('watched_tickets_count'))->toBe(2);
    });

    it('queries the tickets a user may see, as Tickets::visibleTo() does', function (string $who): void {
        $ada = User::query()->create(['name' => 'Ada']);
        $cc = User::query()->create(['name' => 'Cc']);
        $grace = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $mine = mcTicket($ada, 'Mine');
        mcTicket(User::query()->create(['name' => 'Bo']), 'Theirs');
        mcWatch($mine, $cc);
        $user = ['requester' => $ada, 'watcher' => $cc, 'agent' => $grace][$who];

        expect($user->visibleTickets()->orderBy('id')->pluck('id')->all())
            ->toBe(Tickets::visibleTo($user)->orderBy('id')->pluck('id')->all())
            ->and($user->visibleTickets()->count())->toBe($who === 'agent' ? 2 : 1);
    })->with(['requester', 'watcher', 'agent']);
});

describe('status read helpers', function (): void {
    it('reads each state the way its scope filters it', function (): void {
        $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $ticket = mcTicket(User::query()->create(['name' => 'Ada']));

        $states = [];
        $read = static function (string $label) use ($ticket, &$states): void {
            $states[$label] = [$ticket->isUnresolved(), $ticket->isResolved(), $ticket->isClosed(), $ticket->isAssigned()];
        };

        $read('new');
        Tickets::for($ticket)->by($agent)->assign($agent);
        $read('assigned');
        Tickets::for($ticket)->by($agent)->wait();
        $read('waiting');
        Tickets::for($ticket)->by($agent)->resolve();
        $read('resolved');
        Tickets::for($ticket)->by($agent)->close();
        $read('closed');

        expect($states)->toBe([
            'new' => [true, false, false, false],
            'assigned' => [true, false, false, true],
            'waiting' => [true, false, false, true],
            'resolved' => [false, true, false, true],
            'closed' => [false, false, true, true],
        ]);
    });

    it('tells a merged ticket', function (): void {
        $ada = User::query()->create(['name' => 'Ada']);
        $source = mcTicket($ada, 'Duplicate');
        $target = mcTicket($ada, 'Original');

        expect($source->isMerged())->toBeFalse();

        Tickets::for($source)->by(User::query()->create(['name' => 'Grace', 'is_agent' => true]))->mergeInto($target);

        expect($source->isMerged())->toBeTrue()
            ->and($target->isMerged())->toBeFalse()
            ->and(Ticket::query()->merged()->pluck('id')->all())->toBe([$source->id]);
    });

    it('calls a ticket overdue exactly when the overdue scope finds it', function (?string $due, ?string $transition, bool $overdue): void {
        CarbonImmutable::setTestNow('2030-06-01 12:00:00');
        $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $ticket = mcTicket(User::query()->create(['name' => 'Ada']));
        Ticket::query()->whereKey($ticket->id)->update(['due_at' => $due]);
        $ticket = $ticket->fresh() ?? throw new RuntimeException('gone');

        if ($transition !== null) {
            Tickets::for($ticket)->by($agent)->transition($transition, 'A reason');
        }

        expect($ticket->isOverdue())->toBe($overdue)
            ->and(Ticket::query()->overdue()->whereKey($ticket->id)->exists())->toBe($overdue);

        CarbonImmutable::setTestNow();
    })->with([
        'no due date' => [null, null, false],
        'due in the future' => ['2030-06-02 12:00:00', null, false],
        'due a second ago, new' => ['2030-06-01 11:59:59', null, true],
        'due exactly now' => ['2030-06-01 12:00:00', null, false],
        'past due, in progress' => ['2030-05-01 09:00:00', 'start', true],
        'past due, waiting' => ['2030-05-01 09:00:00', 'wait', true],
        'past due, on hold' => ['2030-05-01 09:00:00', 'hold', true],
        'past due, resolved' => ['2030-05-01 09:00:00', 'resolve', false],
        'past due, dismissed' => ['2030-05-01 09:00:00', 'dismiss', false],
    ]);
});
