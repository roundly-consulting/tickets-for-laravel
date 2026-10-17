<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Exceptions\InvalidMergeException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Http\Resources\TicketGuestResource;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Fixtures\ProjectAgentScope;

beforeEach(fn () => apiAbilities());

function secWatch(Ticket $ticket, Model $watcher): void
{
    TicketWatcher::query()->create(['ticket_id' => $ticket->getKey(), 'watcher_type' => $watcher->getMorphClass(), 'watcher_id' => $watcher->getKey()]);
}

describe('internal_notes_never_reach_non_agents', function (): void {
    /**
     * A ticket with a public reply and an internal note, a guest twin, and a merge — then every
     * read the requester side has.
     *
     * @return array{User, User, string, string}
     */
    function secConversation(): array
    {
        $requester = apiUser();
        $watcher = apiUser('Cc');
        $agent = apiAgent();
        $ticket = apiTicket($requester);
        secWatch($ticket, $watcher);
        Tickets::for($ticket)->by($agent)->reply('PUBLIC-REPLY');
        Tickets::for($ticket)->by($agent)->note('SECRET-NOTE');

        $guestTicket = Tickets::make()->subject('Guest twin')->guest(['name' => 'Jo', 'email' => 'jo@acme.test'])->asSystem()->create();
        Tickets::for($guestTicket)->by($agent)->note('SECRET-NOTE');

        $source = apiTicket($requester, 'Duplicate');
        Tickets::for($source)->by($agent)->mergeInto($ticket);

        return [$requester, $watcher, $ticket->reference, Tickets::for($guestTicket)->guestLink()];
    }

    it('keeps notes out of every read of the requester side', function (Closure $read): void {
        [$requester, $watcher, $reference, $guestLink] = secConversation();

        foreach ([$requester, $watcher] as $person) {
            $response = $read($this->actingAs($person), $reference, $guestLink);
            $body = (string) $response->getContent();

            expect($response->status())->toBeLessThan(400, $body)
                ->and($body)->not->toContain('SECRET-NOTE')
                ->and($body)->not->toContain('merged into this ticket')
                ->and($body)->not->toContain('note_added')
                ->and($body)->not->toContain('"internal"');
        }
    })->with([
        'the thread' => [fn ($test, $reference) => $test->getJson("/api/tickets/{$reference}/comments")],
        'the activity' => [fn ($test, $reference) => $test->getJson("/api/tickets/{$reference}/activity")],
        'the ticket' => [fn ($test, $reference) => $test->getJson("/api/tickets/{$reference}")],
        'the list' => [fn ($test) => $test->getJson('/api/tickets')],
        'a search for the note' => [fn ($test) => $test->getJson('/api/tickets?filter[search]=SECRET-NOTE')],
        'the guest view' => [fn ($test, $reference, $guestLink) => $test->getJson($guestLink)],
    ]);

    it('counts public messages only for the requester side', function (): void {
        [$requester, , $reference] = secConversation();
        $agent = User::query()->where('name', 'Grace')->firstOrFail();

        // The public reply; for an agent also the note and the merge's system note.
        expect($this->actingAs($requester)->getJson("/api/tickets/{$reference}")->json('data.messages_count'))->toBe(1)
            ->and($this->actingAs($agent)->getJson("/api/tickets/{$reference}")->json('data.messages_count'))->toBe(3)
            ->and(collect($this->actingAs($requester)->getJson('/api/tickets')->json('data'))->firstWhere('reference', $reference)['messages_count'])->toBe(1);
    });

    it('keeps the note count and the merge reason out of the public merge entries', function (): void {
        $requester = apiUser();
        $agent = apiAgent();
        $target = apiTicket($requester, 'Printer jammed');
        $source = apiTicket($requester, 'Printer jammed again');
        Tickets::for($source)->by($agent)->reply('PUBLIC');
        Tickets::for($source)->by($agent)->note('SECRET-1');
        Tickets::for($source)->by($agent)->note('SECRET-2');

        Tickets::for($source)->by($agent)->mergeInto($target, 'customer is a serial complainer, ignore');

        $public = fn (Ticket $ticket, string $type): array => collect($this->actingAs($requester)->getJson("/api/tickets/{$ticket->reference}/activity")->assertOk()->json('data'))
            ->where('type', $type)->pluck('properties')->all();
        $internal = fn (Ticket $ticket, string $type): array => collect($this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}/activity")->assertOk()->json('data'))
            ->where('type', $type)->where('visibility', 'internal')->pluck('properties')->all();

        // The requester reads one public message of the source — and is told one.
        expect($public($source, 'merged'))->toBe([['into' => $target->reference, 'comments' => 1, 'attachments' => 0]])
            ->and($public($target, 'merged_from'))->toBe([['from' => $source->reference, 'comments' => 1]])
            // Agents keep the whole story in an internal entry.
            ->and(array_map(keySorted(...), $internal($source, 'merged')))->toBe([['attachments' => 0, 'comments' => 3, 'into' => $target->reference, 'reason' => 'customer is a serial complainer, ignore']])
            ->and(array_map(keySorted(...), $internal($target, 'merged_from')))->toBe([['comments' => 3, 'from' => $source->reference, 'reason' => 'customer is a serial complainer, ignore']]);
    });

    it('refuses the internal thread to the requester side, and finds no note for them', function (): void {
        [$requester, $watcher, $reference] = secConversation();
        $note = Ticket::query()->where('reference', $reference)->firstOrFail()->comments()->where('visible', false)->firstOrFail();

        $this->actingAs($requester)->getJson("/api/tickets/{$reference}/comments?internal=1")->assertForbidden()->assertJson(['code' => 'forbidden']);
        $this->actingAs($watcher)->patchJson("/api/tickets/{$reference}/comments/{$note->getKey()}", ['body' => 'x'])->assertNotFound()->assertJson(['code' => 'comment_not_found']);
        $this->actingAs($requester)->deleteJson("/api/tickets/{$reference}/comments/{$note->getKey()}")->assertNotFound();
    });

    it('shows an agent the internal thread', function (): void {
        [, , $reference] = secConversation();
        $agent = User::query()->where('name', 'Grace')->firstOrFail();

        $bodies = collect($this->actingAs($agent)->getJson("/api/tickets/{$reference}/comments?internal=1")->assertOk()->json('data'))->pluck('body')->all();

        expect($bodies)->toContain('SECRET-NOTE')->toContain('PUBLIC-REPLY');
    });
});

describe('hidden_tickets_answer_like_missing_ones', function (): void {
    it('answers a ticket the user may not view with the 404 of a missing one', function (string $method, string $path): void {
        $stranger = apiAdmin();
        config()->set('tickets.authorization.agent_scope', ProjectAgentScope::class);
        $stranger->forceFill(['name' => 'Scoped'])->save();
        Gate::define('tickets.manage', static fn (User $user): bool => $user->name === 'Scoped');
        Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
        $ticket = apiTicket(apiUser('Owner'));
        if ($path === '/restore') {
            Tickets::for($ticket)->asSystem()->delete();
        }

        $hidden = $this->actingAs($stranger)->json($method, "/api/tickets/{$ticket->reference}{$path}");
        $missing = $this->actingAs($stranger)->json($method, "/api/tickets/TCK-999{$path}");

        $missing->assertNotFound();
        $hidden->assertNotFound()->assertJson(['code' => 'ticket_not_found']);
        expect(Ticket::withTrashed()->whereKey($ticket->getKey())->sole()->trashed())->toBe($path === '/restore')
            ->and($ticket->watchers()->count())->toBe(1);
    })->with([
        'delete' => ['DELETE', ''],
        'restore' => ['POST', '/restore'],
        'watch' => ['POST', '/watch'],
        'unwatch' => ['DELETE', '/watch'],
    ]);

    it('lets a manager delete and restore only tickets they work on as an agent', function (): void {
        config()->set('tickets.authorization.agent_scope', ProjectAgentScope::class);
        Gate::define('tickets.manage', static fn (User $user): bool => true);
        $sup = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
        $scoped = User::query()->create(['name' => 'Scoped', 'is_agent' => true]);
        $mine = apiTicket($scoped, 'My own, outside the scope');
        $inScope = Tickets::make()->subject('VPN drops')->requester(apiUser())->project($sup)->asSystem()->create();

        // Their own ticket they see as its requester — but they are no agent on it.
        $this->actingAs($scoped)->deleteJson("/api/tickets/{$mine->reference}")->assertForbidden();
        $this->actingAs($scoped)->deleteJson("/api/tickets/{$inScope->reference}")->assertNoContent();
        $this->actingAs($scoped)->postJson("/api/tickets/{$inScope->reference}/restore")->assertOk();

        expect($mine->fresh()?->trashed())->toBeFalse()
            ->and($inScope->fresh()?->trashed())->toBeFalse();
    });
});

describe('agent_scope_holds_across_tickets', function (): void {
    beforeEach(function (): void {
        config()->set('tickets.authorization.agent_scope', ProjectAgentScope::class);
        $this->sup = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
        $this->hr = Project::query()->create(['name' => 'People', 'key' => 'HR']);
        $this->scoped = User::query()->create(['name' => 'Scoped', 'is_agent' => true]);
    });

    it('refuses a merge to an agent outside the scope of either ticket', function (): void {
        $hrAgent = apiAgent('Hilda');
        $first = Tickets::make()->subject('Payslip wrong')->requester($this->scoped)->project($this->hr)->asSystem()->create();
        $second = Tickets::make()->subject('Payslip still wrong')->requester($this->scoped)->project($this->hr)->asSystem()->create();
        $inScope = Tickets::make()->subject('VPN drops')->requester(apiUser())->project($this->sup)->asSystem()->create();
        Tickets::for($first)->by($hrAgent)->note('HR-INTERNAL: salary dispute');

        // Not their tickets to work on: no transition beyond the requester's — and no merge.
        $this->actingAs($this->scoped)->postJson("/api/tickets/{$first->reference}/transitions/dismiss", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->scoped)->postJson("/api/tickets/{$first->reference}/merge", ['target' => $second->reference])->assertForbidden();
        $this->actingAs($this->scoped)->postJson("/api/tickets/{$inScope->reference}/merge", ['target' => $first->reference])->assertForbidden();

        expect(fn () => Tickets::for($first)->by($this->scoped)->mergeInto($second))->toThrow(InvalidMergeException::class)
            ->and(fn () => Tickets::for($inScope)->by($this->scoped)->mergeInto($first))->toThrow(InvalidMergeException::class)
            ->and($first->fresh()?->merged_into_id)->toBeNull()
            ->and($inScope->fresh()?->merged_into_id)->toBeNull();
    });
});

describe('rejects_class_names_as_morph_types', function (): void {
    it('refuses a class name or an unknown alias wherever a type is sent', function (Closure $send, string $code): void {
        $agent = apiAgent();
        $ticket = apiTicket();

        $send($this->actingAs($agent), $ticket->reference)->assertStatus(422)->assertJson(['code' => $code]);
    })->with([
        'requester on create' => [fn ($test) => $test->postJson('/api/tickets', ['subject' => 'x', 'requester' => ['type' => User::class, 'id' => 1]]), 'unknown_requester_type'],
        'requester on update' => [fn ($test, $reference) => $test->patchJson("/api/tickets/{$reference}", ['requester' => ['type' => 'admin', 'id' => 1]]), 'unknown_requester_type'],
        'assignee' => [fn ($test, $reference) => $test->putJson("/api/tickets/{$reference}/assignee", ['assignee' => ['type' => Team::class, 'id' => 1]]), 'unknown_assignee_type'],
        'watcher' => [fn ($test, $reference) => $test->postJson("/api/tickets/{$reference}/watchers", ['watcher' => ['type' => User::class, 'id' => 1]]), 'unknown_watcher_type'],
        'project' => [fn ($test) => $test->postJson('/api/tickets', ['subject' => 'x', 'project' => ['type' => 'App\\Models\\Project', 'id' => 1]]), 'unknown_project_type'],
        'assignee filter' => [fn ($test) => $test->getJson('/api/tickets?filter[assignee]='.urlencode(User::class.':1')), 'unknown_assignee_type'],
        'requester filter' => [fn ($test) => $test->getJson('/api/tickets?filter[requester]=nobody'), 'unknown_requester_type'],
        'project filter' => [fn ($test) => $test->getJson('/api/tickets?filter[project]=team:1'), 'unknown_project_type'],
        'a key that matches no record' => [fn ($test, $reference) => $test->putJson("/api/tickets/{$reference}/assignee", ['assignee' => ['type' => 'user', 'id' => 999]]), 'unknown_assignee_type'],
    ]);
});

describe('guest_link_*', function (): void {
    it('answers every link that does not work with the same 403', function (Closure $link): void {
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
        $ticket = Tickets::make()->subject('A')->requester($guest)->asSystem()->create();
        $other = Tickets::make()->subject('B')->requester($guest)->asSystem()->create();
        $stranger = Tickets::guests()->resolve(['name' => 'Eve', 'email' => 'eve@acme.test']);
        $valid = Tickets::for($ticket)->guestLink();

        $reference = $this->getJson(guestTampered($valid, 'v', '999'));
        $response = $this->getJson($link($ticket, $other, $guest, $stranger, $valid));

        $response->assertForbidden();
        expect($response->getContent())->toBe($reference->getContent())
            ->and($response->json())->toBe(['message' => 'This link is invalid or has expired.', 'code' => 'guest_link_invalid']);
    })->with([
        'ticket A’s link on ticket B' => [fn ($a, $b, $guest, $stranger, $valid) => str_replace('/TCK-1?', '/TCK-2?', $valid)],
        'revoked' => [function ($a, $b, $guest, $stranger, $valid) {
            Tickets::for($a)->asSystem()->revokeGuestAccess();

            return $valid;
        }],
        'expired' => [function ($a, $b, $guest, $stranger, $valid) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(31));

            return $valid;
        }],
        'tampered signature' => [fn ($a, $b, $guest, $stranger, $valid) => guestTampered($valid, 'signature', str_repeat('0', 64))],
        'tampered guest' => [fn ($a, $b, $guest, $stranger, $valid) => guestTampered($valid, 'guest', (string) $stranger->getKey())],
        'tampered version' => [fn ($a, $b, $guest, $stranger, $valid) => guestTampered($valid, 'v', '2')],
        'no expiry' => [fn ($a, $b, $guest, $stranger, $valid) => URL::signedRoute('tickets.guest.show', ['ticket' => $a->reference, 'guest' => $guest->getKey(), 'v' => 1])],
        'signed for a guest who is no recipient' => [fn ($a, $b, $guest, $stranger) => URL::temporarySignedRoute('tickets.guest.show', now()->addDay(), ['ticket' => $a->reference, 'guest' => $stranger->getKey(), 'v' => 1])],
        'a deleted ticket' => [function ($a, $b, $guest, $stranger, $valid) {
            Tickets::for($a)->asSystem()->delete();

            return $valid;
        }],
        'unsigned' => [fn ($a) => '/api/tickets/guest/'.$a->reference.'?guest=1&v=1'],
    ]);

    afterEach(fn () => CarbonImmutable::setTestNow());
});

describe('guest_action_links_are_scoped', function (): void {
    it('lets the embedded action links work for their ticket, guest and version only', function (): void {
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
        $cc = Tickets::guests()->resolve(['name' => 'Cc', 'email' => 'cc@acme.test']);
        $ticket = Tickets::make()->subject('A')->requester($guest)->asSystem()->create();
        $other = Tickets::make()->subject('B')->requester($guest)->asSystem()->create();
        secWatch($ticket, $cc);
        $reply = (string) $this->getJson(Tickets::for($ticket)->guestLink())->json('links.reply');
        $invalid = $this->postJson(guestTampered($reply, 'v', '9'), ['body' => 'Tampered.'])->getContent();

        $this->postJson($reply, ['body' => 'Works.'])->assertCreated();
        $this->postJson(str_replace('/TCK-1/', '/TCK-2/', $reply), ['body' => 'Other ticket.'])->assertForbidden();
        $this->postJson(guestTampered($reply, 'guest', (string) $cc->getKey()), ['body' => 'Other guest.'])->assertForbidden();
        $this->getJson(str_replace('/replies?', '?', $reply))->assertForbidden();

        Tickets::for($ticket)->asSystem()->revokeGuestAccess();
        $replayed = $this->postJson($reply, ['body' => 'Replayed.'])->assertForbidden();

        expect($replayed->getContent())->toBe($invalid)
            ->and($ticket->comments()->pluck('comment')->all())->toBe(['Works.'])
            ->and($other->comments()->count())->toBe(0);
    });
});

describe('requester_cannot_*', function (): void {
    it('refuses the requester every agent action', function (Closure $act, int $status, string $code): void {
        $requester = apiUser();
        $ticket = apiTicket($requester);
        Tickets::fields()->make('root_cause', FieldType::Text)->agentOnly()->define();

        $act($this->actingAs($requester), $ticket->reference, $requester)->assertStatus($status)->assertJson(['code' => $code]);
    })->with([
        'set an agent-only field' => [fn ($test, $reference) => $test->putJson("/api/tickets/{$reference}/fields", ['fields' => ['root_cause' => 'user']]), 422, 'field_not_editable'],
        'set an unknown field' => [fn ($test, $reference) => $test->putJson("/api/tickets/{$reference}/fields", ['fields' => ['nope' => 'x']]), 422, 'unknown_field'],
        'tag' => [fn ($test, $reference) => $test->putJson("/api/tickets/{$reference}/tags", ['tags' => ['vip']]), 403, 'forbidden'],
        'link' => [fn ($test, $reference) => $test->postJson("/api/tickets/{$reference}/links", ['ticket' => apiTicket()->reference, 'type' => 'relates_to']), 403, 'forbidden'],
        'merge' => [fn ($test, $reference, $requester) => $test->postJson("/api/tickets/{$reference}/merge", ['target' => apiTicket($requester)->reference]), 403, 'forbidden'],
        'assign someone else' => [fn ($test, $reference) => $test->putJson("/api/tickets/{$reference}/assignee", ['assignee' => ['type' => 'user', 'id' => apiAgent()->id]]), 403, 'forbidden'],
        'claim' => [fn ($test, $reference) => $test->putJson("/api/tickets/{$reference}/assignee", ['claim' => true]), 403, 'forbidden'],
        'unassign' => [fn ($test, $reference) => $test->deleteJson("/api/tickets/{$reference}/assignee"), 403, 'forbidden'],
        'add a note' => [fn ($test, $reference) => $test->postJson("/api/tickets/{$reference}/notes", ['body' => 'x']), 403, 'forbidden'],
        'run a transition not allowed to requesters' => [fn ($test, $reference) => $test->postJson("/api/tickets/{$reference}/transitions/start"), 403, 'forbidden'],
        'reply with such a transition' => [fn ($test, $reference) => $test->postJson("/api/tickets/{$reference}/replies", ['body' => 'x', 'transition' => 'resolve']), 403, 'forbidden'],
        'change the priority' => [fn ($test, $reference) => $test->patchJson("/api/tickets/{$reference}", ['priority' => 'urgent']), 403, 'forbidden'],
        'file for someone else' => [fn ($test) => $test->postJson('/api/tickets', ['subject' => 'x', 'requester' => ['type' => 'user', 'id' => apiUser('Bob')->id]]), 403, 'forbidden'],
        'file with an assignee' => [fn ($test) => $test->postJson('/api/tickets', ['subject' => 'x', 'assignee' => ['type' => 'user', 'id' => apiAgent()->id]]), 403, 'forbidden'],
        'watch others in' => [fn ($test, $reference) => $test->postJson("/api/tickets/{$reference}/watchers", ['watcher' => ['type' => 'user', 'id' => apiUser('Bob')->id]]), 403, 'forbidden'],
        'list the watchers' => [fn ($test, $reference) => $test->getJson("/api/tickets/{$reference}/watchers"), 403, 'forbidden'],
        'list the links' => [fn ($test, $reference) => $test->getJson("/api/tickets/{$reference}/links"), 403, 'forbidden'],
        'delete' => [fn ($test, $reference) => $test->deleteJson("/api/tickets/{$reference}"), 403, 'forbidden'],
        'manage categories' => [fn ($test) => $test->postJson('/api/ticket-categories', ['name' => 'x']), 403, 'forbidden'],
        'list the tags' => [fn ($test) => $test->getJson('/api/ticket-tags'), 403, 'forbidden'],
    ]);

    it('lets the requester do what is theirs', function (): void {
        $requester = apiUser();
        $ticket = apiTicket($requester);
        Tickets::fields()->make('asset_tag', FieldType::Text)->requesterEditable()->define();

        $this->actingAs($requester)->patchJson("/api/tickets/{$ticket->reference}", ['subject' => 'Printer on fire'])->assertOk()->assertJsonPath('data.subject', 'Printer on fire');
        $this->actingAs($requester)->putJson("/api/tickets/{$ticket->reference}/fields", ['fields' => ['asset_tag' => 'PRN-1']])->assertOk()->assertJsonPath('data.0.value', 'PRN-1');
        $this->actingAs($requester)->postJson("/api/tickets/{$ticket->reference}/watch")->assertNoContent();
        Tickets::for($ticket)->by(apiAgent())->resolve();
        $this->actingAs($requester)->postJson("/api/tickets/{$ticket->reference}/transitions/close")->assertOk()->assertJsonPath('data.status.value', 'closed');
    });
});

describe('comment_scope_is_enforced', function (): void {
    it('finds no comment of another ticket, for an agent either', function (string $method): void {
        $agent = apiAgent();
        $a = apiTicket();
        $b = apiTicket();
        $comment = Tickets::for($b)->by($agent)->reply('On B.');

        $this->actingAs($agent)->json($method, "/api/tickets/{$a->reference}/comments/{$comment->getKey()}", ['body' => 'Hijacked.'])
            ->assertNotFound()->assertJson(['code' => 'comment_not_found']);

        expect($comment->fresh()?->comment)->toBe('On B.');
    })->with(['PATCH', 'DELETE']);
});

describe('attachment_scope_is_enforced', function (): void {
    it('finds no file of another ticket', function (string $method): void {
        Storage::fake('local');
        $agent = apiAgent();
        $a = apiTicket();
        $b = apiTicket();
        $file = Tickets::for($b)->by($agent)->attachments()->add(UploadedFile::fake()->createWithContent('b.txt', 'Some plain text on B.'));

        $this->actingAs($agent)->json($method, "/api/tickets/{$a->reference}/attachments/{$file->uuid}")
            ->assertNotFound()->assertJson(['code' => 'attachment_not_found']);

        expect($file->fresh())->not->toBeNull();
    })->with(['GET', 'DELETE']);

    it('finds no file of another ticket through a guest link either', function (): void {
        Storage::fake('local');
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
        $a = Tickets::make()->subject('A')->requester($guest)->asSystem()->create();
        $b = apiTicket();
        $file = Tickets::for($b)->asSystem()->attachments()->add(UploadedFile::fake()->createWithContent('b.txt', 'Some plain text on B.'));
        $url = URL::temporarySignedRoute('tickets.guest.attachment', now()->addDay(), ['ticket' => $a->reference, 'media' => $file->uuid, 'guest' => $guest->getKey(), 'v' => 1]);

        $this->getJson($url)->assertNotFound()->assertJson(['code' => 'attachment_not_found']);
    });
});

describe('guest_endpoints_are_throttled', function (): void {
    it('limits a guest per IP and ticket', function (): void {
        config()->set('tickets.routes.guest.rate_limit', 3);
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
        $ticket = Tickets::make()->subject('A')->requester($guest)->asSystem()->create();
        $other = Tickets::make()->subject('B')->requester($guest)->asSystem()->create();
        $link = Tickets::for($ticket)->guestLink();

        foreach (range(1, 3) as $attempt) {
            $this->getJson($link)->assertOk();
        }

        $this->getJson($link)->assertStatus(429);
        $this->getJson(guestTampered($link, 'v', '9'))->assertStatus(429);
        $this->getJson(Tickets::for($other)->guestLink())->assertOk();
    });

    it('limits the signed-in API per user', function (): void {
        config()->set('tickets.routes.rate_limit', 2);
        $user = apiUser();
        $other = apiUser('Bob');

        $this->actingAs($user)->getJson('/api/tickets')->assertOk();
        $this->actingAs($user)->getJson('/api/tickets')->assertOk();
        $this->actingAs($user)->getJson('/api/tickets')->assertStatus(429);
        $this->actingAs($other)->getJson('/api/tickets')->assertOk();
    });
});

describe('search_escapes_like_wildcards', function (): void {
    it('matches % and _ literally', function (string $term, array $expected): void {
        $agent = apiAgent();

        foreach (['Disk 100% full', 'Disk 1000 full', 'error a_b', 'error axb'] as $subject) {
            apiTicket(subject: $subject);
        }

        $found = collect($this->actingAs($agent)->getJson('/api/tickets?filter[search]='.urlencode($term))->assertOk()->json('data'))->pluck('subject')->sort()->values()->all();

        expect($found)->toBe($expected);
    })->with([
        'percent' => ['100%', ['Disk 100% full']],
        'underscore' => ['a_b', ['error a_b']],
        'plain' => ['disk', ['Disk 100% full', 'Disk 1000 full']],
        'a reference' => ['tck-3', ['error a_b']],
    ]);
});

describe('guest_pii_is_agent_only', function (): void {
    it('shows a guest’s fields to agents only, and their own name to the guest', function (): void {
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test', 'phone' => '+421 900 000 000']);
        $ticket = Tickets::make()->subject('A')->requester($guest)->asSystem()->create();
        $watcher = apiUser('Cc');
        secWatch($ticket, $watcher);
        $agent = apiAgent();
        Tickets::for($ticket)->by($guest)->reply('From Jo.');

        $asAgent = $this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}")->json('data.requester');
        $asWatcher = $this->actingAs($watcher)->getJson("/api/tickets/{$ticket->reference}");
        $asWatcherThread = $this->actingAs($watcher)->getJson("/api/tickets/{$ticket->reference}/comments");
        $asGuest = $this->getJson(Tickets::for($ticket)->guestLink());

        expect($asAgent)->toMatchArray(['type' => 'guest', 'name' => 'Jo', 'guest' => ['name' => 'Jo', 'email' => 'jo@acme.test', 'phone' => '+421900000000']])
            ->and($asWatcher->json('data.requester'))->toBe(['type' => 'guest'])
            ->and($asWatcherThread->json('data.0.author'))->toBe(['type' => 'guest', 'name' => null])
            ->and($asGuest->json('data.requester'))->toBe(['type' => 'guest', 'name' => 'Jo'])
            // The phone's subscriber digits, stored and as entered — never a bare '900', which the
            // guest link's `expires` timestamp carries now and then.
            ->and((string) $asWatcher->getContent().$asWatcherThread->getContent().$asGuest->getContent())->not->toContain('jo@acme.test')->not->toContain('900000000')->not->toContain('900 000 000');

        Artisan::call('about', ['--only' => 'tickets']);
        expect(Artisan::output())->toContain('Routes')->not->toContain('jo@acme.test')->not->toContain('Jo');
    });

    it('answers a self-watch with the watcher’s own entry, never the agent-only list', function (): void {
        $requester = apiUser();
        $agent = apiAgent();
        $ticket = apiTicket($requester);
        $cc = Tickets::guests()->resolve(['name' => 'Jo Guest', 'email' => 'jo@acme.test', 'phone' => '+421900000000']);
        secWatch($ticket, $cc);
        secWatch($ticket, $agent);

        $self = $this->actingAs($requester)->postJson("/api/tickets/{$ticket->reference}/watchers", ['watcher' => ['type' => 'user', 'id' => $requester->id]]);

        $self->assertCreated();
        expect($self->json('data'))->toBe([['type' => 'user', 'name' => 'Ada']])
            ->and((string) $self->getContent())->not->toContain('jo@acme.test')->not->toContain('900000000')->not->toContain('Grace');

        // An agent on the ticket keeps the whole list.
        $this->actingAs($agent)->postJson("/api/tickets/{$ticket->reference}/watchers", ['watcher' => ['type' => 'user', 'id' => $agent->id]])
            ->assertCreated()->assertJsonCount(3, 'data')->assertJsonPath('data.1.guest.email', 'jo@acme.test');
    });

    it('renders TicketGuestResource by viewer', function (): void {
        $guest = TicketGuest::factory()->create();
        Tickets::guests()->update($guest, ['name' => 'Jo', 'email' => 'jo@acme.test']);
        $agent = apiAgent();
        $user = apiUser();

        $render = function (Model $viewer) use ($guest): array {
            $request = Request::create('/');
            Viewer::{$viewer instanceof TicketGuest ? 'guest' : 'user'}($viewer)->attach($request);

            return (new TicketGuestResource($guest->fresh()))->toArray($request);
        };

        expect($render($agent))->toMatchArray(['type' => 'guest', 'name' => 'Jo', 'fields' => ['name' => 'Jo', 'email' => 'jo@acme.test', 'phone' => null]])
            ->and($render($user))->toBe(['type' => 'guest', 'name' => null])
            ->and($render($guest->fresh() ?? $guest))->toBe(['type' => 'guest', 'name' => 'Jo']);
    });
});

describe('untrusted record keys', function (): void {
    it('answers a key that cannot be a record key as an unknown record, never a database error', function (Closure $send, int $status): void {
        $agent = apiAgent();
        $ticket = apiTicket();

        $send($this->actingAs($agent), $ticket->reference)->assertStatus($status);
    })->with([
        'text for an integer assignee key' => [fn ($test, $reference) => $test->putJson("/api/tickets/{$reference}/assignee", ['assignee' => ['type' => 'user', 'id' => 'abc']]), 422],
        'an out-of-range assignee key' => [fn ($test, $reference) => $test->putJson("/api/tickets/{$reference}/assignee", ['assignee' => ['type' => 'user', 'id' => '99999999999999999999']]), 422],
        'a malformed guest key' => [fn ($test) => $test->postJson('/api/tickets', ['subject' => 'x', 'requester' => ['type' => 'guest', 'id' => 'not-an-id']]), 422],
        'text in an assignee filter' => [fn ($test) => $test->getJson('/api/tickets?filter[assignee]=user:abc'), 200],
        'text in a requester filter' => [fn ($test) => $test->getJson('/api/tickets?filter[requester]=guest:abc'), 200],
        'text in a watcher path' => [fn ($test, $reference) => $test->deleteJson("/api/tickets/{$reference}/watchers/user:abc"), 422],
    ]);
});
