<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Tickets\DataTransferObjects\TicketLinkView;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Support\TicketVisibility;

/**
 * A link, read from the ticket it was asked for: `blocks` on the outgoing end, `blocked_by` on the
 * incoming one. The other ticket shows its subject and status only to a viewer who may view it;
 * anyone else (an agent the agent scope keeps off it) gets its reference alone.
 *
 * @property TicketLinkView $resource
 *
 * @internal
 */
final class TicketLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $view = $this->resource;
        $visible = TicketVisibility::canView(Viewer::of($request)->model, $view->ticket);

        return [
            'id' => $view->link,
            'type' => $view->type->value,
            'label' => $view->label,
            'direction' => $view->direction,
            'ticket' => $visible ? [
                'reference' => $view->ticket->reference,
                'subject' => $view->ticket->subject,
                'status' => Present::state($view->ticket->status),
            ] : ['reference' => $view->ticket->reference],
        ];
    }
}
