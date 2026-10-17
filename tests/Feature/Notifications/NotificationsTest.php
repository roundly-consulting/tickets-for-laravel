<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Contracts\GuestLinkGenerator;
use RoundlyConsulting\Tickets\Enums\NotificationType;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Notifications\RecipientResolver;
use RoundlyConsulting\Tickets\Notifications\TicketAssignedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketCreatedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketDueSoonNotification;
use RoundlyConsulting\Tickets\Notifications\TicketMentionedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketMergedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketNoteAddedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketOverdueNotification;
use RoundlyConsulting\Tickets\Notifications\TicketRepliedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketRequesterRepliedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketStatusChangedNotification;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Tests\Fixtures\MentionResolver;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Fixtures\Notifications\ExplodingRepliedNotification;
use RoundlyConsulting\Tickets\Tests\Fixtures\Notifications\HostRepliedNotification;
use RoundlyConsulting\Tickets\Tests\Fixtures\ProjectAgentScope;

beforeEach(function (): void {
    defineAgents();
    Notification::fake();
});

function ntPerson(string $name, bool $agent = false): User
{
    return User::query()->create(['name' => $name, 'email' => strtolower($name).'@acme.test', 'is_agent' => $agent]);
}

function ntTicket(?Model $requester = null, string $subject = 'Printer jammed'): Ticket
{
    return Tickets::make()->subject($subject)->requester($requester ?? ntPerson('Ada'))->asSystem()->create();
}

function ntWatch(Ticket $ticket, Model $watcher): void
{
    TicketWatcher::query()->create([
        'ticket_id' => $ticket->getKey(),
        'watcher_type' => $watcher->getMorphClass(),
        'watcher_id' => $watcher->getKey(),
    ]);
}

function ntGuest(string $name = 'Jo'): TicketGuest
{
    return Tickets::guests()->resolve(['name' => $name, 'email' => strtolower($name).'@guest.test']);
}

/**
 * A guest-link generator that needs no routes: the link names the ticket, the guest and the
 * access version it was minted for.
 */
function ntGuestLinks(): void
{
    app()->bind(GuestLinkGenerator::class, static fn (): GuestLinkGenerator => new class implements GuestLinkGenerator
    {
        public function for(Ticket $ticket, Model $recipient): string
        {
            return 'https://help.test/guest/'.$ticket->reference.'/'.$recipient->getKey().'/v'.$ticket->guest_access_version;
        }
    });
}

describe('who is notified', function (): void {
    it('sends the requester a receipt — even for a ticket they filed themselves — and nobody else', function (): void {
        $ada = ntPerson('Ada');
        $grace = ntPerson('Grace', true);

        $own = Tickets::make()->subject('Mine')->requester($ada)->by($ada)->create();
        $filed = Tickets::make()->subject('Filed for Ada')->requester($ada)->by($grace)->create();

        Notification::assertSentToTimes($ada, TicketCreatedNotification::class, 2);
        Notification::assertSentTo($ada, TicketCreatedNotification::class, static fn (TicketCreatedNotification $notification): bool => $notification->ticket->is($own));
        Notification::assertSentTo($ada, TicketCreatedNotification::class, static fn (TicketCreatedNotification $notification): bool => $notification->ticket->is($filed));
        Notification::assertNotSentTo($grace, TicketCreatedNotification::class);
    });

    it('tells whoever the assignee names — never the agent who took it, unless notify_actor', function (): void {
        $grace = ntPerson('Grace', true);
        $linus = ntPerson('Linus', true);
        $ticket = ntTicket();

        Tickets::for($ticket)->by($grace)->assign($linus);
        Tickets::for(ntTicket())->by($grace)->claim();

        Notification::assertSentTo($linus, TicketAssignedNotification::class, static fn (TicketAssignedNotification $notification): bool => $notification->ticket->is($ticket)
            && $notification->assignee->is($linus));
        Notification::assertNotSentTo($grace, TicketAssignedNotification::class);

        config()->set('tickets.notifications.notify_actor', 'on');
        Tickets::for(ntTicket())->by($grace)->claim();

        Notification::assertSentToTimes($grace, TicketAssignedNotification::class, 1);
    });

    it('tells the requester and the watchers who are not agents about a reply from the help desk', function (): void {
        $ada = ntPerson('Ada');
        $cc = ntPerson('Cc');
        $linus = ntPerson('Linus', true);
        $grace = ntPerson('Grace', true);
        $stranger = ntPerson('Stranger');
        $ticket = ntTicket($ada);
        ntWatch($ticket, $cc);
        ntWatch($ticket, $linus);

        $reply = Tickets::for($ticket)->by($grace)->reply('We ordered a new drum.');
        Tickets::for($ticket)->asSystem()->reply('An automated status update.');

        Notification::assertSentToTimes($ada, TicketRepliedNotification::class, 2);
        Notification::assertSentTo($ada, TicketRepliedNotification::class, static fn (TicketRepliedNotification $notification): bool => $notification->comment->is($reply));
        Notification::assertSentToTimes($cc, TicketRepliedNotification::class, 2);

        foreach ([$linus, $grace, $stranger] as $nobody) {
            Notification::assertNotSentTo($nobody, TicketRepliedNotification::class);
        }
    });

    it('tells whoever the assignee names and the agents watching about a reply from the requester side', function (): void {
        $ada = ntPerson('Ada');
        $cc = ntPerson('Cc');
        $grace = ntPerson('Grace', true);
        $linus = ntPerson('Linus', true);
        $ticket = ntTicket($ada);
        Tickets::for($ticket)->asSystem()->assign($grace);
        ntWatch($ticket, $linus);
        ntWatch($ticket, $cc);

        Tickets::for($ticket)->by($ada)->reply('Still jammed.');
        Tickets::for($ticket)->by($cc)->reply('Same on floor 2.');

        Notification::assertSentToTimes($grace, TicketRequesterRepliedNotification::class, 2);
        Notification::assertSentToTimes($linus, TicketRequesterRepliedNotification::class, 2);

        // The requester side hears from the help desk, not from each other.
        foreach ([$ada, $cc] as $requesterSide) {
            Notification::assertNotSentTo($requesterSide, TicketRequesterRepliedNotification::class);
            Notification::assertNotSentTo($requesterSide, TicketRepliedNotification::class);
        }
    });

    it('tells a team\'s members about the requester\'s reply, and about the ticket being overdue', function (): void {
        $team = Team::query()->create(['name' => 'Hardware']);
        $grace = ntPerson('Grace', true);
        $linus = ntPerson('Linus', true);
        $team->members()->attach([$grace->id, $linus->id]);
        $ada = ntPerson('Ada');
        $ticket = Tickets::make()->subject('Jammed')->requester($ada)->due(CarbonImmutable::now()->subHour())->asSystem()->create();
        Tickets::for($ticket)->asSystem()->assign($team);

        Tickets::for($ticket)->by($ada)->reply('Any news?');
        Tickets::sweep();

        foreach ([$grace, $linus] as $member) {
            Notification::assertSentTo($member, TicketRequesterRepliedNotification::class);
            Notification::assertSentTo($member, TicketOverdueNotification::class, static fn (TicketOverdueNotification $notification): bool => $notification->ticket->is($ticket));
        }

        Notification::assertNotSentTo($ada, TicketOverdueNotification::class);
    });

    it('tells nobody that an unassigned ticket is overdue or due soon', function (): void {
        Tickets::make()->subject('a')->requester(ntPerson('Ada'))->due(CarbonImmutable::now()->subHour())->asSystem()->create();
        Tickets::make()->subject('b')->requester(ntPerson('Bob'))->due(CarbonImmutable::now()->addHour())->asSystem()->create();

        expect(Tickets::sweep()->total())->toBe(2);

        Notification::assertNotSentTo(User::query()->get(), TicketOverdueNotification::class);
        Notification::assertNotSentTo(User::query()->get(), TicketDueSoonNotification::class);
    });

    it('tells the assignee a ticket is due soon', function (): void {
        $grace = ntPerson('Grace', true);
        $ticket = Tickets::make()->subject('a')->requester(ntPerson('Ada'))->assignee($grace)->due(CarbonImmutable::now()->addHour())->asSystem()->create();

        Tickets::sweep();

        Notification::assertSentTo($grace, TicketDueSoonNotification::class, static fn (TicketDueSoonNotification $notification): bool => $notification->ticket->is($ticket)
            && $notification->dueAt->equalTo($ticket->due_at));
    });

    it('tells the requester and every watcher about resolving, reopening and closing — not the one who did it', function (): void {
        $ada = ntPerson('Ada');
        $cc = ntPerson('Cc');
        $grace = ntPerson('Grace', true);
        $ticket = ntTicket($ada);
        ntWatch($ticket, $cc);

        Tickets::for($ticket)->by($grace)->resolve();
        Tickets::for($ticket)->by($ada)->reply('Not fixed, sorry.');
        Tickets::for($ticket)->by($grace)->resolve()->close();

        $changes = static fn (Model $notifiable): array => Notification::sent($notifiable, TicketStatusChangedNotification::class)
            ->map(static fn (TicketStatusChangedNotification $notification): string => $notification->change)->all();

        // Ada's reply reopened it through an automation, which runs as the system: she is told
        // too, as everyone is of a system change.
        expect($changes($ada))->toBe(['resolved', 'reopened', 'resolved', 'closed'])
            ->and($changes($cc))->toBe(['resolved', 'reopened', 'resolved', 'closed']);

        Notification::assertNotSentTo($grace, TicketStatusChangedNotification::class);
    });

    it('passes a mention on to someone the message itself does not reach', function (): void {
        config()->set('comments.mention_resolver', MentionResolver::class);
        $ada = ntPerson('Ada');
        $grace = ntPerson('Grace', true);
        $linus = ntPerson('Linus', true);
        $ticket = ntTicket($ada);

        $reply = Tickets::for($ticket)->by($grace)->reply('@Linus @Ada @Grace have a look.');

        Notification::assertSentTo($linus, TicketMentionedNotification::class, static fn (TicketMentionedNotification $notification): bool => $notification->comment->is($reply)
            && ! $notification->internal());
        Notification::assertSentTo($ada, TicketRepliedNotification::class);
        Notification::assertNotSentTo($ada, TicketMentionedNotification::class);
        Notification::assertNotSentTo($grace, TicketMentionedNotification::class);
    });

    it('passes a mention on when the message\'s own notification is switched off', function (): void {
        config()->set('comments.mention_resolver', MentionResolver::class);
        config()->set('tickets.notifications.types.replied', false);
        config()->set('tickets.notifications.types.note_added', false);
        $ada = ntPerson('Ada');
        $grace = ntPerson('Grace', true);
        $linus = ntPerson('Linus', true);
        $ticket = ntTicket($ada);
        ntWatch($ticket, $linus);

        Tickets::for($ticket)->by($grace)->reply('@Ada done.');
        Tickets::for($ticket)->by($grace)->note('@Linus psst.');

        Notification::assertSentTo($ada, TicketMentionedNotification::class);
        Notification::assertSentTo($linus, TicketMentionedNotification::class, static fn (TicketMentionedNotification $notification): bool => $notification->internal());
    });

    it('tells the source\'s requester about a merge — instead of its closing', function (): void {
        $ada = ntPerson('Ada');
        $bob = ntPerson('Bob');
        $cc = ntPerson('Cc');
        $grace = ntPerson('Grace', true);
        $source = ntTicket($ada);
        $target = ntTicket($bob);
        ntWatch($source, $cc);

        Tickets::for($source)->by($grace)->mergeInto($target);

        Notification::assertSentTo($ada, TicketMergedNotification::class, static fn (TicketMergedNotification $notification): bool => $notification->ticket->is($source)
            && $notification->target->is($target));
        Notification::assertNotSentTo($bob, TicketMergedNotification::class);

        // The merge's own system note and reply are not messages anyone is told about.
        foreach ([$ada, $bob, $cc] as $person) {
            Notification::assertNotSentTo($person, TicketStatusChangedNotification::class);
            Notification::assertNotSentTo($person, TicketRepliedNotification::class);
            Notification::assertNotSentTo($person, TicketNoteAddedNotification::class);
        }
    });

    it('notifies each person once, whatever roles they hold', function (): void {
        $ada = ntPerson('Ada');
        $ticket = ntTicket($ada);

        Tickets::for($ticket)->by(ntPerson('Grace', true))->reply('Hello.');

        // The requester, and a watcher of her own ticket (requesters watch on creation).
        expect(Tickets::for($ticket)->watchers()->has($ada))->toBeTrue();
        Notification::assertSentToTimes($ada, TicketRepliedNotification::class, 1);
    });

    it('skips a candidate that cannot be notified, or whose model is gone', function (): void {
        $ada = ntPerson('Ada');
        $ticket = ntTicket($ada);
        $document = Document::query()->create();
        ntWatch($ticket, $document);
        TicketWatcher::query()->create(['ticket_id' => $ticket->getKey(), 'watcher_type' => $ada->getMorphClass(), 'watcher_id' => 999]);

        expect(app(RecipientResolver::class)->statusChanged($ticket, null))->toHaveCount(1)
            ->and(app(RecipientResolver::class)->assigned($ticket, $document, null))->toBe([]);
    });
});

describe('internal_notes_never_reach_non_agents', function (): void {
    it('never tells a non-agent about a note, a mention in one, or a held reply', function (): void {
        config()->set('comments.mention_resolver', MentionResolver::class);
        config()->set('tickets.authorization.agent_scope', ProjectAgentScope::class);
        Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
        $ada = ntPerson('Ada');
        $cc = ntPerson('Cc');
        $grace = ntPerson('Grace', true);
        $linus = ntPerson('Linus', true);
        $scoped = ntPerson('Scoped', true);
        $guest = ntGuest();
        $ticket = ntTicket($ada);
        Tickets::for($ticket)->asSystem()->assign($grace);

        foreach ([$cc, $linus, $scoped, $guest] as $watcher) {
            ntWatch($ticket, $watcher);
        }

        Tickets::for($ticket)->by($grace)->note('@Ada @Cc @Scoped @Linus the vendor overcharged us.');

        config()->set('comments.blocklist', ['scam']);
        config()->set('comments.blocklist_action', 'pending');
        Tickets::for($ticket)->by($ada)->reply('@Linus this is a scam link.');

        // `Scoped` is an agent, but not for this ticket (the agent scope keeps them to SUP).
        foreach ([$ada, $cc, $guest, $scoped] as $outsider) {
            foreach ([TicketNoteAddedNotification::class, TicketMentionedNotification::class, TicketRepliedNotification::class, TicketRequesterRepliedNotification::class] as $notification) {
                Notification::assertNotSentTo($outsider, $notification);
            }
        }

        Notification::assertSentTo($linus, TicketNoteAddedNotification::class, static fn (TicketNoteAddedNotification $notification): bool => $notification->internal());
        Notification::assertNotSentTo($linus, TicketMentionedNotification::class);
        Notification::assertNotSentTo($linus, TicketRequesterRepliedNotification::class);
        Notification::assertNotSentTo($grace, TicketNoteAddedNotification::class);
    });

    it('re-checks on delivery, so a note never reaches someone who lost sight of it', function (): void {
        $ada = ntPerson('Ada');
        $grace = ntPerson('Grace', true);
        $ticket = ntTicket($ada);
        $note = Tickets::for($ticket)->by($grace)->note('Internal.');
        $reply = Tickets::for($ticket)->by($grace)->reply('Public.');
        $forNote = new TicketNoteAddedNotification($ticket, $note);
        $forReply = new TicketRepliedNotification($ticket, $reply);

        expect($forNote->shouldSend($grace, 'mail'))->toBeTrue()
            ->and($forNote->shouldSend($ada, 'mail'))->toBeFalse()
            ->and($forNote->shouldSend(new AnonymousNotifiable, 'mail'))->toBeFalse()
            ->and($forReply->shouldSend($ada, 'mail'))->toBeTrue();

        // The reply turned internal, the note deleted, before the queue got to them.
        $reply->forceFill(['meta' => ['tickets' => ['internal' => true, 'role' => 'agent', 'channel' => 'api']]])->save();
        $note->delete();

        expect($forReply->shouldSend($ada, 'mail'))->toBeFalse()
            ->and($forNote->shouldSend($grace, 'mail'))->toBeFalse();

        $ticket->delete();

        expect((new TicketCreatedNotification($ticket))->shouldSend($ada, 'mail'))->toBeFalse();
    });

    it('drops a watcher removed before delivery, and a message moved off the ticket', function (): void {
        $ada = ntPerson('Ada');
        $cc = ntPerson('Cc');
        $grace = ntPerson('Grace', true);
        $ticket = ntTicket($ada);
        ntWatch($ticket, $cc);
        $reply = Tickets::for($ticket)->by($grace)->reply('Public.');
        $notification = new TicketRepliedNotification($ticket, $reply);

        expect($notification->shouldSend($cc, 'mail'))->toBeTrue();

        Tickets::for($ticket)->asSystem()->watchers()->remove($cc);

        expect($notification->shouldSend($cc, 'mail'))->toBeFalse()
            ->and((new TicketRepliedNotification(ntTicket(ntPerson('Bob')), $reply))->shouldSend($grace, 'mail'))->toBeFalse();
    });

    it('drops a due-date notification once the date moved or the ticket is resolved', function (): void {
        $grace = ntPerson('Grace', true);
        $ticket = Tickets::make()->subject('a')->requester(ntPerson('Ada'))->assignee($grace)->due(CarbonImmutable::parse('2030-01-01 12:00'))->asSystem()->create();
        $notification = new TicketOverdueNotification($ticket, CarbonImmutable::parse('2030-01-01 12:00'));

        expect($notification->shouldSend($grace, 'mail'))->toBeTrue();

        Tickets::for($ticket)->asSystem()->due(CarbonImmutable::parse('2030-01-02 12:00'));

        expect($notification->shouldSend($grace, 'mail'))->toBeFalse()
            ->and((new TicketOverdueNotification($ticket, CarbonImmutable::parse('2030-01-02 12:00')))->shouldSend($grace, 'mail'))->toBeTrue();

        Tickets::for($ticket)->by($grace)->resolve();

        expect((new TicketOverdueNotification($ticket, CarbonImmutable::parse('2030-01-02 12:00')))->shouldSend($grace, 'mail'))->toBeFalse();
    });
});

describe('notifications_after_commit', function (): void {
    it('sends nothing before the change commits, and nothing for a change that rolls back', function (): void {
        $ada = ntPerson('Ada');
        $grace = ntPerson('Grace', true);
        $ticket = ntTicket($ada);

        DB::transaction(function () use ($ticket, $grace, $ada): void {
            Tickets::for($ticket)->by($grace)->reply('First.');

            Notification::assertNotSentTo($ada, TicketRepliedNotification::class);
        });

        Notification::assertSentToTimes($ada, TicketRepliedNotification::class, 1);

        try {
            DB::transaction(function () use ($ticket, $grace): void {
                Tickets::for($ticket)->by($grace)->reply('Second.');

                throw new RuntimeException('Rolled back.');
            });
        } catch (RuntimeException) {
        }

        Notification::assertSentToTimes($ada, TicketRepliedNotification::class, 1);
    });

    it('queues each notification after commit, on the configured queue', function (): void {
        config()->set('tickets.notifications.queue', 'tickets');
        $ada = ntPerson('Ada');

        ntTicket($ada);

        Notification::assertSentTo($ada, TicketCreatedNotification::class, static fn (TicketCreatedNotification $notification): bool => $notification instanceof ShouldQueue
            && $notification->afterCommit === true
            && $notification->queue === 'tickets'
            && $notification->deleteWhenMissingModels);
    });

    it('survives the queue round trip', function (): void {
        Notification::fake()->serializeAndRestore();
        $ada = ntPerson('Ada');
        $ticket = ntTicket($ada);

        $reply = Tickets::for($ticket)->by(ntPerson('Grace', true))->reply('Hello.');

        Notification::assertSentTo($ada, TicketRepliedNotification::class, static fn (TicketRepliedNotification $notification): bool => $notification->ticket->is($ticket)
            && $notification->comment->is($reply));
    });

    it('keeps the change, and reports a notification that fails', function (): void {
        Exceptions::fake();
        // The real dispatcher, on the sync queue: the mail throws while it is built.
        Notification::swap(new ChannelManager(app()));
        config()->set('tickets.notifications.types.replied', ExplodingRepliedNotification::class);
        $ticket = ntTicket(ntPerson('Ada'));

        $reply = Tickets::for($ticket)->by(ntPerson('Grace', true))->reply('Hello.');

        expect(Comment::query()->whereKey($reply->getKey())->exists())->toBeTrue()
            ->and(Tickets::for($ticket)->thread()->modelKeys())->toBe([$reply->getKey()]);

        Exceptions::assertReported(static fn (RuntimeException $exception): bool => $exception->getMessage() === 'The mail server is down.');
    });

    it('sends real mail through the configured channel', function (): void {
        Notification::swap(new ChannelManager(app()));
        $ada = ntPerson('Ada');

        ntTicket($ada, 'Printer on floor 3');

        $sent = app('mailer')->getSymfonyTransport()->messages();

        expect($sent)->toHaveCount(1)
            ->and($sent[0]->getOriginalMessage()->getSubject())->toBe('[TCK-1] We received your request: Printer on floor 3');
    });
});

describe('notification settings', function (): void {
    it('sends nothing with notifications off', function (): void {
        config()->set('tickets.notifications.enabled', 'off');

        Tickets::for(ntTicket())->by(ntPerson('Grace', true))->reply('Hello.');

        Notification::assertNothingSent();
    });

    it('switches one type off, and sends a host subclass for another', function (): void {
        config()->set('tickets.notifications.types.created', false);
        config()->set('tickets.notifications.types.replied', HostRepliedNotification::class);
        $ada = ntPerson('Ada');

        Tickets::for(ntTicket($ada))->by(ntPerson('Grace', true))->reply('Hello.');

        Notification::assertNotSentTo($ada, TicketCreatedNotification::class);
        Notification::assertSentTo($ada, HostRepliedNotification::class);
    });

    it('reports a class that does not extend the packaged one, and sends nothing for it', function (): void {
        Exceptions::fake();
        config()->set('tickets.notifications.types.created', TicketRepliedNotification::class);

        $ticket = ntTicket();

        expect($ticket->exists)->toBeTrue();

        Notification::assertNothingSent();
        Exceptions::assertReported(static fn (InvalidTicketsConfigurationException $exception): bool => str_contains($exception->getMessage(), 'tickets.notifications.types.created'));
    });

    it('reads the type settings', function (mixed $value, ?string $expected): void {
        config()->set('tickets.notifications.types.created', $value);

        expect(TicketsConfig::notification(NotificationType::Created))->toBe($expected);
    })->with([
        'the packaged class' => [TicketCreatedNotification::class, TicketCreatedNotification::class],
        'blank is the packaged class' => ['', TicketCreatedNotification::class],
        'null is the packaged class' => [null, TicketCreatedNotification::class],
        'false switches it off' => [false, null],
        "'off' switches it off" => ['off', null],
    ]);

    it('leaves guests out with tickets.notifications.guests off', function (): void {
        $guest = ntGuest();
        ntTicket($guest);

        Notification::assertSentTo($guest, TicketCreatedNotification::class);

        config()->set('tickets.notifications.guests', 'off');
        ntTicket($guest, 'Another');

        Notification::assertSentToTimes($guest, TicketCreatedNotification::class, 1);
    });

    it('sends on the configured channels', function (): void {
        config()->set('tickets.notifications.channels', ['mail', 'database']);
        $ada = ntPerson('Ada');

        ntTicket($ada);

        Notification::assertSentTo($ada, TicketCreatedNotification::class, static fn (TicketCreatedNotification $notification, array $channels): bool => $channels === ['mail', 'database']);
    });

    it('refuses a broken url template', function (string $template): void {
        config()->set('tickets.notifications.url', $template);

        TicketsConfig::notificationUrl('TCK-1');
    })->throws(InvalidTicketsConfigurationException::class, 'tickets.notifications.url')->with([
        'not a url' => ['helpdesk/{reference}'],
        'not http' => ['ftp://help.test/{reference}'],
    ]);

    it('refuses an unknown status change', function (): void {
        new TicketStatusChangedNotification(ntTicket(), 'archived');
    })->throws(InvalidArgumentException::class, 'archived');
});

describe('notification mails', function (): void {
    it('links a guest\'s mail to a fresh guest link and anyone else\'s to the configured url', function (): void {
        ntGuestLinks();
        $guest = ntGuest();
        $ada = ntPerson('Ada');
        $guestTicket = ntTicket($guest);
        $userTicket = ntTicket($ada, 'Another');

        expect((new TicketCreatedNotification($guestTicket))->toMail($guest)->actionUrl)->toBe('https://help.test/guest/TCK-1/'.$guest->getKey().'/v1')
            ->and((new TicketCreatedNotification($userTicket))->toMail($ada)->actionUrl)->toBeNull();

        config()->set('tickets.notifications.url', 'https://help.test/tickets/{reference}');

        $mail = (new TicketCreatedNotification($userTicket))->toMail($ada);

        expect($mail->actionUrl)->toBe('https://help.test/tickets/TCK-2')
            ->and($mail->actionText)->toBe('Open ticket TCK-2');
    });

    it('gives a guest no link when none can be issued, or when closing revoked them', function (): void {
        $guest = ntGuest();
        $ticket = ntTicket($guest);

        // The default generator needs the guest routes, which are off here.
        expect((new TicketCreatedNotification($ticket))->toMail($guest)->actionUrl)->toBeNull();

        ntGuestLinks();
        config()->set('tickets.guests.revoke_on_close', 'on');
        Tickets::for($ticket)->by(ntPerson('Grace', true))->resolve()->close();

        expect((new TicketStatusChangedNotification($ticket, TicketStatusChangedNotification::CLOSED))->toMail($guest)->actionUrl)->toBeNull();

        config()->set('tickets.guests.revoke_on_close', 'off');

        expect((new TicketStatusChangedNotification($ticket, TicketStatusChangedNotification::CLOSED))->toMail($guest)->actionUrl)->toStartWith('https://help.test/guest/TCK-1/');
    });

    it('links a merge to the ticket the conversation moved to', function (): void {
        ntGuestLinks();
        $guest = ntGuest();
        $source = ntTicket($guest);
        $target = ntTicket(ntPerson('Bob'), 'Printer jammed again');

        Tickets::for($source)->by(ntPerson('Grace', true))->mergeInto($target);

        $mail = (new TicketMergedNotification($source, $target))->toMail($guest);

        expect($mail->actionUrl)->toStartWith('https://help.test/guest/TCK-2/'.$guest->getKey())
            ->and($mail->subject)->toBe('[TCK-1] Merged into TCK-2')
            ->and($mail->introLines)->toContain('The conversation continues on TCK-2.');

        Notification::assertSentTo($guest, TicketMergedNotification::class);
    });

    it('shows what people wrote as text, never as markdown links', function (): void {
        $ada = ntPerson('Ada');
        $ticket = ntTicket($ada, 'Invoice [urgent]');
        $reply = Tickets::for($ticket)->by(ntPerson('Grace', true))->reply('Pay here: [secure portal](https://evil.test) **now** ![x](https://evil.test/p.png)');

        $html = (string) (new TicketRepliedNotification($ticket, $reply))->toMail($ada)->render();

        expect($html)->not->toContain('href="https://evil.test"')
            ->not->toContain('src="https://evil.test')
            ->not->toContain('<strong>now</strong>')
            ->toContain('[secure portal](https://evil.test)')
            ->toContain('Invoice [urgent]');
    });

    it('greets the recipient by name, or without one', function (): void {
        $ada = ntPerson('Ada');
        $nameless = User::query()->create(['email' => 'x@acme.test']);
        $ticket = ntTicket($ada);

        expect((new TicketCreatedNotification($ticket))->toMail($ada)->greeting)->toBe('Hello Ada,')
            ->and((new TicketCreatedNotification($ticket))->toMail($nameless)->greeting)->toBe('Hello,');
    });

    it('names the author of a message, or the help desk for a system one', function (): void {
        $ada = ntPerson('Ada');
        $grace = ntPerson('Grace', true);
        $ticket = ntTicket($ada);
        $reply = Tickets::for($ticket)->by($ada)->reply('Still jammed.');
        $system = Tickets::for($ticket)->asSystem()->note('Escalated by the overnight job.');

        expect((new TicketRequesterRepliedNotification($ticket, $reply))->toMail($grace)->introLines[0])->toStartWith('Ada replied on ticket TCK-1')
            ->and((new TicketNoteAddedNotification($ticket, $system))->toMail($grace)->introLines)->toBe([
                'Someone added an internal note to ticket TCK-1 — “Printer jammed”:',
                'Escalated by the overnight job.',
                'This is internal: the requester does not see it.',
            ]);
    });

    it('carries the type, the ticket and the type\'s own facts in its database payload', function (): void {
        $ada = ntPerson('Ada');
        $grace = ntPerson('Grace', true);
        $ticket = ntTicket($ada);
        $reply = Tickets::for($ticket)->by($grace)->reply('Hello.');
        $target = ntTicket(ntPerson('Bob'));

        expect((new TicketCreatedNotification($ticket))->toArray($ada))->toBe(['type' => 'created', 'ticket' => 'TCK-1', 'subject' => 'Printer jammed'])
            ->and((new TicketRepliedNotification($ticket, $reply))->toArray($ada))->toBe(['type' => 'replied', 'ticket' => 'TCK-1', 'subject' => 'Printer jammed', 'comment' => $reply->getKey(), 'author' => 'Grace'])
            ->and((new TicketAssignedNotification($ticket, $grace))->toArray($grace))->toBe(['type' => 'assigned', 'ticket' => 'TCK-1', 'subject' => 'Printer jammed', 'assignee' => 'Grace'])
            ->and((new TicketStatusChangedNotification($ticket, 'resolved'))->toArray($ada))->toBe(['type' => 'status_changed', 'ticket' => 'TCK-1', 'subject' => 'Printer jammed', 'change' => 'resolved', 'status' => 'new'])
            ->and((new TicketOverdueNotification($ticket, CarbonImmutable::parse('2030-01-01 12:00')))->toArray($grace))->toBe(['type' => 'overdue', 'ticket' => 'TCK-1', 'subject' => 'Printer jammed', 'due_at' => '2030-01-01T12:00:00+00:00'])
            ->and((new TicketMergedNotification($ticket, $target))->toArray($ada))->toBe(['type' => 'merged', 'ticket' => 'TCK-1', 'subject' => 'Printer jammed', 'target' => 'TCK-2']);
    });
});
