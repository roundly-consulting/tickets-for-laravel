<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Events\TicketAssigned;
use RoundlyConsulting\Tickets\Events\TicketUnassigned;
use RoundlyConsulting\Tickets\Exceptions\InvalidAssigneeException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Notifications\TicketAssignedNotification;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Agent;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Desk;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

beforeEach(fn () => defineAgents());

function workTicket(?User $requester = null): Ticket
{
    return Tickets::make()->subject('Laptop will not boot')->requester($requester ?? User::query()->create(['name' => 'Ada']))->asSystem()->create();
}

function workAgent(string $name = 'Grace'): User
{
    return User::query()->create(['name' => $name, 'is_agent' => true]);
}

describe('assignment', function (): void {
    it('assigns an eligible assignee, opens a new ticket and makes the assignee watch', function (): void {
        Event::fake([TicketAssigned::class]);
        $agent = workAgent();
        $ticket = workTicket();

        $handle = Tickets::for($ticket)->by($agent)->assign($agent);
        $entry = $ticket->activities()->where('type', ActivityType::Assigned->value)->sole();

        expect($handle->ticket()->assignee?->is($agent))->toBeTrue()
            ->and($ticket->assigned_at)->not->toBeNull()
            ->and($ticket->status)->toBe(TicketStatus::Open)
            ->and(keySorted($entry->properties))->toBe(['to' => ['id' => $agent->id, 'type' => $agent->getMorphClass()]])
            ->and(Tickets::for($ticket)->watchers()->has($agent))->toBeTrue();

        Event::assertDispatched(TicketAssigned::class, static fn (TicketAssigned $event): bool => $event->assignee->is($agent) && $event->actor?->is($agent) === true);
    });

    it('leaves a ticket past New in its state', function (): void {
        $agent = workAgent();
        $ticket = workTicket();
        Tickets::for($ticket)->by($agent)->start();

        Tickets::for($ticket)->by($agent)->assign($agent);

        expect($ticket->status)->toBe(TicketStatus::InProgress);
    });

    it('records the previous assignee on a re-assignment', function (): void {
        $grace = workAgent();
        $linus = workAgent('Linus');
        $ticket = workTicket();

        Tickets::for($ticket)->asSystem()->assign($grace)->assign($linus);

        $entry = $ticket->activities()->where('type', ActivityType::Assigned->value)->reorder('id', 'desc')->first();

        expect(keySorted($entry?->properties['from'] ?? null))->toBe(['id' => $grace->id, 'type' => $grace->getMorphClass()])
            ->and($ticket->assignee_id)->toBe($linus->id);
    });
});

describe('assignment_rules', function (): void {
    it('treats re-assigning the same assignee as a no-op', function (): void {
        Event::fake([TicketAssigned::class]);
        $agent = workAgent();
        $ticket = workTicket();
        Tickets::for($ticket)->asSystem()->assign($agent);
        $activities = TicketActivity::query()->count();

        Tickets::for($ticket)->asSystem()->assign($agent);

        expect(TicketActivity::query()->count())->toBe($activities);
        Event::assertDispatchedTimes(TicketAssigned::class, 1);
    });

    it('refuses an ineligible assignee and a model that is no assignee', function (): void {
        $ticket = workTicket();

        expect(fn () => Tickets::for($ticket)->asSystem()->assign(User::query()->create(['name' => 'Not an agent'])))
            ->toThrow(InvalidAssigneeException::class, 'not eligible')
            ->and(fn () => Tickets::for($ticket)->asSystem()->assign(Document::query()->create()))
            ->toThrow(InvalidAssigneeException::class, 'Assignee contract')
            ->and($ticket->fresh()?->assignee_type)->toBeNull();
    });

    it('assigns a team, whose members are the ones notified', function (): void {
        Notification::fake();
        $team = Team::query()->create(['name' => 'Hardware']);
        $members = [workAgent(), workAgent('Linus')];
        $outsider = workAgent('Ken');
        $team->members()->attach(array_map(static fn (User $member): int => $member->id, $members));
        $ticket = workTicket();

        Tickets::for($ticket)->asSystem()->assign($team);

        expect($ticket->assignee?->is($team))->toBeTrue()
            ->and(collect($team->ticketNotifiables())->pluck('name')->all())->toBe(['Grace', 'Linus']);

        foreach ($members as $member) {
            Notification::assertSentTo($member, TicketAssignedNotification::class, static fn (TicketAssignedNotification $notification): bool => $notification->ticket->is($ticket)
                && $notification->assignee->is($team)
                && $notification->toMail($member)->introLines[0] === 'Ticket '.$ticket->reference.' — “Laptop will not boot” — was assigned to Hardware.');
        }

        Notification::assertNotSentTo($outsider, TicketAssignedNotification::class);
        Notification::assertSentToTimes($members[0], TicketAssignedNotification::class, 1);
    });

    it('unassigns, once', function (): void {
        Event::fake([TicketUnassigned::class]);
        $agent = workAgent();
        $ticket = workTicket();
        Tickets::for($ticket)->asSystem()->assign($agent);

        Tickets::for($ticket)->asSystem()->unassign()->unassign();

        expect($ticket->assignee_type)->toBeNull()
            ->and($ticket->assigned_at)->toBeNull()
            ->and(keySorted($ticket->activities()->where('type', ActivityType::Unassigned->value)->sole()->properties))
            ->toBe(['from' => ['id' => $agent->id, 'type' => $agent->getMorphClass()]])
            ->and(Tickets::for($ticket)->watchers()->has($agent))->toBeTrue();

        Event::assertDispatchedTimes(TicketUnassigned::class, 1);
        Event::assertDispatched(TicketUnassigned::class, static fn (TicketUnassigned $event): bool => $event->assignee?->is($agent) === true);
    });

    it('lets an agent claim a ticket, and nobody else', function (): void {
        $agent = workAgent();
        $ticket = workTicket();

        Tickets::for($ticket)->by($agent)->claim();

        expect($ticket->assignee?->is($agent))->toBeTrue()
            ->and(fn () => Tickets::for(workTicket())->asSystem()->claim())->toThrow(InvalidAssigneeException::class, 'actor')
            ->and(fn () => Tickets::for(workTicket())->by(User::query()->create(['name' => 'Requester']))->claim())->toThrow(InvalidAssigneeException::class);
    });

    it('claims as the authenticated user by default', function (): void {
        $agent = workAgent();
        $this->actingAs($agent);

        expect(Tickets::for(workTicket())->claim()->ticket()->assignee?->is($agent))->toBeTrue();
    });

    it('lets the assignee not watch with auto-watch off', function (): void {
        config()->set('tickets.watchers.auto.assignee', 'off');
        $agent = workAgent();
        $ticket = workTicket();

        Tickets::for($ticket)->asSystem()->assign($agent);
        $created = Tickets::make()->subject('b')->requester(User::query()->create())->assignee($agent)->asSystem()->create();

        expect(Tickets::for($ticket)->watchers()->has($agent))->toBeFalse()
            ->and(Tickets::for($created)->watchers()->has($agent))->toBeFalse();
    });

    it('makes the assignee of a new ticket watch it', function (): void {
        $agent = workAgent();

        $ticket = Tickets::make()->subject('b')->requester(User::query()->create())->assignee($agent)->asSystem()->create();

        expect(Tickets::for($ticket)->watchers()->has($agent))->toBeTrue();
    });

    it('assigns through the ticket model, via the manager', function (): void {
        $fake = Tickets::fake();
        $agent = workAgent();
        $ticket = workTicket();

        expect($ticket->assignTo($agent, $agent))->toBe($ticket)
            ->and($ticket->assignee?->is($agent))->toBeTrue();

        $fake->assertAssigned($ticket, $agent);
    });
});

describe('the default assignee contract', function (): void {
    it('names, notifies and admits a model through IsTicketAssignee', function (): void {
        $agent = Agent::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $user = Agent::query()->create(['name' => '', 'is_agent' => false]);
        $desk = Desk::query()->create(['name' => 'Front desk']);
        $ticket = workTicket();

        expect($agent->ticketAssigneeName())->toBe('Grace')
            ->and($user->ticketAssigneeName())->toBe((string) $user->id)
            ->and($agent->ticketNotifiables())->toBe([$agent])
            ->and($agent->isEligibleTicketAssignee($ticket))->toBeTrue()
            ->and($user->isEligibleTicketAssignee($ticket))->toBeFalse()
            ->and($desk->isEligibleTicketAssignee($ticket))->toBeTrue()
            ->and(Tickets::for($ticket)->asSystem()->assign($desk)->ticket()->assignee?->is($desk))->toBeTrue();
    });
});
