<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateTicketData;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Enums\TicketPriority;
use RoundlyConsulting\Tickets\Events\TicketCategoryChanged;
use RoundlyConsulting\Tickets\Events\TicketDeleted;
use RoundlyConsulting\Tickets\Events\TicketDueDateChanged;
use RoundlyConsulting\Tickets\Events\TicketPriorityChanged;
use RoundlyConsulting\Tickets\Events\TicketRequesterChanged;
use RoundlyConsulting\Tickets\Events\TicketRestored;
use RoundlyConsulting\Tickets\Events\TicketUpdated;
use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;
use RoundlyConsulting\Tickets\Exceptions\TicketNotFoundException;
use RoundlyConsulting\Tickets\Exceptions\TicketTrashedException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Handles\TicketHandle;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

function fileTicket(array $attributes = []): Ticket
{
    $requester = User::query()->create(['name' => 'Ada']);

    return Tickets::make()
        ->subject($attributes['subject'] ?? 'Printer jammed')
        ->description($attributes['description'] ?? 'Paper jam error E-102.')
        ->requester($requester)
        ->asSystem()
        ->create();
}

describe('for_accepts_a_reference', function (): void {
    it('resolves a handle by reference', function (): void {
        $ticket = fileTicket();

        expect(Tickets::for('TCK-1')->ticket()->is($ticket))->toBeTrue()
            ->and(Tickets::find('TCK-1')?->is($ticket))->toBeTrue()
            ->and(Tickets::findOrFail('TCK-1')->is($ticket))->toBeTrue()
            ->and(Tickets::find('TCK-404'))->toBeNull();
    });

    it('refuses an unknown or soft-deleted reference', function (): void {
        $ticket = fileTicket();
        Tickets::for($ticket)->asSystem()->delete();

        expect(fn () => Tickets::for('TCK-404'))->toThrow(TicketNotFoundException::class, 'TCK-404')
            ->and(fn () => Tickets::for('TCK-1'))->toThrow(TicketNotFoundException::class)
            ->and(fn () => Tickets::findOrFail('TCK-1'))->toThrow(TicketNotFoundException::class);
    });
});

describe('handle_mutators_chain', function (): void {
    it('returns the same handle from every mutator and lets a chain see each prior effect', function (): void {
        $category = TicketCategory::factory()->create();
        $handle = Tickets::for(fileTicket())->asSystem();
        $due = CarbonImmutable::parse('2031-01-01 09:00:00');

        $chained = $handle
            ->prioritize('urgent')
            ->categorize($category)
            ->due($due)
            ->update(new UpdateTicketData(subject: 'Printer still jammed'));

        $ticket = $chained->ticket();

        expect($chained)->toBe($handle)
            ->and($ticket->priority)->toBe('urgent')
            ->and($ticket->category_id)->toBe($category->id)
            ->and($ticket->due_at?->equalTo($due))->toBeTrue()
            ->and($ticket->subject)->toBe('Printer still jammed')
            ->and($ticket->fresh()?->priority)->toBe('urgent')
            ->and($ticket->activities->pluck('type')->all())->toBe([
                ActivityType::Created, ActivityType::PriorityChanged, ActivityType::CategoryChanged,
                ActivityType::DueChanged, ActivityType::Updated,
            ]);
    });

    it('returns a new handle from by() and asSystem() and keeps the original untouched', function (): void {
        $handle = Tickets::for(fileTicket());
        $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);

        $as = $handle->by($agent);
        $system = $as->asSystem();

        expect($as)->toBeInstanceOf(TicketHandle::class)->not->toBe($handle)
            ->and($system)->not->toBe($as)
            ->and($as->ticket())->toBe($handle->ticket());

        $as->prioritize('high');
        $system->prioritize('low');

        $entries = $handle->ticket()->activities()->where('type', ActivityType::PriorityChanged->value)->get();

        expect($entries[0]->actor?->is($agent))->toBeTrue()
            ->and($entries[1]->actor_type)->toBeNull()
            ->and($entries[1]->is_system)->toBeTrue();
    });
});

it('changes priority once and treats the same priority as a no-op', function (): void {
    Event::fake([TicketPriorityChanged::class]);
    $ticket = fileTicket();

    Tickets::for($ticket)->asSystem()->prioritize(TicketPriority::High)->prioritize('high');

    expect($ticket->activities()->where('type', 'priority_changed')->count())->toBe(1)
        ->and(keySorted($ticket->activities()->where('type', 'priority_changed')->first()?->properties))->toBe(['from' => 'normal', 'to' => 'high']);

    Event::assertDispatchedTimes(TicketPriorityChanged::class, 1);
});

it('refuses an unknown priority', function (): void {
    Tickets::for(fileTicket())->prioritize('critical');
})->throws(ValidationException::class);

it('moves a ticket between categories and out of every category', function (): void {
    Event::fake([TicketCategoryChanged::class]);
    $first = TicketCategory::factory()->create();
    $second = TicketCategory::factory()->create();
    $ticket = fileTicket();

    Tickets::for($ticket)->asSystem()->categorize($first)->categorize($second->id)->categorize($second)->categorize(null);

    expect($ticket->category_id)->toBeNull()
        ->and(keySorted($ticket->activities()->where('type', 'category_changed')->pluck('properties')->all()))->toBe([
            ['from' => null, 'to' => $first->id],
            ['from' => $first->id, 'to' => $second->id],
            ['from' => $second->id, 'to' => null],
        ]);

    Event::assertDispatchedTimes(TicketCategoryChanged::class, 3);
});

it('does not re-apply category defaults on a category change', function (): void {
    $category = TicketCategory::factory()->create(['default_priority' => 'urgent', 'default_due_in' => 'P1D']);
    $ticket = fileTicket();

    Tickets::for($ticket)->asSystem()->categorize($category);

    expect($ticket->priority)->toBe('normal')
        ->and($ticket->due_at)->toBeNull();
});

it('refuses a category of another project or an inactive one on a move', function (Closure $category): void {
    Tickets::for(fileTicket())->categorize($category());
})->with([
    'inactive' => [fn () => TicketCategory::factory()->inactive()->create()],
    'another project' => [fn () => TicketCategory::factory()->inProject(Project::query()->create(['name' => 'p', 'key' => 'P']))->create()],
])->throws(InvalidCategoryException::class);

it('sets, moves and clears the due date and re-arms the sweep flags', function (): void {
    Event::fake([TicketDueDateChanged::class]);
    $ticket = fileTicket();
    $handle = Tickets::for($ticket)->asSystem();

    $handle->due(CarbonImmutable::parse('2030-01-01 10:00:00'));
    $ticket->newQuery()->whereKey($ticket->getKey())->update(['overdue_notified_at' => now(), 'due_soon_notified_at' => now()]);

    $handle->due(CarbonImmutable::parse('2030-01-02 10:00:00'))
        ->due(CarbonImmutable::parse('2030-01-02 10:00:00'))
        ->due(null);

    expect($ticket->due_at)->toBeNull()
        ->and($ticket->overdue_notified_at)->toBeNull()
        ->and($ticket->due_soon_notified_at)->toBeNull()
        ->and($ticket->activities()->where('type', 'due_changed')->count())->toBe(3);

    Event::assertDispatchedTimes(TicketDueDateChanged::class, 3);
});

it('allows a past due date for imports', function (): void {
    $ticket = fileTicket();

    Tickets::for($ticket)->due(CarbonImmutable::parse('2001-01-01'));

    expect($ticket->due_at?->year)->toBe(2001);
});

it('hands a ticket to another requester or a guest', function (): void {
    Event::fake([TicketRequesterChanged::class]);
    $ticket = fileTicket();
    $other = User::query()->create(['name' => 'Linus']);

    Tickets::for($ticket)->asSystem()->changeRequester($other)->changeRequester($other);

    expect($ticket->requester->is($other))->toBeTrue();

    Tickets::for($ticket)->asSystem()->changeRequester(new GuestData(['name' => 'Jo', 'email' => 'jo@acme.test']));

    expect($ticket->fresh()?->requester)->toBeInstanceOf(TicketGuest::class)
        ->and($ticket->activities()->where('type', 'requester_changed')->count())->toBe(2);

    Event::assertDispatchedTimes(TicketRequesterChanged::class, 2);
});

it('updates subject and description in one activity and delegates the rest', function (): void {
    Event::fake([TicketUpdated::class]);
    $ticket = fileTicket();
    $category = TicketCategory::factory()->create();
    $other = User::query()->create(['name' => 'Linus']);

    Tickets::for($ticket)->asSystem()->update(new UpdateTicketData(
        subject: 'New subject',
        description: null,
        priority: 'low',
        category: $category,
        dueAt: CarbonImmutable::parse('2030-03-03'),
        requester: $other,
    ));

    expect($ticket->subject)->toBe('New subject')
        ->and($ticket->description)->toBeNull()
        ->and($ticket->priority)->toBe('low')
        ->and($ticket->category_id)->toBe($category->id)
        ->and($ticket->requester_id)->toBe($other->id)
        ->and($ticket->activities()->where('type', 'updated')->first()?->properties)->toBe(['changed' => ['subject', 'description']]);

    Event::assertDispatched(TicketUpdated::class, static fn (TicketUpdated $event): bool => $event->changed === ['subject', 'description']);
});

it('treats an update that changes nothing as a no-op', function (): void {
    Event::fake([TicketUpdated::class]);
    $ticket = fileTicket(['subject' => 'Same']);

    Tickets::for($ticket)->update(new UpdateTicketData(subject: 'Same'))->update(new UpdateTicketData);

    expect($ticket->activities()->where('type', 'updated')->count())->toBe(0);

    Event::assertNotDispatched(TicketUpdated::class);
});

it('validates the subject on update', function (): void {
    Tickets::for(fileTicket())->update(new UpdateTicketData(subject: ''));
})->throws(ValidationException::class);

describe('trashed_ticket_is_read_only', function (): void {
    it('refuses every mutation of a soft-deleted ticket, then allows them after a restore', function (Closure $mutate): void {
        Event::fake([TicketDeleted::class, TicketRestored::class]);
        $ticket = fileTicket();
        $handle = Tickets::for($ticket)->asSystem();

        $handle->delete();

        expect($ticket->trashed())->toBeTrue()
            ->and(fn () => $mutate($handle))->toThrow(TicketTrashedException::class);

        $handle->restore()->restore();

        $mutate($handle);

        expect($ticket->trashed())->toBeFalse()
            ->and($ticket->activities()->where('type', 'deleted')->count())->toBe(1)
            ->and($ticket->activities()->where('type', 'restored')->count())->toBe(1);

        Event::assertDispatchedTimes(TicketDeleted::class, 1);
        Event::assertDispatchedTimes(TicketRestored::class, 1);
    })->with([
        'prioritize' => [fn (TicketHandle $handle) => $handle->prioritize('high')],
        'categorize' => [fn (TicketHandle $handle) => $handle->categorize(TicketCategory::factory()->create())],
        'due' => [fn (TicketHandle $handle) => $handle->due(now()->addDay())],
        'change requester' => [fn (TicketHandle $handle) => $handle->changeRequester(User::query()->create(['name' => 'x']))],
        'update' => [fn (TicketHandle $handle) => $handle->update(new UpdateTicketData(subject: 'x'))],
    ]);

    it('refuses to delete a ticket twice', function (): void {
        $handle = Tickets::for(fileTicket())->asSystem()->delete();

        $handle->delete();
    })->throws(TicketTrashedException::class);

    it('refuses a ticket whose row is gone', function (): void {
        $ticket = fileTicket();
        $ticket->forceDelete();

        Tickets::for($ticket)->prioritize('high');
    })->throws(TicketNotFoundException::class);
});

describe('unknown_priority_is_tolerated', function (): void {
    it('reads an unknown stored priority as null and still sorts', function (): void {
        $known = fileTicket();
        $unknown = fileTicket();
        Ticket::query()->whereKey($unknown->getKey())->update(['priority' => 'critical']);

        $sorted = Ticket::query()->orderByPriority()->pluck('reference')->all();

        expect($unknown->fresh()?->priority())->toBeNull()
            ->and($known->fresh()?->priority())->toBe(TicketPriority::Normal)
            ->and($sorted)->toBe([$known->reference, $unknown->reference]);
    });
});

it('reads the timeline, internal entries included or public only', function (): void {
    $ticket = fileTicket();
    TicketActivity::factory()->create(['ticket_id' => $ticket->id, 'type' => ActivityType::NoteAdded, 'visibility' => ActivityVisibility::Internal]);

    expect(Tickets::for($ticket)->activity()->pluck('type')->all())->toBe([ActivityType::Created, ActivityType::NoteAdded])
        ->and(Tickets::for($ticket)->activity(internal: false)->pluck('type')->all())->toBe([ActivityType::Created]);
});
