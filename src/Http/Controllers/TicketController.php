<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RoundlyConsulting\Tickets\DataTransferObjects\CreateTicketData;
use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateTicketData;
use RoundlyConsulting\Tickets\Http\Queries\TicketEagerLoads;
use RoundlyConsulting\Tickets\Http\Queries\TicketIndexQuery;
use RoundlyConsulting\Tickets\Http\Requests\ListTicketsRequest;
use RoundlyConsulting\Tickets\Http\Requests\StoreTicketRequest;
use RoundlyConsulting\Tickets\Http\Requests\UpdateTicketRequest;
use RoundlyConsulting\Tickets\Http\Resources\TicketResource;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\MorphAliases;
use RoundlyConsulting\Tickets\Support\Optional;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * `/tickets` — list, file, read, update, delete and restore tickets.
 *
 * @internal
 */
final class TicketController extends Controller
{
    public function index(ListTicketsRequest $request): AnonymousResourceCollection
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'viewAny', [TicketModels::ticket()]);

        $manage = $this->allows($viewer->model, TicketsConfig::manageAbility());
        $tickets = TicketIndexQuery::for($request, $viewer, $manage)->orderBy('id')->paginate($request->perPage());

        return TicketResource::collection($tickets);
    }

    public function store(StoreTicketRequest $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'create', [TicketModels::ticket()]);

        if ($request->agentOnlyInputs() !== [] && ! $viewer->isAgent()) {
            Response::deny()->authorize();
        }

        $assignee = self::resolve(MorphAliases::ASSIGNABLES, $request->assigneeReference());

        if ($assignee !== null && ! $viewer->is($assignee) && ! $this->allows($viewer->model, TicketsConfig::assignAbility())) {
            Response::deny()->authorize();
        }

        $ticket = $this->manager()->create(new CreateTicketData(
            subject: $request->subject(),
            requester: $request->guest() ?? self::resolve(MorphAliases::REQUESTERS, $request->requesterReference()) ?? $viewer->model,
            description: $request->description(),
            category: $request->categoryId(),
            priority: $request->priority(),
            project: self::resolve(MorphAliases::PROJECTS, $request->projectReference()),
            assignee: $assignee,
            dueAt: $request->dueAt(),
            channel: $request->channel(),
            actor: $viewer->model,
            fields: $request->fieldValues(),
            tags: $request->tags(),
            watchers: array_map(
                static fn (MorphReference $reference): Model => MorphAliases::resolve(MorphAliases::WATCHABLES, $reference->type, $reference->id),
                $request->watcherReferences(),
            ),
            attachments: $request->attachments(),
        ));

        return $this->detailed($ticket, $viewer)->response()->setStatusCode(201);
    }

    public function show(Request $request, string $ticket): TicketResource
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);

        return $this->detailed($model, $viewer);
    }

    public function update(UpdateTicketRequest $request, string $ticket): TicketResource
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'update', [$model, $request->touched()]);

        $requester = $request->sent('requester') ? self::resolve(MorphAliases::REQUESTERS, $request->requesterReference()) : null;

        $this->handle($model, $viewer)->update(new UpdateTicketData(
            subject: $request->sent('subject') ? (string) $request->subject() : new Optional,
            description: $request->sent('description') ? $request->description() : new Optional,
            priority: $request->sent('priority') ? (string) $request->priority() : new Optional,
            category: $request->sent('category_id') ? $request->categoryId() : new Optional,
            dueAt: $request->sent('due_at') ? $request->dueAt() : new Optional,
            requester: $requester ?? new Optional,
        ));

        return $this->detailed($model, $viewer);
    }

    public function destroy(Request $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'delete', [$model]);

        $this->handle($model, $viewer)->delete();

        return new JsonResponse(null, 204);
    }

    public function restore(Request $request, string $ticket): TicketResource
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket, withTrashed: true);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'restore', [$model]);

        $this->handle($model, $viewer)->restore();

        return $this->detailed($model, $viewer);
    }

    private function detailed(Ticket $ticket, Viewer $viewer): TicketResource
    {
        return (new TicketResource(TicketEagerLoads::load($ticket, $viewer)))->detailed();
    }
}
