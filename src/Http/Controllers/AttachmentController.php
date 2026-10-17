<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RoundlyConsulting\Tickets\Http\Requests\AttachmentRequest;
use RoundlyConsulting\Tickets\Http\Resources\TicketAttachmentResource;
use RoundlyConsulting\Tickets\Support\AttachmentBucket;

/**
 * `/tickets/{ticket}/attachments` — files on the ticket itself, each with a short-lived signed
 * download URL. Reading needs `view`, adding and removing `update`; a file of another ticket is
 * not found.
 *
 * @internal
 */
final class AttachmentController extends Controller
{
    public function index(Request $request, string $ticket): AnonymousResourceCollection
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);

        return TicketAttachmentResource::collection(AttachmentBucket::all($model));
    }

    public function store(AttachmentRequest $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'update', [$model, ['attachments']]);

        $media = $this->handle($model, $viewer)->attachments()->add($request->upload());

        return (new TicketAttachmentResource($media))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $ticket, string $media): TicketAttachmentResource
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);

        return new TicketAttachmentResource(AttachmentBucket::owned($model, $media));
    }

    public function destroy(Request $request, string $ticket, string $media): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'update', [$model, ['attachments']]);

        $this->handle($model, $viewer)->attachments()->remove(AttachmentBucket::owned($model, $media));

        return new JsonResponse(null, 204);
    }
}
