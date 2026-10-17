<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Http\Request;
use RoundlyConsulting\Tickets\Http\Queries\TicketEagerLoads;
use RoundlyConsulting\Tickets\Http\Requests\AssignRequest;
use RoundlyConsulting\Tickets\Http\Resources\TicketResource;
use RoundlyConsulting\Tickets\Support\MorphAliases;

/**
 * `/tickets/{ticket}/assignee` — assign (an `assignables` alias, or a claim) and unassign.
 *
 * @internal
 */
final class AssignmentController extends Controller
{
    public function update(AssignRequest $request, string $ticket): TicketResource
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);

        if ($request->claims()) {
            $this->authorize($viewer, 'assign', [$model, $viewer->model]);
            $this->handle($model, $viewer)->claim();
        } else {
            $assignee = self::resolve(MorphAliases::ASSIGNABLES, $request->assigneeReference());
            $this->authorize($viewer, 'assign', [$model, $assignee]);
            $this->handle($model, $viewer)->assign($assignee ?? $viewer->model);
        }

        return (new TicketResource(TicketEagerLoads::load($model, $viewer)))->detailed();
    }

    public function destroy(Request $request, string $ticket): TicketResource
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'unassign', [$model]);

        $this->handle($model, $viewer)->unassign();

        return (new TicketResource(TicketEagerLoads::load($model, $viewer)))->detailed();
    }
}
