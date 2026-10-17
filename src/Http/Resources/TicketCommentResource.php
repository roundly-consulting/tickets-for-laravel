<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketComments;

/**
 * One message of a ticket's conversation — the package's own shape, never comments' resource:
 * the comment's raw `meta` (the host's keys included) is not exposed. An agent also sees whether
 * it is internal and its moderation status; nobody else is ever handed an internal note or a
 * held reply (the controllers read the public thread for them), and a guest author's name stays
 * with agents and the guest.
 *
 * @property Comment $resource
 *
 * @internal
 */
final class TicketCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $comment = $this->resource;
        $viewer = Viewer::of($request);
        $ticket = $comment->commentable;
        $agent = $ticket instanceof Ticket && $viewer->isAgentOn($ticket);
        $channel = $comment->meta(TicketComments::META.'.channel');

        return [
            'id' => $comment->getKey(),
            'body' => $comment->comment,
            'role' => TicketComments::role($comment)?->value,
            'author' => Party::person($comment->actor_type === null ? null : $comment->actor, $viewer, $agent),
            'channel' => is_string($channel) ? $channel : null,
            ...($agent ? ['internal' => TicketComments::isInternal($comment), 'status' => $comment->status->value] : []),
            'attachments' => $comment->attachments()
                ->map(static fn (Media $media): array => (new TicketAttachmentResource($media))->toArray($request))
                ->values()
                ->all(),
            'created_at' => Present::date($comment->created_at),
            'updated_at' => Present::date($comment->updated_at),
        ];
    }
}
