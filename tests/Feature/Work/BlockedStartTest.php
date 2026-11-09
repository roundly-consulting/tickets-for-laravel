<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Tickets\Actions\Internal\GuardTicketStartAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Exceptions\TicketBlockedException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles\HostLifecycle;
use RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles\HostStatus;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Support\RecordsLocks;

beforeEach(fn () => apiAbilities());

function blockedTicket(string $subject = 'Deploy the fix'): Ticket
{
    return Tickets::make()->subject($subject)->requester(User::query()->create(['name' => 'Ada']))->asSystem()->create();
}

function blocks(Ticket $blocker, Ticket $ticket, LinkType $type = LinkType::Blocks): void
{
    Tickets::for($blocker)->asSystem()->links()->add($ticket, $type);
}

function blockersPreventStart(): void
{
    config()->set('tickets.links.blockers_prevent_start', 'on');
}

describe('the guard is opt-in', function (): void {
    it('starts a blocked ticket while the setting is off — the default', function (): void {
        $ticket = blockedTicket();
        blocks(blockedTicket('Blocker'), $ticket);

        Tickets::for($ticket)->by(apiAgent())->start();

        expect($ticket->status)->toBe(TicketStatus::InProgress);
    });
});

describe('a blocked start is refused', function (): void {
    beforeEach(fn () => blockersPreventStart());

    it('refuses to start a ticket an unresolved ticket blocks, naming every blocker in key order', function (): void {
        $first = blockedTicket('First');
        $second = blockedTicket('Second');
        $ticket = blockedTicket();
        $agent = apiAgent();
        Tickets::for($second)->by($agent)->wait();
        blocks($second, $ticket);
        blocks($first, $ticket);

        try {
            Tickets::for($ticket)->by($agent)->start();
            $this->fail('The start was not refused.');
        } catch (TicketBlockedException $exception) {
            expect($exception->getMessage())->toBe('Ticket [TCK-3] cannot be started while unresolved tickets block it: TCK-1, TCK-2.')
                ->and($exception->blockers())->toBe(['TCK-1', 'TCK-2']);
        }

        expect($ticket->fresh()?->status)->toBe(TicketStatus::New)
            ->and($ticket->activities()->where('type', ActivityType::StatusChanged->value)->exists())->toBeFalse()
            ->and(Tickets::for($ticket)->by($agent)->can('start'))->toBeFalse()
            ->and(Tickets::for($ticket)->by($agent)->allowedTransitions())->not->toContain('start')
            ->and(Tickets::for($ticket)->by($agent)->allowedTransitions())->toContain('wait', 'resolve');
    });

    it('starts the ticket once every blocker is resolved', function (): void {
        $agent = apiAgent();
        $blocker = blockedTicket('Blocker');
        $ticket = blockedTicket();
        blocks($blocker, $ticket);

        expect(fn () => Tickets::for($ticket)->by($agent)->start())->toThrow(TicketBlockedException::class);

        Tickets::for($blocker)->by($agent)->resolve();

        expect(Tickets::for($ticket)->by($agent)->can('start'))->toBeTrue()
            ->and(Tickets::for($ticket)->by($agent)->start()->status())->toBe(TicketStatus::InProgress);
    });

    it('ignores resolved, closed and trashed blockers, outgoing blocks and other link types', function (): void {
        $agent = apiAgent();
        $ticket = blockedTicket();
        [$resolved, $closed, $trashed, $blocked, $related, $parent, $duplicate] = array_map(blockedTicket(...), ['r', 'c', 't', 'b', 'rel', 'p', 'd']);
        blocks($resolved, $ticket);
        blocks($closed, $ticket);
        blocks($trashed, $ticket);
        blocks($ticket, $blocked);
        blocks($related, $ticket, LinkType::RelatesTo);
        blocks($parent, $ticket, LinkType::ParentOf);
        blocks($duplicate, $ticket, LinkType::Duplicates);
        Tickets::for($resolved)->by($agent)->resolve();
        Tickets::for($closed)->by($agent)->dismiss('spam');
        Tickets::for($trashed)->by($agent)->delete();

        Tickets::for($ticket)->by($agent)->start();

        expect($ticket->status)->toBe(TicketStatus::InProgress);
    });

    it('lets every transition that does not start the ticket through', function (): void {
        $agent = apiAgent();
        $ticket = blockedTicket();
        blocks(blockedTicket('Blocker'), $ticket);

        Tickets::for($ticket)->by($agent)->wait()->resume()->hold('parts')->resolve();

        expect($ticket->status)->toBe(TicketStatus::Resolved);
    });

    it('only guards entering the Active category, not a move inside it', function (): void {
        $ticket = blockedTicket();
        blocks(blockedTicket('Blocker'), $ticket);
        $guard = app(GuardTicketStartAction::class);

        $guard->execute($ticket, TicketStatus::InProgress, TicketStatus::InProgress);
        $guard->execute($ticket, TicketStatus::Open, TicketStatus::Pending);

        expect(fn () => $guard->execute($ticket, null, TicketStatus::InProgress))->toThrow(TicketBlockedException::class)
            ->and(fn () => $guard->execute($ticket, 'open', 'in_progress'))->toThrow(TicketBlockedException::class);
    });

    it('refuses a start applied through lifecycle directly too', function (): void {
        $ticket = blockedTicket();
        blocks(blockedTicket('Blocker'), $ticket);

        expect(fn () => Lifecycles::for($ticket, 'status')->by(apiAgent())->apply('start'))->toThrow(TicketBlockedException::class)
            ->and($ticket->fresh()?->status)->toBe(TicketStatus::New);
    });

    it('speaks Slovak', function (): void {
        $ticket = blockedTicket();
        blocks(blockedTicket('Blocker'), $ticket);
        app()->setLocale('sk');

        expect(fn () => Tickets::for($ticket)->by(apiAgent())->start())
            ->toThrow(TicketBlockedException::class, 'Tiket [TCK-1] nemožno začať riešiť, kým ho blokujú nevyriešené tikety: TCK-2.');
    });
});

describe('automations', function (): void {
    it('skips an automation that would start a blocked ticket, and runs it once unblocked', function (): void {
        config()->set('tickets.lifecycle', HostLifecycle::class);
        blockersPreventStart();
        $agent = apiAgent();
        $blocker = blockedTicket('Blocker');
        $ticket = blockedTicket();
        blocks($blocker, $ticket);

        Tickets::for($ticket)->by($agent)->assign($agent);

        expect($ticket->fresh()?->assignee_id)->toBe($agent->id)
            ->and($ticket->fresh()?->status)->toBe(HostStatus::Triage);

        Tickets::for($blocker)->by($agent)->transition('finish');
        Tickets::for($ticket)->by($agent)->unassign()->assign($agent);

        expect($ticket->fresh()?->status)->toBe(HostStatus::Working);
    });
});

describe('the decision is made under the ticket lock', function (): void {
    it('reads the blockers inside the transition, after the ticket row is locked', function (): void {
        blockersPreventStart();
        $agent = apiAgent();
        $ticket = blockedTicket();
        $queries = [];
        RecordsLocks::start();
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
        });

        Tickets::for($ticket)->by($agent)->start();

        $lock = LockRecorder::recorded()[0]['sql'] ?? '';
        $lockAt = array_search($lock, array_column($queries, 'sql'), true);
        $readAt = array_key_first(array_filter($queries, static fn (array $query): bool => str_contains($query['sql'], 'related_ticket_id')));

        expect(RecordsLocks::tables()[0] ?? null)->toBe('tickets')
            ->and($lockAt)->toBeInt()
            ->and($readAt)->toBeInt()
            ->and($readAt)->toBeGreaterThan($lockAt)
            ->and($queries[$readAt]['level'])->toBeGreaterThan(0);
    })->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');
});
