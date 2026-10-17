<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;
use RoundlyConsulting\Tickets\Notifications\TicketAssignedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketCreatedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketDueSoonNotification;
use RoundlyConsulting\Tickets\Notifications\TicketMentionedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketMergedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketNoteAddedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketNotification;
use RoundlyConsulting\Tickets\Notifications\TicketOverdueNotification;
use RoundlyConsulting\Tickets\Notifications\TicketRepliedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketRequesterRepliedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketStatusChangedNotification;

/**
 * Every notification the package sends — the keys of `tickets.notifications.types`.
 */
#[TranslatedLabels('tickets::enums.notification_type')]
enum NotificationType: string implements HasColor
{
    use Helpers;

    case Created = 'created';
    case Assigned = 'assigned';
    case Replied = 'replied';
    case RequesterReplied = 'requester_replied';
    case NoteAdded = 'note_added';
    case Mentioned = 'mentioned';
    case StatusChanged = 'status_changed';
    case Overdue = 'overdue';
    case DueSoon = 'due_soon';
    case Merged = 'merged';

    /**
     * The packaged class — a configured replacement must extend it.
     *
     * @return class-string<TicketNotification>
     */
    public function defaultClass(): string
    {
        return match ($this) {
            self::Created => TicketCreatedNotification::class,
            self::Assigned => TicketAssignedNotification::class,
            self::Replied => TicketRepliedNotification::class,
            self::RequesterReplied => TicketRequesterRepliedNotification::class,
            self::NoteAdded => TicketNoteAddedNotification::class,
            self::Mentioned => TicketMentionedNotification::class,
            self::StatusChanged => TicketStatusChangedNotification::class,
            self::Overdue => TicketOverdueNotification::class,
            self::DueSoon => TicketDueSoonNotification::class,
            self::Merged => TicketMergedNotification::class,
        };
    }

    /**
     * Whether the type goes to the requester side — and so may reach a guest.
     */
    public function reachesRequesters(): bool
    {
        return match ($this) {
            self::Created, self::Replied, self::StatusChanged, self::Merged => true,
            default => false,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Created => 'green',
            self::Assigned => 'indigo',
            self::Replied, self::RequesterReplied => 'blue',
            self::NoteAdded, self::Mentioned => 'amber',
            self::StatusChanged, self::Merged => 'purple',
            self::Overdue, self::DueSoon => 'red',
        };
    }
}
