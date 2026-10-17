<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Events\GuestConverted;
use RoundlyConsulting\Tickets\Events\TicketRequesterChanged;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

/**
 * @return array<string, mixed>
 */
function guestFields(TicketGuest $guest): array
{
    return $guest->fresh()?->getAttachedAttributes()->sortKeys()->all() ?? [];
}

describe('guest_resolution', function (): void {
    it('creates a guest with every given field and finds it again by a normalised identifying value', function (): void {
        $first = Tickets::guests()->resolve(['name' => ' Jo Doe ', 'email' => ' Jo@Acme.TEST ', 'phone' => '+421 (900) 123-456']);
        $again = Tickets::guests()->resolve(new GuestData(['name' => 'Jo', 'email' => 'jo@acme.test']));
        $byPhone = Tickets::guests()->resolve(['name' => 'Jo', 'phone' => '+421900123456']);

        expect(guestFields($first))->toBe(['email' => 'jo@acme.test', 'name' => 'Jo Doe', 'phone' => '+421900123456'])
            ->and($again->is($first))->toBeTrue()
            ->and($byPhone->is($first))->toBeTrue()
            ->and($first->displayName())->toBe('Jo Doe')
            ->and($first->routeNotificationFor('mail'))->toBe('jo@acme.test')
            ->and($first->routeNotificationFor('vonage'))->toBe('+421900123456')
            ->and($first->routeNotificationFor('slack'))->toBeNull()
            ->and(TicketGuest::query()->count())->toBe(1);
    });

    it('tries the identifying fields in config order', function (): void {
        $byEmail = Tickets::guests()->resolve(['name' => 'A', 'email' => 'a@acme.test']);
        $byPhone = Tickets::guests()->resolve(['name' => 'B', 'phone' => '111']);

        // The e-mail matches A and A has no phone yet, so the submission is A's; B's phone
        // is never copied onto A (it is unique — another guest holds it).
        $resolved = Tickets::guests()->resolve(['name' => 'A', 'email' => 'a@acme.test', 'phone' => '111']);

        expect($resolved->is($byEmail))->toBeTrue()
            ->and(guestFields($byEmail))->toBe(['email' => 'a@acme.test', 'name' => 'A'])
            ->and(guestFields($byPhone))->toBe(['name' => 'B', 'phone' => '111']);
    });

    it('fills blank fields on the match but leaves differing ones alone', function (): void {
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);

        Tickets::guests()->resolve(['name' => 'Joanna', 'email' => 'jo@acme.test', 'phone' => '222']);

        expect(guestFields($guest))->toBe(['email' => 'jo@acme.test', 'name' => 'Jo', 'phone' => '222']);
    });

    it('updates differing non-identifying fields with update_on_resolve', function (): void {
        config()->set('tickets.guests.update_on_resolve', true);
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);

        Tickets::guests()->resolve(['name' => 'Joanna', 'email' => 'jo@acme.test']);

        expect(guestFields($guest)['name'])->toBe('Joanna');
    });

    it('restores a soft-deleted guest that writes in again', function (): void {
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
        $guest->delete();

        expect(Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test'])->is($guest))->toBeTrue()
            ->and($guest->fresh()?->trashed())->toBeFalse();
    });

    it('validates the submission against the configured fields', function (array $fields, string $key): void {
        try {
            Tickets::guests()->resolve($fields);
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($key);

            return;
        }

        $this->fail('Expected a ValidationException.');
    })->with([
        'unknown field' => [['name' => 'Jo', 'email' => 'jo@acme.test', 'shoe_size' => 42], 'fields.shoe_size'],
        'missing name' => [['email' => 'jo@acme.test'], 'fields.name'],
        'bad email' => [['name' => 'Jo', 'email' => 'not-an-email'], 'fields.email'],
        'no identifying field' => [['name' => 'Jo'], 'fields.email'],
        'blank identifying field' => [['name' => 'Jo', 'email' => '   '], 'fields.email'],
    ]);

    it('keys guest errors guest.<name> where a ticket takes the guest, fields.<name> on the accessor', function (Closure $submit, string $key): void {
        try {
            $submit();
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe([$key]);

            return;
        }

        $this->fail('Expected a ValidationException.');
    })->with([
        'filing a ticket' => [fn () => Tickets::make()->subject('x')->guest(['name' => 'Jo', 'email' => 'nope'])->asSystem()->create(), 'guest.email'],
        'filing a ticket, an unknown field' => [fn () => Tickets::make()->subject('x')->guest(['name' => 'Jo', 'email' => 'jo@acme.test', 'shoe' => 42])->asSystem()->create(), 'guest.shoe'],
        'changing the requester' => [fn () => Tickets::for(Tickets::make()->subject('x')->requester(User::query()->create(['name' => 'Ada']))->asSystem()->create())
            ->asSystem()->changeRequester(new GuestData(['name' => 'Jo', 'email' => 'nope'])), 'guest.email'],
        'the guests accessor' => [fn () => Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'nope']), 'fields.email'],
    ]);

    it('resolves the guest before it validates the custom fields, so a guest error carries guest keys only', function (): void {
        Tickets::fields()->make('email', FieldType::Email)->define();

        try {
            Tickets::make()->subject('x')->guest(['name' => 'Jo', 'email' => 'nope'])->fields(['email' => 'nope'])->asSystem()->create();
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(['guest.email']);

            return;
        }

        $this->fail('Expected a ValidationException.');
    });

    it('fills only the blanks a submission gives a value for — a null leaves the stored value', function (): void {
        $first = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test', 'phone' => '+421900123456']);

        $again = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test', 'phone' => null]);

        expect($again->is($first))->toBeTrue()
            ->and(guestFields($first))->toBe(['email' => 'jo@acme.test', 'name' => 'Jo', 'phone' => '+421900123456']);
    });

    it('accepts a guest without an identifying field when not required', function (): void {
        config()->set('tickets.guests.require_one_identifying', false);

        expect(Tickets::guests()->resolve(['name' => 'Jo'])->displayName())->toBe('Jo');
    });
});

describe('guest_identity', function (): void {
    it('turns a conflicting submission into a new guest holding only the unclaimed identifying values', function (): void {
        $alice = Tickets::guests()->resolve(['name' => 'Alice', 'email' => 'alice@acme.test', 'phone' => '111']);

        // Alice's phone, someone else's e-mail: never route this person's ticket to Alice.
        $newcomer = Tickets::guests()->resolve(['name' => 'Bob', 'email' => 'bob@acme.test', 'phone' => '111']);

        expect($newcomer->is($alice))->toBeFalse()
            ->and(guestFields($newcomer))->toBe(['email' => 'bob@acme.test', 'name' => 'Bob'])
            ->and(guestFields($alice))->toBe(['email' => 'alice@acme.test', 'name' => 'Alice', 'phone' => '111']);
    });

    it('creates a conflicting guest with no identifying value when every one is claimed', function (): void {
        Tickets::guests()->resolve(['name' => 'Alice', 'email' => 'alice@acme.test', 'phone' => '111']);
        Tickets::guests()->resolve(['name' => 'Bob', 'email' => 'bob@acme.test', 'phone' => '222']);

        $mixed = Tickets::guests()->resolve(['name' => 'Eve', 'email' => 'alice@acme.test', 'phone' => '222']);

        expect(guestFields($mixed))->toBe(['name' => 'Eve'])
            ->and(TicketGuest::query()->count())->toBe(3);
    });

    it('frees the identifying values of a converted guest for the next guest', function (): void {
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
        Tickets::guests()->convert($guest, User::query()->create(['name' => 'Jo']));

        $next = Tickets::guests()->resolve(['name' => 'Jo again', 'email' => 'jo@acme.test']);

        expect($next->is($guest))->toBeFalse()
            ->and(guestFields($next)['email'])->toBe('jo@acme.test');
    });
});

it('updates a guest partially, clears a field with null and refuses a value another guest holds', function (): void {
    $jo = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test', 'phone' => '111']);
    Tickets::guests()->resolve(['name' => 'Al', 'email' => 'al@acme.test']);

    Tickets::guests()->update($jo, ['name' => 'Joanna', 'phone' => null]);
    Tickets::guests()->update($jo, new GuestData(['email' => 'JO@acme.test']));

    expect(guestFields($jo))->toBe(['email' => 'jo@acme.test', 'name' => 'Joanna'])
        ->and(fn () => Tickets::guests()->update($jo, ['email' => 'al@acme.test']))->toThrow(ValidationException::class);
});

describe('guest_conversion', function (): void {
    it('re-points every ticket and watch of the guest to the user atomically', function (): void {
        Event::fake([GuestConverted::class, TicketRequesterChanged::class]);
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
        $user = User::query()->create(['name' => 'Jo']);
        $first = Tickets::make()->subject('a')->requester($guest)->asSystem()->create();
        $second = Tickets::make()->subject('b')->requester($guest)->asSystem()->create();
        $watched = Ticket::factory()->create();
        $alreadyWatched = Ticket::factory()->create();
        TicketWatcher::factory()->create(['ticket_id' => $watched->id, 'watcher_type' => $guest->getMorphClass(), 'watcher_id' => $guest->id]);
        TicketWatcher::factory()->create(['ticket_id' => $alreadyWatched->id, 'watcher_type' => $guest->getMorphClass(), 'watcher_id' => $guest->id]);
        TicketWatcher::factory()->create(['ticket_id' => $alreadyWatched->id, 'watcher_type' => $user->getMorphClass(), 'watcher_id' => $user->id]);

        $moved = Tickets::guests()->convert($guest, $user);

        expect($moved)->toBe(2)
            ->and($first->fresh()?->requester->is($user))->toBeTrue()
            ->and($second->fresh()?->requester->is($user))->toBeTrue()
            ->and($first->activities()->where('type', 'requester_changed')->first()?->properties['converted'] ?? null)->toBeTrue()
            ->and(TicketWatcher::query()->where('watcher_type', $guest->getMorphClass())->count())->toBe(0)
            // The guest watched its own two tickets from creation (tickets.watchers.auto.requester).
            ->and(TicketWatcher::query()->where('watcher_type', $user->getMorphClass())->pluck('ticket_id')->sort()->values()->all())
            ->toEqual([$first->id, $second->id, $watched->id, $alreadyWatched->id])
            ->and($guest->fresh()?->trashed())->toBeTrue()
            ->and(guestFields($guest))->toBe(['name' => 'Jo']);

        Event::assertDispatched(GuestConverted::class, static fn (GuestConverted $event): bool => $event->tickets === 2 && $event->user->is($user));
        Event::assertDispatchedTimes(TicketRequesterChanged::class, 2);
    });

    it('converts a cc watch the user starts watching concurrently instead of rolling back', function (): void {
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
        $user = User::query()->create(['name' => 'Jo']);
        $cc = Ticket::factory()->create();
        TicketWatcher::factory()->create(['ticket_id' => $cc->id, 'watcher_type' => $guest->getMorphClass(), 'watcher_id' => $guest->id]);

        // Conversion never locks a ticket the guest only watches, so another session may add the
        // user as its watcher at any moment: here, right before the conversion writes the user's
        // watch row — the latest moment there is.
        $raced = false;
        DB::connection()->beforeExecuting(static function (string $query) use (&$raced, $cc, $user): void {
            if ($raced || preg_match('/^\s*(insert|update)\b.*ticket_watchers/is', $query) !== 1) {
                return;
            }

            $raced = true;
            TicketWatcher::query()->toBase()->insert([
                'ticket_id' => $cc->id, 'watcher_type' => $user->getMorphClass(), 'watcher_id' => $user->id, 'auto' => false,
            ]);
        });

        expect(Tickets::guests()->convert($guest, $user))->toBe(0)
            ->and($raced)->toBeTrue()
            ->and(TicketWatcher::query()->where('ticket_id', $cc->id)->get()->map(static fn (TicketWatcher $watch): array => [$watch->watcher_type, (string) $watch->watcher_id])->all())
            ->toBe([[$user->getMorphClass(), (string) $user->id]])
            ->and($guest->fresh()?->trashed())->toBeTrue();
    });

    it('rolls the whole conversion back when one step fails', function (): void {
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
        $ticket = Tickets::make()->subject('a')->requester($guest)->asSystem()->create();
        $user = User::query()->create(['name' => 'Jo']);

        TicketGuest::deleting(static fn (): never => throw new RuntimeException('host refused'));

        expect(fn () => Tickets::guests()->convert($guest, $user))->toThrow(RuntimeException::class);

        expect($ticket->fresh()?->requester->is($guest))->toBeTrue()
            ->and(guestFields($guest)['email'] ?? null)->toBe('jo@acme.test');
    });
});

describe('guest field configuration', function (): void {
    it('refuses a broken field declaration, naming the key', function (array $fields, string $key): void {
        config()->set('tickets.guests.fields', $fields);

        expect(fn () => Tickets::guests()->resolve(['name' => 'Jo']))->toThrow(InvalidTicketsConfigurationException::class, $key);
    })->with([
        'empty' => [[], 'tickets.guests.fields'],
        'bad name' => [['Name' => ['type' => 'string', 'display' => true]], 'tickets.guests.fields.Name'],
        'not an array' => [['name' => 'string'], 'tickets.guests.fields.name'],
        'unknown type' => [['name' => ['type' => 'text', 'display' => true]], 'tickets.guests.fields.name.type'],
        'rules not a list' => [['name' => ['rules' => 'required', 'display' => true]], 'tickets.guests.fields.name.rules'],
        'route not a string' => [['name' => ['route' => 5, 'display' => true]], 'tickets.guests.fields.name.route'],
        'unknown normalize' => [['name' => ['normalize' => 'upper', 'display' => true]], 'tickets.guests.fields.name.normalize'],
        'identifying and encrypted' => [['name' => ['display' => true], 'email' => ['identifying' => true, 'encrypted' => true]], 'tickets.guests.fields.email'],
        'no display field' => [['name' => ['type' => 'string']], 'display'],
        'two display fields' => [['name' => ['display' => true], 'alias' => ['display' => true]], 'display'],
        'flag not a boolean' => [['name' => ['display' => 'sometimes']], 'tickets.guests.fields.name.display'],
    ]);

    it('declares identifying fields unique among guests to attributes', function (): void {
        expect((new TicketGuest)->attributeDefinitions())->toBe([
            'name' => ['type' => 'string', 'rules' => ['required', 'string', 'max:120'], 'unique' => 'none', 'encrypted' => false],
            'email' => ['type' => 'string', 'rules' => ['nullable', 'email:rfc', 'max:191'], 'unique' => 'owner', 'encrypted' => false],
            'phone' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:32'], 'unique' => 'owner', 'encrypted' => false],
        ]);
    });

    it('stores a non-string field in its type', function (): void {
        config()->set('tickets.guests.fields.crm_id', ['type' => 'integer', 'rules' => ['nullable', 'integer'], 'identifying' => true]);

        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'crm_id' => 42]);

        expect(Tickets::guests()->resolve(['name' => 'Jo', 'crm_id' => 42])->is($guest))->toBeTrue()
            ->and(guestFields($guest)['crm_id'])->toBe(42);
    });
});
