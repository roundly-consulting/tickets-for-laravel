<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;

/**
 * Who may read an activity entry: everyone who can view the ticket, or agents only.
 */
#[TranslatedLabels('tickets::enums.activity_visibility')]
enum ActivityVisibility: string implements HasColor
{
    use Helpers;

    case Public = 'public';
    case Internal = 'internal';

    public function color(): string
    {
        return match ($this) {
            self::Public => 'green',
            self::Internal => 'amber',
        };
    }
}
