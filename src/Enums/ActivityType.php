<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;

/**
 * One entry type of a ticket's append-only activity timeline.
 */
#[TranslatedLabels('tickets::enums.activity_type')]
enum ActivityType: string implements HasColor
{
    use Helpers;

    case Created = 'created';
    case Updated = 'updated';
    case StatusChanged = 'status_changed';
    case PriorityChanged = 'priority_changed';
    case CategoryChanged = 'category_changed';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case DueChanged = 'due_changed';
    case FieldsChanged = 'fields_changed';
    case Tagged = 'tagged';
    case Untagged = 'untagged';
    case Linked = 'linked';
    case Unlinked = 'unlinked';
    case Merged = 'merged';
    case MergedFrom = 'merged_from';
    case WatcherAdded = 'watcher_added';
    case WatcherRemoved = 'watcher_removed';
    case AttachmentAdded = 'attachment_added';
    case AttachmentRemoved = 'attachment_removed';
    case Replied = 'replied';
    case NoteAdded = 'note_added';
    case RequesterChanged = 'requester_changed';
    case GuestAccessRevoked = 'guest_access_revoked';
    case Overdue = 'overdue';
    case Deleted = 'deleted';
    case Restored = 'restored';

    /**
     * Whether an entry of this type is visible to the requester side by default. Notes,
     * watcher lists and guest-access changes are agent-only.
     */
    public function defaultVisibility(): ActivityVisibility
    {
        return match ($this) {
            self::NoteAdded, self::WatcherAdded, self::WatcherRemoved, self::GuestAccessRevoked,
            self::FieldsChanged, self::Linked, self::Unlinked, self::Tagged, self::Untagged,
            self::Deleted, self::Restored => ActivityVisibility::Internal,
            default => ActivityVisibility::Public,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Created, self::Restored => 'green',
            self::StatusChanged, self::Merged, self::MergedFrom => 'purple',
            self::Assigned, self::Unassigned, self::RequesterChanged => 'blue',
            self::Overdue, self::Deleted => 'red',
            self::NoteAdded, self::GuestAccessRevoked => 'amber',
            default => 'gray',
        };
    }
}
