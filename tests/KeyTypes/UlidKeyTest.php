<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;

it('keys tickets and guests with ulids', function (): void {
    $guest = TicketGuest::factory()->create();
    $ticket = Ticket::factory()->requestedBy($guest)->create();

    expect(Str::isUlid((string) $ticket->getKey()))->toBeTrue()
        ->and(Str::isUlid((string) $guest->getKey()))->toBeTrue()
        ->and($ticket->fresh()?->requester->is($guest))->toBeTrue()
        ->and(fn () => (new Ticket)->resolveRouteBinding('not-a-ulid', 'id'))->toThrow(ModelNotFoundException::class);
});
