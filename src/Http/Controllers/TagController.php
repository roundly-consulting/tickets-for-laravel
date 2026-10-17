<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RoundlyConsulting\Tickets\Http\Requests\SyncTagsRequest;
use RoundlyConsulting\Tickets\Http\Resources\TicketTagResource;

/**
 * `PUT /tickets/{ticket}/tags` — the ticket's whole tag list (agents only).
 *
 * @internal
 */
final class TagController extends Controller
{
    public function sync(SyncTagsRequest $request, string $ticket): AnonymousResourceCollection
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'tag', [$model]);

        $tags = $this->handle($model, $viewer)->tags()->sync($request->tags())->all();

        return TicketTagResource::collection($tags);
    }
}
