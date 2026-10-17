<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketTag;

/**
 * The model seam: the only place that reads `tickets.models.*`. A host swaps a model by
 * extending the packaged class and naming the subclass in config; every query, relation and
 * listener resolves the class through here.
 */
final class TicketModels
{
    /** @return class-string<Ticket> */
    public static function ticket(): string
    {
        return ModelResolver::for('tickets.models.ticket', Ticket::class);
    }

    /** @return class-string<TicketCategory> */
    public static function category(): string
    {
        return ModelResolver::for('tickets.models.category', TicketCategory::class);
    }

    /** @return class-string<TicketField> */
    public static function field(): string
    {
        return ModelResolver::for('tickets.models.field', TicketField::class);
    }

    /** @return class-string<TicketTag> */
    public static function tag(): string
    {
        return ModelResolver::for('tickets.models.tag', TicketTag::class);
    }

    /** @return class-string<TicketGuest> */
    public static function guest(): string
    {
        return ModelResolver::for('tickets.models.guest', TicketGuest::class);
    }
}
