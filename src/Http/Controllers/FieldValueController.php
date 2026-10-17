<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Tickets\Http\Requests\FieldValuesRequest;
use RoundlyConsulting\Tickets\Http\Resources\TicketResource;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * `/tickets/{ticket}/fields` — the custom field values: every field for an agent, the
 * requester-visible ones for everyone else; the requester writes only `requester_editable`
 * fields (422 `field_not_editable`), and a key that is no field of the ticket is 422
 * `unknown_field`.
 *
 * @internal
 */
final class FieldValueController extends Controller
{
    public function index(Request $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);

        return self::values($model, $viewer->isAgentOn($model));
    }

    public function update(FieldValuesRequest $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'update', [$model, ['fields']]);

        $this->handle($model, $viewer)->fields()->setMany($request->values());

        return self::values($model, $viewer->isAgentOn($model));
    }

    private static function values(Ticket $ticket, bool $agent): JsonResponse
    {
        return new JsonResponse(['data' => TicketResource::fields($ticket, $agent)]);
    }
}
