<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;
use RoundlyConsulting\Tickets\Contracts\Priority;

/**
 * The shipped priority scale. Hosts swap it with `tickets.priority.enum`.
 */
#[TranslatedLabels('tickets::enums.ticket_priority')]
enum TicketPriority: string implements HasColor, Priority
{
    use Helpers;

    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function weight(): int
    {
        return match ($this) {
            self::Low => 10,
            self::Normal => 20,
            self::High => 30,
            self::Urgent => 40,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Normal => 'blue',
            self::High => 'orange',
            self::Urgent => 'red',
        };
    }
}
