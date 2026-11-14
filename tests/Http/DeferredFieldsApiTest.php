<?php

declare(strict_types=1);

use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Facades\Tickets;

beforeEach(fn () => apiAbilities());

it('answers leaving a deferred state with a missing required field with 422 fields.<key>', function (): void {
    config()->set('tickets.fields.defer_required_in', ['new']);
    Tickets::fields()->make('asset_tag', FieldType::Text)->label('Asset tag')->required()->define();
    $agent = apiAgent();
    $ticket = apiTicket();

    $this->actingAs($agent)->postJson("/api/tickets/{$ticket->reference}/transitions/open")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fields.asset_tag' => 'The Asset tag field is required.']);

    expect($ticket->fresh()?->status)->toBe(TicketStatus::New);

    Tickets::for($ticket)->by($agent)->fields()->set('asset_tag', 'PRN-1');

    $this->actingAs($agent)->postJson("/api/tickets/{$ticket->reference}/transitions/open")
        ->assertOk()->assertJsonPath('data.status.value', 'open');
});
