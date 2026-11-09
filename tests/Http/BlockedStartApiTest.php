<?php

declare(strict_types=1);

use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Facades\Tickets;

beforeEach(fn () => apiAbilities());

it('leaves a refused start out of the list and answers it with 409 ticket_blocked', function (): void {
    config()->set('tickets.links.blockers_prevent_start', 'on');
    $agent = apiAgent();
    $ticket = apiTicket();
    Tickets::for(apiTicket(subject: 'Blocker'))->asSystem()->links()->add($ticket, LinkType::Blocks);

    $names = collect($this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}/transitions")->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toBe(['open', 'wait', 'hold', 'resolve', 'dismiss']);

    $this->actingAs($agent)->postJson("/api/tickets/{$ticket->reference}/transitions/start")
        ->assertStatus(409)
        ->assertExactJson(['code' => 'ticket_blocked', 'message' => 'Ticket [TCK-1] cannot be started while unresolved tickets block it: TCK-2.']);

    expect($ticket->fresh()?->status)->toBe(TicketStatus::New);
});

it('lists and runs start for a blocked ticket while the guard is off', function (): void {
    $agent = apiAgent();
    $ticket = apiTicket();
    Tickets::for(apiTicket(subject: 'Blocker'))->asSystem()->links()->add($ticket, LinkType::Blocks);

    $names = collect($this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}/transitions")->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toContain('start');

    $this->actingAs($agent)->postJson("/api/tickets/{$ticket->reference}/transitions/start")
        ->assertOk()->assertJsonPath('data.status.value', 'in_progress');
});
