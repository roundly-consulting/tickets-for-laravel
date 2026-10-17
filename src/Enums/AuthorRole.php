<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;

/**
 * The role a reply author holds on a ticket, computed when the reply is posted.
 */
#[TranslatedLabels('tickets::enums.author_role')]
enum AuthorRole: string implements HasColor
{
    use Helpers;

    case Agent = 'agent';
    case Requester = 'requester';
    case Participant = 'participant';
    case System = 'system';

    public function color(): string
    {
        return match ($this) {
            self::Agent => 'indigo',
            self::Requester => 'blue',
            self::Participant => 'sky',
            self::System => 'gray',
        };
    }
}
