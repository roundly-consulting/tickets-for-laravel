<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Comments\Enums\CommentStatus;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Exceptions\TicketCommentNotFoundException;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The ticket conversation on comments-for-laravel. Tickets keeps its own facts about a comment
 * under one `meta` key — `meta.tickets = {internal, role, channel}` — so a host's own meta keys
 * on the same comment are never touched, and a host can never spoof the namespace.
 *
 * @internal
 */
final class TicketComments
{
    public const string META = 'tickets';

    /**
     * The comment's meta with the tickets namespace written last, so it always wins over a
     * host-supplied `tickets` key.
     *
     * @param  array<string, mixed>  $hostMeta
     * @return array<string, mixed>
     */
    public static function meta(array $hostMeta, bool $internal, AuthorRole $role, string $channel): array
    {
        return [...$hostMeta, self::META => [
            'internal' => $internal,
            'role' => $role->value,
            'channel' => $channel,
        ]];
    }

    /**
     * Whether a comment is internal — `visible = false` or marked internal (defence in depth:
     * either alone keeps it from a requester).
     */
    public static function isInternal(Comment $comment): bool
    {
        return ! $comment->visible || $comment->meta(self::META.'.internal') === true;
    }

    /**
     * The role the author held when the comment was posted, or null for a comment tickets did
     * not write.
     */
    public static function role(Comment $comment): ?AuthorRole
    {
        $role = $comment->meta(self::META.'.role');

        return is_string($role) ? AuthorRole::tryFrom($role) : null;
    }

    /**
     * Who may see an entry about the comment: the requester side only when the comment is a
     * published public reply — never for a note or a reply the host's moderation holds.
     */
    public static function visibility(Comment $comment): ActivityVisibility
    {
        return ! self::isInternal($comment) && $comment->status === CommentStatus::Approved
            ? ActivityVisibility::Public
            : ActivityVisibility::Internal;
    }

    /**
     * Narrow a comment query to what the requester side may read: visible, approved and not
     * internal — a host making an internal note visible still does not reveal it.
     *
     * @param  Builder<Comment>  $comments
     */
    public static function wherePublished(Builder $comments): void
    {
        $comments->where('visible', true)
            ->where('status', CommentStatus::Approved)
            ->where(static fn (Builder $public): Builder => $public
                ->whereNull('meta')
                ->orWhereJsonDoesntContainKey('meta->'.self::META.'->internal')
                ->orWhere('meta->'.self::META.'->internal', false));
    }

    /**
     * The activity properties of an entry about the comment: its id and its author's role —
     * never a body.
     *
     * @return array<string, mixed>
     */
    public static function trail(Comment $comment): array
    {
        $role = self::role($comment);

        return ['comment' => $comment->getKey(), ...($role === null ? [] : ['role' => $role->value])];
    }

    /**
     * Whether the comment belongs to the ticket's conversation.
     */
    public static function belongsTo(Comment $comment, Ticket $ticket): bool
    {
        return $comment->commentable_type === $ticket->getMorphClass()
            && (string) $comment->commentable_id === (string) $ticket->getKey();
    }

    /**
     * The comment, once it is known to belong to the ticket — scoping is a security boundary.
     */
    public static function assertOn(Comment $comment, Ticket $ticket): Comment
    {
        if (! self::belongsTo($comment, $ticket)) {
            throw TicketCommentNotFoundException::on($ticket, $comment);
        }

        return $comment;
    }
}
