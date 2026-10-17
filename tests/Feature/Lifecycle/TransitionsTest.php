<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Comments\Facades\Comments;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Enums\IssueCode;
use RoundlyConsulting\Lifecycle\Exceptions\DirectStateWriteException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Tickets\Actions\Internal\ApplyAutomationAction;
use RoundlyConsulting\Tickets\Contracts\ConversationLock;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\Automation;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Events\TicketClosed;
use RoundlyConsulting\Tickets\Events\TicketReopened;
use RoundlyConsulting\Tickets\Events\TicketResolved;
use RoundlyConsulting\Tickets\Events\TicketStatusChanged;
use RoundlyConsulting\Tickets\Exceptions\TicketTrashedException;
use RoundlyConsulting\Tickets\Exceptions\UnknownTicketTransitionException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Handles\TicketHandle;
use RoundlyConsulting\Tickets\Lifecycle\TicketLifecycle;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Support\TicketStates;
use RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles\HostLifecycle;
use RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles\HostStatus;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

function lifecycleTicket(): Ticket
{
    return Tickets::make()->subject('Printer jammed')->requester(User::query()->create(['name' => 'Ada']))->asSystem()->create();
}

function agentUser(): User
{
    return User::query()->create(['name' => 'Grace', 'is_agent' => true]);
}

it('applies a transition through lifecycle, with its activity, stamp and events', function (): void {
    Event::fake([TicketStatusChanged::class, TicketResolved::class]);
    CarbonImmutable::setTestNow('2030-01-01 10:00:00');
    $ticket = lifecycleTicket();
    $agent = agentUser();
    CarbonImmutable::setTestNow('2030-01-01 11:00:00');

    $result = Tickets::for($ticket)->by($agent)->transition('resolve', payload: ['resolution' => 'Replaced the drum.']);

    $entry = $ticket->activities()->where('type', ActivityType::StatusChanged->value)->sole();

    expect($result->to)->toBe(TicketStatus::Resolved)
        ->and($result->record->context)->toMatchArray(['resolution' => 'Replaced the drum.'])
        ->and($ticket->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->resolved_at?->toDateTimeString())->toBe('2030-01-01 11:00:00')
        ->and($ticket->last_activity_at?->toDateTimeString())->toBe('2030-01-01 11:00:00')
        ->and($ticket->fresh()?->last_activity_at?->toDateTimeString())->toBe('2030-01-01 11:00:00')
        ->and(keySorted($entry->properties))->toBe(['from' => 'new', 'to' => 'resolved', 'transition' => 'resolve'])
        ->and($entry->actor?->is($agent))->toBeTrue()
        ->and($entry->is_system)->toBeFalse();

    Event::assertDispatched(TicketStatusChanged::class, static fn (TicketStatusChanged $event): bool => $event->transition === 'resolve'
        && $event->from === TicketStatus::New && $event->to === TicketStatus::Resolved && $event->actor?->is($agent) === true);
    Event::assertDispatched(TicketResolved::class);

    CarbonImmutable::setTestNow();
});

it('surfaces lifecycle denials unchanged', function (): void {
    $handle = Tickets::for(lifecycleTicket())->by(agentUser());

    expect(fn () => $handle->transition('reopen'))->toThrow(TransitionDeniedException::class)
        ->and(fn () => Tickets::for($handle->ticket())->asSystem()->start())->toThrow(TransitionDeniedException::class)
        ->and(fn () => $handle->transition('hold'))->toThrow(TransitionDeniedException::class)
        ->and(fn () => $handle->transition('resolve', payload: ['resolution' => str_repeat('x', 5001)]))->toThrow(TransitionDeniedException::class);
});

it('refuses a transition of a soft-deleted ticket', function (): void {
    $handle = Tickets::for(lifecycleTicket())->by(agentUser())->delete();

    $handle->start();
})->throws(TicketTrashedException::class);

it('reads what the handle may do and the current state', function (): void {
    $handle = Tickets::for(lifecycleTicket())->by(agentUser());

    // Only automations run as the system: the shipped lifecycle allows system context on
    // open, resume, reopen, close and dismiss alone. A required reason is checked on apply.
    expect($handle->can('start'))->toBeTrue()
        ->and($handle->can('reopen'))->toBeFalse()
        ->and($handle->can('hold'))->toBeTrue()
        ->and($handle->allowedTransitions())->toBe(['open', 'start', 'wait', 'hold', 'resolve', 'dismiss'])
        ->and($handle->status())->toBe(TicketStatus::New)
        ->and(Tickets::for($handle->ticket())->asSystem()->allowedTransitions())->toBe(['open', 'dismiss'])
        ->and(Tickets::for($handle->ticket())->asSystem()->can('start'))->toBeFalse();
});

describe('named_transition_conveniences', function (): void {
    it('applies each default transition and returns the handle', function (string $method, array $arguments, TicketStatus $status, array $before): void {
        $handle = Tickets::for(lifecycleTicket())->by(agentUser());

        foreach ($before as $step) {
            $handle->transition(...$step);
        }

        expect($handle->{$method}(...$arguments))->toBe($handle)
            ->and($handle->ticket()->status)->toBe($status)
            ->and($handle->status())->toBe($status);
    })->with([
        'start' => ['start', [], TicketStatus::InProgress, []],
        'wait' => ['wait', [], TicketStatus::Pending, []],
        'hold' => ['hold', ['Waiting for the vendor'], TicketStatus::OnHold, []],
        'resume' => ['resume', [], TicketStatus::Open, [['wait']]],
        'resolve' => ['resolve', ['Replaced the drum.'], TicketStatus::Resolved, []],
        'resolve without a resolution' => ['resolve', [], TicketStatus::Resolved, []],
        'reopen' => ['reopen', ['Still broken'], TicketStatus::Open, [['resolve']]],
        'close' => ['close', [], TicketStatus::Closed, [['resolve']]],
        'dismiss' => ['dismiss', ['Spam'], TicketStatus::Closed, []],
    ]);

    it('chains conveniences with the other mutators', function (): void {
        $agent = agentUser();
        $ticket = lifecycleTicket();

        Tickets::for($ticket)->by($agent)->prioritize('urgent')->start()->wait()->resume()->resolve('Done');

        expect($ticket->status)->toBe(TicketStatus::Resolved)
            ->and($ticket->priority)->toBe('urgent')
            ->and($ticket->activities()->where('type', 'status_changed')->count())->toBe(4);
    });

    it('names the generic path when a host lifecycle lacks the convenience', function (): void {
        config()->set('tickets.lifecycle', HostLifecycle::class);
        $handle = Tickets::for(lifecycleTicket())->asSystem();

        expect(fn () => $handle->resolve())->toThrow(UnknownTicketTransitionException::class, 'transition()')
            ->and(fn () => $handle->transition('nope'))->toThrow(UnknownTicketTransitionException::class, '[nope]');

        $handle->transition('finish');

        expect($handle->status())->toBe(HostStatus::Done);
    });

    it('exposes the default transition names as constants', function (): void {
        expect([TicketLifecycle::START, TicketLifecycle::WAIT, TicketLifecycle::HOLD, TicketLifecycle::RESUME,
            TicketLifecycle::RESOLVE, TicketLifecycle::REOPEN, TicketLifecycle::CLOSE, TicketLifecycle::DISMISS])
            ->toBe(['start', 'wait', 'hold', 'resume', 'resolve', 'reopen', 'close', 'dismiss']);
    });
});

describe('ticket_lifecycle_definition', function (): void {
    it('validates with no error and exactly the one ambiguous_target warning', function (): void {
        $report = app(DefinitionRegistry::class)->validate(TicketLifecycle::class);

        expect($report->errors())->toBe([])
            ->and($report->warnings())->toHaveCount(1)
            ->and($report->has(IssueCode::AmbiguousTarget))->toBeTrue();
    });

    it('does not fire TicketStatusChanged for the initial state', function (): void {
        Event::fake([TicketStatusChanged::class]);

        lifecycleTicket();

        Event::assertNotDispatched(TicketStatusChanged::class);
    });

    it('maps every shipped state to the category the package reads', function (): void {
        $ticket = lifecycleTicket();

        expect($ticket->statusCategory())->toBe(StatusCategory::Open);
    });
});

describe('strict_writes', function (): void {
    it('keeps lifecycle strict writes on, so a direct status write throws', function (): void {
        $ticket = lifecycleTicket();

        expect(config('lifecycle.strict_writes'))->toBeTrue()
            ->and(fn () => $ticket->forceFill(['status' => 'closed'])->save())->toThrow(DirectStateWriteException::class);
    });

    it('never writes the status column from package code', function (): void {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../../src'));
        $writers = [];

        foreach ($files as $file) {
            // The HTTP layer and the notifications only render `status` (the API's writes go
            // through the manager — pinned by the arch test), so they are not scanned.
            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Http'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Notifications'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            // The two mentions allowed declare it: the model's cast and its lifecycle map.
            if ($file->isFile() && preg_match("/['\"]status['\"]\s*+=>\s*+(?!AsLifecycleState|TicketsConfig::lifecycle)/", (string) file_get_contents($file->getPathname())) === 1) {
                $writers[] = $file->getFilename();
            }
        }

        expect($writers)->toBe([]);
    });
});

describe('automations', function (): void {
    it('opens a ticket created with an assignee, as the system, crediting the filer', function (): void {
        $agent = agentUser();

        $ticket = Tickets::make()->subject('a')->requester(User::query()->create())->assignee($agent)->by($agent)->create();
        $entry = $ticket->activities()->where('type', 'status_changed')->sole();

        expect($ticket->status)->toBe(TicketStatus::Open)
            ->and($entry->is_system)->toBeTrue()
            ->and($entry->actor?->is($agent))->toBeTrue()
            ->and($entry->properties['automation'] ?? null)->toBe('assigned');
    });

    it('applies a host lifecycle automation and skips a switched-off one', function (): void {
        config()->set('tickets.lifecycle', HostLifecycle::class);
        $agent = agentUser();

        $ticket = Tickets::make()->subject('a')->requester(User::query()->create())->assignee($agent)->create();

        expect($ticket->status)->toBe(HostStatus::Working);
    });

    it('does nothing when the automation transition cannot leave the current state', function (): void {
        $agent = agentUser();
        $ticket = Ticket::factory()->inStatus('pending')->create();

        expect(app(ApplyAutomationAction::class)
            ->execute($ticket, Automation::Assigned, $agent))->toBeNull()
            ->and($ticket->fresh()?->status)->toBe(TicketStatus::Pending);
    });
});

describe('conversation_lock', function (): void {
    it('locks the comments when a ticket closes', function (): void {
        Event::fake([TicketClosed::class]);
        $ticket = lifecycleTicket();

        Tickets::for($ticket)->by(agentUser())->resolve()->close();

        expect(Comments::isLocked($ticket))->toBeTrue();

        Event::assertDispatched(TicketClosed::class);
    });

    it('unlocks them when a host lifecycle leaves Closed', function (): void {
        Event::fake([TicketReopened::class]);
        config()->set('tickets.lifecycle', HostLifecycle::class);
        $ticket = lifecycleTicket();
        $handle = Tickets::for($ticket)->asSystem();

        $handle->transition('finish');
        expect(Comments::isLocked($ticket))->toBeTrue();

        $handle->transition('revive');
        expect(Comments::isLocked($ticket))->toBeFalse();

        Event::assertDispatched(TicketReopened::class);
    });

    it('syncs the lock after commit for a lifecycle rollback out of and back into Closed', function (): void {
        $ticket = lifecycleTicket();
        Tickets::for($ticket)->by(agentUser())->resolve()->close();

        Lifecycles::for($ticket)->asSystem()->rollback();

        $entry = $ticket->activities()->where('type', 'status_changed')->reorder('id', 'desc')->first();

        expect($ticket->fresh()?->status)->toBe(TicketStatus::Resolved)
            ->and(Comments::isLocked($ticket))->toBeFalse()
            ->and($entry?->properties['kind'] ?? null)->toBe('rollback');
    });

    it('syncs the lock for an adopted state', function (): void {
        $ticket = lifecycleTicket();

        Lifecycles::allowDirectWrites(static fn () => $ticket->forceFill(['status' => 'closed'])->save());

        expect(Comments::isLocked($ticket))->toBeTrue()
            ->and($ticket->activities()->where('type', 'status_changed')->reorder('id', 'desc')->first()?->properties['kind'] ?? null)->toBe('adopted');
    });
});

it('fires TicketReopened when a resolved ticket reopens', function (): void {
    Event::fake([TicketReopened::class, TicketResolved::class]);
    $handle = Tickets::for(lifecycleTicket())->by(agentUser());

    $handle->resolve()->reopen();

    Event::assertDispatchedTimes(TicketResolved::class, 1);
    Event::assertDispatchedTimes(TicketReopened::class, 1);
});

it('ignores lifecycle events of other subjects', function (): void {
    $plain = Document::query()->create();

    $plain->transition('finish');

    expect($plain->fresh()?->status)->toBe('done')
        ->and(TicketActivity::query()->count())->toBe(0);
});

it('records a handle transition on the fake', function (): void {
    $fake = Tickets::fake();
    $ticket = lifecycleTicket();

    expect(fn () => $fake->assertTransitioned())->toThrow(ExpectationFailedException::class);

    $fake->assertNothingTransitioned();
    Tickets::for($ticket)->by(agentUser())->start();

    $fake->assertTransitioned($ticket, 'start');
    $fake->assertTransitioned('TCK-1');

    expect(fn () => $fake->assertTransitioned($ticket, 'resolve'))->toThrow(ExpectationFailedException::class, 'none matched')
        ->and(fn () => $fake->assertNothingTransitioned())->toThrow(ExpectationFailedException::class);
});

it('returns a TicketHandle from every convenience', function (): void {
    expect(Tickets::for(lifecycleTicket())->by(agentUser())->start())->toBeInstanceOf(TicketHandle::class);
});

it('skips an automation the configured lifecycle switched off', function (): void {
    config()->set('tickets.lifecycle', HostLifecycle::class);
    $ticket = lifecycleTicket();

    expect(app(ApplyAutomationAction::class)
        ->execute($ticket, Automation::RequesterReplied))->toBeNull()
        ->and($ticket->fresh()?->status)->toBe(HostStatus::Triage);
});

it('reports the conversation lock state', function (): void {
    $ticket = lifecycleTicket();
    $lock = app(ConversationLock::class);

    $lock->unlock($ticket);
    $lock->lock($ticket);
    $lock->lock($ticket);

    expect($lock->isLocked($ticket))->toBeTrue();

    $lock->unlock($ticket);

    expect($lock->isLocked($ticket))->toBeFalse();
});

it('reads the category of a state given as a case, a raw value or nothing', function (): void {
    expect(TicketStates::category(TicketStatus::Pending))->toBe(StatusCategory::Waiting)
        ->and(TicketStates::category('resolved'))->toBe(StatusCategory::Resolved)
        ->and(TicketStates::category('unknown'))->toBeNull()
        ->and(TicketStates::category(null))->toBeNull();
});
