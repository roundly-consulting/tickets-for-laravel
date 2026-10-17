<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Tickets\DataTransferObjects\TicketLinkView;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Events\TicketLinked;
use RoundlyConsulting\Tickets\Events\TicketUnlinked;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLinkException;
use RoundlyConsulting\Tickets\Exceptions\TicketNotFoundException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketLink;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Support\RecordsLocks;

function linkTicket(?Project $project = null): Ticket
{
    return Tickets::make()->subject('Network outage')->requester(User::query()->create(['name' => 'Ada']))->project($project)->asSystem()->create();
}

/**
 * @return list<array{string, string, string}>
 */
function linkViews(Ticket $ticket): array
{
    return array_map(
        static fn (TicketLinkView $view): array => [$view->ticket->reference, $view->label, $view->direction],
        Tickets::for($ticket)->links()->all(),
    );
}

describe('link_rules', function (): void {
    it('links two tickets and reads the link from both ends', function (): void {
        Event::fake([TicketLinked::class]);
        $a = linkTicket();
        $b = linkTicket();

        $links = Tickets::for($a)->asSystem()->links();
        $chained = $links->add($b, LinkType::Blocks)->add('TCK-2', LinkType::Blocks);

        expect($chained)->toBe($links)
            ->and(TicketLink::query()->count())->toBe(1)
            ->and(linkViews($a))->toBe([['TCK-2', 'blocks', 'outgoing']])
            ->and(linkViews($b))->toBe([['TCK-1', 'blocked_by', 'incoming']])
            ->and($a->activities()->where('type', ActivityType::Linked->value)->sole()->properties)->toBe(['link' => 'blocks', 'ticket' => 'TCK-2'])
            ->and($b->activities()->where('type', ActivityType::Linked->value)->sole()->properties)->toBe(['link' => 'blocked_by', 'ticket' => 'TCK-1'])
            ->and(Tickets::for($a)->links()->ticket())->toBe($a);

        Event::assertDispatchedTimes(TicketLinked::class, 1);
        Event::assertDispatched(TicketLinked::class, static fn (TicketLinked $event): bool => $event->ticket->is($a) && $event->related->is($b) && $event->type === LinkType::Blocks);
    });

    it('refuses a self link', function (): void {
        $a = linkTicket();

        Tickets::for($a)->asSystem()->links()->add($a, LinkType::RelatesTo);
    })->throws(InvalidTicketLinkException::class, 'itself');

    it('refuses a link that closes a cycle', function (LinkType $type): void {
        [$a, $b, $c] = [linkTicket(), linkTicket(), linkTicket()];

        Tickets::for($a)->asSystem()->links()->add($b, $type);
        Tickets::for($b)->asSystem()->links()->add($c, $type);

        expect(fn () => Tickets::for($c)->asSystem()->links()->add($a, $type))->toThrow(InvalidTicketLinkException::class, 'cycle')
            ->and(fn () => Tickets::for($b)->asSystem()->links()->add($a, $type))->toThrow(InvalidTicketLinkException::class)
            ->and(TicketLink::query()->count())->toBe(2);
    })->with([LinkType::Blocks, LinkType::ParentOf]);

    it('lets duplicates and relates_to links run both ways', function (): void {
        [$a, $b] = [linkTicket(), linkTicket()];

        Tickets::for($a)->asSystem()->links()->add($b, LinkType::Duplicates);
        Tickets::for($b)->asSystem()->links()->add($a, LinkType::Duplicates);

        expect(TicketLink::query()->where('type', 'duplicates')->count())->toBe(2);
    });

    it('refuses a second parent', function (): void {
        [$parent, $other, $child] = [linkTicket(), linkTicket(), linkTicket()];

        Tickets::for($parent)->asSystem()->links()->add($child, LinkType::ParentOf);

        expect(fn () => Tickets::for($other)->asSystem()->links()->add($child, LinkType::ParentOf))
            ->toThrow(InvalidTicketLinkException::class, 'TCK-3');
    });

    it('stores a relates_to link once, lower key first', function (): void {
        [$a, $b] = [linkTicket(), linkTicket()];

        Tickets::for($b)->asSystem()->links()->add($a, LinkType::RelatesTo);
        Tickets::for($a)->asSystem()->links()->add($b, LinkType::RelatesTo);

        $link = TicketLink::query()->sole();

        expect($link->ticket_id)->toBe($a->id)
            ->and($link->related_ticket_id)->toBe($b->id)
            ->and(linkViews($b))->toBe([['TCK-1', 'relates_to', 'incoming']]);
    });

    it('refuses a cross-project link only while those are off', function (): void {
        $sup = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
        [$a, $b] = [linkTicket(), linkTicket($sup)];

        Tickets::for($a)->asSystem()->links()->add($b, LinkType::RelatesTo);

        config()->set('tickets.links.cross_project', 'off');

        expect(fn () => Tickets::for($a)->asSystem()->links()->add($b, LinkType::Blocks))->toThrow(InvalidTicketLinkException::class, 'projects');
    });

    it('refuses a chain deeper than links.max_depth', function (): void {
        config()->set('tickets.links.max_depth', 2);
        [$a, $b, $c, $d] = [linkTicket(), linkTicket(), linkTicket(), linkTicket()];

        Tickets::for($b)->asSystem()->links()->add($c, LinkType::Blocks);
        Tickets::for($c)->asSystem()->links()->add($d, LinkType::Blocks);

        Tickets::for($a)->asSystem()->links()->add($b, LinkType::Blocks);
    })->throws(InvalidTicketLinkException::class, '2 levels');

    it('removes a link from either end and refuses one this ticket is not part of', function (): void {
        Event::fake([TicketUnlinked::class]);
        [$a, $b, $c] = [linkTicket(), linkTicket(), linkTicket()];
        Tickets::for($a)->asSystem()->links()->add($b, LinkType::Blocks)->add($c, LinkType::RelatesTo);

        Tickets::for($b)->asSystem()->links()->remove($a, LinkType::Blocks);
        Tickets::for($c)->asSystem()->links()->remove($a, LinkType::RelatesTo);

        expect(TicketLink::query()->count())->toBe(0)
            ->and($a->activities()->where('type', ActivityType::Unlinked->value)->count())->toBe(2)
            ->and(fn () => Tickets::for($b)->asSystem()->links()->remove($c, LinkType::Blocks))->toThrow(InvalidTicketLinkException::class, 'TCK-3');

        Event::assertDispatchedTimes(TicketUnlinked::class, 2);
    });

    it('leaves out links to a deleted ticket and refuses an unknown reference', function (): void {
        [$a, $b] = [linkTicket(), linkTicket()];
        Tickets::for($a)->asSystem()->links()->add($b, LinkType::RelatesTo);

        Tickets::for($b)->asSystem()->delete();

        expect(linkViews($a))->toBe([])
            ->and(fn () => Tickets::for($a)->asSystem()->links()->add('TCK-404', LinkType::Blocks))->toThrow(TicketNotFoundException::class);
    });
});

describe('advisory_locks', function (): void {
    it('serialises acyclic links on the links advisory row, then locks both tickets in key order', function (LinkType $type, array $expected): void {
        [$a, $b] = [linkTicket(), linkTicket()];
        // The advisory row exists after its first use; the first use also inserts it.
        Tickets::for(linkTicket())->asSystem()->links()->add(linkTicket(), LinkType::Blocks);
        RecordsLocks::start();
        $keys = [];
        DB::listen(static function (QueryExecuted $query) use (&$keys): void {
            if (str_contains($query->sql, 'lock-') && str_contains($query->sql, '"tickets"')) {
                $keys[] = $query->bindings[0] ?? null;
            }
        });

        Tickets::for($b)->asSystem()->links()->add($a, $type);

        expect(RecordsLocks::tables())->toBe($expected)
            ->and($keys)->toBe([$a->id, $b->id]);
    })->with([
        'blocks' => [LinkType::Blocks, ['ticket_sequences', 'tickets', 'tickets']],
        'parent_of' => [LinkType::ParentOf, ['ticket_sequences', 'tickets', 'tickets']],
        'relates_to' => [LinkType::RelatesTo, ['tickets', 'tickets']],
    ])->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');
});
