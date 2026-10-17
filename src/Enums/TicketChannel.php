<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;

/**
 * The intake channels the package ships labels for. `tickets.channels` lists the channels a
 * host accepts; a host-added channel is stored as its plain string.
 */
#[TranslatedLabels('tickets::enums.ticket_channel')]
enum TicketChannel: string implements HasColor
{
    use Helpers;

    case Web = 'web';
    case Email = 'email';
    case Api = 'api';
    case Phone = 'phone';
    case Chat = 'chat';

    public function color(): string
    {
        return match ($this) {
            self::Web => 'blue',
            self::Email => 'amber',
            self::Api => 'gray',
            self::Phone => 'green',
            self::Chat => 'purple',
        };
    }
}
