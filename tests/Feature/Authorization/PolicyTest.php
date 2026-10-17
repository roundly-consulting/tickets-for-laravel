<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Comments\Facades\Comments;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Policies\TicketPolicy;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Fixtures\Policies\StrictTicketPolicy;
use RoundlyConsulting\Tickets\TicketsServiceProvider;

function policyPeople(): array
{
    return [
        'agent' => User::query()->create(['name' => 'Grace', 'is_agent' => true]),
        'requester' => User::query()->create(['name' => 'Ada']),
        'watcher' => User::query()->create(['name' => 'Cc']),
        'stranger' => User::query()->create(['name' => 'Eve']),
        'admin' => User::query()->create(['name' => 'Admin', 'is_agent' => true]),
    ];
}

function policyTicket(User $requester, ?User $watcher = null): Ticket
{
    $ticket = Tickets::make()->subject('Printer')->requester($requester)->asSystem()->create();

    if ($watcher !== null) {
        TicketWatcher::query()->create(['ticket_id' => $ticket->getKey(), 'watcher_type' => $watcher->getMorphClass(), 'watcher_id' => $watcher->getKey()]);
    }

    return $ticket;
}

function policyAbilities(): void
{
    defineAgents();
    Gate::define('tickets.assign', static fn (User $user): bool => $user->name === 'Admin');
    Gate::define('tickets.manage', static fn (User $user): bool => $user->name === 'Admin');
}

describe('the policy', function (): void {
    it('is registered for the configured ticket model', function (): void {
        expect(Gate::getPolicyFor(Ticket::class))->toBeInstanceOf(TicketPolicy::class);
    });

    it('lets the requester side see, file and reply, and keeps the agent work to agents', function (): void {
        policyAbilities();
        ['agent' => $agent, 'requester' => $requester, 'watcher' => $watcher, 'stranger' => $stranger, 'admin' => $admin] = policyPeople();
        $ticket = policyTicket($requester, $watcher);
        $can = static fn (User $user, string $ability, array $arguments = []): bool => Gate::forUser($user)->allows($ability, [$ticket, ...$arguments]);

        expect(Gate::forUser($stranger)->allows('viewAny', Ticket::class))->toBeTrue()
            ->and(Gate::forUser($stranger)->allows('create', Ticket::class))->toBeTrue()
            ->and($can($requester, 'view'))->toBeTrue()
            ->and($can($watcher, 'view'))->toBeTrue()
            ->and($can($stranger, 'view'))->toBeFalse()
            ->and(Gate::forUser($stranger)->inspect('view', $ticket)->status())->toBe(404)
            ->and($can($requester, 'reply'))->toBeTrue()
            ->and($can($watcher, 'reply'))->toBeTrue()
            ->and($can($stranger, 'reply'))->toBeFalse()
            ->and($can($requester, 'note'))->toBeFalse()
            ->and($can($agent, 'note'))->toBeTrue()
            ->and($can($requester, 'viewInternal'))->toBeFalse()
            ->and($can($agent, 'viewInternal'))->toBeTrue()
            ->and($can($requester, 'tag'))->toBeFalse()
            ->and($can($agent, 'tag'))->toBeTrue()
            ->and($can($requester, 'link'))->toBeFalse()
            ->and($can($agent, 'link'))->toBeTrue()
            ->and($can($requester, 'merge'))->toBeFalse()
            ->and($can($agent, 'merge'))->toBeTrue()
            ->and($can($agent, 'delete'))->toBeFalse()
            ->and($can($admin, 'delete'))->toBeTrue()
            ->and($can($admin, 'restore'))->toBeTrue()
            ->and(Gate::forUser($agent)->allows('manageCategories', Ticket::class))->toBeFalse()
            ->and(Gate::forUser($admin)->allows('manageCategories', Ticket::class))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('manageFields', Ticket::class))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('manageTags', Ticket::class))->toBeTrue()
            ->and(Gate::forUser($agent)->allows('viewTags', Ticket::class))->toBeTrue()
            ->and(Gate::forUser($requester)->allows('viewTags', Ticket::class))->toBeFalse();
    });

    it('lets the requester edit the subject, description and fields of an unresolved ticket only', function (): void {
        policyAbilities();
        ['agent' => $agent, 'requester' => $requester, 'watcher' => $watcher] = policyPeople();
        $ticket = policyTicket($requester, $watcher);
        $update = static fn (User $user, array $attributes): bool => Gate::forUser($user)->allows('update', [$ticket, $attributes]);

        expect($update($requester, ['subject', 'description']))->toBeTrue()
            ->and($update($requester, ['fields']))->toBeTrue()
            ->and($update($requester, []))->toBeTrue()
            ->and($update($requester, ['priority']))->toBeFalse()
            ->and($update($requester, ['subject', 'due_at']))->toBeFalse()
            ->and($update($requester, ['attachments']))->toBeFalse()
            ->and($update($watcher, ['subject']))->toBeFalse()
            ->and($update($agent, ['priority', 'requester']))->toBeTrue();

        config()->set('tickets.authorization.requester_can_edit', 'off');

        expect($update($requester, ['subject']))->toBeFalse()
            ->and($update($requester, []))->toBeFalse()
            ->and($update($requester, ['fields']))->toBeTrue();

        config()->set('tickets.authorization.requester_can_edit', 'on');
        Tickets::for($ticket)->by($agent)->resolve();

        expect($update($requester, ['subject']))->toBeFalse();
    });

    it('limits the requester side to the requester transitions', function (): void {
        policyAbilities();
        ['agent' => $agent, 'requester' => $requester, 'watcher' => $watcher, 'stranger' => $stranger] = policyPeople();
        $ticket = policyTicket($requester, $watcher);
        $may = static fn (User $user, ?string $transition): bool => Gate::forUser($user)->allows('transition', [$ticket, $transition]);

        expect($may($agent, 'start'))->toBeTrue()
            ->and($may($requester, 'close'))->toBeTrue()
            ->and($may($requester, 'reopen'))->toBeTrue()
            ->and($may($requester, 'start'))->toBeFalse()
            ->and($may($requester, null))->toBeTrue()
            ->and($may($watcher, 'close'))->toBeFalse()
            ->and($may($stranger, 'close'))->toBeFalse();

        config()->set('tickets.authorization.participants_can_transition', 'on');

        expect($may($watcher, 'close'))->toBeTrue()
            ->and($may($watcher, 'start'))->toBeFalse();

        config()->set('tickets.watchers.can_view', 'off');

        expect($may($watcher, 'close'))->toBeFalse();

        config()->set('tickets.authorization.requester_transitions', []);

        expect($may($requester, 'close'))->toBeFalse()
            ->and($may($requester, null))->toBeFalse();
    });

    it('lets the assign ability assign anyone and any agent claim or hand back', function (): void {
        policyAbilities();
        ['agent' => $agent, 'requester' => $requester, 'admin' => $admin] = policyPeople();
        $ticket = policyTicket($requester);
        $assign = static fn (User $user, ?User $assignee): bool => Gate::forUser($user)->allows('assign', [$ticket, $assignee]);

        expect($assign($agent, $agent))->toBeTrue()
            ->and($assign($agent, $admin))->toBeFalse()
            ->and($assign($agent, null))->toBeFalse()
            ->and($assign($admin, $agent))->toBeTrue()
            ->and($assign($requester, $requester))->toBeFalse()
            ->and(Gate::forUser($agent)->allows('unassign', $ticket))->toBeFalse()
            ->and(Gate::forUser($requester)->allows('unassign', $ticket))->toBeFalse();

        Tickets::for($ticket)->by($agent)->claim();

        expect(Gate::forUser($agent)->allows('unassign', $ticket))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('unassign', $ticket))->toBeTrue();
    });

    it('lets an agent edit any message and anyone else only their own public reply', function (): void {
        policyAbilities();
        ['agent' => $agent, 'requester' => $requester, 'watcher' => $watcher] = policyPeople();
        $ticket = policyTicket($requester, $watcher);
        $own = Tickets::for($ticket)->by($requester)->reply('Mine.');
        $note = Tickets::for($ticket)->by($agent)->note('Internal.');
        $foreign = Comments::on($ticket)->as($requester)->body('Written straight through comments.')->visible(false)->post();
        $edit = static fn (User $user, mixed $comment, string $ability = 'updateComment'): bool => Gate::forUser($user)->allows($ability, [$ticket, $comment]);

        expect($edit($requester, $own))->toBeTrue()
            ->and($edit($requester, $own, 'deleteComment'))->toBeTrue()
            ->and($edit($watcher, $own))->toBeFalse()
            ->and($edit($requester, $note))->toBeFalse()
            ->and($edit($requester, $foreign))->toBeFalse()
            ->and($edit($agent, $own))->toBeTrue()
            ->and($edit($agent, $note, 'deleteComment'))->toBeTrue();
    });

    it('lets an agent manage every watcher and anyone who may view manage only themselves', function (): void {
        policyAbilities();
        ['agent' => $agent, 'requester' => $requester, 'stranger' => $stranger] = policyPeople();
        $ticket = policyTicket($requester);
        $manage = static fn (User $user, ?User $watcher): bool => Gate::forUser($user)->allows('manageWatchers', [$ticket, $watcher]);

        expect($manage($agent, $stranger))->toBeTrue()
            ->and($manage($agent, null))->toBeTrue()
            ->and($manage($requester, $requester))->toBeTrue()
            ->and($manage($requester, $stranger))->toBeFalse()
            ->and($manage($requester, null))->toBeFalse()
            ->and($manage($stranger, $stranger))->toBeFalse();
    });

    it('answers a reply to a closed ticket with a 409', function (): void {
        policyAbilities();
        ['requester' => $requester] = policyPeople();
        $ticket = policyTicket($requester);
        Tickets::for($ticket)->asSystem()->dismiss('Spam');

        $response = Gate::forUser($requester)->inspect('reply', $ticket);

        expect($response->allowed())->toBeFalse()
            ->and($response->status())->toBe(409)
            ->and($response->code())->toBe('ticket_closed');
    });

    it('lets self-service be switched off', function (): void {
        policyAbilities();
        ['agent' => $agent, 'requester' => $requester] = policyPeople();
        config()->set('tickets.authorization.self_service', 'off');

        expect(Gate::forUser($requester)->allows('create', Ticket::class))->toBeFalse()
            ->and(Gate::forUser($agent)->allows('create', Ticket::class))->toBeTrue();
    });
});

describe('undefined_abilities_deny', function (): void {
    it('denies every ability the host never defined', function (): void {
        ['agent' => $agent, 'requester' => $requester, 'admin' => $admin] = policyPeople();
        $ticket = policyTicket($requester);

        foreach (['view', 'note', 'viewInternal', 'tag', 'link', 'merge', 'delete', 'restore'] as $ability) {
            expect(Gate::forUser($agent)->allows($ability, $ticket))->toBeFalse("agent {$ability}");
        }

        expect(Gate::forUser($agent)->allows('assign', [$ticket, $agent]))->toBeFalse()
            ->and(Gate::forUser($admin)->allows('manageCategories', Ticket::class))->toBeFalse()
            ->and(Gate::forUser($requester)->allows('view', $ticket))->toBeTrue();
    });

    it('lets an agent claim but neither assign others nor manage while those abilities are undefined', function (): void {
        defineAgents();
        ['agent' => $agent, 'requester' => $requester, 'admin' => $admin] = policyPeople();
        $ticket = policyTicket($requester);

        expect(Gate::forUser($agent)->allows('assign', [$ticket, $agent]))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('assign', [$ticket, $agent]))->toBeFalse()
            ->and(Gate::forUser($admin)->allows('delete', $ticket))->toBeFalse()
            ->and(Gate::forUser($admin)->allows('manageFields', Ticket::class))->toBeFalse();
    });
});

/**
 * Register the policy again on a fresh Gate, the way boot does.
 */
function policyRegisterAgain(): void
{
    app()->forgetInstance(GateContract::class);
    Gate::clearResolvedInstances();

    $provider = app()->getProvider(TicketsServiceProvider::class);
    (fn () => $this->registerPolicy())->call($provider);
}

describe('policy registration', function (): void {
    it('registers a swapped policy', function (): void {
        config()->set('tickets.authorization.policy', StrictTicketPolicy::class);
        policyRegisterAgain();

        expect(Gate::getPolicyFor(Ticket::class))->toBeInstanceOf(StrictTicketPolicy::class)
            ->and(Gate::forUser(User::query()->create())->allows('create', Ticket::class))->toBeFalse();
    });

    it('leaves the host its own policy with register_policy off', function (): void {
        config()->set('tickets.authorization.register_policy', 'off');
        app()->forgetInstance(GateContract::class);
        Gate::clearResolvedInstances();
        Gate::policy(Ticket::class, StrictTicketPolicy::class);

        (fn () => $this->registerPolicy())->call(app()->getProvider(TicketsServiceProvider::class));

        expect(Gate::policies())->toBe([Ticket::class => StrictTicketPolicy::class]);
    });

    /**
     * Documented: "off" is not deny-all. Laravel's own policy discovery maps Models\Ticket to
     * Policies\TicketPolicy, so a host that wants another policy registers it itself.
     */
    it('still finds the packaged policy through Laravel discovery with register_policy off', function (): void {
        config()->set('tickets.authorization.register_policy', 'off');
        policyRegisterAgain();

        expect(Gate::policies())->toBe([])
            ->and(Gate::getPolicyFor(Ticket::class))->toBeInstanceOf(TicketPolicy::class)
            ->and(Gate::getPolicyFor(Ticket::class))->not->toBeInstanceOf(StrictTicketPolicy::class);
    });

    it('registers nothing for a policy that does not extend the package policy', function (): void {
        config()->set('tickets.authorization.policy', stdClass::class);
        policyRegisterAgain();

        expect(Gate::policies())->not->toHaveKey(Ticket::class)
            ->and(fn () => TicketsConfig::policy())->toThrow(InvalidTicketsConfigurationException::class);
    });
});
