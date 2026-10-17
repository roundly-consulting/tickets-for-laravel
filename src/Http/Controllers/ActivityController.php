<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RoundlyConsulting\Tickets\Http\Resources\TicketActivityResource;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * `GET /tickets/{ticket}/activity` — the timeline: every entry for an agent, the public ones for
 * everyone else; empty with `tickets.activity.enabled` off.
 *
 * @internal
 */
final class ActivityController extends Controller
{
    public function __invoke(Request $request, string $ticket): AnonymousResourceCollection
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);

        $entries = TicketsConfig::activityEnabled()
            ? $this->manager()->for($model)->activity(internal: $viewer->isAgentOn($model))
            : $model->activities()->whereRaw('1 = 0')->get();

        $entries->load(['actor' => static fn (MorphTo $actor): MorphTo => $actor->morphWith([TicketModels::guest() => ['attachedAttributes']])])
            ->each(static fn (TicketActivity $entry): TicketActivity => $entry->setRelation('ticket', $model));

        return TicketActivityResource::collection($entries);
    }
}
