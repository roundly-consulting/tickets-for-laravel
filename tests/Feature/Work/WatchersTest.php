<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Events\TicketWatcherAdded;
use RoundlyConsulting\Tickets\Events\TicketWatcherRemoved;
use RoundlyConsulting\Tickets\Exceptions\InvalidWatcherException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Support\MorphAliases;
use RoundlyConsulting\Tickets\Tests\Fixtures\MentionResolver;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

beforeEach(fn () => defineAgents());

function watchTicket(?User $requester = null): Ticket
{
    return Tickets::make()->subject('Badge reader offline')->requester($requester ?? User::query()->create(['name' => 'Ada']))->asSystem()->create();
}

describe('watchers', function (): void {
    it('adds and removes watchers, idempotently, with an internal activity entry each', function (): void {
        Event::fake([TicketWatcherAdded::class, TicketWatcherRemoved::class]);
        $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $cc = User::query()->create(['name' => 'Cc']);
        $ticket = watchTicket();

        $watchers = Tickets::for($ticket)->by($agent)->watchers();
        $chained = $watchers->add($cc)->add($cc);

        expect($chained)->toBe($watchers)
            ->and($watchers->has($cc))->toBeTrue()
            ->and($watchers->all()->map(static fn (TicketWatcher $watch): ?string => $watch->watcher?->getAttribute('name'))->all())->toBe(['Ada', 'Cc'])
            ->and($watchers->ticket())->toBe($ticket);

        $watchers->remove($cc)->remove($cc);

        $entries = $ticket->activities()->whereIn('type', [ActivityType::WatcherAdded->value, ActivityType::WatcherRemoved->value])->get();

        expect($watchers->has($cc))->toBeFalse()
            ->and($entries)->toHaveCount(2)
            ->and($entries->every(static fn (TicketActivity $entry): bool => $entry->visibility === ActivityVisibility::Internal))->toBeTrue()
            ->and(keySorted($entries->first()?->properties))->toBe(['watcher' => ['id' => $cc->id, 'type' => $cc->getMorphClass()]]);

        Event::assertDispatchedTimes(TicketWatcherAdded::class, 1);
        Event::assertDispatchedTimes(TicketWatcherRemoved::class, 1);
    });

    it('lets the actor watch and unwatch the ticket', function (): void {
        $cc = User::query()->create(['name' => 'Cc']);
        $ticket = watchTicket();

        Tickets::for($ticket)->by($cc)->watchers()->watch();
        expect(Tickets::for($ticket)->watchers()->has($cc))->toBeTrue();

        Tickets::for($ticket)->by($cc)->watchers()->unwatch();
        expect(Tickets::for($ticket)->watchers()->has($cc))->toBeFalse()
            ->and(fn () => Tickets::for($ticket)->asSystem()->watchers()->watch())->toThrow(InvalidWatcherException::class);
    });

    it('lets a guest and a team watch', function (): void {
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
        $team = Team::query()->create(['name' => 'Facilities']);
        $ticket = watchTicket();

        Tickets::for($ticket)->asSystem()->watchers()->add($guest)->add($team);

        expect(Ticket::query()->watchedBy($guest)->pluck('id')->all())->toBe([$ticket->id])
            ->and(Ticket::query()->watchedBy($team)->pluck('id')->all())->toBe([$ticket->id]);
    });

    it('starts the ticket with the watchers it was filed with', function (): void {
        Event::fake([TicketWatcherAdded::class]);
        $cc = User::query()->create(['name' => 'Cc']);

        $ticket = Tickets::make()->subject('a')->requester(User::query()->create(['name' => 'Ada']))->watchers($cc)->asSystem()->create();

        expect(Tickets::for($ticket)->watchers()->has($cc))->toBeTrue();
        Event::assertDispatched(TicketWatcherAdded::class, static fn (TicketWatcherAdded $event): bool => $event->watcher->is($cc));
    });
});

describe('auto-watch', function (): void {
    it('makes the requester watch a new ticket — unless switched off', function (): void {
        $ada = User::query()->create(['name' => 'Ada']);

        expect(Tickets::for(watchTicket($ada))->watchers()->has($ada))->toBeTrue();

        config()->set('tickets.watchers.auto.requester', 'off');

        expect(Tickets::for(watchTicket($ada))->watchers()->has($ada))->toBeFalse();
    });

    it('makes an agent who writes on the ticket watch it — unless switched off', function (): void {
        $grace = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $linus = User::query()->create(['name' => 'Linus', 'is_agent' => true]);
        $ticket = watchTicket();

        Tickets::for($ticket)->by($grace)->reply('On it.');
        Tickets::for($ticket)->by($linus)->note('Me too.');

        expect(Tickets::for($ticket)->watchers()->has($grace))->toBeTrue()
            ->and(Tickets::for($ticket)->watchers()->has($linus))->toBeTrue();

        config()->set('tickets.watchers.auto.agent_repliers', 'off');
        $other = watchTicket();
        Tickets::for($other)->by($grace)->reply('Also on it.');

        expect(Tickets::for($other)->watchers()->has($grace))->toBeFalse();
    });

    it('makes a mentioned reader watch only when switched on', function (): void {
        config()->set('comments.mention_resolver', MentionResolver::class);
        $grace = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $linus = User::query()->create(['name' => 'Linus', 'is_agent' => true]);
        $ticket = watchTicket();

        Tickets::for($ticket)->asSystem()->note('@Grace have a look.');

        expect(Tickets::for($ticket)->watchers()->has($grace))->toBeFalse();

        config()->set('tickets.watchers.auto.mentioned', 'on');
        Tickets::for($ticket)->asSystem()->note('@Linus you too.');

        expect(Tickets::for($ticket)->watchers()->has($linus))->toBeTrue();
    });
});

describe('the watchables allow-list', function (): void {
    it('defaults to every requester and assignable alias', function (): void {
        expect(MorphAliases::map(MorphAliases::WATCHABLES))->toBe([
            'user' => User::class,
            'team' => Team::class,
            'guest' => TicketGuest::class,
        ]);
    });

    it('takes its own list when configured', function (): void {
        config()->set('tickets.watchables', ['member' => User::class]);

        expect(MorphAliases::map(MorphAliases::WATCHABLES))->toBe(['member' => User::class])
            ->and(MorphAliases::aliasFor(MorphAliases::WATCHABLES, User::query()->create()))->toBe('member');
    });
});
