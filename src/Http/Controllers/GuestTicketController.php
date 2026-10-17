<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RoundlyConsulting\Comments\Enums\CommentStatus;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Comments\Support\CommentsConfig;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Exceptions\GuestAccessDeniedException;
use RoundlyConsulting\Tickets\Exceptions\TicketAccessDeniedException;
use RoundlyConsulting\Tickets\Exceptions\TicketAttachmentNotFoundException;
use RoundlyConsulting\Tickets\Http\GuestLinks;
use RoundlyConsulting\Tickets\Http\Queries\TicketEagerLoads;
use RoundlyConsulting\Tickets\Http\Requests\MessageRequest;
use RoundlyConsulting\Tickets\Http\Requests\TransitionRequest;
use RoundlyConsulting\Tickets\Http\Resources\TicketCommentResource;
use RoundlyConsulting\Tickets\Http\Resources\TicketResource;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\AttachmentBucket;
use RoundlyConsulting\Tickets\Support\RequesterTransitions;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `/tickets/guest/{ticket}` — what a guest does through a signed link: read the ticket and its
 * public thread, reply, run a requester transition and download a file. The view embeds the
 * signed URLs of those actions — each signed for the same ticket, guest, `v` and expiry.
 *
 * @internal
 */
final class GuestTicketController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->view($request, self::links($request));
    }

    public function reply(MessageRequest $request): JsonResponse
    {
        $links = self::links($request);

        $this->manager()->for($links->ticket())->by($links->guest())
            ->reply($request->body(), attachments: $request->attachments(), channel: $request->channel());

        return $this->view($request, $links)->setStatusCode(201);
    }

    public function transition(TransitionRequest $request, string $ticket, string $transition): JsonResponse
    {
        $links = self::links($request);

        if (! RequesterTransitions::allows($links->guest(), $links->ticket(), $transition)) {
            throw TicketAccessDeniedException::notAParticipant($links->ticket());
        }

        $this->manager()->for($links->ticket())->by($links->guest())->transition($transition, $request->reason(), $request->payload());

        return $this->view($request, $links);
    }

    public function attachment(Request $request, string $ticket, string $media): StreamedResponse
    {
        $links = self::links($request);

        return self::file($links->ticket(), $media)->toDownloadResponse();
    }

    private function view(Request $request, GuestLinks $links): JsonResponse
    {
        $ticket = TicketEagerLoads::load($links->ticket(), Viewer::of($request));
        $thread = CommentController::prepare($this->manager()->for($ticket)->thread(), $ticket);

        $transitions = [];

        foreach (TransitionController::available($ticket, Viewer::of($request), static fn (string $name): bool => RequesterTransitions::allows($links->guest(), $ticket, $name)) as $transition) {
            $transitions[(string) $transition['name']] = $links->transition((string) $transition['name']);
        }

        $attachments = [];

        foreach ([...AttachmentBucket::all($ticket)->all(), ...$thread->flatMap(static fn (Comment $comment): Collection => $comment->attachments())->all()] as $file) {
            $attachments[$file->uuid] = $links->attachment($file->uuid);
        }

        return new JsonResponse([
            'data' => (new TicketResource($ticket))->detailed()->toArray($request),
            'messages' => TicketCommentResource::collection($thread)->toArray($request),
            'links' => [
                'self' => $links->show(),
                'reply' => $ticket->statusCategory() === StatusCategory::Closed ? null : $links->reply(),
                'transitions' => $transitions,
                'attachments' => $attachments,
            ],
        ]);
    }

    /**
     * One of the ticket's own files, or a file of a message in its public thread — anything
     * else is not found.
     */
    private static function file(Ticket $ticket, string $uuid): Media
    {
        if (! Str::isUuid($uuid)) {
            throw TicketAttachmentNotFoundException::on($ticket, $uuid);
        }

        $media = Container::getInstance()->make(MediaLibraryManager::class)->find($uuid);
        $owner = $media?->model;

        $public = match (true) {
            $media === null => false,
            $owner instanceof Ticket => $owner->is($ticket) && $media->bucket_name === TicketsConfig::attachmentsBucket(),
            $owner instanceof Comment => TicketComments::belongsTo($owner, $ticket)
                && ! TicketComments::isInternal($owner)
                && $owner->status === CommentStatus::Approved
                && $media->bucket_name === CommentsConfig::attachmentsBucket(),
            default => false,
        };

        if ($media === null || ! $public) {
            throw TicketAttachmentNotFoundException::on($ticket, $uuid);
        }

        return $media;
    }

    private static function links(Request $request): GuestLinks
    {
        return GuestLinks::of($request) ?? throw GuestAccessDeniedException::invalidLink();
    }
}
