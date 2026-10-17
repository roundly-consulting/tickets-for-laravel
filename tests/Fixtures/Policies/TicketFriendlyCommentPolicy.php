<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Policies;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Comments\Policies\CommentPolicy;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The documented recipe for a host running `comments.authorization`: its own comments stay
 * locked down, while ticket comments are left to tickets' policy — guest replies, system
 * notes, the auto-approval and the auto-close lock all run without a privileged user.
 */
final class TicketFriendlyCommentPolicy extends CommentPolicy
{
    public function create(?Model $user, ?Model $commentable = null): bool
    {
        return $commentable instanceof Ticket;
    }

    public function update(?Model $user, Comment $comment): bool
    {
        return $comment->commentable instanceof Ticket;
    }

    public function delete(?Model $user, Comment $comment): bool
    {
        return $comment->commentable instanceof Ticket;
    }

    public function moderate(?Model $user, Comment $comment): bool
    {
        return $comment->commentable instanceof Ticket;
    }

    public function lock(?Model $user, Model $lockable): bool
    {
        return $lockable instanceof Ticket;
    }

    public function unlock(?Model $user, Model $lockable): bool
    {
        return $lockable instanceof Ticket;
    }
}
