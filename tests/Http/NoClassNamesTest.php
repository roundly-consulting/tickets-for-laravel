<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectTrashedException;
use RoundlyConsulting\Tickets\Exceptions\InvalidAssigneeException;
use RoundlyConsulting\Tickets\Exceptions\InvalidProjectException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Http\ErrorResponse;
use RoundlyConsulting\Tickets\Http\Resources\TicketActivityResource;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

/**
 * Nothing a client receives names a PHP class — the suite has no morph map, so every stored
 * `*_type` is a class name, and only the alias (or the class basename in a message) may leave.
 */
beforeEach(fn () => apiAbilities());

/**
 * One ticket's timeline with a person of every kind in it: a guest requester converted to a user
 * and the ticket then handed to another user; a team, then an agent assigned and unassigned; a
 * watcher added and removed.
 *
 * @return array{ticket: Ticket, agent: User, watcher: User, converted: User, requester: User, team: Team, guest: TicketGuest}
 */
function noClassNamesTimeline(): array
{
    $agent = apiAgent();
    $watcher = apiAgent('Linus');
    $converted = apiUser('Jo');
    $requester = apiUser('Ada');
    $team = Team::query()->create(['name' => 'Hardware']);
    $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
    $ticket = Tickets::make()->subject('Printer jammed')->requester($guest)->asSystem()->create();

    Tickets::for($ticket)->by($agent)->assign($team)->assign($agent)->unassign()->watchers()->add($watcher)->remove($watcher);
    Tickets::guests()->convert($guest, $converted);
    Tickets::for($ticket->refresh())->by($agent)->changeRequester($requester);

    return compact('ticket', 'agent', 'watcher', 'converted', 'requester', 'team', 'guest');
}

/**
 * The properties of every entry of one type, oldest first, keys sorted.
 *
 * @param  list<array<string, mixed>>  $timeline
 * @return list<array<array-key, mixed>|null>
 */
function noClassNamesProperties(array $timeline, string $type): array
{
    return array_values(array_map(
        static fn (array $entry): ?array => keySorted($entry['properties']),
        array_filter($timeline, static fn (array $entry): bool => $entry['type'] === $type),
    ));
}

describe('activity properties', function (): void {
    it('shows an agent every person in the timeline as its alias with its key, never a class name', function (): void {
        $world = noClassNamesTimeline();
        [$agent, $team, $watcher] = [$world['agent'], $world['team'], $world['watcher']];
        $user = static fn (User $user): array => ['id' => $user->id, 'type' => 'user'];

        // No morph map: what the actions store is the class name, and they keep storing it.
        expect((new User)->getMorphClass())->toBe(User::class)
            ->and($world['ticket']->activities()->where('type', 'assigned')->firstOrFail()->properties['to']['type'] ?? null)->toBe(Team::class);

        $response = $this->actingAs($agent)->getJson("/api/tickets/{$world['ticket']->reference}/activity")->assertOk();
        $timeline = $response->json('data');

        expect(noClassNamesProperties($timeline, 'assigned'))->toBe([
            ['to' => ['id' => $team->id, 'type' => 'team']],
            ['from' => ['id' => $team->id, 'type' => 'team'], 'to' => $user($agent)],
        ])
            ->and(noClassNamesProperties($timeline, 'unassigned'))->toBe([['from' => $user($agent)]])
            ->and(noClassNamesProperties($timeline, 'watcher_added'))->toContain(['watcher' => $user($watcher)])
            ->and(noClassNamesProperties($timeline, 'watcher_removed'))->toContain(['watcher' => $user($watcher)])
            ->and(noClassNamesProperties($timeline, 'requester_changed'))->toBe([
                ['converted' => true, 'from' => ['id' => $world['guest']->id, 'type' => 'guest'], 'to' => $user($world['converted'])],
                ['from' => $user($world['converted']), 'to' => $user($world['requester'])],
            ])
            // An escaped backslash is what a class name looks like in JSON.
            ->and($response->getContent())->not->toContain('\\\\')->not->toContain('Fixtures');
    });

    it('still shows everyone else the aliases alone, without a key', function (): void {
        $world = noClassNamesTimeline();

        $response = $this->actingAs($world['requester'])->getJson("/api/tickets/{$world['ticket']->reference}/activity")->assertOk();
        $timeline = $response->json('data');

        expect(noClassNamesProperties($timeline, 'assigned'))->toBe([
            ['to' => ['type' => 'team']],
            ['from' => ['type' => 'team'], 'to' => ['type' => 'user']],
        ])
            ->and(noClassNamesProperties($timeline, 'unassigned'))->toBe([['from' => ['type' => 'user']]])
            ->and(noClassNamesProperties($timeline, 'requester_changed'))->toBe([
                ['converted' => true, 'from' => ['type' => 'guest'], 'to' => ['type' => 'user']],
                ['from' => ['type' => 'user'], 'to' => ['type' => 'user']],
            ])
            ->and(noClassNamesProperties($timeline, 'watcher_added'))->toBe([])
            ->and($response->getContent())->not->toContain('\\\\');
    });

    it('shows an agent a morph outside the alias lists as a null type with its key, and a pair without a key too', function (): void {
        $agent = apiAgent();
        $ticket = apiTicket();
        $entry = TicketActivity::query()->create([
            'ticket_id' => $ticket->getKey(), 'type' => 'assigned', 'is_system' => true, 'visibility' => 'public',
            'properties' => ['to' => ['type' => 'App\\Models\\Robot', 'id' => 7], 'from' => ['type' => User::class, 'id' => null], 'note' => 'kept'],
            'occurred_at' => now(),
        ]);
        $request = Request::create('/');
        Viewer::user($agent)->attach($request);

        expect(keySorted((new TicketActivityResource($entry->setRelation('ticket', $ticket)))->toArray($request)['properties']))
            ->toBe(['from' => ['id' => null, 'type' => 'user'], 'note' => 'kept', 'to' => ['id' => 7, 'type' => null]]);
    });
});

describe('error messages', function (): void {
    it('names a refused assignee by its class basename in the 422', function (): void {
        $ticket = apiTicket();

        $response = $this->actingAs(apiAdmin())->putJson("/api/tickets/{$ticket->reference}/assignee", ['assignee' => ['type' => 'user', 'id' => apiUser('Bob')->id]])
            ->assertUnprocessable()
            ->assertJson(['code' => 'invalid_assignee', 'message' => 'The model [User] is not eligible to be assigned this ticket.']);

        expect($response->getContent())->not->toContain('\\\\');
    });

    it('names a model that breaks a contract by its class basename', function (): void {
        expect(InvalidAssigneeException::notAnAssignee(new Document)->getMessage())
            ->toBe('The model [Document] cannot be assigned tickets: it must implement the Assignee contract.')
            ->and(InvalidAssigneeException::ineligible(new Document)->getMessage())->toBe('The model [Document] is not eligible to be assigned this ticket.')
            ->and(InvalidProjectException::notAProject(new Document)->getMessage())->toBe('The project [Document] must implement the TicketProject contract.');
    });

    it('answers a trashed lifecycle subject without its class or key', function (): void {
        $ticket = apiTicket();
        $error = ErrorResponse::from(SubjectTrashedException::for($ticket));

        expect($error?->status)->toBe(409)
            ->and($error?->code)->toBe('ticket_trashed')
            ->and($error?->message)->toBe('The ticket is deleted. Restore it before changing it.')
            ->and(json_encode($error?->toResponse()->getData(true)))->not->toContain('\\\\');

        app()->setLocale('sk');

        expect(ErrorResponse::from(SubjectTrashedException::for($ticket))?->message)->toBe('Tiket je odstránený. Pred zmenou ho obnovte.');
    });
});
