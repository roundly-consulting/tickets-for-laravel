<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Tickets\DataTransferObjects\CategoryData;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateTicketData;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Support\RecordsLocks;

/**
 * The lock order every action keeps: advisory row → ticket rows → package rows, all inside
 * the action's transaction. The same first lock lifecycle takes (the subject row), so a
 * transition inside a tickets action never inverts the order.
 */
it('locks the ticket row first in every ticket mutation', function (Closure $mutate): void {
    $ticket = Tickets::make()->subject('a')->requester(User::query()->create())->asSystem()->create();
    RecordsLocks::start();

    $mutate(Tickets::for($ticket)->asSystem());

    $locks = LockRecorder::recorded();

    expect(RecordsLocks::tables()[0] ?? null)->toBe('tickets')
        ->and($locks[0]['transactionDepth'])->toBeGreaterThanOrEqual(1);
})->with([
    'prioritize' => [fn ($handle) => $handle->prioritize('high')],
    'categorize' => [fn ($handle) => $handle->categorize(null)],
    'due' => [fn ($handle) => $handle->due(now())],
    'requester' => [fn ($handle) => $handle->changeRequester(User::query()->create())],
    'update' => [fn ($handle) => $handle->update(new UpdateTicketData(subject: 'b'))],
    'delete' => [fn ($handle) => $handle->delete()],
    'restore' => [fn ($handle) => $handle->restore()],
])->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');

it('takes the advisory row before any ticket row when a category delete moves tickets', function (): void {
    $from = Tickets::categories()->create(new CategoryData(name: 'From'));
    $to = Tickets::categories()->create(new CategoryData(name: 'To'));
    Ticket::factory()->create(['category_id' => $from->id]);
    RecordsLocks::start();

    Tickets::categories()->delete($from, $to);

    expect(RecordsLocks::tables())->toBe(['ticket_sequences', 'tickets']);
})->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');
