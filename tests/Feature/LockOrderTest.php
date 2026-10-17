<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Tickets\DataTransferObjects\CategoryData;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateTicketData;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Support\RecordsLocks;

/**
 * The lock order every action keeps: advisory row → ticket rows → package rows, all inside
 * the action's transaction. The same first lock lifecycle takes (the subject row), so a
 * transition inside a tickets action never inverts the order.
 */
it('locks the ticket row first in every ticket mutation', function (Closure $mutate): void {
    $ticket = Tickets::make()->subject('a')->requester(User::query()->create())->asSystem()->create();
    $other = Ticket::factory()->create();
    RecordsLocks::start();

    $mutate(Tickets::for($ticket)->asSystem(), $other);

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
    'reply' => [fn ($handle) => $handle->reply('Hello.')],
    'note' => [fn ($handle) => $handle->note('Psst.')],
    'assign' => [fn ($handle) => $handle->assign(User::query()->create(['is_agent' => true]))],
    'unassign' => [fn ($handle) => $handle->unassign()],
    'claim' => [fn ($handle) => Tickets::for($handle->ticket())->by(User::query()->create(['is_agent' => true]))->claim()],
    'watch' => [fn ($handle) => $handle->watchers()->add(User::query()->create())],
    'unwatch' => [fn ($handle) => $handle->watchers()->remove(User::query()->create())],
    'tag by model' => [fn ($handle) => $handle->tags()->attach(TicketTag::factory()->create())],
    'untag' => [fn ($handle) => $handle->tags()->detach(TicketTag::factory()->create())],
    'fields' => [fn ($handle) => $handle->fields()->setMany([])],
    'attachment' => [function ($handle) {
        Storage::fake('local');

        return $handle->attachments()->add(UploadedFile::fake()->createWithContent('a.txt', 'Some plain text.'));
    }],
    'relates_to link' => [fn ($handle, Ticket $other) => $handle->links()->add($other, LinkType::RelatesTo)],
    'merge' => [fn ($handle, Ticket $other) => $handle->mergeInto($other)],
])->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');

it('takes the advisory row before any ticket row when a category delete moves tickets', function (): void {
    $from = Tickets::categories()->create(new CategoryData(name: 'From'));
    $to = Tickets::categories()->create(new CategoryData(name: 'To'));
    Ticket::factory()->create(['category_id' => $from->id]);
    RecordsLocks::start();

    Tickets::categories()->delete($from, $to);

    expect(RecordsLocks::tables())->toBe(['ticket_sequences', 'tickets']);
})->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');
