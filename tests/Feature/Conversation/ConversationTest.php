<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Comments\Enums\CommentStatus;
use RoundlyConsulting\Comments\Facades\Comments;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Events\TicketFirstResponded;
use RoundlyConsulting\Tickets\Events\TicketNoteAdded;
use RoundlyConsulting\Tickets\Events\TicketReplied;
use RoundlyConsulting\Tickets\Exceptions\TicketAccessDeniedException;
use RoundlyConsulting\Tickets\Exceptions\TicketClosedException;
use RoundlyConsulting\Tickets\Exceptions\TicketCommentNotFoundException;
use RoundlyConsulting\Tickets\Exceptions\TicketTrashedException;
use RoundlyConsulting\Tickets\Exceptions\UnknownTicketTransitionException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles\HostLifecycle;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

beforeEach(fn () => defineAgents());

function convRequester(string $name = 'Ada'): User
{
    return User::query()->create(['name' => $name]);
}

function convAgent(string $name = 'Grace'): User
{
    return User::query()->create(['name' => $name, 'is_agent' => true]);
}

function convTicket(?User $requester = null): Ticket
{
    return Tickets::make()->subject('Printer jammed')->requester($requester ?? convRequester())->asSystem()->create();
}

function convWatch(Ticket $ticket, User $watcher): void
{
    TicketWatcher::query()->create([
        'ticket_id' => $ticket->getKey(),
        'watcher_type' => $watcher->getMorphClass(),
        'watcher_id' => $watcher->getKey(),
    ]);
}

describe('conversation roles', function (): void {
    it('computes the author role when the message is posted and keeps it in the meta', function (): void {
        $requester = convRequester();
        $ticket = convTicket($requester);
        $watcher = convRequester('Cc');
        convWatch($ticket, $watcher);

        $byRequester = Tickets::for($ticket)->by($requester)->reply('It is still jammed.');
        $byAgent = Tickets::for($ticket)->by(convAgent())->reply('On my way.');
        $byWatcher = Tickets::for($ticket)->by($watcher)->reply('Same on floor 4.');
        $bySystem = Tickets::for($ticket)->asSystem()->reply('Reminder: we are on it.');

        expect(TicketComments::role($byRequester))->toBe(AuthorRole::Requester)
            ->and(TicketComments::role($byAgent))->toBe(AuthorRole::Agent)
            ->and(TicketComments::role($byWatcher))->toBe(AuthorRole::Participant)
            ->and(TicketComments::role($bySystem))->toBe(AuthorRole::System)
            ->and($bySystem->actor_id)->toBeNull()
            ->and($byRequester->actor?->is($requester))->toBeTrue();
    });

    it('gives a requester who is also an agent the requester role', function (): void {
        $agent = convAgent();
        $ticket = convTicket($agent);

        expect(TicketComments::role(Tickets::for($ticket)->by($agent)->reply('Filed it myself.')))->toBe(AuthorRole::Requester);
    });

    it('attributes a message to the authenticated user by default', function (): void {
        $requester = convRequester();
        $ticket = convTicket($requester);
        $this->actingAs($requester);

        expect(TicketComments::role(Tickets::for($ticket)->reply('Hello?')))->toBe(AuthorRole::Requester);
    });

    it('refuses a reply from someone with no role on the ticket', function (): void {
        $ticket = convTicket();

        expect(fn () => Tickets::for($ticket)->by(convRequester('Stranger'))->reply('Let me in.'))
            ->toThrow(TicketAccessDeniedException::class, 'TCK-1')
            ->and(Comment::query()->count())->toBe(0)
            ->and($ticket->activities()->where('type', ActivityType::Replied->value)->count())->toBe(0);
    });

    it('refuses a watcher once watchers may not view the ticket', function (): void {
        config()->set('tickets.watchers.can_view', 'off');
        $ticket = convTicket();
        $watcher = convRequester('Cc');
        convWatch($ticket, $watcher);

        Tickets::for($ticket)->by($watcher)->reply('Me too.');
    })->throws(TicketAccessDeniedException::class);

    it('lets only an agent or the system add an internal note', function (): void {
        $requester = convRequester();
        $ticket = convTicket($requester);

        expect(fn () => Tickets::for($ticket)->by($requester)->note('Psst.'))->toThrow(TicketAccessDeniedException::class, 'internal note')
            ->and(TicketComments::role(Tickets::for($ticket)->by(convAgent())->note('Looks like a hardware fault.')))->toBe(AuthorRole::Agent)
            ->and(TicketComments::role(Tickets::for($ticket)->asSystem()->note('Imported from the old desk.')))->toBe(AuthorRole::System);
    });

    it('refuses a message on a soft-deleted ticket', function (): void {
        $ticket = convTicket();
        Tickets::for($ticket)->asSystem()->delete();

        Tickets::for($ticket)->by(convAgent())->reply('Anyone?');
    })->throws(TicketTrashedException::class);
});

describe('reply_automations', function (): void {
    it('resumes a waiting ticket when the requester replies', function (): void {
        $requester = convRequester();
        $ticket = convTicket($requester);
        Tickets::for($ticket)->by(convAgent())->wait();

        Tickets::for($ticket)->by($requester)->reply('Here is the photo you asked for.');

        $entry = $ticket->activities()->where('type', ActivityType::StatusChanged->value)->reorder('id', 'desc')->first();

        expect($ticket->status)->toBe(TicketStatus::Open)
            ->and($entry?->properties['automation'] ?? null)->toBe('requester_replied')
            ->and($entry?->is_system)->toBeTrue()
            ->and($entry?->actor?->is($requester))->toBeTrue();
    });

    it('resumes a waiting ticket when a participant replies', function (): void {
        $ticket = convTicket();
        $watcher = convRequester('Cc');
        convWatch($ticket, $watcher);
        Tickets::for($ticket)->by(convAgent())->wait();

        Tickets::for($ticket)->by($watcher)->reply('I can confirm.');

        expect($ticket->status)->toBe(TicketStatus::Open);
    });

    it('leaves a waiting ticket waiting when an agent replies', function (): void {
        $agent = convAgent();
        $ticket = convTicket();
        Tickets::for($ticket)->by($agent)->wait();

        Tickets::for($ticket)->by($agent)->reply('Still waiting on you.');

        expect($ticket->status)->toBe(TicketStatus::Pending);
    });

    it('reopens a resolved ticket when the requester replies', function (): void {
        $requester = convRequester();
        $ticket = convTicket($requester);
        Tickets::for($ticket)->by(convAgent())->resolve();

        Tickets::for($ticket)->by($requester)->reply('It broke again.');

        expect($ticket->status)->toBe(TicketStatus::Open)
            ->and($ticket->activities()->where('type', 'status_changed')->reorder('id', 'desc')->first()?->properties['automation'] ?? null)->toBe('requester_reopened');
    });

    it('leaves a resolved ticket resolved with reply_reopens off', function (): void {
        config()->set('tickets.comments.reply_reopens', 'off');
        $requester = convRequester();
        $ticket = convTicket($requester);
        Tickets::for($ticket)->by(convAgent())->resolve();

        Tickets::for($ticket)->by($requester)->reply('Thanks!');

        expect($ticket->status)->toBe(TicketStatus::Resolved);
    });

    it('refuses every message on a closed ticket', function (Closure $post): void {
        $requester = convRequester();
        $ticket = convTicket($requester);
        Tickets::for($ticket)->by(convAgent())->resolve()->close();
        $comments = Comment::query()->count();

        expect(fn () => $post($ticket, $requester))->toThrow(TicketClosedException::class, 'follow-up')
            ->and(Comment::query()->count())->toBe($comments)
            ->and($ticket->fresh()?->status)->toBe(TicketStatus::Closed);
    })->with([
        'requester reply' => [fn (Ticket $ticket, User $requester) => Tickets::for($ticket)->by($requester)->reply('Hello?')],
        'agent reply' => [fn (Ticket $ticket) => Tickets::for($ticket)->by(convAgent('Linus'))->reply('Closing words.')],
        'agent note' => [fn (Ticket $ticket) => Tickets::for($ticket)->by(convAgent('Linus'))->note('Post-mortem.')],
    ]);
});

describe('conversation_lock', function (): void {
    it('takes messages again once a host lifecycle leaves Closed', function (): void {
        config()->set('tickets.lifecycle', HostLifecycle::class);
        $requester = convRequester();
        $ticket = convTicket($requester);
        $handle = Tickets::for($ticket)->asSystem();

        $handle->transition('finish');

        expect(fn () => Tickets::for($ticket)->by($requester)->reply('Hello?'))->toThrow(TicketClosedException::class);

        $handle->transition('revive');

        expect(Tickets::for($ticket)->by($requester)->reply('Hello again.')->exists)->toBeTrue();
    });
});

describe('reply_with_transition_is_atomic', function (): void {
    it('applies the transition together with the reply', function (): void {
        $agent = convAgent();
        $ticket = convTicket();

        $comment = Tickets::for($ticket)->by($agent)->reply('Please send a photo of the display.', transition: 'wait');

        $types = $ticket->activities()->pluck('type')->map(static fn (ActivityType $type): string => $type->value)->all();

        expect($comment->exists)->toBeTrue()
            ->and($ticket->status)->toBe(TicketStatus::Pending)
            ->and(array_slice($types, -2))->toBe(['replied', 'status_changed']);
    });

    it('passes the transition reason and payload through', function (): void {
        $ticket = convTicket();

        Tickets::for($ticket)->by(convAgent())->reply('Fixed the drum.', transition: 'resolve', payload: ['resolution' => 'New drum.']);
        Tickets::for($ticket = convTicket())->by(convAgent('Linus'))->reply('Waiting on the vendor.', transition: 'hold', reason: 'Vendor part');

        expect($ticket->status)->toBe(TicketStatus::OnHold)
            ->and($ticket->lifecycleHistory()->latest('id')->first()?->reason)->toBe('Vendor part');
    });

    it('leaves no reply when the transition is denied', function (Closure $reply, string $exception): void {
        $ticket = convTicket();
        $activities = TicketActivity::query()->count();
        // The stored clock before the attempt — the bound instance may carry the rolled-back
        // write, so comparing against it fails whenever the second ticks over in between.
        $clock = $ticket->fresh()?->last_activity_at;

        expect(fn () => $reply($ticket))->toThrow($exception)
            ->and(Comment::query()->count())->toBe(0)
            ->and(TicketActivity::query()->count())->toBe($activities)
            ->and($clock)->not->toBeNull()
            ->and($ticket->fresh()?->last_activity_at?->equalTo($clock))->toBeTrue();
    })->with([
        'not allowed from the state' => [fn (Ticket $ticket) => Tickets::for($ticket)->by(convAgent())->reply('Reopening.', transition: 'reopen'), TransitionDeniedException::class],
        'missing its required reason' => [fn (Ticket $ticket) => Tickets::for($ticket)->by(convAgent())->reply('Parking it.', transition: 'hold'), TransitionDeniedException::class],
        'unknown to the lifecycle' => [fn (Ticket $ticket) => Tickets::for($ticket)->by(convAgent())->reply('Hm.', transition: 'teleport'), UnknownTicketTransitionException::class],
    ]);

    it('lets an explicit transition replace the reply automation', function (): void {
        $requester = convRequester();
        $ticket = convTicket($requester);
        Tickets::for($ticket)->by(convAgent())->resolve();

        Tickets::for($ticket)->by($requester)->reply('Works now, thank you!', transition: 'close');

        expect($ticket->status)->toBe(TicketStatus::Closed)
            ->and(Comments::isLocked($ticket))->toBeTrue()
            ->and($ticket->activities()->where('type', 'status_changed')->get()->contains(
                static fn (TicketActivity $entry): bool => ($entry->properties['automation'] ?? null) === 'requester_reopened',
            ))->toBeFalse();
    });

    it('stamps the first response once, on the first public agent reply only', function (): void {
        Event::fake([TicketFirstResponded::class]);
        CarbonImmutable::setTestNow('2030-01-01 09:00:00');
        $requester = convRequester();
        $agent = convAgent();
        $ticket = convTicket($requester);

        Tickets::for($ticket)->by($requester)->reply('Any news?');
        Tickets::for($ticket)->by($agent)->note('Checking the logs first.');

        expect($ticket->fresh()?->first_responded_at)->toBeNull();

        CarbonImmutable::setTestNow('2030-01-01 10:00:00');
        $first = Tickets::for($ticket)->by($agent)->reply('Found it.');
        CarbonImmutable::setTestNow('2030-01-01 11:00:00');
        Tickets::for($ticket)->by(convAgent('Linus'))->reply('Following up.');

        expect($ticket->fresh()?->first_responded_at?->toDateTimeString())->toBe('2030-01-01 10:00:00')
            ->and($ticket->first_responded_at?->toDateTimeString())->toBe('2030-01-01 10:00:00');

        Event::assertDispatchedTimes(TicketFirstResponded::class, 1);
        Event::assertDispatched(TicketFirstResponded::class, static fn (TicketFirstResponded $event): bool => $event->comment->is($first)
            && $event->actor?->is($agent) === true);

        CarbonImmutable::setTestNow();
    });

    it('does not stamp the first response for a held agent reply', function (): void {
        config()->set('comments.blocklist', ['jammed']);
        config()->set('comments.blocklist_action', 'pending');
        $ticket = convTicket();

        Tickets::for($ticket)->by(convAgent())->reply('The printer is jammed.');

        expect($ticket->fresh()?->first_responded_at)->toBeNull();
    });
});

describe('blocklist_hold_is_not_bypassed', function (): void {
    it('keeps a reply the blocklist held: unapproved, agent-only, silent', function (string $action, CommentStatus $status): void {
        Event::fake([TicketReplied::class]);
        config()->set('comments.blocklist', ['scam']);
        config()->set('comments.blocklist_action', $action);
        config()->set('comments.require_approval', true);
        $requester = convRequester();
        $ticket = convTicket($requester);
        Tickets::for($ticket)->by(convAgent())->wait();

        $comment = Tickets::for($ticket)->by($requester)->reply('This is a scam link.');
        $entry = $ticket->activities()->where('type', ActivityType::Replied->value)->sole();

        expect($comment->fresh()?->status)->toBe($status)
            ->and(Tickets::for($ticket)->thread())->toHaveCount(0)
            ->and(Tickets::for($ticket)->thread(internal: true)->modelKeys())->toBe([$comment->getKey()])
            ->and($ticket->status)->toBe(TicketStatus::Pending)
            ->and($entry->visibility)->toBe(ActivityVisibility::Internal)
            ->and($entry->properties['held'] ?? null)->toBeTrue()
            ->and(Tickets::for($ticket)->activity(internal: false)->pluck('type')->all())->not->toContain(ActivityType::Replied);

        Event::assertNotDispatched(TicketReplied::class);
    })->with([
        'pending' => ['pending', CommentStatus::Pending],
        'hidden' => ['hidden', CommentStatus::Hidden],
    ]);

    it('approves a reply only require_approval held', function (): void {
        Event::fake([TicketReplied::class]);
        config()->set('comments.require_approval', true);
        $requester = convRequester();
        $ticket = convTicket($requester);

        $comment = Tickets::for($ticket)->by($requester)->reply('Still broken.');

        expect($comment->fresh()?->status)->toBe(CommentStatus::Approved)
            ->and(Tickets::for($ticket)->thread()->modelKeys())->toBe([$comment->getKey()]);

        Event::assertDispatched(TicketReplied::class, static fn (TicketReplied $event): bool => $event->comment->is($comment)
            && $event->role === AuthorRole::Requester && $event->actor?->is($requester) === true);
    });

    it('leaves a require_approval hold for a moderator with auto_approve off', function (): void {
        config()->set('comments.require_approval', true);
        config()->set('tickets.comments.auto_approve', 'off');
        $requester = convRequester();
        $ticket = convTicket($requester);

        $comment = Tickets::for($ticket)->by($requester)->reply('Still broken.');

        expect($comment->fresh()?->status)->toBe(CommentStatus::Pending)
            ->and(Tickets::for($ticket)->thread())->toHaveCount(0);
    });
});

describe('notes_leave_no_public_trace', function (): void {
    it('moves the activity clock on a note without touching updated_at', function (): void {
        CarbonImmutable::setTestNow('2030-01-01 09:00:00');
        $ticket = convTicket();
        $updatedAt = $ticket->fresh()?->updated_at?->toDateTimeString();

        CarbonImmutable::setTestNow('2030-01-01 12:00:00');
        Tickets::for($ticket)->by(convAgent())->note('Escalating to hardware.');

        $stored = $ticket->fresh();

        expect($stored?->last_activity_at?->toDateTimeString())->toBe('2030-01-01 12:00:00')
            ->and($ticket->last_activity_at?->toDateTimeString())->toBe('2030-01-01 12:00:00')
            ->and($stored?->updated_at?->toDateTimeString())->toBe($updatedAt)
            ->and($ticket->activities()->where('type', ActivityType::NoteAdded->value)->sole()->visibility)->toBe(ActivityVisibility::Internal);

        CarbonImmutable::setTestNow();
    });
});

describe('internal_notes_never_reach_non_agents', function (): void {
    it('keeps a note out of every public read', function (Closure $read): void {
        $agent = convAgent();
        $ticket = convTicket();
        $reply = Tickets::for($ticket)->by($agent)->reply('We ordered a new drum.');
        $note = Tickets::for($ticket)->by($agent)->note('Internal: the vendor overcharged us.');

        $seen = $read($ticket);

        expect($seen)->toBe([$reply->getKey()])
            ->and($seen)->not->toContain($note->getKey());
    })->with([
        'the handle thread' => [fn (Ticket $ticket): array => Tickets::for($ticket)->thread()->modelKeys()],
        'comments visible()' => [fn (Ticket $ticket): array => Comments::for($ticket)->visible()->get()->modelKeys()],
        'approvedComments' => [fn (Ticket $ticket): array => $ticket->approvedComments()->get()->modelKeys()],
        'threadedComments' => [fn (Ticket $ticket): array => $ticket->threadedComments()->get()->modelKeys()],
        'the public comment count' => [fn (Ticket $ticket): array => $ticket->loadCommentCount()->getAttribute('comments_count') === 1
            ? [Tickets::for($ticket)->thread()->sole()->getKey()] : ['count leaked']],
        'withCommentCounts' => [fn (Ticket $ticket): array => Ticket::query()->withCommentCounts()->findOrFail($ticket->getKey())->getAttribute('comments_count') === 1
            ? [Tickets::for($ticket)->thread()->sole()->getKey()] : ['count leaked']],
        'the public activity' => [fn (Ticket $ticket): array => Tickets::for($ticket)->activity(internal: false)
            ->filter(static fn (TicketActivity $entry): bool => isset($entry->properties['comment']))
            ->map(static fn (TicketActivity $entry): mixed => $entry->properties['comment'])->values()->all()],
    ]);

    it('shows every message in the internal thread, oldest first', function (): void {
        $agent = convAgent();
        $ticket = convTicket();
        $reply = Tickets::for($ticket)->by($agent)->reply('Public.');
        $note = Tickets::for($ticket)->by($agent)->note('Internal.');

        expect(Tickets::for($ticket)->thread(internal: true)->modelKeys())->toBe([$reply->getKey(), $note->getKey()]);
    });

    it('records a note by id, never by body, and fires no public reply event', function (): void {
        Event::fake([TicketReplied::class, TicketNoteAdded::class]);
        $agent = convAgent();
        $ticket = convTicket();

        $note = Tickets::for($ticket)->by($agent)->note('The vendor password is hunter2.');
        $entry = $ticket->activities()->where('type', ActivityType::NoteAdded->value)->sole();

        expect($entry->properties)->toBe(['comment' => $note->getKey()])
            ->and(json_encode(TicketActivity::query()->pluck('properties')->all()))->not->toContain('hunter2')
            ->and($note->visible)->toBeFalse()
            ->and(TicketComments::isInternal($note))->toBeTrue();

        Event::assertNotDispatched(TicketReplied::class);
        Event::assertDispatched(TicketNoteAdded::class, static fn (TicketNoteAdded $event): bool => $event->comment->is($note) && $event->actor?->is($agent) === true);
    });

    it('treats a comment marked internal as internal even if a host made it visible', function (): void {
        $agent = convAgent();
        $ticket = convTicket();
        $note = Tickets::for($ticket)->by($agent)->note('Internal.');

        $note->forceFill(['visible' => true])->save();

        expect(Tickets::for($ticket)->thread())->toHaveCount(0);
    });
});

describe('comment_meta_is_namespaced', function (): void {
    it('writes the tickets namespace on replies and notes', function (): void {
        $agent = convAgent();
        $ticket = convTicket();

        $reply = Tickets::for($ticket)->by($agent)->reply('Hello.', channel: 'email');
        $note = Tickets::for($ticket)->by($agent)->note('Psst.');

        expect(keySorted($reply->fresh()?->meta))->toEqual(['tickets' => ['channel' => 'email', 'internal' => false, 'role' => 'agent']])
            ->and(keySorted($note->fresh()?->meta))->toEqual(['tickets' => ['channel' => 'api', 'internal' => true, 'role' => 'agent']]);
    });

    it('keeps the host meta keys and never lets them spoof the tickets namespace', function (): void {
        $requester = convRequester();
        $ticket = convTicket($requester);

        $comment = Tickets::for($ticket)->by($requester)->reply('Hi.', meta: [
            'crm' => ['thread' => 'T-7'],
            'tickets' => ['internal' => true, 'role' => 'agent'],
        ]);

        $stored = $comment->fresh();

        expect($stored?->meta('crm.thread'))->toBe('T-7')
            ->and($stored?->meta('tickets.internal'))->toBeFalse()
            ->and($stored?->meta('tickets.role'))->toBe('requester')
            ->and(Tickets::for($ticket)->thread()->modelKeys())->toBe([$comment->getKey()]);
    });

    it('lets the thread be filtered by role and kind through comments meta queries', function (): void {
        $requester = convRequester();
        $agent = convAgent();
        $ticket = convTicket($requester);
        Tickets::for($ticket)->by($requester)->reply('Help.');
        $answer = Tickets::for($ticket)->by($agent)->reply('Sure.');
        $note = Tickets::for($ticket)->by($agent)->note('Easy one.');

        expect(Comments::for($ticket)->whereMeta('tickets.role', 'agent')->whereMeta('tickets.internal', false)->get()->modelKeys())->toBe([$answer->getKey()])
            ->and(Comments::for($ticket)->whereMeta('tickets.internal', true)->get()->modelKeys())->toBe([$note->getKey()]);
    });

    it('refuses a channel the host does not accept', function (): void {
        $requester = convRequester();

        Tickets::for(convTicket($requester))->by($requester)->reply('Hi.', channel: 'pigeon');
    })->throws(ValidationException::class);
});

describe('conversation edits', function (): void {
    it('edits a message and keeps a note internal', function (): void {
        $agent = convAgent();
        $ticket = convTicket();
        $note = Tickets::for($ticket)->by($agent)->note('Typo heer.');

        $edited = Tickets::for($ticket)->by($agent)->editComment($note, 'Typo here.');

        expect($edited->fresh()?->comment)->toBe('Typo here.')
            ->and($edited->fresh()?->visible)->toBeFalse()
            ->and($edited->fresh()?->meta('tickets.internal'))->toBeTrue()
            ->and(Tickets::for($ticket)->thread())->toHaveCount(0);
    });

    it('deletes a message and returns the handle', function (): void {
        $agent = convAgent();
        $ticket = convTicket();
        $reply = Tickets::for($ticket)->by($agent)->reply('Oops, wrong ticket.');

        $handle = Tickets::for($ticket)->by($agent);

        expect($handle->deleteComment($reply))->toBe($handle)
            ->and($reply->fresh()?->trashed())->toBeTrue()
            ->and($handle->thread())->toHaveCount(0);
    });

    it('refuses a comment of another ticket', function (Closure $act): void {
        $agent = convAgent();
        $mine = convTicket();
        $theirs = convTicket();
        $comment = Tickets::for($theirs)->by($agent)->reply('Not yours.');

        expect(fn () => $act(Tickets::for($mine)->by($agent), $comment))->toThrow(TicketCommentNotFoundException::class, 'TCK-1')
            ->and($comment->fresh()?->comment)->toBe('Not yours.')
            ->and($comment->fresh()?->trashed())->toBeFalse();
    })->with([
        'edit' => [fn ($handle, Comment $comment) => $handle->editComment($comment, 'Hijacked.')],
        'delete' => [fn ($handle, Comment $comment) => $handle->deleteComment($comment)],
    ]);

    it('refuses edits and deletes on a closed ticket', function (Closure $act): void {
        $agent = convAgent();
        $ticket = convTicket();
        $reply = Tickets::for($ticket)->by($agent)->reply('Done.');
        Tickets::for($ticket)->by($agent)->resolve()->close();

        expect(fn () => $act(Tickets::for($ticket)->by($agent), $reply))->toThrow(TicketClosedException::class)
            ->and($reply->fresh()?->comment)->toBe('Done.');
    })->with([
        'edit' => [fn ($handle, Comment $comment) => $handle->editComment($comment, 'Changed.')],
        'delete' => [fn ($handle, Comment $comment) => $handle->deleteComment($comment)],
    ]);
});

describe('reply attachments', function (): void {
    it('stores attachments on the comment, on the private disk', function (): void {
        Storage::fake('local');
        Storage::fake('public');
        $requester = convRequester();
        $ticket = convTicket($requester);

        $comment = Tickets::for($ticket)->by($requester)->reply('Log attached.', attachments: [
            UploadedFile::fake()->create('printer.log', 2, 'text/plain'),
        ]);

        $media = $comment->attachments()->sole();

        expect($media->disk)->toBe('local')
            ->and($media->isPrivate())->toBeTrue()
            ->and(Storage::disk('local')->exists($media->getPath()))->toBeTrue()
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('attaches files to an internal note too', function (): void {
        Storage::fake('local');
        $ticket = convTicket();

        $note = Tickets::for($ticket)->by(convAgent())->note('Screenshot of the admin panel.', attachments: [
            UploadedFile::fake()->create('admin.txt', 1, 'text/plain'),
        ]);

        expect($note->attachments())->toHaveCount(1);
    });
});

describe('model conveniences', function (): void {
    it('replies and notes through the ticket model, via the manager', function (): void {
        $fake = Tickets::fake();
        $requester = convRequester();
        $agent = convAgent();
        $ticket = convTicket($requester);

        $reply = $ticket->reply('From the model.', $requester);
        $note = $ticket->note('Model note.', $agent);

        expect(TicketComments::role($reply))->toBe(AuthorRole::Requester)
            ->and(TicketComments::isInternal($note))->toBeTrue();

        $fake->assertReplied($ticket, AuthorRole::Requester);
        $fake->assertNoted($ticket);
    });
});
