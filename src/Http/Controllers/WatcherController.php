<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;
use RoundlyConsulting\Tickets\Exceptions\UnknownMorphAliasException;
use RoundlyConsulting\Tickets\Http\Requests\WatcherRequest;
use RoundlyConsulting\Tickets\Http\Resources\Party;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Support\MorphAliases;

/**
 * `/tickets/{ticket}/watchers` and `/watch` — the watcher list is an agent's; an agent adds and
 * removes anyone (a `tickets.watchables` alias, `{type, id}` or `alias:key`), and whoever may
 * view the ticket watches and unwatches it themselves. A non-agent who adds themselves gets back
 * their own entry only: the list (other watchers, guests' personal data) stays agent-only.
 *
 * @internal
 */
final class WatcherController extends Controller
{
    public function index(Request $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'manageWatchers', [$model]);

        return $this->list($model, $viewer);
    }

    public function store(WatcherRequest $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $reference = $request->watcherReference();
        $watcher = MorphAliases::resolve(MorphAliases::WATCHABLES, $reference->type, $reference->id);
        $this->authorize($viewer, 'manageWatchers', [$model, $watcher]);

        $this->handle($model, $viewer)->watchers()->add($watcher);

        if (! $viewer->isAgentOn($model)) {
            return new JsonResponse(['data' => [Party::person($watcher, $viewer, false)]], 201);
        }

        return $this->list($model, $viewer)->setStatusCode(201);
    }

    public function destroy(Request $request, string $ticket, string $watcher): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $reference = MorphReference::parse($watcher) ?? throw UnknownMorphAliasException::alias(MorphAliases::WATCHABLES, $watcher);
        $target = MorphAliases::resolve(MorphAliases::WATCHABLES, $reference->type, $reference->id);
        $this->authorize($viewer, 'manageWatchers', [$model, $target]);

        $this->handle($model, $viewer)->watchers()->remove($target);

        return new JsonResponse(null, 204);
    }

    public function watch(Request $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'manageWatchers', [$model, $viewer->model]);

        $this->handle($model, $viewer)->watchers()->watch();

        return new JsonResponse(null, 204);
    }

    public function unwatch(Request $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'manageWatchers', [$model, $viewer->model]);

        $this->handle($model, $viewer)->watchers()->unwatch();

        return new JsonResponse(null, 204);
    }

    private function list(Ticket $ticket, Viewer $viewer): JsonResponse
    {
        $watchers = $this->manager()->for($ticket)->watchers()->all();
        $agent = $viewer->isAgentOn($ticket);

        return new JsonResponse(['data' => $watchers
            ->map(static fn (TicketWatcher $watch): ?array => Party::person($watch->watcher, $viewer, $agent))
            ->filter()
            ->values()
            ->all()]);
    }
}
