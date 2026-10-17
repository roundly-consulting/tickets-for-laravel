<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Http\Resources\Party;
use RoundlyConsulting\Tickets\Http\Resources\Present;
use RoundlyConsulting\Tickets\Http\Resources\TicketActivityResource;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Tickets\Tests\Fixtures\PlainPriority;

beforeEach(fn () => apiAbilities());

it('shows a person outside every alias list by name only, and a nameless one without a name', function (): void {
    $document = Document::query()->create();
    $named = new Document(['name' => 'Spec']);

    expect(Party::alias($document))->toBeNull()
        ->and(Party::name($document))->toBeNull()
        ->and(Party::name($named))->toBe('Spec')
        ->and(Present::state(null))->toBeNull();
});

describe('presented values', function (): void {
    it('presents a shipped channel with its label and colour, in English and Slovak', function (): void {
        $agent = apiAgent();
        $ticket = Tickets::make()->subject('Printer jammed')->requester(apiUser())->channel('web')->asSystem()->create();

        $this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}")
            ->assertJsonPath('data.channel', ['value' => 'web', 'label' => 'Web', 'color' => 'blue']);

        app()->setLocale('sk');

        expect(Present::channel('email'))->toBe(['value' => 'email', 'label' => 'E-mail', 'color' => 'amber'])
            ->and(Present::channel(null))->toBeNull();
    });

    it('presents a host channel by its value, or by the label the host published', function (): void {
        config()->set('tickets.channels', ['web', 'whatsapp', 'kiosk']);
        $agent = apiAgent();
        $ticket = Tickets::make()->subject('Printer jammed')->requester(apiUser())->channel('whatsapp')->asSystem()->create();
        app('translator')->addLines(['enums.ticket_channel.kiosk' => 'Lobby kiosk', 'enums.ticket_channel.web' => 'Website'], 'en', 'tickets');

        $this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}")
            ->assertJsonPath('data.channel', ['value' => 'whatsapp', 'label' => 'whatsapp', 'color' => null]);

        expect(Present::channel('kiosk'))->toBe(['value' => 'kiosk', 'label' => 'Lobby kiosk', 'color' => null])
            ->and(Present::channel('web'))->toBe(['value' => 'web', 'label' => 'Website', 'color' => 'blue'])
            ->and(Present::channel('a.b'))->toBe(['value' => 'a.b', 'label' => 'a.b', 'color' => null]);
    });

    it('gives status and priority their colour, and none to a priority the enum lost or a host enum without colours', function (): void {
        $agent = apiAgent();
        $ticket = apiTicket();
        $lost = apiTicket(subject: 'Lost priority');
        Ticket::query()->whereKey($lost->getKey())->update(['priority' => 'critical']);

        $this->actingAs($agent)->getJson("/api/tickets/{$ticket->reference}")
            ->assertJsonPath('data.status', ['value' => 'new', 'label' => 'New', 'category' => 'open', 'color' => 'sky'])
            ->assertJsonPath('data.priority', ['value' => 'normal', 'label' => 'Normal', 'weight' => 20, 'color' => 'blue']);
        $this->actingAs($agent)->getJson("/api/tickets/{$lost->reference}")
            ->assertJsonPath('data.priority', ['value' => 'critical', 'label' => null, 'weight' => 0, 'color' => null]);

        config()->set('tickets.priority.enum', PlainPriority::class);
        Ticket::query()->whereKey($lost->getKey())->update(['priority' => 'p1']);

        expect(Present::priority($lost->fresh() ?? $lost))->toBe(['value' => 'p1', 'label' => 'p1', 'weight' => 1, 'color' => null]);
    });
});

it('never shows the host\'s metadata, to an agent or the requester', function (): void {
    $requester = apiUser();
    $ticket = Tickets::make()->subject('Printer jammed')->requester($requester)->meta(['crm_id' => 'C-SECRET-1'])->asSystem()->create();

    foreach ([apiAgent(), $requester] as $viewer) {
        $response = $this->actingAs($viewer)->getJson("/api/tickets/{$ticket->reference}")->assertOk();
        $list = $this->actingAs($viewer)->getJson('/api/tickets')->assertOk();

        expect($response->json('data'))->not->toHaveKey('meta')
            ->and($response->getContent().$list->getContent())->not->toContain('C-SECRET-1');
    }
});

it('reduces a morph outside the alias lists to a nameless type for the requester side', function (): void {
    $requester = apiUser();
    $ticket = apiTicket($requester);
    $entry = TicketActivity::query()->create([
        'ticket_id' => $ticket->getKey(), 'type' => 'assigned', 'is_system' => true, 'visibility' => 'public',
        'properties' => ['to' => ['type' => 'App\\Models\\Robot', 'id' => 7], 'note' => 'kept'], 'occurred_at' => now(),
    ]);
    $request = Request::create('/');
    Viewer::user($requester)->attach($request);

    expect((new TicketActivityResource($entry->setRelation('ticket', $ticket)))->toArray($request)['properties'])
        ->toBe(['to' => ['type' => null], 'note' => 'kept']);
});

it('passes a reply transition its payload, in a reply and in bulk', function (): void {
    $agent = apiAgent();
    $one = apiTicket();
    $two = apiTicket();

    $this->actingAs($agent)->postJson("/api/tickets/{$one->reference}/replies", ['body' => 'Fixed.', 'transition' => 'resolve', 'payload' => ['resolution' => 'Reseated the drum']])->assertCreated();
    $this->actingAs($agent)->postJson('/api/tickets/bulk', ['action' => 'transition', 'transition' => 'resolve', 'payload' => ['resolution' => 'Batch'], 'tickets' => [$two->reference]])->assertOk();

    expect($one->fresh()?->status?->value)->toBe('resolved')
        ->and($one->lifecycleHistory()->latest('id')->first()?->context)->toMatchArray(['resolution' => 'Reseated the drum'])
        ->and($two->lifecycleHistory()->latest('id')->first()?->context)->toMatchArray(['resolution' => 'Batch']);
});

it('refuses a name that is neither a text nor a locale map', function (mixed $name): void {
    $this->actingAs(apiAdmin())->postJson('/api/ticket-categories', ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
})->with([
    'a number' => [42],
    'an empty map' => [[]],
    'a bad locale' => [['English' => 'Hardware']],
    'a text that is not a string' => [['en' => ['Hardware']]],
]);

it('matches every ticket for a blank search', function (): void {
    apiTicket();
    apiTicket();

    expect(Ticket::query()->search('  ')->count())->toBe(2);
});

it('finds no file that is not a uuid, or that belongs to nobody, through a guest link', function (): void {
    Storage::fake('local');
    $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
    $ticket = Tickets::make()->subject('A')->requester($guest)->asSystem()->create();
    $global = app(MediaLibraryManager::class)->add(UploadedFile::fake()->createWithContent('g.txt', 'Some plain text.'))->toBucket('attachments');
    $sign = static fn (string $media): string => URL::temporarySignedRoute('tickets.guest.attachment', now()->addDay(), [
        'ticket' => $ticket->reference, 'media' => $media, 'guest' => $guest->getKey(), 'v' => 1,
    ]);

    $this->getJson($sign(str_repeat('f', 36)))->assertNotFound()->assertJson(['code' => 'attachment_not_found']);
    $this->getJson($sign($global->uuid))->assertNotFound()->assertJson(['code' => 'attachment_not_found']);
});
