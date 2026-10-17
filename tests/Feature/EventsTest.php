<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Tickets\Contracts\TicketEvent;
use RoundlyConsulting\Tickets\Events\GuestConverted;
use RoundlyConsulting\Tickets\Events\TicketAssigned;
use RoundlyConsulting\Tickets\Events\TicketCreated;
use RoundlyConsulting\Tickets\Events\TicketMerged;
use RoundlyConsulting\Tickets\Events\TicketOverdue;
use RoundlyConsulting\Tickets\Events\TicketReplied;
use RoundlyConsulting\Tickets\Events\TicketResolved;
use RoundlyConsulting\Tickets\Events\TicketStatusChanged;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use Symfony\Component\Finder\Finder;

beforeEach(fn () => defineAgents());

it('lets one listener hear every ticket event', function (): void {
    $heard = [];
    Event::listen(TicketEvent::class, static function (TicketEvent $event) use (&$heard): void {
        $heard[] = [$event::class, $event->ticket()->reference, $event->actor()?->getAttribute('name')];
    });
    $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
    $requester = User::query()->create(['name' => 'Ada']);

    $ticket = Tickets::make()->subject('Printer jammed')->requester($requester)->by($requester)->create();
    Tickets::for($ticket)->by($agent)->reply('On my way.');
    Tickets::for($ticket)->by($agent)->assign($agent)->resolve();

    $kinds = array_column($heard, 0);

    expect($kinds)->toContain(TicketCreated::class, TicketReplied::class, TicketAssigned::class, TicketResolved::class, TicketStatusChanged::class)
        ->and(array_unique(array_column($heard, 1)))->toBe(['TCK-1'])
        ->and($heard[array_search(TicketCreated::class, $kinds, true)][2])->toBe('Ada')
        ->and($heard[array_search(TicketResolved::class, $kinds, true)][2])->toBe('Grace');
});

it('names the source of a merge as its ticket, and nobody as the actor of a sweep', function (): void {
    $merged = [];
    Event::listen(TicketEvent::class, static function (TicketEvent $event) use (&$merged): void {
        $merged[$event::class] = [$event->ticket()->reference, $event->actor()?->getAttribute('name')];
    });
    $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
    $requester = User::query()->create(['name' => 'Ada']);
    $source = Tickets::make()->subject('Dup')->requester($requester)->asSystem()->create();
    $target = Tickets::make()->subject('Original')->requester($requester)->due(CarbonImmutable::now()->addHour())->asSystem()->create();

    Tickets::for($source)->by($agent)->mergeInto($target);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
    Tickets::sweep();
    CarbonImmutable::setTestNow();

    expect($merged[TicketMerged::class])->toBe(['TCK-1', 'Grace'])
        ->and($merged[TicketOverdue::class])->toBe(['TCK-2', null]);
});

it('marks every event but GuestConverted a TicketEvent, dispatched after commit', function (): void {
    $events = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../../src/Events')->name('*.php') as $file) {
        $events[] = 'RoundlyConsulting\\Tickets\\Events\\'.$file->getBasename('.php');
    }

    sort($events);
    $ticketEvents = array_values(array_filter($events, static fn (string $class): bool => is_subclass_of($class, TicketEvent::class)));
    $afterCommit = array_values(array_filter($events, static fn (string $class): bool => is_subclass_of($class, ShouldDispatchAfterCommit::class)));

    expect($events)->toHaveCount(34)
        ->and(array_values(array_diff($events, $ticketEvents)))->toBe([GuestConverted::class])
        ->and($afterCommit)->toBe($events);
});
