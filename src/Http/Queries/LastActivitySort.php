<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\QueryBuilder\Contracts\Sort;
use RoundlyConsulting\QueryBuilder\Enums\SortDirection;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Support\TicketVisibility;

/**
 * `sort=last_activity_at` — an agent's sort, and a note moves it. So it orders only the tickets
 * the agent scope lets the viewer work on by their last activity; a ticket they see only as its
 * requester or a watcher sorts by its public `updated_at`, which no note moves.
 *
 * @internal
 */
final readonly class LastActivitySort implements Sort
{
    public function __construct(
        private Viewer $viewer,
    ) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function apply(Builder $query, SortDirection $direction, string $property): void
    {
        $order = $direction === SortDirection::Descending ? 'desc' : 'asc';
        $keys = TicketVisibility::agentTickets($this->viewer->model);

        if ($keys === null) {
            $query->orderBy($query->qualifyColumn('last_activity_at'), $order);

            return;
        }

        $model = $query->getModel();

        // The join adds a column; the tickets keep only their own.
        if ($query->getQuery()->columns === null) {
            $query->select($model->qualifyColumn('*'));
        }

        $query->leftJoinSub(
            $keys->select($model->getQualifiedKeyName().' as agent_ticket_key'),
            'agent_tickets',
            'agent_tickets.agent_ticket_key',
            '=',
            $model->getQualifiedKeyName(),
        )->orderByRaw('case when agent_tickets.agent_ticket_key is null then updated_at else last_activity_at end '.$order);
    }
}
