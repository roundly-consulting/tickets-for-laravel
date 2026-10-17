<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Conversation;

use RoundlyConsulting\Comments\CommentsManager;
use RoundlyConsulting\Tickets\Contracts\ConversationLock;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The ticket's comments-for-laravel thread, locked through `Comments::lock()` /
 * `Comments::unlock()` — idempotent both ways.
 */
final readonly class CommentsConversationLock implements ConversationLock
{
    public function __construct(
        private CommentsManager $comments,
    ) {}

    public function lock(Ticket $ticket): void
    {
        if (! $this->comments->isLocked($ticket)) {
            $this->comments->lock($ticket);
        }
    }

    public function unlock(Ticket $ticket): void
    {
        if ($this->comments->isLocked($ticket)) {
            $this->comments->unlock($ticket);
        }
    }

    public function isLocked(Ticket $ticket): bool
    {
        return $this->comments->isLocked($ticket);
    }
}
