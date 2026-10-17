<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RoundlyConsulting\Comments\Enums\CommentStatus;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Exceptions\TicketCommentNotFoundException;
use RoundlyConsulting\Tickets\Http\Requests\ListCommentsRequest;
use RoundlyConsulting\Tickets\Http\Requests\MessageRequest;
use RoundlyConsulting\Tickets\Http\Requests\ReplyRequest;
use RoundlyConsulting\Tickets\Http\Requests\UpdateCommentRequest;
use RoundlyConsulting\Tickets\Http\Resources\TicketCommentResource;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * `/tickets/{ticket}/comments`, `/replies`, `/notes` — the conversation. Only an agent reads the
 * internal thread (`?internal=1`); everyone else reads the public one, and a message they cannot
 * read is not found for them — never forbidden, which would tell them it exists.
 *
 * @internal
 */
final class CommentController extends Controller
{
    public function index(ListCommentsRequest $request, string $ticket): AnonymousResourceCollection
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);

        if ($request->internal()) {
            $this->authorize($viewer, 'viewInternal', [$model]);
        }

        $comments = $this->handle($model, $viewer)->thread(internal: $request->internal());

        return TicketCommentResource::collection(self::prepare($comments, $model));
    }

    public function reply(ReplyRequest $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'reply', [$model]);

        $transition = $request->transition();

        if ($transition !== null) {
            $this->authorize($viewer, 'transition', [$model, $transition]);
        }

        $comment = $this->handle($model, $viewer)->reply(
            $request->body(),
            transition: $transition,
            attachments: $request->attachments(),
            channel: $request->channel(),
            reason: $request->reason(),
            payload: $request->payload(),
        );

        return self::created($comment, $model);
    }

    public function note(MessageRequest $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'note', [$model]);

        $comment = $this->handle($model, $viewer)->note($request->body(), $request->attachments(), $request->channel());

        return self::created($comment, $model);
    }

    public function update(UpdateCommentRequest $request, string $ticket, string $comment): TicketCommentResource
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $message = self::message($model, $comment, $viewer);
        $this->authorize($viewer, 'updateComment', [$model, $message]);

        $updated = $this->handle($model, $viewer)->editComment($message, $request->body());

        return new TicketCommentResource(self::prepare(new Collection([$updated]), $model)->firstOrFail());
    }

    public function destroy(Request $request, string $ticket, string $comment): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $message = self::message($model, $comment, $viewer);
        $this->authorize($viewer, 'deleteComment', [$model, $message]);

        $this->handle($model, $viewer)->deleteComment($message);

        return new JsonResponse(null, 204);
    }

    /**
     * A message of this ticket the viewer can read — scoped to the ticket, and to its public
     * thread for anyone but an agent.
     */
    private static function message(Ticket $ticket, string $id, Viewer $viewer): Comment
    {
        $comment = ctype_digit($id) ? $ticket->comments()->whereKey((int) $id)->first() : null;

        if ($comment === null
            || (! $viewer->isAgentOn($ticket) && (TicketComments::isInternal($comment) || $comment->status !== CommentStatus::Approved))) {
            throw TicketCommentNotFoundException::missing($ticket, $id);
        }

        return $comment;
    }

    /**
     * The comments with their ticket, authors (and a guest author's fields) and files loaded.
     *
     * @param  Collection<int, Comment>  $comments
     * @return Collection<int, Comment>
     */
    public static function prepare(Collection $comments, Ticket $ticket): Collection
    {
        $comments->load([
            'actor' => static fn (MorphTo $actor): MorphTo => $actor->morphWith([TicketModels::guest() => ['attachedAttributes']]),
            'media',
        ]);

        return $comments->each(static fn (Comment $comment): Comment => $comment->setRelation('commentable', $ticket));
    }

    private static function created(Comment $comment, Ticket $ticket): JsonResponse
    {
        return (new TicketCommentResource(self::prepare(new Collection([$comment]), $ticket)->firstOrFail()))->response()->setStatusCode(201);
    }
}
