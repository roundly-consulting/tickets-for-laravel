<?php

declare(strict_types=1);

use RoundlyConsulting\Tickets\Builders\TicketBuilder;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Exceptions\TicketNotFoundException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Handles\ProjectTickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\TicketsManager;

function projectHelpdesk(string $key = 'SUP'): Project
{
    return Project::query()->create(['name' => $key, 'key' => $key]);
}

describe('Tickets::project()', function (): void {
    it('files tickets in the project, in its own sequence', function (): void {
        $support = projectHelpdesk();
        $user = User::query()->create(['name' => 'Ada']);

        $first = Tickets::project($support)->make()->subject('a')->requester($user)->asSystem()->create();
        $second = Tickets::project($support)->make()->subject('b')->requester($user)->asSystem()->create();
        $global = Tickets::make()->subject('c')->requester($user)->asSystem()->create();

        expect([$first->reference, $second->reference, $global->reference])->toBe(['SUP-1', 'SUP-2', 'TCK-1'])
            ->and(Tickets::project($support)->project()->is($support))->toBeTrue()
            ->and(Tickets::project($support)->tickets()->pluck('reference')->sort()->values()->all())->toBe(['SUP-1', 'SUP-2']);
    });

    it('finds and handles only its own tickets', function (): void {
        $support = projectHelpdesk();
        $ops = projectHelpdesk('OPS');
        $user = User::query()->create(['name' => 'Ada']);
        $mine = Tickets::project($support)->make()->subject('a')->requester($user)->asSystem()->create();
        $theirs = Tickets::project($ops)->make()->subject('b')->requester($user)->asSystem()->create();
        $global = Tickets::make()->subject('c')->requester($user)->asSystem()->create();

        expect(Tickets::project($support)->find('SUP-1')?->is($mine))->toBeTrue()
            ->and(Tickets::project($support)->find('OPS-1'))->toBeNull()
            ->and(Tickets::project($support)->for($mine)->ticket()->is($mine))->toBeTrue()
            ->and(Tickets::project($support)->for('SUP-1')->ticket()->is($mine))->toBeTrue()
            ->and(fn () => Tickets::project($support)->for($theirs))->toThrow(TicketNotFoundException::class)
            ->and(fn () => Tickets::project($support)->for('OPS-1'))->toThrow(TicketNotFoundException::class)
            ->and(fn () => Tickets::project($support)->for($global))->toThrow(TicketNotFoundException::class);
    });

    it('lists the categories, tags and fields a ticket of the project may use', function (): void {
        $support = projectHelpdesk();
        $ops = projectHelpdesk('OPS');
        Tickets::categories()->make('Global')->create();
        $own = Tickets::categories()->make('Hardware')->project($support)->create();
        Tickets::categories()->make('Other')->project($ops)->create();
        Tickets::tags()->create('printer');
        Tickets::tags()->create('vpn', project: $support);
        Tickets::tags()->create('db', project: $ops);
        Tickets::fields()->make('asset_tag', FieldType::Text)->define();
        Tickets::fields()->make('serial', FieldType::Text)->project($support)->define();
        Tickets::fields()->make('rack', FieldType::Text)->project($ops)->define();
        Tickets::fields()->make('model', FieldType::Text)->project($support)->category($own)->define();

        $tickets = Tickets::project($support);

        expect($tickets->categories()->pluck('name')->sort()->values()->all())->toBe(['Global', 'Hardware'])
            ->and($tickets->tags()->pluck('name')->all())->toBe(['printer', 'vpn'])
            ->and(array_keys($tickets->fields()))->toBe(['asset_tag', 'serial'])
            ->and(array_keys($tickets->fields($own)))->toBe(['asset_tag', 'serial', 'model'])
            ->and(array_keys($tickets->fields((int) $own->getKey())))->toBe(['asset_tag', 'serial', 'model']);
    });

    it('is the same handle through an injected manager', function (): void {
        $support = projectHelpdesk();

        expect(app(TicketsManager::class)->project($support))->toBeInstanceOf(ProjectTickets::class)
            ->and(app(TicketsManager::class)->project($support)->project()->is($support))->toBeTrue();
    });
});

describe('model traits', function (): void {
    it('gives a project its tickets, categories, fields and tags, and a builder in it', function (): void {
        $support = projectHelpdesk();
        $user = User::query()->create(['name' => 'Ada']);

        $ticket = $support->openTicket()->subject('Printer')->requester($user)->asSystem()->create();
        Tickets::categories()->make('Hardware')->project($support)->create();
        Tickets::fields()->make('serial', FieldType::Text)->project($support)->define();
        Tickets::tags()->create('vpn', project: $support);

        expect($support->openTicket())->toBeInstanceOf(TicketBuilder::class)
            ->and($ticket->reference)->toBe('SUP-1')
            ->and($support->tickets()->pluck('reference')->all())->toBe(['SUP-1'])
            ->and($support->ticketCategories()->get()->pluck('name')->all())->toBe(['Hardware'])
            ->and($support->ticketFields()->pluck('key')->all())->toBe(['serial'])
            ->and($support->ticketTags()->pluck('name')->all())->toBe(['vpn'])
            ->and($support->ticketing()->project()->is($support))->toBeTrue();
    });

    it('gives a user the tickets they requested and the ones assigned to them, and a builder for them', function (): void {
        defineAgents();
        $user = User::query()->create(['name' => 'Ada']);
        $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);

        $ticket = $user->openTicket()->subject('Printer')->asSystem()->create();
        Tickets::for($ticket)->asSystem()->assign($agent);

        expect($ticket->requester_id)->toBe($user->id)
            ->and($user->requestedTickets()->pluck('reference')->all())->toBe([$ticket->reference])
            ->and($agent->assignedTickets()->pluck('reference')->all())->toBe([$ticket->reference])
            ->and($user->assignedTickets()->count())->toBe(0);
    });

    it('goes through the manager, so the fake records a ticket filed through a trait', function (): void {
        $fake = Tickets::fake();
        $support = projectHelpdesk();
        $user = User::query()->create(['name' => 'Ada']);

        $support->openTicket()->subject('From the project')->requester($user)->asSystem()->create();
        $user->openTicket()->subject('From the user')->asSystem()->create();

        $fake->assertCreated(static fn (Ticket $ticket): bool => $ticket->reference === 'SUP-1');
        $fake->assertCreated(static fn (Ticket $ticket): bool => $ticket->subject === 'From the user');
        expect($fake->recorded(TicketOperation::Create))->toHaveCount(2);
    });
});
