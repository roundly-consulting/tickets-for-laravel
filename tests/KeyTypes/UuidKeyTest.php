<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketLink;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Notifications\TicketCreatedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketMergedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketNoteAddedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketOverdueNotification;
use RoundlyConsulting\Tickets\Notifications\TicketRepliedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketRequesterRepliedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketStatusChangedNotification;
use RoundlyConsulting\Tickets\Support\MorphAliases;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\UuidUser;

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

describe('uuid_installation_works', function (): void {
    it('runs the whole flow with uuid keys everywhere', function (): void {
        defineAgents();
        Notification::fake();
        $ada = UuidUser::query()->create(['name' => 'Ada', 'email' => 'ada@acme.test']);
        $grace = UuidUser::query()->create(['name' => 'Grace', 'email' => 'grace@acme.test', 'is_agent' => true]);
        $linus = UuidUser::query()->create(['name' => 'Linus', 'email' => 'linus@acme.test', 'is_agent' => true]);
        $hardware = Tickets::categories()->make('Hardware')->defaults(assignee: $linus)->create();
        Tickets::fields()->make('asset_tag', FieldType::Text)->requesterEditable()->define();

        $ticket = Tickets::make()->subject('Printer jammed')->requester($ada)->category($hardware)
            ->fields(['asset_tag' => 'PRN-1'])->tags(['printer'])->due(CarbonImmutable::now()->subHour())->by($ada)->create();

        expect($ticket->assignee?->is($linus))->toBeTrue();

        Tickets::for($ticket)->by($grace)->claim()->prioritize('high')->start();
        $reply = Tickets::for($ticket)->by($grace)->reply('On it.');
        Tickets::for($ticket)->by($grace)->wait();
        Tickets::for($ticket)->by($ada)->reply('Thanks — still jammed.');
        $note = Tickets::for($ticket)->by($grace)->note('Vendor ticket opened.');
        Tickets::for($ticket)->by($grace)->watchers()->add($linus);
        Tickets::for($ticket)->by($grace)->fields()->set('asset_tag', 'PRN-2');
        $guestTicket = Tickets::make()->subject('Scanner')->guest(['name' => 'Jo', 'email' => 'jo@guest.test'])->asSystem()->create();
        Tickets::for($guestTicket)->by($grace)->links()->add($ticket, LinkType::RelatesTo);
        $sweep = Tickets::sweep();
        $merge = Tickets::for($guestTicket)->by($grace)->mergeInto($ticket);
        Tickets::for($ticket)->by($grace)->resolve('Replaced the drum.');

        $fresh = $ticket->fresh();
        $guest = $guestTicket->fresh()?->requester;

        expect(Str::isUuid((string) $ticket->getKey()))->toBeTrue()
            ->and($fresh?->requester_id)->toBe($ada->id)
            ->and($fresh?->assignee_id)->toBe($grace->id)
            ->and($fresh?->status)->toBe(TicketStatus::Resolved)
            ->and($fresh?->first_responded_at)->not->toBeNull()
            ->and(Comment::query()->whereKey($reply->getKey())->value('actor_id'))->toBe($grace->id)
            ->and(Tickets::for($ticket)->thread(internal: true)->modelKeys())->toContain($reply->getKey(), $note->getKey())
            ->and(Tickets::for($ticket)->fields()->get('asset_tag'))->toBe('PRN-2')
            ->and(Tickets::for($ticket)->tags()->all()->pluck('slug')->all())->toBe(['printer'])
            ->and(Tickets::for($ticket)->watchers()->has($linus))->toBeTrue()
            ->and(Tickets::for($ticket)->watchers()->has($guest instanceof TicketGuest ? $guest : $ada))->toBeTrue()
            ->and($sweep->overdue)->toBe(1)
            ->and($merge->target->is($ticket))->toBeTrue()
            ->and($guestTicket->fresh()?->statusCategory())->toBe(StatusCategory::Closed)
            ->and($fresh?->lifecycleHistory()->count())->toBe(6)
            ->and(Tickets::visibleTo($ada)->pluck('reference')->all())->toBe([$ticket->reference])
            ->and(Tickets::visibleTo($grace)->count())->toBe(2);

        Notification::assertSentTo($ada, TicketCreatedNotification::class);
        Notification::assertSentTo($ada, TicketRepliedNotification::class, static fn (TicketRepliedNotification $notification): bool => $notification->comment->is($reply));
        Notification::assertSentTo($ada, TicketStatusChangedNotification::class, static fn (TicketStatusChangedNotification $notification): bool => $notification->change === 'resolved');
        Notification::assertSentTo($grace, TicketRequesterRepliedNotification::class);
        Notification::assertSentTo($grace, TicketOverdueNotification::class);
        Notification::assertSentTo($linus, TicketNoteAddedNotification::class);
        Notification::assertNotSentTo($ada, TicketNoteAddedNotification::class);
        Notification::assertSentTo($guest, TicketMergedNotification::class);
    });

    it('passes the doctor aligned, and fails it misaligned', function (): void {
        Artisan::call('tickets:doctor', ['--json' => true]);
        $aligned = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $keys = array_values(array_filter($aligned['checks'], static fn (array $check): bool => $check['section'] === 'keys'));

        expect(array_column($keys, 'level', 'code'))->toBe(['alignment' => 'ok'])
            ->and($keys[0]['message'])->toContain('uuid');

        config()->set('comments.key_type', 'bigint');

        expect(Artisan::call('tickets:doctor', ['--json' => true]))->toBe(1);

        $misaligned = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $errors = array_values(array_filter($misaligned['checks'], static fn (array $check): bool => $check['section'] === 'keys' && $check['level'] === 'error'));

        expect(array_column($errors, 'message'))->toBe([
            'tickets.primary_key_type is uuid, but comments.key_type is bigint: they must be the same, or the columns and lookups will not match.',
            'tickets.key_type is uuid, but comments.key_type is bigint: they must be the same, or the columns and lookups will not match.',
        ]);
    });
});
