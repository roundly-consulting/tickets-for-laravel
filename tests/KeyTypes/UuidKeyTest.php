<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketLink;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Support\MorphAliases;

it('keys tickets and guests with uuids and points every ticket column at them', function (): void {
    $guest = TicketGuest::factory()->create();
    $ticket = Ticket::factory()->requestedBy($guest)->create();
    $other = Ticket::factory()->create(['merged_into_id' => $ticket->id]);
    $tag = TicketTag::factory()->create();

    $ticket->tags()->attach($tag->id, ['created_at' => now()]);
    TicketWatcher::factory()->create(['ticket_id' => $ticket->id, 'watcher_type' => $guest->getMorphClass(), 'watcher_id' => $guest->id]);
    TicketLink::factory()->create(['ticket_id' => $ticket->id, 'related_ticket_id' => $other->id]);
    TicketActivity::factory()->create(['ticket_id' => $ticket->id]);

    expect(Str::isUuid((string) $ticket->getKey()))->toBeTrue()
        ->and(Str::isUuid((string) $guest->getKey()))->toBeTrue()
        ->and($ticket->getIncrementing())->toBeFalse()
        ->and($ticket->getKeyType())->toBe('string')
        ->and($ticket->fresh()?->requester->is($guest))->toBeTrue()
        ->and($other->fresh()?->mergedInto?->is($ticket))->toBeTrue()
        ->and($ticket->tags()->count())->toBe(1)
        ->and($ticket->watchers()->count())->toBe(1)
        ->and($ticket->links()->count())->toBe(1)
        ->and($ticket->activities()->count())->toBe(1)
        ->and($ticket->lifecycleHistory()->count())->toBe(1)
        ->and(fn () => (new Ticket)->resolveRouteBinding('not-a-uuid', 'id'))->toThrow(ModelNotFoundException::class);
});

it('finds a uuid-keyed record by an untrusted key, and nothing by a malformed one', function (): void {
    $guest = TicketGuest::factory()->create();

    expect(MorphAliases::find(TicketGuest::class, (string) $guest->getKey())?->is($guest))->toBeTrue()
        ->and(MorphAliases::find(TicketGuest::class, 'not-a-uuid'))->toBeNull()
        ->and(MorphAliases::find(TicketGuest::class, 42))->toBeNull();
});
