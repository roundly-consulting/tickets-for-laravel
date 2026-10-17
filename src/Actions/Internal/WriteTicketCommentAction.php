<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Comments\CommentsManager;
use RoundlyConsulting\Comments\Enums\CommentStatus;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\Tickets\DataTransferObjects\ReplyData;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * Writes one message of a ticket's conversation through comments-for-laravel, inside the
 * calling action's transaction: a root comment (v1 has no threaded replies), `visible =
 * false` for an internal note, the `meta.tickets` namespace, then the host's moderation:
 *
 *  - a reply `comments.require_approval` left pending is approved at once
 *    (`tickets.comments.auto_approve`);
 *  - a reply the host's blocklist held (`pending` / `hidden`) is never approved — that would
 *    bypass the host's filter — so it stays held, agent-visible only.
 *
 * Attachments land in the comment's own attachments bucket, and the ticket's activity clock
 * moves with a plain query, so `updated_at` (a public field) leaks nothing about a note.
 *
 * @internal
 */
final readonly class WriteTicketCommentAction
{
    public function __construct(
        private CommentsManager $comments,
        private MediaLibraryManager $media,
    ) {}

    public function execute(Ticket $ticket, ?Model $author, AuthorRole $role, ReplyData $data, string $channel, bool $internal): Comment
    {
        $builder = $this->comments->on($ticket)
            ->body($data->body)
            ->visible(! $internal)
            ->meta(TicketComments::meta($data->meta, $internal, $role, $channel));

        if ($author !== null) {
            $builder->as($author);
        }

        $comment = $builder->post();

        if ($comment->status === CommentStatus::Pending
            && TicketsConfig::autoApproveReplies()
            && ! $this->comments->blocklisted($data->body)) {
            $comment = $this->comments->approve($comment);
        }

        foreach ($data->attachments as $file) {
            $this->media->for($comment)->add($file)->toBucket($comment->attachmentsBucket());
        }

        $now = CarbonImmutable::now();
        $ticket->newQueryWithoutScopes()->whereKey($ticket->getKey())->toBase()->update(['last_activity_at' => $now]);
        $ticket->setAttribute('last_activity_at', $now)->syncOriginalAttribute('last_activity_at');

        return $comment;
    }
}
