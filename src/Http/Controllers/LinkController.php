<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Tickets\DataTransferObjects\TicketLinkView;
use RoundlyConsulting\Tickets\Exceptions\TicketRecordNotFoundException;
use RoundlyConsulting\Tickets\Http\Requests\LinkRequest;
use RoundlyConsulting\Tickets\Http\Resources\TicketLinkResource;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketLink;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * `/tickets/{ticket}/links` — links between tickets, agents only (on both tickets). A link id
 * that does not join this ticket is not found.
 *
 * @internal
 */
final class LinkController extends Controller
{
    public function index(Request $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'link', [$model]);

        return $this->list($model, $request);
    }

    public function store(LinkRequest $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $other = $this->ticket($request->reference());
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'link', [$model]);
        $this->authorize($viewer, 'view', [$other]);
        $this->authorize($viewer, 'link', [$other]);

        $this->handle($model, $viewer)->links()->add($other, $request->type());

        return $this->list($model, $request)->setStatusCode(201);
    }

    public function destroy(Request $request, string $ticket, string $link): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'link', [$model]);

        $row = ctype_digit($link) ? TicketLink::query()
            ->whereKey((int) $link)
            ->where(static fn (Builder $query): Builder => $query->where('ticket_id', $model->getKey())->orWhere('related_ticket_id', $model->getKey()))
            ->first() : null;
        $otherKey = $row === null ? null : ((string) $row->ticket_id === (string) $model->getKey() ? $row->related_ticket_id : $row->ticket_id);
        $other = $otherKey === null ? null : TicketModels::ticket()::query()->find($otherKey);

        if ($row === null || $other === null) {
            throw TicketRecordNotFoundException::link($model, $link);
        }

        $this->authorize($viewer, 'link', [$other]);

        $this->handle($model, $viewer)->links()->remove($other, $row->type);

        return new JsonResponse(null, 204);
    }

    private function list(Ticket $ticket, Request $request): JsonResponse
    {
        return new JsonResponse(['data' => array_map(
            static fn (TicketLinkView $view): array => (new TicketLinkResource($view))->toArray($request),
            $this->manager()->for($ticket)->links()->all(),
        )]);
    }
}
