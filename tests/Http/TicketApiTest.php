<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketLink;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

beforeEach(fn () => apiAbilities());

describe('tickets', function (): void {
    it('files a ticket for the signed-in user', function (): void {
        $user = apiUser();
        $support = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
        $category = TicketCategory::factory()->create();
        Tickets::fields()->make('asset_tag', FieldType::Text)->requesterEditable()->define();

        $response = $this->actingAs($user)->postJson('/api/tickets', [
            'subject' => 'Printer jammed',
            'description' => 'E-102',
            'category_id' => $category->id,
            'priority' => 'high',
            'project' => ['type' => 'project', 'id' => $support->id],
            'channel' => 'web',
            'fields' => ['asset_tag' => 'PRN-1'],
        ])->assertCreated();

        expect($response->json('data'))->toMatchArray([
            'reference' => 'SUP-1',
            'subject' => 'Printer jammed',
            'description' => 'E-102',
            'priority' => ['value' => 'high', 'label' => 'High', 'weight' => 30],
            'category' => ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug],
            'project' => ['type' => 'project', 'id' => $support->id],
            'requester' => ['type' => 'user', 'name' => 'Ada'],
            'channel' => 'web',
        ])->and($response->json('data.fields.0'))->toMatchArray(['key' => 'asset_tag', 'value' => 'PRN-1'])
            ->and(Ticket::query()->sole()->requester_id)->toBe($user->id);
    });

    it('lets an agent file for a user or a guest, assign it, date it, tag it and add watchers', function (): void {
        Storage::fake('local');
        $agent = apiAgent();
        $user = apiUser();
        $due = CarbonImmutable::now()->addDay()->startOfSecond();

        $forUser = $this->actingAs($agent)->postJson('/api/tickets', [
            'subject' => 'For Ada',
            'requester' => ['type' => 'user', 'id' => $user->id],
            'assignee' => ['type' => 'user', 'id' => $agent->id],
            'due_at' => $due->toIso8601String(),
            'tags' => ['printer'],
            'watchers' => [['type' => 'user', 'id' => apiUser('Cc')->id]],
            'attachments' => [UploadedFile::fake()->createWithContent('a.txt', 'Some plain text.')],
        ])->assertCreated();
        $forGuest = $this->actingAs($agent)->postJson('/api/tickets', [
            'subject' => 'For Jo',
            'guest' => ['name' => 'Jo', 'email' => 'jo@acme.test'],
        ])->assertCreated();

        expect($forUser->json('data.requester'))->toMatchArray(['type' => 'user', 'id' => $user->id, 'name' => 'Ada'])
            ->and($forUser->json('data.assignee'))->toBe(['type' => 'user', 'id' => $agent->id, 'name' => 'Grace'])
            ->and($forUser->json('data.status.value'))->toBe('open')
            ->and($forUser->json('data.due_at'))->toBe($due->toIso8601String())
            ->and($forUser->json('data.tags.0.name'))->toBe('printer')
            ->and($forUser->json('data.attachments'))->toHaveCount(1)
            ->and($forUser->json('data.attachments.0.url'))->toBeString()
            ->and(Ticket::query()->where('reference', 'TCK-1')->sole()->watchers()->count())->toBe(3)
            ->and($forGuest->json('data.requester'))->toMatchArray(['type' => 'guest', 'name' => 'Jo', 'guest' => ['name' => 'Jo', 'email' => 'jo@acme.test', 'phone' => null]])
            ->and(TicketGuest::query()->count())->toBe(1);
    });

    it('refuses an agent without the assign ability an assignee other than themselves', function (): void {
        $junior = User::query()->create(['name' => 'Junior', 'is_agent' => true]);

        $this->actingAs($junior)->postJson('/api/tickets', ['subject' => 'x', 'assignee' => ['type' => 'user', 'id' => apiAgent()->id]])->assertForbidden();
        $this->actingAs($junior)->postJson('/api/tickets', ['subject' => 'x', 'assignee' => ['type' => 'user', 'id' => $junior->id]])->assertCreated();
    });

    it('validates the payload', function (array $payload, string $error): void {
        $this->actingAs(apiAgent())->postJson('/api/tickets', $payload)->assertUnprocessable()->assertJsonValidationErrors($error);
    })->with([
        'no subject' => [[], 'subject'],
        'a past due date' => [['subject' => 'x', 'due_at' => '2000-01-01'], 'due_at'],
        'guest and requester' => [['subject' => 'x', 'guest' => ['name' => 'Jo'], 'requester' => ['type' => 'user', 'id' => 1]], 'guest'],
        'a guest field that is not a value' => [['subject' => 'x', 'guest' => ['name' => ['Jo']]], 'guest.name'],
        'a morph key that is no key' => [['subject' => 'x', 'requester' => ['type' => 'user', 'id' => ['x']]], 'requester.id'],
        'a watcher without a type' => [['subject' => 'x', 'watchers' => [['id' => 1]]], 'watchers.0.type'],
    ]);

    it('answers 401 without a user', function (): void {
        $this->getJson('/api/tickets')->assertUnauthorized();
    });

    it('shows, updates, deletes and restores a ticket', function (): void {
        $agent = apiAgent();
        $admin = apiAdmin();
        $ticket = apiTicket();
        $category = TicketCategory::factory()->create();
        $other = apiUser('Bob');

        $this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}")->assertOk()->assertJsonPath('data.reference', 'TCK-1');

        $updated = $this->actingAs($agent)->patchJson("/api/tickets/{$ticket->reference}", [
            'subject' => 'Printer on fire',
            'description' => null,
            'priority' => 'urgent',
            'category_id' => $category->id,
            'due_at' => CarbonImmutable::now()->addDay()->toIso8601String(),
            'requester' => ['type' => 'user', 'id' => $other->id],
        ])->assertOk();

        expect($updated->json('data'))->toMatchArray(['subject' => 'Printer on fire', 'description' => null])
            ->and($updated->json('data.priority.value'))->toBe('urgent')
            ->and($updated->json('data.category.id'))->toBe($category->id)
            ->and($updated->json('data.requester.id'))->toBe($other->id);

        $this->actingAs($agent)->patchJson("/api/tickets/{$ticket->reference}", ['category_id' => null, 'due_at' => null])->assertOk()
            ->assertJsonPath('data.category', null)->assertJsonPath('data.due_at', null);

        $this->actingAs($admin)->deleteJson("/api/tickets/{$ticket->reference}")->assertNoContent();
        $this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}")->assertNotFound()->assertJson(['code' => 'ticket_not_found']);
        $this->actingAs($admin)->postJson("/api/tickets/{$ticket->reference}/restore")->assertOk()->assertJsonPath('data.reference', 'TCK-1');
    });

    it('tells a stranger a ticket they may not see does not exist', function (): void {
        $ticket = apiTicket();
        $stranger = apiUser('Eve');

        $hidden = $this->actingAs($stranger)->getJson("/api/tickets/{$ticket->reference}")->assertNotFound();
        $missing = $this->actingAs($stranger)->getJson('/api/tickets/TCK-404')->assertNotFound();

        expect($hidden->json('code'))->toBe($missing->json('code'))->toBe('ticket_not_found');
    });

    it('maps a domain refusal to its status and code', function (): void {
        $agent = apiAgent();
        $ticket = apiTicket();

        $this->actingAs($agent)->patchJson("/api/tickets/{$ticket->reference}", ['category_id' => 999])->assertUnprocessable()->assertJson(['code' => 'invalid_category']);
        $this->actingAs($agent)->postJson('/api/tickets', ['subject' => 'x', 'priority' => 'meh'])->assertUnprocessable()->assertJsonValidationErrors('priority');
    });
});

describe('transitions', function (): void {
    it('lists what the viewer may run and runs it', function (): void {
        $agent = apiAgent();
        $requester = apiUser();
        $ticket = apiTicket($requester);

        $names = collect($this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}/transitions")->assertOk()->json('data'))->pluck('name')->all();
        $requesterNames = collect($this->actingAs($requester)->getJson("/api/tickets/{$ticket->reference}/transitions")->json('data'))->pluck('name')->all();

        expect($names)->toBe(['open', 'start', 'wait', 'hold', 'resolve', 'dismiss'])
            ->and($requesterNames)->toBe([]);

        $this->actingAs($agent)->postJson("/api/tickets/{$ticket->reference}/transitions/resolve", ['payload' => ['resolution' => 'Unjammed']])
            ->assertOk()->assertJsonPath('data.status.value', 'resolved');

        expect(collect($this->actingAs($requester)->getJson("/api/tickets/{$ticket->reference}/transitions")->json('data'))->pluck('name')->all())->toBe(['reopen', 'close']);
    });

    it('answers a refused transition with its denials', function (): void {
        $agent = apiAgent();
        $ticket = apiTicket();

        $this->actingAs($agent)->postJson("/api/tickets/{$ticket->reference}/transitions/hold")
            ->assertUnprocessable()
            ->assertJson(['code' => 'ticket_transition_denied'])
            ->assertJsonPath('denials.0.code', 'reason_required');
        $this->actingAs($agent)->postJson("/api/tickets/{$ticket->reference}/transitions/teleport")
            ->assertUnprocessable()->assertJson(['code' => 'unknown_transition']);
    });
});

describe('assignment', function (): void {
    it('assigns, claims and unassigns', function (): void {
        $admin = apiAdmin();
        $agent = apiAgent();
        $team = Team::query()->create(['name' => 'Hardware']);
        $ticket = apiTicket();

        $this->actingAs($admin)->putJson("/api/tickets/{$ticket->reference}/assignee", ['assignee' => ['type' => 'team', 'id' => $team->id]])
            ->assertOk()->assertJsonPath('data.assignee', ['type' => 'team', 'id' => $team->id, 'name' => 'Hardware']);
        $this->actingAs($agent)->putJson("/api/tickets/{$ticket->reference}/assignee", ['claim' => true])
            ->assertOk()->assertJsonPath('data.assignee.id', $agent->id);
        $this->actingAs($agent)->deleteJson("/api/tickets/{$ticket->reference}/assignee")->assertOk()->assertJsonPath('data.assignee', null);
    });

    it('refuses an ineligible assignee', function (): void {
        $ticket = apiTicket();

        $this->actingAs(apiAdmin())->putJson("/api/tickets/{$ticket->reference}/assignee", ['assignee' => ['type' => 'user', 'id' => apiUser('Bob')->id]])
            ->assertUnprocessable()->assertJson(['code' => 'invalid_assignee']);
    });

    it('needs an assignee or a claim', function (): void {
        $this->actingAs(apiAgent())->putJson('/api/tickets/'.apiTicket()->reference.'/assignee', [])->assertUnprocessable()->assertJsonValidationErrors('assignee');
    });
});

describe('conversation', function (): void {
    it('posts replies and notes, with files, and edits and deletes them', function (): void {
        Storage::fake('local');
        $agent = apiAgent();
        $requester = apiUser();
        $ticket = apiTicket($requester);

        $reply = $this->actingAs($requester)->postJson("/api/tickets/{$ticket->reference}/replies", [
            'body' => 'Still jammed.',
            'attachments' => [UploadedFile::fake()->createWithContent('photo.txt', 'Some plain text.')],
        ])->assertCreated();
        $note = $this->actingAs($agent)->postJson("/api/tickets/{$ticket->reference}/notes", ['body' => 'Check the drum.', 'channel' => 'phone'])->assertCreated();
        $answer = $this->actingAs($agent)->postJson("/api/tickets/{$ticket->reference}/replies", ['body' => 'Waiting on you.', 'transition' => 'wait'])->assertCreated();

        expect($reply->json('data'))->toMatchArray(['body' => 'Still jammed.', 'role' => 'requester', 'channel' => 'api'])
            ->and($reply->json('data.attachments.0.name'))->toBe('photo.txt')
            ->and($note->json('data'))->toMatchArray(['body' => 'Check the drum.', 'role' => 'agent', 'internal' => true, 'channel' => 'phone'])
            ->and($answer->json('data.role'))->toBe('agent')
            ->and($ticket->fresh()?->status?->value)->toBe('pending');

        $this->actingAs($requester)->patchJson("/api/tickets/{$ticket->reference}/comments/{$reply->json('data.id')}", ['body' => 'Still jammed!'])
            ->assertOk()->assertJsonPath('data.body', 'Still jammed!');
        $this->actingAs($agent)->patchJson("/api/tickets/{$ticket->reference}/comments/{$note->json('data.id')}", ['body' => 'Check the fuser.'])
            ->assertOk()->assertJsonPath('data.internal', true);
        $this->actingAs($requester)->patchJson("/api/tickets/{$ticket->reference}/comments/{$answer->json('data.id')}", ['body' => 'Hijack.'])->assertForbidden();
        $this->actingAs($requester)->deleteJson("/api/tickets/{$ticket->reference}/comments/{$reply->json('data.id')}")->assertNoContent();
        $this->actingAs($agent)->patchJson("/api/tickets/{$ticket->reference}/comments/abc", ['body' => 'x'])->assertNotFound();

        expect(collect($this->actingAs($requester)->getJson("/api/tickets/{$ticket->reference}/comments")->json('data'))->pluck('body')->all())->toBe(['Waiting on you.']);
    });

    it('answers a message on a closed ticket with 409 ticket_closed', function (): void {
        $requester = apiUser();
        $ticket = apiTicket($requester);
        Tickets::for($ticket)->asSystem()->dismiss('Spam');

        $this->actingAs($requester)->postJson("/api/tickets/{$ticket->reference}/replies", ['body' => 'Hello?'])->assertStatus(409)->assertJson(['code' => 'ticket_closed']);
        $this->actingAs(apiAgent())->postJson("/api/tickets/{$ticket->reference}/notes", ['body' => 'x'])->assertStatus(409)->assertJson(['code' => 'ticket_closed']);
    });

    it('answers a reply the host blocklist rejects with 422 comment_rejected', function (): void {
        config()->set('comments.blocklist', ['viagra']);
        config()->set('comments.blocklist_action', 'reject');
        $requester = apiUser();

        $this->actingAs($requester)->postJson('/api/tickets/'.apiTicket($requester)->reference.'/replies', ['body' => 'Buy viagra'])
            ->assertUnprocessable()->assertJson(['code' => 'comment_rejected']);
    });
});

describe('custom field values', function (): void {
    it('reads every field for an agent and the visible ones for the requester', function (): void {
        $agent = apiAgent();
        $requester = apiUser();
        $ticket = apiTicket($requester);
        Tickets::fields()->make('asset_tag', FieldType::Text)->label(['en' => 'Asset tag'])->define();
        Tickets::fields()->make('root_cause', FieldType::Text)->agentOnly()->define();
        Tickets::for($ticket)->asSystem()->fields()->setMany(['asset_tag' => 'PRN-1', 'root_cause' => 'user']);

        $this->actingAs($agent)->putJson("/api/tickets/{$ticket->reference}/fields", ['fields' => ['root_cause' => 'hardware']])->assertOk();

        expect($this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}/fields")->json('data'))->toBe([
            ['key' => 'asset_tag', 'label' => 'Asset tag', 'type' => 'text', 'value' => 'PRN-1'],
            ['key' => 'root_cause', 'label' => 'Root Cause', 'type' => 'text', 'value' => 'hardware'],
        ])->and($this->actingAs($requester)->getJson("/api/tickets/{$ticket->reference}/fields")->json('data'))->toBe([
            ['key' => 'asset_tag', 'label' => 'Asset tag', 'type' => 'text', 'value' => 'PRN-1'],
        ]);
    });
});

describe('watchers, tags, links, merge, attachments, activity', function (): void {
    it('manages watchers', function (): void {
        $agent = apiAgent();
        $cc = apiUser('Cc');
        $ticket = apiTicket();

        $this->actingAs($agent)->postJson("/api/tickets/{$ticket->reference}/watchers", ['watcher' => ['type' => 'user', 'id' => $cc->id]])
            ->assertCreated()->assertJsonCount(2, 'data');
        $this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}/watchers")->assertOk()->assertJsonPath('data.1', ['type' => 'user', 'id' => $cc->id, 'name' => 'Cc']);
        $this->actingAs($agent)->deleteJson("/api/tickets/{$ticket->reference}/watchers/user:{$cc->id}")->assertNoContent();
        // Unwatched, a CC'd colleague can no longer see the ticket — so cannot watch it again.
        $this->actingAs($cc)->postJson("/api/tickets/{$ticket->reference}/watch")->assertForbidden();

        $requester = User::query()->where('name', 'Ada')->firstOrFail();
        $this->actingAs($requester)->deleteJson("/api/tickets/{$ticket->reference}/watch")->assertNoContent();
        expect($ticket->watchers()->count())->toBe(0);
        $this->actingAs($requester)->postJson("/api/tickets/{$ticket->reference}/watch")->assertNoContent();

        expect($ticket->watchers()->count())->toBe(1);
    });

    it('syncs tags', function (): void {
        $agent = apiAgent();
        $ticket = apiTicket();

        $this->actingAs($agent)->putJson("/api/tickets/{$ticket->reference}/tags", ['tags' => ['printer', 'floor-3']])
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($agent)->putJson("/api/tickets/{$ticket->reference}/tags", ['tags' => []])->assertOk()->assertJsonCount(0, 'data');
    });

    it('links tickets and unlinks them from either end', function (): void {
        $agent = apiAgent();
        $a = apiTicket();
        $b = apiTicket();

        $this->actingAs($agent)->postJson("/api/tickets/{$a->reference}/links", ['ticket' => $b->reference, 'type' => 'blocks'])
            ->assertCreated()->assertJsonPath('data.0', ['id' => 1, 'type' => 'blocks', 'label' => 'blocks', 'direction' => 'outgoing', 'ticket' => ['reference' => 'TCK-2', 'subject' => 'Printer jammed', 'status' => ['value' => 'new', 'label' => 'New', 'category' => 'open']]]);
        $this->actingAs($agent)->getJson("/api/tickets/{$b->reference}/links")->assertJsonPath('data.0.label', 'blocked_by');
        $this->actingAs($agent)->postJson("/api/tickets/{$b->reference}/links", ['ticket' => $a->reference, 'type' => 'blocks'])->assertUnprocessable()->assertJson(['code' => 'invalid_link']);
        $this->actingAs($agent)->deleteJson("/api/tickets/{$b->reference}/links/1")->assertNoContent();

        expect(TicketLink::query()->count())->toBe(0);
        $this->actingAs($agent)->deleteJson("/api/tickets/{$a->reference}/links/1")->assertNotFound()->assertJson(['code' => 'link_not_found']);
    });

    it('merges a ticket into another', function (): void {
        $agent = apiAgent();
        $source = apiTicket();
        $target = apiTicket();

        $response = $this->actingAs($agent)->postJson("/api/tickets/{$source->reference}/merge", ['target' => $target->reference, 'reason' => 'Same jam'])->assertOk();

        expect($response->json('source.merged_into'))->toBe('TCK-2')
            ->and($response->json('source.status.value'))->toBe('closed')
            ->and($response->json('moved'))->toBe(['comments' => 0, 'attachments' => 0]);
        $this->actingAs($agent)->postJson("/api/tickets/{$target->reference}/merge", ['target' => $target->reference])->assertUnprocessable()->assertJson(['code' => 'invalid_merge']);
    });

    it('adds, lists, shows and removes attachments', function (): void {
        Storage::fake('local');
        $agent = apiAgent();
        $requester = apiUser();
        $ticket = apiTicket($requester);

        $added = $this->actingAs($agent)->postJson("/api/tickets/{$ticket->reference}/attachments", ['file' => UploadedFile::fake()->createWithContent('log.txt', 'Some plain text.')])->assertCreated();
        $uuid = $added->json('data.id');

        $this->actingAs($requester)->getJson("/api/tickets/{$ticket->reference}/attachments")->assertOk()->assertJsonPath('data.0.id', $uuid);
        $this->actingAs($requester)->getJson("/api/tickets/{$ticket->reference}/attachments/{$uuid}")->assertOk()->assertJsonPath('data.name', 'log.txt');
        $this->actingAs($requester)->deleteJson("/api/tickets/{$ticket->reference}/attachments/{$uuid}")->assertForbidden();
        $this->actingAs($agent)->deleteJson("/api/tickets/{$ticket->reference}/attachments/{$uuid}")->assertNoContent();
        $this->actingAs($agent)->postJson("/api/tickets/{$ticket->reference}/attachments", [])->assertUnprocessable()->assertJsonValidationErrors('file');
    });

    it('shows the timeline — all of it to agents, the public part as aliases to the requester', function (): void {
        $agent = apiAgent();
        $requester = apiUser();
        $ticket = apiTicket($requester);
        Tickets::for($ticket)->by($agent)->claim()->note('x');

        $forAgent = $this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}/activity")->assertOk()->json('data');
        $forRequester = $this->actingAs($requester)->getJson("/api/tickets/{$ticket->reference}/activity")->json('data');

        expect(collect($forAgent)->pluck('type')->all())->toBe(['created', 'assigned', 'status_changed', 'note_added'])
            ->and(collect($forRequester)->pluck('type')->all())->toBe(['created', 'assigned', 'status_changed'])
            ->and(collect($forRequester)->firstWhere('type', 'assigned')['properties'])->toBe(['to' => ['type' => 'user']])
            ->and(collect($forAgent)->firstWhere('type', 'assigned')['properties']['to']['id'])->toBe($agent->id);

        config()->set('tickets.activity.enabled', 'off');
        $this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}/activity")->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('admin endpoints', function (): void {
    it('reads and writes the category tree', function (): void {
        $admin = apiAdmin();
        $user = apiUser();
        $support = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);

        $root = $this->actingAs($admin)->postJson('/api/ticket-categories', [
            'name' => ['en' => 'Hardware', 'sk' => 'Hardvér'],
            'description' => 'Laptops',
            'default_priority' => 'high',
            'default_assignee' => ['type' => 'user', 'id' => $admin->id],
            'default_due_in' => 'P1D',
        ])->assertCreated();
        $child = $this->actingAs($admin)->postJson('/api/ticket-categories', ['name' => 'Printers', 'parent_id' => $root->json('data.id'), 'project' => ['type' => 'project', 'id' => $support->id]])->assertCreated();

        expect($root->json('data.defaults'))->toBe(['priority' => 'high', 'assignee' => ['type' => 'user', 'id' => $admin->id, 'name' => 'Admin'], 'due_in' => 'P1D'])
            ->and($root->json('data.translations.name'))->toBe(['en' => 'Hardware', 'sk' => 'Hardvér'])
            ->and($this->actingAs($user)->getJson('/api/ticket-categories')->json('data.0.children'))->toBe([])
            ->and($this->actingAs($user)->getJson('/api/ticket-categories?project=project:'.$support->id)->json('data.0.children.0.name'))->toBe('Printers')
            ->and($this->actingAs($user)->getJson('/api/ticket-categories/'.$root->json('data.id'))->json('data'))->not->toHaveKey('defaults');

        $this->actingAs($admin)->patchJson('/api/ticket-categories/'.$child->json('data.id'), ['name' => 'Printing', 'position' => 3, 'is_active' => false, 'default_assignee' => null])
            ->assertOk()->assertJsonPath('data.name', 'Printing')->assertJsonPath('data.is_active', false);
        $this->actingAs($admin)->postJson('/api/ticket-categories/'.$child->json('data.id').'/move', ['parent_id' => null])->assertOk()->assertJsonPath('data.parent_id', null);
        $this->actingAs($admin)->postJson('/api/ticket-categories/'.$root->json('data.id').'/move', ['parent_id' => $root->json('data.id')])->assertUnprocessable()->assertJson(['code' => 'invalid_category']);
        $this->actingAs($admin)->deleteJson('/api/ticket-categories/'.$child->json('data.id'))->assertNoContent();
        $this->actingAs($admin)->getJson('/api/ticket-categories/999')->assertNotFound()->assertJson(['code' => 'category_not_found']);
    });

    it('reads and writes field definitions', function (): void {
        $admin = apiAdmin();
        $user = apiUser();
        $category = TicketCategory::factory()->create();

        $field = $this->actingAs($admin)->postJson('/api/ticket-fields', [
            'key' => 'root_cause', 'type' => 'select', 'label' => ['en' => 'Root cause'],
            'options' => ['hardware' => ['en' => 'Hardware'], 'user' => 'User error'],
            'requester_visible' => false, 'category_id' => $category->id, 'rules' => [],
        ])->assertCreated();
        $this->actingAs($admin)->postJson('/api/ticket-fields', ['key' => 'asset_tag', 'type' => 'text', 'label' => 'Asset tag', 'rules' => ['max:32']])->assertCreated();

        expect($field->json('data.options'))->toEqualCanonicalizing([['value' => 'hardware', 'label' => 'Hardware'], ['value' => 'user', 'label' => 'User error']])
            ->and(collect($this->actingAs($admin)->getJson('/api/ticket-fields')->json('data'))->pluck('key')->all())->toBe(['root_cause', 'asset_tag'])
            ->and(collect($this->actingAs($user)->getJson('/api/ticket-fields')->json('data'))->pluck('key')->all())->toBe(['asset_tag'])
            ->and(collect($this->actingAs($admin)->getJson('/api/ticket-fields?category='.$category->id)->json('data'))->pluck('key')->all())->toBe(['root_cause', 'asset_tag'])
            ->and($this->actingAs($user)->getJson('/api/ticket-fields?category='.$category->id)->json('data.0'))->not->toHaveKey('rules');

        $this->actingAs($user)->getJson('/api/ticket-fields/'.$field->json('data.id'))->assertNotFound()->assertJson(['code' => 'field_not_found']);
        $this->actingAs($admin)->getJson('/api/ticket-fields/'.$field->json('data.id'))->assertOk()->assertJsonPath('data.requester_visible', false);
        $this->actingAs($admin)->patchJson('/api/ticket-fields/'.$field->json('data.id'), ['label' => 'Cause', 'position' => 2, 'required' => true])->assertOk()->assertJsonPath('data.label', 'Cause');
        $this->actingAs($admin)->postJson('/api/ticket-fields', ['key' => 'Bad Key', 'type' => 'text', 'label' => 'x'])->assertUnprocessable()->assertJson(['code' => 'invalid_field']);
        $this->actingAs($admin)->getJson('/api/ticket-fields?category=999')->assertNotFound();
        $this->actingAs($admin)->deleteJson('/api/ticket-fields/'.$field->json('data.id'))->assertNoContent();

        expect(TicketField::query()->count())->toBe(1);
    });

    it('reads and writes tags', function (): void {
        $admin = apiAdmin();
        $agent = apiAgent();

        $tag = $this->actingAs($admin)->postJson('/api/ticket-tags', ['name' => 'printer', 'color' => '#f24e29'])->assertCreated();
        $this->actingAs($agent)->getJson('/api/ticket-tags')->assertOk()->assertJsonPath('data.0.slug', 'printer');
        $this->actingAs($admin)->patchJson('/api/ticket-tags/'.$tag->json('data.id'), ['name' => 'printers', 'color' => null])->assertOk()->assertJsonPath('data.color', null);
        $this->actingAs($admin)->postJson('/api/ticket-tags', ['name' => 'x', 'color' => 'red'])->assertUnprocessable()->assertJson(['code' => 'invalid_tag']);
        $this->actingAs($admin)->deleteJson('/api/ticket-tags/'.$tag->json('data.id'))->assertNoContent();
        $this->actingAs($admin)->deleteJson('/api/ticket-tags/999')->assertNotFound()->assertJson(['code' => 'tag_not_found']);

        expect(TicketTag::query()->count())->toBe(0);
    });
});

describe('the fake', function (): void {
    it('records API calls, which go through the manager', function (): void {
        $fake = Tickets::fake();
        $agent = apiAgent();

        $this->actingAs($agent)->postJson('/api/tickets', ['subject' => 'Through the API'])->assertCreated();
        $this->actingAs($agent)->putJson('/api/tickets/TCK-1/assignee', ['claim' => true])->assertOk();

        $fake->assertCreated(static fn (Ticket $ticket): bool => $ticket->subject === 'Through the API');
        $fake->assertClaimed('TCK-1');
        expect($fake->recorded(TicketOperation::Claim)[0]->model('actor')?->is($agent))->toBeTrue();
    });
});
