<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;

/**
 * The fixed classification every ticket state maps to. Package logic reads only categories,
 * never concrete state values, so a host can swap the whole status lifecycle.
 */
#[TranslatedLabels('tickets::enums.status_category')]
enum StatusCategory: string implements HasColor
{
    use Helpers;

    case Open = 'open';
    case Active = 'active';
    case Waiting = 'waiting';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * The categories a ticket still needs work in: Open, Active and Waiting.
     *
     * @return list<self>
     */
    public static function unresolved(): array
    {
        return [self::Open, self::Active, self::Waiting];
    }

    public function isUnresolved(): bool
    {
        return in_array($this, self::unresolved(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'blue',
            self::Active => 'indigo',
            self::Waiting => 'amber',
            self::Resolved => 'green',
            self::Closed => 'gray',
        };
    }
}
