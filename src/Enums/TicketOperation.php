<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;

/**
 * Every state-changing operation of the public API. The manager funnels each one through
 * one method, so `Tickets::fake()` records it however it was called — facade, injected
 * manager, handle, sub-accessor, builder or model method.
 */
#[TranslatedLabels('tickets::enums.ticket_operation')]
enum TicketOperation: string implements HasColor
{
    use Helpers;

    case Create = 'create';
    case Update = 'update';
    case ChangePriority = 'change_priority';
    case ChangeCategory = 'change_category';
    case SetDueDate = 'set_due_date';
    case ChangeRequester = 'change_requester';
    case Delete = 'delete';
    case Restore = 'restore';
    case Transition = 'transition';
    case Reply = 'reply';
    case Note = 'note';
    case UpdateComment = 'update_comment';
    case DeleteComment = 'delete_comment';
    case CreateCategory = 'create_category';
    case UpdateCategory = 'update_category';
    case MoveCategory = 'move_category';
    case DeleteCategory = 'delete_category';
    case RestoreCategory = 'restore_category';
    case DefineField = 'define_field';
    case ResolveGuest = 'resolve_guest';
    case UpdateGuest = 'update_guest';
    case ConvertGuest = 'convert_guest';

    public function color(): string
    {
        return match ($this) {
            self::Create, self::CreateCategory, self::DefineField, self::Restore, self::RestoreCategory => 'green',
            self::Delete, self::DeleteCategory, self::DeleteComment => 'red',
            self::Reply, self::Note => 'blue',
            self::Transition => 'purple',
            default => 'gray',
        };
    }
}
