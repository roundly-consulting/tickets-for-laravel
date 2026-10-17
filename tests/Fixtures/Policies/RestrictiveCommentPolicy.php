<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Policies;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Comments\Policies\CommentPolicy;

/**
 * A host policy that lets nobody comment or moderate — ticket comments included.
 */
final class RestrictiveCommentPolicy extends CommentPolicy
{
    public function create(?Model $user, ?Model $commentable = null): bool
    {
        return false;
    }

    public function moderate(?Model $user, Comment $comment): bool
    {
        return false;
    }

    public function lock(?Model $user, Model $lockable): bool
    {
        return false;
    }
}
