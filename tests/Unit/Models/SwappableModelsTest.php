<?php

declare(strict_types=1);

use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketTag;

it('ships every swappable model as the default of its config key', function (string $model, string $key): void {
    expect($model)->toBeSwappableVia($key);
})->with([
    [Ticket::class, 'tickets.models.ticket'],
    [TicketCategory::class, 'tickets.models.category'],
    [TicketField::class, 'tickets.models.field'],
    [TicketTag::class, 'tickets.models.tag'],
    [TicketGuest::class, 'tickets.models.guest'],
]);
