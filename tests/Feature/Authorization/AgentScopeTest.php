<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Exceptions\FieldNotEditableException;
use RoundlyConsulting\Tickets\Exceptions\InvalidAssigneeException;
use RoundlyConsulting\Tickets\Exceptions\TicketAccessDeniedException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Notifications\TicketRepliedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketRequesterRepliedNotification;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Agent;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Fixtures\ProjectAgentScope;

/*
 * The agent scope is the authority on "an agent for this ticket": `Scoped` (ProjectAgentScope)
 * works on SUP tickets only, so on any other ticket they are no agent — not as an assignee, not
 * as a message author, not as a field writer, not as a notification recipient.
 */

beforeEach(function (): void {
    defineAgents();
    config()->set('tickets.authorization.agent_scope', ProjectAgentScope::class);
    $this->sup = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
});

function scopeTicket(?Project $project = null, ?User $requester = null): Ticket
{
    return Tickets::make()->subject('VPN drops')->requester($requester ?? User::query()->create(['name' => 'Ada']))
        ->project($project)->asSystem()->create();
}

function scopeWatch(Ticket $ticket, User $watcher): void
{
    TicketWatcher::query()->create(['ticket_id' => $ticket->getKey(), 'watcher_type' => $watcher->getMorphClass(), 'watcher_id' => $watcher->getKey()]);
}

describe('agent_scope_applies_everywhere', function (): void {
    it('makes an agent outside the scope an ineligible assignee', function (): void {
        $scoped = Agent::query()->create(['name' => 'Scoped', 'is_agent' => true]);
        $outside = scopeTicket();
        $inside = scopeTicket($this->sup);

        expect($scoped->isEligibleTicketAssignee($outside))->toBeFalse()
            ->and($scoped->isEligibleTicketAssignee($inside))->toBeTrue()
            ->and(fn () => Tickets::for($outside)->asSystem()->assign($scoped))->toThrow(InvalidAssigneeException::class)
            ->and(fn () => Tickets::for($outside)->by($scoped)->claim())->toThrow(InvalidAssigneeException::class)
            ->and($outside->fresh()?->assignee_id)->toBeNull()
            ->and(Tickets::for($inside)->by($scoped)->claim()->ticket()->assignee?->is($scoped))->toBeTrue();
    });

    it('leaves a ticket unassigned when the category default is outside the scope — judged with the project set', function (): void {
        $scoped = Agent::query()->create(['name' => 'Scoped', 'is_agent' => true]);
        $global = Tickets::categories()->make('Network')->defaults(assignee: $scoped)->create();
        $local = Tickets::categories()->make('VPN')->project($this->sup)->defaults(assignee: $scoped)->create();

        $outside = Tickets::make()->subject('VPN drops')->requester(User::query()->create(['name' => 'Ada']))->category($global)->asSystem()->create();
        $inside = Tickets::make()->subject('VPN drops')->requester(User::query()->create(['name' => 'Bo']))->category($local)->project($this->sup)->asSystem()->create();

        expect($outside->assignee_id)->toBeNull()
            ->and($outside->status)->toBe(TicketStatus::New)
            ->and($inside->assignee?->is($scoped))->toBeTrue();
    });

    it('lets an agent outside the scope reply only as the watcher they are', function (): void {
        $scoped = User::query()->create(['name' => 'Scoped', 'is_agent' => true]);
        $ticket = scopeTicket();
        scopeWatch($ticket, $scoped);
        Tickets::for($ticket)->by(User::query()->create(['name' => 'Grace', 'is_agent' => true]))->wait();

        $reply = Tickets::for($ticket)->by($scoped)->reply('Seeing this on my laptop as well.');

        expect(TicketComments::role($reply))->toBe(AuthorRole::Participant)
            ->and($ticket->first_responded_at)->toBeNull()
            ->and($ticket->status)->toBe(TicketStatus::Open);
    });

    it('refuses a reply from an agent outside the scope who has no other role', function (): void {
        $ticket = scopeTicket();

        Tickets::for($ticket)->by(User::query()->create(['name' => 'Scoped', 'is_agent' => true]))->reply('Let me in.');
    })->throws(TicketAccessDeniedException::class);

    it('refuses an internal note from an agent outside the scope', function (): void {
        $scoped = User::query()->create(['name' => 'Scoped', 'is_agent' => true]);

        expect(fn () => Tickets::for(scopeTicket())->by($scoped)->note('Psst.'))->toThrow(TicketAccessDeniedException::class, 'internal note')
            ->and(TicketComments::role(Tickets::for(scopeTicket($this->sup))->by($scoped)->note('On it.')))->toBe(AuthorRole::Agent);
    });

    it('limits an agent outside the scope to requester-editable fields on their own ticket', function (): void {
        Tickets::fields()->make('root_cause', FieldType::Text)->agentOnly()->define();
        $scoped = User::query()->create(['name' => 'Scoped', 'is_agent' => true]);
        $outside = scopeTicket(requester: $scoped);
        $inside = scopeTicket($this->sup, $scoped);

        expect(fn () => Tickets::for($outside)->by($scoped)->fields()->set('root_cause', 'cable'))->toThrow(FieldNotEditableException::class, 'root_cause')
            ->and(Tickets::for($inside)->by($scoped)->fields()->set('root_cause', 'cable')->all())->toBe(['root_cause' => 'cable']);
    });

    it('tells an agent watcher outside the scope what a participant hears', function (): void {
        Notification::fake();
        $scoped = User::query()->create(['name' => 'Scoped', 'is_agent' => true]);
        $requester = User::query()->create(['name' => 'Ada']);
        $ticket = scopeTicket(requester: $requester);
        scopeWatch($ticket, $scoped);

        Tickets::for($ticket)->by(User::query()->create(['name' => 'Grace', 'is_agent' => true]))->reply('Restart the router, please.');
        Tickets::for($ticket)->by($requester)->reply('Done, still dropping.');

        Notification::assertSentTo($scoped, TicketRepliedNotification::class);
        Notification::assertNotSentTo($scoped, TicketRequesterRepliedNotification::class);
    });
});
