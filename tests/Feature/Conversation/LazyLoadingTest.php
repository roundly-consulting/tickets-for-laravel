<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Comments\Events\CommentMentioned;
use RoundlyConsulting\Comments\Models\CommentMention;
use RoundlyConsulting\Tickets\Events\TicketMentioned;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Listeners\OnCommentMentioned;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Notifications\TicketMentionedNotification;
use RoundlyConsulting\Tickets\Tests\Fixtures\MentionResolver;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

/*
 * A host that runs `Model::preventLazyLoading()` gets the flag on every model a query returns
 * with siblings — comments fetches a comment's mentions in one query and fires
 * `CommentMentioned` for each, and a host re-fetching mentions (a queued listener, a replay)
 * does the same. The ticket listener must load what it reads itself.
 */

beforeEach(function (): void {
    defineAgents();
    config()->set('comments.mention_resolver', MentionResolver::class);
});

afterEach(function (): void {
    Model::preventLazyLoading(false);
    Model::handleLazyLoadingViolationUsing(null);
});

function llPerson(string $name, bool $agent = false): User
{
    return User::query()->create(['name' => $name, 'email' => strtolower($name).'@acme.test', 'is_agent' => $agent]);
}

function llTicket(User $requester): Ticket
{
    return Tickets::make()->subject('VPN drops')->requester($requester)->asSystem()->create();
}

/**
 * Record every lazy load instead of throwing, so a violation a listener reports (and never
 * throws) still fails the test.
 *
 * @return ArrayObject<int, string>
 */
function llRecordViolations(): ArrayObject
{
    $violations = new ArrayObject;
    Model::preventLazyLoading();
    Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation) use ($violations): void {
        $violations[] = $model::class.'::'.$relation;
    });

    return $violations;
}

it('passes mentions on with lazy loading prevented', function (): void {
    Event::fake([TicketMentioned::class]);
    Model::preventLazyLoading();
    $ada = llPerson('Ada');
    $grace = llPerson('Grace', true);
    $watcher = llPerson('Cc');
    $ticket = llTicket($ada);
    TicketWatcher::query()->create(['ticket_id' => $ticket->getKey(), 'watcher_type' => $watcher->getMorphClass(), 'watcher_id' => $watcher->getKey()]);

    $reply = Tickets::for($ticket)->by($grace)->reply('@Grace @Ada @Cc fixed.');

    $mentioned = [];
    Event::assertDispatched(TicketMentioned::class, static function (TicketMentioned $event) use (&$mentioned, $reply, $ticket): bool {
        $mentioned[] = $event->mentioned->getAttribute('name');

        return $event->comment->is($reply) && $event->ticket->is($ticket);
    });
    sort($mentioned);

    expect($mentioned)->toBe(['Ada', 'Cc', 'Grace']);
});

it('reads no relation lazily on the way to the mention notification', function (): void {
    Notification::fake();
    $violations = llRecordViolations();
    $ada = llPerson('Ada');
    $grace = llPerson('Grace', true);
    $linus = llPerson('Linus', true);
    $ticket = llTicket($ada);

    Tickets::for($ticket)->by($grace)->reply('@Linus @Ada have a look.');

    expect($violations->getArrayCopy())->toBe([]);
    Notification::assertSentTo($linus, TicketMentionedNotification::class);
});

it('handles mentions re-fetched with their comments loaded, as a queued listener would', function (): void {
    $ada = llPerson('Ada');
    $grace = llPerson('Grace', true);
    $linus = llPerson('Linus', true);
    $ticket = llTicket($ada);
    $other = llTicket(llPerson('Bob'));
    config()->set('tickets.notifications.enabled', 'off');
    $first = Tickets::for($ticket)->by($grace)->note('@Linus psst.');
    $second = Tickets::for($other)->by($grace)->note('@Linus and here.');

    // The note's own notification off, so the mention is what tells Linus.
    config()->set('tickets.notifications.enabled', 'on');
    config()->set('tickets.notifications.types.note_added', false);
    Notification::fake();
    $violations = llRecordViolations();

    // Fresh instances, comments eager-loaded across two rows: every model carries the flag.
    $mentions = CommentMention::query()->whereNotNull('mentionable_id')->with('comment')->orderBy('id')->get();

    foreach ($mentions as $mention) {
        event(new CommentMentioned($mention));
    }

    expect($mentions)->toHaveCount(2)
        ->and($violations->getArrayCopy())->toBe([]);
    Notification::assertSentTo($linus, TicketMentionedNotification::class, static fn (TicketMentionedNotification $notification): bool => $notification->comment->is($first) || $notification->comment->is($second));
    Notification::assertSentToTimes($linus, TicketMentionedNotification::class, 2);
});

it('passes a mention on from an unserialized event', function (): void {
    Event::fake([TicketMentioned::class]);
    $ada = llPerson('Ada');
    $grace = llPerson('Grace', true);
    $reply = Tickets::for(llTicket($ada))->by($grace)->reply('@Ada done.');
    $mention = CommentMention::query()->whereNotNull('mentionable_id')->sole();
    $event = unserialize(serialize(new CommentMentioned($mention)));
    $violations = llRecordViolations();

    app(OnCommentMentioned::class)->handle($event);

    expect($violations->getArrayCopy())->toBe([]);
    Event::assertDispatched(TicketMentioned::class, static fn (TicketMentioned $dispatched): bool => $dispatched->comment->is($reply)
        && $dispatched->mentioned->is($ada));
});
