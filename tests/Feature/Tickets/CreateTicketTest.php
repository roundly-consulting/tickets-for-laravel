<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Tickets\Contracts\AssignmentStrategy;
use RoundlyConsulting\Tickets\Contracts\DueDateResolver;
use RoundlyConsulting\Tickets\DataTransferObjects\CreateTicketData;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\TicketPriority;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Events\TicketAssigned;
use RoundlyConsulting\Tickets\Events\TicketCreated;
use RoundlyConsulting\Tickets\Exceptions\DuplicateTicketReferenceException;
use RoundlyConsulting\Tickets\Exceptions\InvalidAssigneeException;
use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;
use RoundlyConsulting\Tickets\Exceptions\InvalidProjectException;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketSequence;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Support\RecordsLocks;

function requester(array $attributes = []): User
{
    return User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', ...$attributes]);
}

function agent(string $name = 'Grace'): User
{
    return User::query()->create(['name' => $name, 'is_agent' => true]);
}

it('files a ticket through the builder with everything the caller gives', function (): void {
    Event::fake([TicketCreated::class, TicketAssigned::class]);
    $user = requester();
    $agent = agent();
    $category = TicketCategory::factory()->create();
    $due = CarbonImmutable::parse('2030-01-01 10:00:00');

    $ticket = Tickets::make()
        ->subject('  Printer on floor 3 is jammed ')
        ->description('Paper jam error E-102.')
        ->requester($user)
        ->category($category)
        ->priority(TicketPriority::High)
        ->assignee($agent)
        ->due($due)
        ->channel('web')
        ->by($agent)
        ->create();

    expect($ticket->reference)->toBe('TCK-1')
        ->and($ticket->number)->toBe(1)
        ->and($ticket->sequence_scope)->toBe('global')
        ->and($ticket->subject)->toBe('Printer on floor 3 is jammed')
        // Assigning a new ticket opens it — the `Assigned` automation.
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->priority)->toBe('high')
        ->and($ticket->channel)->toBe('web')
        ->and($ticket->requester->is($user))->toBeTrue()
        ->and($ticket->assignee?->is($agent))->toBeTrue()
        ->and($ticket->assigned_at)->not->toBeNull()
        ->and($ticket->due_at?->equalTo($due))->toBeTrue()
        ->and($ticket->last_activity_at)->not->toBeNull()
        ->and($ticket->activities->pluck('type')->all())->toBe([ActivityType::Created, ActivityType::Assigned, ActivityType::StatusChanged])
        ->and($ticket->activities->first()?->actor?->is($agent))->toBeTrue();

    Event::assertDispatched(TicketCreated::class, static fn (TicketCreated $event): bool => $event->ticket->is($ticket) && $event->actor?->is($agent) === true);
    Event::assertDispatched(TicketAssigned::class, static fn (TicketAssigned $event): bool => $event->assignee->is($agent));
});

it('numbers tickets per sequence: global, and one per project with its prefix', function (): void {
    $user = requester();
    $support = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
    $ops = Project::query()->create(['name' => 'Ops', 'key' => 'OPS']);

    $references = [
        Tickets::make()->subject('a')->requester($user)->create()->reference,
        Tickets::make()->subject('b')->requester($user)->project($support)->create()->reference,
        Tickets::make()->subject('c')->requester($user)->project($support)->create()->reference,
        Tickets::make()->subject('d')->requester($user)->project($ops)->create()->reference,
        Tickets::make()->subject('e')->requester($user)->create()->reference,
    ];

    expect($references)->toBe(['TCK-1', 'SUP-1', 'SUP-2', 'OPS-1', 'TCK-2'])
        ->and(TicketSequence::query()->where('scope', $support->getMorphClass().':'.$support->id)->value('next_number'))->toBe(3);
});

it('keeps a project sequence going under a renamed prefix', function (): void {
    $user = requester();
    $project = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);

    Tickets::make()->subject('a')->requester($user)->project($project)->create();
    $project->update(['key' => 'HELP']);

    expect(Tickets::make()->subject('b')->requester($user)->project($project)->create()->reference)->toBe('HELP-2')
        ->and(Tickets::find('SUP-1'))->not->toBeNull();
});

it('uses the configured global prefix', function (): void {
    config()->set('tickets.reference.prefix', 'HD');

    expect(Tickets::make()->subject('a')->requester(requester())->create()->reference)->toBe('HD-1');
});

it('refuses a malformed global or project prefix', function (): void {
    config()->set('tickets.reference.prefix', 'hd');

    expect(fn () => Tickets::make()->subject('a')->requester(requester())->create())
        ->toThrow(InvalidTicketsConfigurationException::class, '[tickets.reference.prefix]');

    config()->set('tickets.reference.prefix', 'TCK');
    $project = Project::query()->create(['name' => 'Bad', 'key' => 'sup-1']);

    expect(fn () => Tickets::make()->subject('a')->requester(requester())->project($project)->create())
        ->toThrow(InvalidProjectException::class);
});

it('refuses a project that is not a TicketProject', function (): void {
    Tickets::make()->subject('a')->requester(requester())->project(Team::query()->create(['name' => 'x']))->create();
})->throws(InvalidProjectException::class);

describe('rollback_keeps_sequence', function (): void {
    it('gives the number of a rolled-back create back', function (): void {
        $user = requester();

        Ticket::creating(static function (Ticket $ticket): void {
            if ($ticket->subject === 'boom') {
                throw new RuntimeException('host listener failed');
            }
        });

        expect(fn () => Tickets::make()->subject('boom')->requester($user)->create())->toThrow(RuntimeException::class);

        expect(Ticket::query()->count())->toBe(0)
            ->and(Tickets::make()->subject('fine')->requester($user)->create()->reference)->toBe('TCK-1');
    });

    it('keeps the number of a soft-deleted ticket — numbers are never reused', function (): void {
        $user = requester();
        $first = Tickets::make()->subject('a')->requester($user)->create();

        Tickets::for($first)->delete();

        expect(Tickets::make()->subject('b')->requester($user)->create()->reference)->toBe('TCK-2');
    });
});

describe('duplicate_project_prefix', function (): void {
    it('refuses two projects sharing a prefix with a clear error', function (): void {
        $user = requester();
        $one = Project::query()->create(['name' => 'One', 'key' => 'SUP']);
        $two = Project::query()->create(['name' => 'Two', 'key' => 'SUP']);

        Tickets::make()->subject('a')->requester($user)->project($one)->create();

        expect(fn () => Tickets::make()->subject('b')->requester($user)->project($two)->create())
            ->toThrow(DuplicateTicketReferenceException::class, 'SUP-1');

        expect(Ticket::query()->count())->toBe(1)
            ->and(TicketSequence::query()->where('scope', $two->getMorphClass().':'.$two->id)->value('next_number'))->toBeNull();
    });

    it('translates a unique violation that slips past the check into the same error', function (): void {
        $user = requester();

        // A row the existence probe cannot see on this connection's view: inserted raw with
        // the reference the next create will allocate, then hidden from the probe by a
        // global scope that only applies to that one query.
        Ticket::factory()->create(['reference' => 'TCK-1']);
        Ticket::addGlobalScope('hide', static fn ($query) => $query->where('reference', '!=', 'TCK-1'));

        try {
            Tickets::make()->subject('a')->requester($user)->create();
        } catch (DuplicateTicketReferenceException $exception) {
            expect($exception->getMessage())->toContain('TCK-1');

            return;
        } finally {
            (function (): void {
                unset(static::$globalScopes[Ticket::class]['hide']);
            })->call(new Ticket);
        }

        $this->fail('Expected a DuplicateTicketReferenceException.');
    });
});

describe('reference_allocation_is_race_free', function (): void {
    it('locks the sequence row inside the create transaction', function (): void {
        $user = requester();
        RecordsLocks::start();

        Tickets::make()->subject('a')->requester($user)->create();

        $locks = LockRecorder::recorded();

        expect(RecordsLocks::tables())->toContain('ticket_sequences')
            ->and(collect($locks)->every(static fn (array $lock): bool => $lock['transactionDepth'] >= 1))->toBeTrue();
    })->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');

    it('creates the sequence row once and reuses it', function (): void {
        $user = requester();

        Tickets::make()->subject('a')->requester($user)->create();
        Tickets::make()->subject('b')->requester($user)->create();

        expect(TicketSequence::query()->where('scope', 'global')->count())->toBe(1)
            ->and(TicketSequence::query()->where('scope', 'global')->value('next_number'))->toBe(3);
    });
});

describe('defaults_precedence', function (): void {
    it('takes the caller value first', function (): void {
        $agent = agent();
        $other = agent('Linus');
        $category = TicketCategory::factory()->create([
            'default_priority' => 'low',
            'default_assignee_type' => $other->getMorphClass(),
            'default_assignee_id' => $other->id,
            'default_due_in' => 'P2D',
        ]);
        $due = CarbonImmutable::parse('2031-05-05 08:00:00');

        $ticket = Tickets::make()->subject('a')->requester(requester())->category($category)
            ->priority('urgent')->assignee($agent)->due($due)->create();

        expect($ticket->priority)->toBe('urgent')
            ->and($ticket->assignee?->is($agent))->toBeTrue()
            ->and($ticket->due_at?->equalTo($due))->toBeTrue();
    });

    it('falls back to the nearest category that sets a default', function (): void {
        CarbonImmutable::setTestNow('2030-01-01 12:00:00');
        $agent = agent();
        $root = TicketCategory::factory()->create([
            'default_priority' => 'high',
            'default_assignee_type' => $agent->getMorphClass(),
            'default_assignee_id' => $agent->id,
            'default_due_in' => 'P2D',
        ]);
        $middle = TicketCategory::factory()->under($root)->create(['default_due_in' => 'PT4H']);
        $leaf = TicketCategory::factory()->under($middle)->create();

        $ticket = Tickets::make()->subject('a')->requester(requester())->category($leaf)->create();

        expect($ticket->priority)->toBe('high')
            ->and($ticket->assignee?->is($agent))->toBeTrue()
            ->and($ticket->due_at?->toDateTimeString())->toBe('2030-01-01 16:00:00');

        CarbonImmutable::setTestNow();
    });

    it('falls back to config when no category sets one', function (): void {
        config()->set('tickets.priority.default', 'low');

        $ticket = Tickets::make()->subject('a')->requester(requester())->create();

        expect($ticket->priority)->toBe('low')
            ->and($ticket->assignee_type)->toBeNull()
            ->and($ticket->due_at)->toBeNull();
    });

    it('skips a default priority the enum no longer has', function (): void {
        $category = TicketCategory::factory()->create(['default_priority' => 'critical']);

        expect(Tickets::make()->subject('a')->requester(requester())->category($category)->create()->priority)->toBe('normal');
    });

    it('leaves the ticket unassigned when the default assignee is not eligible', function (): void {
        $customer = requester(['is_agent' => false]);
        $category = TicketCategory::factory()->create([
            'default_assignee_type' => $customer->getMorphClass(),
            'default_assignee_id' => $customer->id,
        ]);

        expect(Tickets::make()->subject('a')->requester(requester())->category($category)->create()->assignee_type)->toBeNull();
    });

    it('asks a host strategy and due resolver bound in config', function (): void {
        $agent = agent();
        $strategy = new class($agent) implements AssignmentStrategy
        {
            public function __construct(private readonly User $agent) {}

            public function assigneeFor(Ticket $ticket): User
            {
                return $this->agent;
            }
        };
        $resolver = new class implements DueDateResolver
        {
            public function dueAt(Ticket $ticket): CarbonImmutable
            {
                return CarbonImmutable::parse('2032-02-02 02:02:02');
            }
        };

        app()->instance(AssignmentStrategy::class, $strategy);
        app()->instance(DueDateResolver::class, $resolver);

        $ticket = Tickets::make()->subject('a')->requester(requester())->create();

        expect($ticket->assignee?->is($agent))->toBeTrue()
            ->and($ticket->due_at?->toDateTimeString())->toBe('2032-02-02 02:02:02');
    });
});

it('refuses an inactive, trashed, foreign-project or missing category', function (Closure $category): void {
    Tickets::make()->subject('a')->requester(requester())->category($category())->create();
})->with([
    'inactive' => [fn () => TicketCategory::factory()->inactive()->create()],
    'trashed' => [function () {
        $category = TicketCategory::factory()->create();
        $category->delete();

        return $category;
    }],
    'another project' => [fn () => TicketCategory::factory()->inProject(Project::query()->create(['name' => 'p', 'key' => 'P']))->create()],
    'missing' => [fn () => 999],
])->throws(InvalidCategoryException::class);

it('lets a project ticket use a global category or its own project category', function (): void {
    $project = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
    $global = TicketCategory::factory()->create();
    $own = TicketCategory::factory()->inProject($project)->create();

    expect(Tickets::make()->subject('a')->requester(requester())->project($project)->category($global)->create()->category_id)->toBe($global->id)
        ->and(Tickets::make()->subject('b')->requester(requester())->project($project)->category($own->id)->create()->category_id)->toBe($own->id);
});

it('refuses an assignee that is not an Assignee or not eligible', function (Closure $assignee, string $message): void {
    expect(fn () => Tickets::make()->subject('a')->requester(requester())->assignee($assignee())->create())
        ->toThrow(InvalidAssigneeException::class, $message);
})->with([
    'not an assignee' => [fn () => Project::query()->create(['name' => 'p', 'key' => 'P']), 'Assignee contract'],
    'not eligible' => [fn () => requester(['is_agent' => false]), 'not eligible'],
]);

it('accepts a team assignee', function (): void {
    $team = Team::query()->create(['name' => 'Hardware']);

    expect(Tickets::make()->subject('a')->requester(requester())->assignee($team)->create()->assignee?->is($team))->toBeTrue();
});

it('validates the subject, channel, priority and requester', function (Closure $build, string $key): void {
    try {
        $build(Tickets::make()->subject('a')->requester(requester()))->create();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($key);

        return;
    }

    $this->fail('Expected a ValidationException.');
})->with([
    'blank subject' => [fn ($builder) => $builder->subject('   '), 'subject'],
    'long subject' => [fn ($builder) => $builder->subject(str_repeat('x', 256)), 'subject'],
    'unknown channel' => [fn ($builder) => $builder->channel('fax'), 'channel'],
    'unknown priority' => [fn ($builder) => $builder->priority('critical'), 'priority'],
    'foreign enum priority' => [fn ($builder) => $builder->priority(TicketStatus::Open), 'priority'],
]);

it('needs a requester', function (): void {
    Tickets::make()->subject('a')->create();
})->throws(ValidationException::class);

it('files a ticket for a guest, resolving the guest in the same transaction', function (): void {
    $ticket = Tickets::make()->subject('a')->guest(['name' => 'Jo', 'email' => ' Jo@Acme.TEST '])->create();
    $again = Tickets::create(new CreateTicketData('b', new GuestData(['name' => 'Jo', 'email' => 'jo@acme.test'])));

    expect($ticket->requester)->toBeInstanceOf(TicketGuest::class)
        ->and($again->requester->is($ticket->requester))->toBeTrue()
        ->and(TicketGuest::query()->count())->toBe(1);
});

it('files as the authenticated user unless told otherwise', function (): void {
    $agent = agent();
    $this->actingAs($agent);

    $byAuth = Tickets::make()->subject('a')->requester(requester())->create();
    $bySystem = Tickets::make()->subject('b')->requester(requester())->asSystem()->create();

    config()->set('tickets.actor.from_auth', 'off');
    $offAuth = Tickets::make()->subject('c')->requester(requester())->create();

    expect($byAuth->activities->first()?->actor?->is($agent))->toBeTrue()
        ->and($bySystem->activities->first()?->actor_type)->toBeNull()
        ->and($bySystem->activities->first()?->is_system)->toBeTrue()
        ->and($offAuth->activities->first()?->actor_type)->toBeNull();
});

it('reads the actor from the configured guard', function (): void {
    config()->set('auth.guards.agents', ['driver' => 'session', 'provider' => 'users']);
    config()->set('tickets.actor.guard', 'agents');
    $agent = agent();
    $this->actingAs($agent, 'agents');

    expect(Tickets::make()->subject('a')->requester(requester())->create()->activities->first()?->actor?->is($agent))->toBeTrue();
});

it('writes no activity with the timeline switched off', function (): void {
    config()->set('tickets.activity.enabled', 'off');

    expect(Tickets::make()->subject('a')->requester(requester())->create()->activities)->toHaveCount(0);
});
