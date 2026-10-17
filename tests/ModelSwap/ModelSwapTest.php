<?php

declare(strict_types=1);

use RoundlyConsulting\Tickets\DataTransferObjects\CategoryData;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\CustomCategory;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\CustomField;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\CustomGuest;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\CustomTicket;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

/**
 * The swaps are real: every flow creates (and returns) the host's subclass, counted through
 * its own `created` events — instanceof alone would pass a row created as the packaged class.
 */
it('creates tickets as the configured ticket model', function (): void {
    expect('tickets.models.ticket')->toHonourModelSwap(CustomTicket::class, function (): array {
        $ticket = Tickets::make()->subject('a')->requester(User::query()->create())->create();

        return [$ticket, Tickets::for($ticket->reference)->ticket()];
    });
});

it('creates categories as the configured category model', function (): void {
    expect('tickets.models.category')->toHonourModelSwap(CustomCategory::class, function (): array {
        $parent = Tickets::categories()->create(new CategoryData(name: 'Hardware'));
        $child = Tickets::categories()->make('Printers')->under($parent)->create();

        return [$parent, $child, $child->parent, ...Tickets::categories()->tree()];
    });
});

it('resolves guests as the configured guest model', function (): void {
    expect('tickets.models.guest')->toHonourModelSwap(CustomGuest::class, function (): array {
        $guest = Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
        $ticket = Tickets::make()->subject('a')->guest(['name' => 'Jo', 'email' => 'jo@acme.test'])->create();

        return [$guest, $ticket->requester];
    });
});

it('defines fields as the configured field model', function (): void {
    expect('tickets.models.field')->toHonourModelSwap(CustomField::class, fn () => Tickets::fields()->make('asset_tag', FieldType::Text)->define());
});
