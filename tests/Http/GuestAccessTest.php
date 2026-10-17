<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Tickets\Contracts\GuestLinkGenerator;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Events\GuestAccessRevoked;
use RoundlyConsulting\Tickets\Exceptions\GuestAccessDeniedException;
use RoundlyConsulting\Tickets\Exceptions\TicketTrashedException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

beforeEach(fn () => apiAbilities());

function guestJo(string $email = 'jo@acme.test'): TicketGuest
{
    return Tickets::guests()->resolve(['name' => 'Jo', 'email' => $email]);
}

function guestTicket(?TicketGuest $guest = null, string $subject = 'Printer jammed'): Ticket
{
    return Tickets::make()->subject($subject)->requester($guest ?? guestJo())->asSystem()->create();
}

describe('guest links', function (): void {
    it('issues a link for the guest requester or a guest watcher only', function (): void {
        $guest = guestJo();
        $cc = guestJo('cc@acme.test');
        $ticket = guestTicket($guest);
        TicketWatcher::query()->create(['ticket_id' => $ticket->getKey(), 'watcher_type' => $cc->getMorphClass(), 'watcher_id' => $cc->getKey()]);

        expect(Tickets::for($ticket)->guestLink())->toContain('/api/tickets/guest/TCK-1?')
            ->and(Tickets::for($ticket)->guestLink($guest))->toContain('guest='.$guest->getKey())
            ->and(Tickets::guests()->linkFor($ticket, $cc))->toContain('guest='.$cc->getKey())
            ->and(fn () => Tickets::for($ticket)->guestLink(guestJo('eve@acme.test')))->toThrow(GuestAccessDeniedException::class)
            ->and(fn () => Tickets::for($ticket)->guestLink(User::query()->create()))->toThrow(GuestAccessDeniedException::class)
            ->and(fn () => Tickets::for(apiTicket())->guestLink())->toThrow(GuestAccessDeniedException::class);
    });

    it('refuses a link to a deleted ticket', function (): void {
        $ticket = guestTicket();
        Tickets::for($ticket)->asSystem()->delete();

        expect(fn () => Tickets::for($ticket)->guestLink())->toThrow(TicketTrashedException::class);
    });

    it('lasts guests.link_ttl', function (): void {
        CarbonImmutable::setTestNow('2026-10-17 12:00:00');
        config()->set('tickets.guests.link_ttl', 'P2D');

        $link = Tickets::for(guestTicket())->guestLink();

        expect($link)->toContain('expires='.CarbonImmutable::parse('2026-10-19 12:00:00')->getTimestamp());
        CarbonImmutable::setTestNow();
    });

    it('uses a host generator bound in its place', function (): void {
        app()->bind(GuestLinkGenerator::class, static fn (): GuestLinkGenerator => new class implements GuestLinkGenerator
        {
            public function for(Ticket $ticket, Model $recipient): string
            {
                return 'https://helpdesk.test/t/'.$ticket->reference;
            }
        });

        expect(Tickets::for(guestTicket())->guestLink())->toBe('https://helpdesk.test/t/TCK-1');
    });

    it('revokes every link at once, internally recorded, with GuestAccessRevoked after commit', function (): void {
        Event::fake([GuestAccessRevoked::class]);
        $ticket = guestTicket();
        $agent = apiAgent();
        $before = $ticket->updated_at;

        Tickets::for($ticket)->by($agent)->revokeGuestAccess();

        $entry = TicketActivity::query()->where('type', ActivityType::GuestAccessRevoked->value)->sole();

        expect($ticket->fresh()?->guest_access_version)->toBe(2)
            ->and($ticket->guest_access_version)->toBe(2)
            ->and($ticket->fresh()?->updated_at?->equalTo($before))->toBeTrue()
            ->and($entry->visibility)->toBe(ActivityVisibility::Internal)
            ->and($entry->actor?->is($agent))->toBeTrue()
            ->and(keySorted($entry->properties))->toBe(['version' => 2]);
        Event::assertDispatched(GuestAccessRevoked::class, static fn (GuestAccessRevoked $event): bool => $event->version === 2 && $event->ticket->is($ticket));
    });

    it('revokes the source links on a merge, through the revoke action', function (): void {
        Event::fake([GuestAccessRevoked::class]);
        $source = guestTicket();
        $target = apiTicket();
        $link = Tickets::for($source)->guestLink();

        Tickets::for($source)->asSystem()->mergeInto($target);

        expect($source->fresh()?->guest_access_version)->toBe(2)
            ->and(TicketActivity::query()->where('ticket_id', $source->getKey())->where('type', ActivityType::GuestAccessRevoked->value)->count())->toBe(1);
        Event::assertDispatched(GuestAccessRevoked::class);
        $this->getJson($link)->assertForbidden()->assertJson(['code' => 'guest_link_invalid']);
    });

    it('revokes on close only with guests.revoke_on_close', function (bool $revoke): void {
        config()->set('tickets.guests.revoke_on_close', $revoke ? 'on' : 'off');
        $ticket = guestTicket();
        $link = Tickets::for($ticket)->guestLink();

        Tickets::for($ticket)->asSystem()->dismiss('Spam');

        expect($ticket->fresh()?->guest_access_version)->toBe($revoke ? 2 : 1);
        $this->getJson($link)->assertStatus($revoke ? 403 : 200);
    })->with(['revoke on close' => true, 'keep on close' => false]);

    it('revokes when a state adopted outside lifecycle enters Closed too', function (): void {
        config()->set('tickets.guests.revoke_on_close', 'on');
        $ticket = guestTicket();

        Lifecycles::allowDirectWrites(static fn () => $ticket->forceFill(['status' => 'closed'])->save());

        expect($ticket->fresh()?->guest_access_version)->toBe(2);
    });
});

describe('guest endpoints', function (): void {
    it('shows the public view, the public thread and the signed action links', function (): void {
        $guest = guestJo();
        $ticket = guestTicket($guest);
        Tickets::for($ticket)->by(apiAgent())->note('SECRET-NOTE');
        Tickets::for($ticket)->by(apiAgent('Linus'))->reply('We are on it.');

        $response = $this->getJson(Tickets::for($ticket)->guestLink())->assertOk();

        expect($response->json('data.reference'))->toBe('TCK-1')
            ->and($response->json('data.requester'))->toBe(['type' => 'guest', 'name' => 'Jo'])
            ->and($response->json('messages'))->toHaveCount(1)
            ->and($response->json('messages.0.body'))->toBe('We are on it.')
            ->and($response->json('links.reply'))->toContain('/api/tickets/guest/TCK-1/replies?')
            ->and($response->json('links.transitions'))->toBe([])
            ->and((string) $response->getContent())->not->toContain('SECRET-NOTE');
    });

    it('takes a reply from the guest requester and from a guest watcher', function (): void {
        $guest = guestJo();
        $cc = guestJo('cc@acme.test');
        $ticket = guestTicket($guest);
        TicketWatcher::query()->create(['ticket_id' => $ticket->getKey(), 'watcher_type' => $cc->getMorphClass(), 'watcher_id' => $cc->getKey()]);

        $reply = $this->getJson(Tickets::for($ticket)->guestLink())->json('links.reply');
        $this->postJson($reply, ['body' => 'Still jammed.'])->assertCreated()->assertJsonPath('messages.0.role', 'requester');

        $ccReply = $this->getJson(Tickets::for($ticket)->guestLink($cc))->json('links.reply');
        $this->postJson($ccReply, ['body' => 'Same here.'])->assertCreated()->assertJsonPath('messages.1.role', 'participant');
    });

    it('offers and runs only the requester transitions', function (): void {
        $ticket = guestTicket();
        Tickets::for($ticket)->by(apiAgent())->resolve();
        $view = $this->getJson(Tickets::for($ticket)->guestLink())->assertOk();

        expect(array_keys((array) $view->json('links.transitions')))->toBe(['reopen', 'close']);

        $this->postJson((string) $view->json('links.transitions.close'))->assertOk()->assertJsonPath('data.status.value', 'closed');
        expect($view->json('links.reply'))->not->toBeNull()
            ->and($this->getJson(Tickets::for($ticket)->guestLink())->json('links.reply'))->toBeNull();
    });

    it('refuses a transition the guest may not run, even with a signed link', function (): void {
        $guest = guestJo();
        $ticket = guestTicket($guest);
        $url = URL::temporarySignedRoute('tickets.guest.transition', now()->addDay(), [
            'ticket' => $ticket->reference, 'transition' => 'start', 'guest' => $guest->getKey(), 'v' => 1,
        ]);

        $this->postJson($url)->assertForbidden()->assertJson(['code' => 'access_denied']);
        expect($ticket->fresh()?->status?->value)->toBe('new');
    });

    it('answers a reply to a closed ticket with 409 ticket_closed', function (): void {
        $ticket = guestTicket();
        $reply = $this->getJson(Tickets::for($ticket)->guestLink())->json('links.reply');
        Tickets::for($ticket)->asSystem()->dismiss('Spam');

        $this->postJson($reply, ['body' => 'Hello?'])->assertStatus(409)->assertJson(['code' => 'ticket_closed']);
    });

    it('streams a ticket file and a public reply file, and nothing else', function (): void {
        Storage::fake('local');
        $ticket = guestTicket();
        $agent = apiAgent();
        $file = Tickets::for($ticket)->by($agent)->attachments()->add(UploadedFile::fake()->createWithContent('photo.txt', 'ticket file'));
        $reply = Tickets::for($ticket)->by($agent)->reply('See attached.', attachments: [UploadedFile::fake()->createWithContent('reply.txt', 'reply file')]);
        $note = Tickets::for($ticket)->by($agent)->note('Internal.', attachments: [UploadedFile::fake()->createWithContent('note.txt', 'note file')]);
        $noteFile = $note->attachments()->firstOrFail();

        $view = $this->getJson(Tickets::for($ticket)->guestLink())->assertOk();
        $links = (array) $view->json('links.attachments');
        $replyFile = $reply->attachments()->firstOrFail();

        expect(array_keys($links))->toEqualCanonicalizing([$file->uuid, $replyFile->uuid])
            ->and($view->json('data.attachments.0.url'))->toBe($links[$file->uuid]);
        expect($this->get($links[$file->uuid])->assertOk()->streamedContent())->toBe('ticket file');
        expect($this->get($links[$replyFile->uuid])->assertOk()->streamedContent())->toBe('reply file');

        $guest = TicketGuest::query()->firstOrFail();
        $sign = static fn (string $uuid): string => URL::temporarySignedRoute('tickets.guest.attachment', now()->addDay(), [
            'ticket' => $ticket->reference, 'media' => $uuid, 'guest' => $guest->getKey(), 'v' => 1,
        ]);

        $this->getJson($sign($noteFile->uuid))->assertNotFound()->assertJson(['code' => 'attachment_not_found']);
        $this->getJson($sign('00000000-0000-4000-8000-000000000000'))->assertNotFound();
    });
});
