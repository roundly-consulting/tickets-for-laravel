<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Tickets\Http\Queries\TicketEagerLoads;
use RoundlyConsulting\Tickets\Http\Requests\MergeRequest;
use RoundlyConsulting\Tickets\Http\Resources\TicketResource;

/**
 * `POST /tickets/{ticket}/merge` — merge this ticket into `target` (the merge ability, on a
 * target the viewer may see).
 *
 * @internal
 */
final class MergeController extends Controller
{
    public function __invoke(MergeRequest $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $source = $this->ticket($ticket);
        $target = $this->ticket($request->target());
        $this->authorize($viewer, 'view', [$source]);
        $this->authorize($viewer, 'merge', [$source]);
        $this->authorize($viewer, 'view', [$target]);
        $this->authorize($viewer, 'merge', [$target]);

        $result = $this->handle($source, $viewer)->mergeInto($target, $request->reason());

        return new JsonResponse([
            'source' => (new TicketResource(TicketEagerLoads::load($result->source, $viewer)))->toArray($request),
            'target' => (new TicketResource(TicketEagerLoads::load($result->target, $viewer)))->toArray($request),
            'moved' => ['comments' => $result->comments, 'attachments' => $result->attachments],
        ]);
    }
}
