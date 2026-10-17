<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Contracts;

use RoundlyConsulting\Tickets\Conversation\CommentsConversationLock;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * Locks a ticket's conversation while it is in a Closed state. The package binds
 * {@see CommentsConversationLock}, which locks the
 * ticket's comments-for-laravel thread.
 */
interface ConversationLock
{
    public function lock(Ticket $ticket): void;

    public function unlock(Ticket $ticket): void;

    public function isLocked(Ticket $ticket): bool;
}
