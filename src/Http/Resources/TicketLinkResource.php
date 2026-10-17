<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Tickets\DataTransferObjects\TicketLinkView;

/**
 * A link, read from the ticket it was asked for: `blocks` on the outgoing end, `blocked_by` on the
 * incoming one.
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

        return [
            'id' => $view->link,
            'type' => $view->type->value,
            'label' => $view->label,
            'direction' => $view->direction,
            'ticket' => [
                'reference' => $view->ticket->reference,
                'subject' => $view->ticket->subject,
                'status' => Present::state($view->ticket->status),
            ],
        ];
    }
}
