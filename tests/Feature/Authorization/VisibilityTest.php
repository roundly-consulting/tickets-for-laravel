<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Tickets\Authorization\UnrestrictedAgentScope;
use RoundlyConsulting\Tickets\Contracts\AgentScope;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Support\TicketVisibility;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Fixtures\ProjectAgentScope;
use RoundlyConsulting\Tickets\TicketsManager;

beforeEach(fn () => defineAgents());

/**
 * A helpdesk in every state a ticket can be in: two projects and none, a guest requester, a
 * watcher, new, working, resolved, closed and merged tickets.
 *
 * @return array<string, Model>
 */
function visibilityWorld(): array
{
    $people = [
        'agent' => User::query()->create(['name' => 'Grace', 'is_agent' => true]),
        'scoped agent' => User::query()->create(['name' => 'Scoped', 'is_agent' => true]),
        'requester' => User::query()->create(['name' => 'Ada']),
        'watcher' => User::query()->create(['name' => 'Cc']),
        'stranger' => User::query()->create(['name' => 'Eve']),
        'guest' => Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']),
    ];
    $sup = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
    $ops = Project::query()->create(['name' => 'Ops', 'key' => 'OPS']);

    $make = static fn (Model $requester, ?Project $project = null): Ticket => Tickets::make()
        ->subject('Ticket')->requester($requester)->project($project)->asSystem()->create();

    $mine = $make($people['requester']);
    $inSup = $make($people['requester'], $sup);
    $inOps = $make($people['stranger'], $ops);
    $guests = $make($people['guest'], $sup);
    $watched = $make($people['stranger']);
    TicketWatcher::query()->create(['ticket_id' => $watched->getKey(), 'watcher_type' => $people['watcher']->getMorphClass(), 'watcher_id' => $people['watcher']->getKey()]);
    TicketWatcher::query()->create(['ticket_id' => $inOps->getKey(), 'watcher_type' => $people['guest']->getMorphClass(), 'watcher_id' => $people['guest']->getKey()]);

    Tickets::for($make($people['requester']))->asSystem()->dismiss('Spam');
    Tickets::for($make($people['requester'], $ops))->by($people['agent'])->resolve();
    Tickets::for($make($people['watcher']))->by($people['agent'])->start();
    Tickets::for($make($people['requester']))->asSystem()->mergeInto($mine);

    return $people;
}

describe('visibility_scope_matches_policy', function (): void {
    it('lists exactly the tickets the policy lets each person view', function (string $who, bool $watchersCanView, bool $scoped): void {
        config()->set('tickets.watchers.can_view', $watchersCanView ? 'on' : 'off');

        if ($scoped) {
            app()->bind(AgentScope::class, ProjectAgentScope::class);
        }

        $people = visibilityWorld();
        $person = $people[$who];

        $byScope = Tickets::visibleTo($person)->orderBy('id')->pluck('reference')->all();
        $byPolicy = Ticket::query()->orderBy('id')->get()
            ->filter(static fn (Ticket $ticket): bool => Gate::forUser($person)->allows('view', $ticket))
            ->pluck('reference')->values()->all();
        $byVisibility = Ticket::query()->orderBy('id')->get()
            ->filter(static fn (Ticket $ticket): bool => TicketVisibility::canView($person, $ticket))
            ->pluck('reference')->values()->all();

        expect($byScope)->toBe($byPolicy)
            ->and($byVisibility)->toBe($byPolicy)
            ->and(Ticket::query()->count())->toBe(9);
    })->with([
        'agent', 'scoped agent', 'requester', 'watcher', 'stranger', 'guest',
    ])->with([
        'watchers may view' => true,
        'watchers may not view' => false,
    ])->with([
        'unrestricted agents' => false,
        'agents scoped to a project' => true,
    ]);

    it('pins what each person sees', function (): void {
        app()->bind(AgentScope::class, ProjectAgentScope::class);
        $people = visibilityWorld();

        $seen = static fn (string $who): int => Tickets::visibleTo($people[$who])->count();

        expect($seen('agent'))->toBe(9)
            ->and($seen('scoped agent'))->toBe(2)
            ->and($seen('requester'))->toBe(5)
            ->and($seen('watcher'))->toBe(2)
            ->and($seen('stranger'))->toBe(2)
            ->and($seen('guest'))->toBe(2);
    });

    it('scopes the same through the facade, an injected manager and the model scope', function (): void {
        $people = visibilityWorld();

        expect(app(TicketsManager::class)->visibleTo($people['requester'])->pluck('id')->all())
            ->toBe(Ticket::query()->visibleTo($people['requester'])->pluck('id')->all());
    });
});

describe('agent scope', function (): void {
    it('binds the unrestricted scope by default and from a blank setting', function (mixed $setting): void {
        config()->set('tickets.authorization.agent_scope', $setting);

        expect(app(AgentScope::class))->toBeInstanceOf(UnrestrictedAgentScope::class);
    })->with([null, '']);

    it('binds a host scope from config', function (): void {
        config()->set('tickets.authorization.agent_scope', ProjectAgentScope::class);

        expect(app(AgentScope::class))->toBeInstanceOf(ProjectAgentScope::class);
    });

    it('keeps a scoped-out agent away from the ticket, but not from one they requested', function (): void {
        app()->bind(AgentScope::class, ProjectAgentScope::class);
        $scoped = User::query()->create(['name' => 'Scoped', 'is_agent' => true]);
        $other = Tickets::make()->subject('x')->requester(User::query()->create())->asSystem()->create();
        $own = Tickets::make()->subject('y')->requester($scoped)->asSystem()->create();

        expect(TicketVisibility::isAgentFor($scoped, $other))->toBeFalse()
            ->and(TicketVisibility::canView($scoped, $other))->toBeFalse()
            ->and(TicketVisibility::canView($scoped, $own))->toBeTrue()
            ->and(Gate::forUser($scoped)->allows('note', $own))->toBeFalse();
    });

    it('never treats a guest as an agent', function (): void {
        $guest = TicketGuest::factory()->create();
        $ticket = Tickets::make()->subject('x')->requester($guest)->asSystem()->create();

        expect(TicketVisibility::isAgentFor($guest, $ticket))->toBeFalse()
            ->and(TicketVisibility::isAgentFor(null, $ticket))->toBeFalse()
            ->and(TicketVisibility::canView($guest, $ticket))->toBeTrue();
    });
});
