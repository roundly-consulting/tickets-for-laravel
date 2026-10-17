<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Tickets\DataTransferObjects\TagData;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateTagData;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketTagged;
use RoundlyConsulting\Tickets\Events\TicketUntagged;
use RoundlyConsulting\Tickets\Exceptions\InvalidTagException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Support\RecordsLocks;

function tagTicket(?Project $project = null): Ticket
{
    return Tickets::make()->subject('Printer offline')->requester(User::query()->create(['name' => 'Ada']))->project($project)->asSystem()->create();
}

/**
 * @return list<string>
 */
function tagSlugs(Ticket $ticket): array
{
    return Tickets::for($ticket)->tags()->all()->pluck('slug')->sort()->values()->all();
}

describe('tag admin', function (): void {
    it('creates global and project tags, with a colour', function (): void {
        $project = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);

        $global = Tickets::tags()->create('Printer', color: '#F24E29');
        $scoped = Tickets::tags()->create(new TagData('Printer', project: $project));

        expect($global->slug)->toBe('printer')
            ->and($global->color)->toBe('#f24e29')
            ->and($global->project_type)->toBeNull()
            ->and($scoped->slug)->toBe('printer')
            ->and($scoped->project?->is($project))->toBeTrue()
            ->and(Tickets::tags()->all()->modelKeys())->toBe([$global->id])
            ->and(Tickets::tags()->all($project)->modelKeys())->toEqualCanonicalizing([$global->id, $scoped->id]);
    });

    it('refuses a bad name, a bad colour and a name the scope holds', function (Closure $create, string $message): void {
        Tickets::tags()->create('printer');

        expect($create)->toThrow(InvalidTagException::class, $message);
    })->with([
        'empty' => [fn () => Tickets::tags()->create('  '), 'name'],
        'too long' => [fn () => Tickets::tags()->create(str_repeat('a', 65)), 'name'],
        'no slug' => [fn () => Tickets::tags()->create('!!!'), 'name'],
        'colour' => [fn () => Tickets::tags()->create('scanner', color: 'red'), 'red'],
        'duplicate' => [fn () => Tickets::tags()->create('Printer'), 'Printer'],
    ]);

    it('finds or creates a tag once', function (): void {
        $first = Tickets::tags()->findOrCreate('Printer');
        $again = Tickets::tags()->findOrCreate('printer');

        expect($again->is($first))->toBeTrue()
            ->and(TicketTag::query()->count())->toBe(1);
    });

    it('renames a tag, its slug following, and recolours it', function (): void {
        $tag = Tickets::tags()->create('printer');
        Tickets::tags()->create('scanner');

        Tickets::tags()->rename($tag, 'Printers');
        Tickets::tags()->recolor($tag, '#00aa00');

        expect($tag->fresh()?->slug)->toBe('printers')
            ->and($tag->fresh()?->color)->toBe('#00aa00')
            ->and(Tickets::tags()->findOrCreate('printers')->is($tag))->toBeTrue()
            ->and(fn () => Tickets::tags()->rename($tag, 'Scanner'))->toThrow(InvalidTagException::class, 'Scanner')
            ->and(Tickets::tags()->update($tag, new UpdateTagData(color: null))->color)->toBeNull();
    });

    it('drops a deleted tag off its tickets and brings it back for its name', function (): void {
        $ticket = tagTicket();
        $tag = Tickets::tags()->create('printer');
        Tickets::for($ticket)->asSystem()->tags()->attach($tag);

        Tickets::tags()->delete($tag);

        expect(tagSlugs($ticket))->toBe([])
            ->and(fn () => Tickets::tags()->rename(Tickets::tags()->create('scanner'), 'printer'))->toThrow(InvalidTagException::class);

        $back = Tickets::tags()->create('Printer', color: '#123456');

        expect($back->is($tag))->toBeTrue()
            ->and($back->trashed())->toBeFalse()
            ->and($back->color)->toBe('#123456')
            ->and(TicketTag::withTrashed()->where('slug', 'like', 'printer%')->count())->toBe(1)
            ->and(tagSlugs($ticket))->toBe(['printer']);
    });
});

describe('ticket tags', function (): void {
    it('attaches by name and model, creating unknown names, idempotently', function (): void {
        Event::fake([TicketTagged::class]);
        $ticket = tagTicket();
        $scanner = Tickets::tags()->create('scanner');

        $tags = Tickets::for($ticket)->asSystem()->tags();
        $chained = $tags->attach(['printer', $scanner])->attach('printer');

        $entry = $ticket->activities()->where('type', ActivityType::Tagged->value)->sole();

        expect($chained)->toBe($tags)
            ->and($tags->ticket())->toBe($ticket)
            ->and(tagSlugs($ticket))->toBe(['printer', 'scanner'])
            ->and(keySorted($entry->properties))->toBe(['added' => ['printer', 'scanner'], 'removed' => []]);

        Event::assertDispatchedTimes(TicketTagged::class, 1);
    });

    it('detaches, ignoring tags the ticket does not carry', function (): void {
        Event::fake([TicketUntagged::class]);
        $ticket = tagTicket();
        Tickets::for($ticket)->asSystem()->tags()->attach(['printer', 'scanner']);
        $activities = TicketActivity::query()->count();

        Tickets::for($ticket)->asSystem()->tags()->detach(['printer', 'unknown'])->detach('printer');

        expect(tagSlugs($ticket))->toBe(['scanner'])
            ->and(TicketActivity::query()->count())->toBe($activities + 1)
            ->and($ticket->activities()->where('type', ActivityType::Untagged->value)->sole()->properties)->toBe(['added' => [], 'removed' => ['printer']]);

        Event::assertDispatchedTimes(TicketUntagged::class, 1);
    });

    it('syncs to exactly a set, with one activity entry for the diff', function (): void {
        Event::fake([TicketTagged::class, TicketUntagged::class]);
        $ticket = tagTicket();
        Tickets::for($ticket)->asSystem()->tags()->attach(['printer', 'scanner']);

        Tickets::for($ticket)->asSystem()->tags()->sync(['scanner', 'toner'])->sync(['scanner', 'toner']);

        $entries = $ticket->activities()->whereIn('type', [ActivityType::Tagged->value, ActivityType::Untagged->value])->get();

        expect(tagSlugs($ticket))->toBe(['scanner', 'toner'])
            ->and($entries)->toHaveCount(2)
            ->and($entries->last()?->properties)->toBe(['added' => ['toner'], 'removed' => ['printer']]);

        Event::assertDispatchedTimes(TicketTagged::class, 2);
        Event::assertDispatchedTimes(TicketUntagged::class, 1);
    });

    it('creates an unknown name in the ticket\'s project, and finds global tags too', function (): void {
        $project = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
        $global = Tickets::tags()->create('printer');
        $ticket = tagTicket($project);

        Tickets::for($ticket)->asSystem()->tags()->attach(['printer', 'vpn']);

        $vpn = TicketTag::query()->where('slug', 'vpn')->sole();

        expect($ticket->tags()->pluck('ticket_tags.id')->all())->toContain($global->id)
            ->and($vpn->project?->is($project))->toBeTrue();
    });

    it('refuses an unknown name with free-form tags off', function (): void {
        config()->set('tickets.tags.free_form', 'off');
        $ticket = tagTicket();
        Tickets::tags()->create('printer');

        Tickets::for($ticket)->asSystem()->tags()->attach('printer');

        expect(fn () => Tickets::for($ticket)->asSystem()->tags()->attach('vpn'))->toThrow(InvalidTagException::class, 'vpn')
            ->and(tagSlugs($ticket))->toBe(['printer']);
    });

    it('refuses another project\'s tag and a deleted one', function (): void {
        $project = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
        $other = Project::query()->create(['name' => 'Facilities', 'key' => 'FAC']);
        $foreign = Tickets::tags()->create('printer', project: $other);
        $deleted = Tickets::tags()->create('old');
        Tickets::tags()->delete($deleted);
        $ticket = tagTicket($project);

        expect(fn () => Tickets::for($ticket)->asSystem()->tags()->attach($foreign))->toThrow(InvalidTagException::class, 'printer')
            ->and(fn () => Tickets::for($ticket)->asSystem()->tags()->attach($deleted))->toThrow(InvalidTagException::class)
            ->and(fn () => Tickets::for(tagTicket())->asSystem()->tags()->attach($foreign))->toThrow(InvalidTagException::class);
    });

    it('files a ticket with its tags', function (): void {
        $ticket = Tickets::make()->subject('a')->requester(User::query()->create())->tags(['printer'])->tags('scanner')->asSystem()->create();

        expect(tagSlugs($ticket))->toBe(['printer', 'scanner'])
            ->and(Ticket::query()->taggedWith('printer')->pluck('id')->all())->toBe([$ticket->id]);
    });
});

describe('advisory_locks', function (): void {
    it('takes the tags advisory row before the ticket row when a name is attached', function (): void {
        $ticket = tagTicket();
        // The advisory row exists after its first use; the first use also inserts it.
        Tickets::tags()->create('seed');
        RecordsLocks::start();

        Tickets::for($ticket)->asSystem()->tags()->attach('printer');

        // The name is unknown, so creating it takes the same advisory row again — re-entrant
        // inside one transaction — and only then the ticket row.
        expect(RecordsLocks::tables())->toBe(['ticket_sequences', 'ticket_sequences', 'tickets']);
    })->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');

    it('takes no advisory row for tags given as models', function (): void {
        $ticket = tagTicket();
        $tag = Tickets::tags()->create('printer');
        RecordsLocks::start();

        Tickets::for($ticket)->asSystem()->tags()->attach($tag);

        expect(RecordsLocks::tables()[0] ?? null)->toBe('tickets');
    })->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');

    it('serialises tag admin writes on the advisory row', function (Closure $write): void {
        $tag = Tickets::tags()->create('printer');
        RecordsLocks::start();

        $write($tag);

        expect(RecordsLocks::tables()[0] ?? null)->toBe('ticket_sequences');
    })->with([
        'create' => [fn () => Tickets::tags()->create('scanner')],
        'rename' => [fn (TicketTag $tag) => Tickets::tags()->rename($tag, 'printers')],
        'delete' => [fn (TicketTag $tag) => Tickets::tags()->delete($tag)],
    ])->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');
});
