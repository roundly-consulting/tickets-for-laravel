<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Tickets\Http\ErrorResponse;
use RoundlyConsulting\Tickets\Http\Requests\BulkRequest;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Support\MorphAliases;
use Throwable;

/**
 * `POST /tickets/bulk` — one action on many tickets, each on its own: its own policy check, its
 * own transaction, its own result. One ticket failing never stops the others; the response lists
 * every ticket with `ok`, its status and — on failure — the error `code` and `message`.
 *
 * @internal
 */
final class BulkController extends Controller
{
    public function __invoke(BulkRequest $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $results = [];

        foreach ($request->references() as $reference) {
            try {
                $this->apply($request, $viewer, $reference);

                $results[] = ['ticket' => $reference, 'ok' => true, 'status' => 200];
            } catch (ValidationException $exception) {
                $results[] = ['ticket' => $reference, 'ok' => false, 'status' => 422, 'code' => 'validation_failed', 'message' => $exception->getMessage()];
            } catch (Throwable $exception) {
                $error = ErrorResponse::from($exception) ?? throw $exception;

                $results[] = ['ticket' => $reference, 'ok' => false, 'status' => $error->status, 'code' => $error->code, 'message' => $error->message];
            }
        }

        return new JsonResponse(['data' => $results]);
    }

    private function apply(BulkRequest $request, Viewer $viewer, string $reference): void
    {
        $ticket = $this->ticket($reference);
        $this->authorize($viewer, 'view', [$ticket]);
        $handle = $this->handle($ticket, $viewer);

        switch ($request->action()) {
            case 'assign':
                $assignee = $request->claims() ? $viewer->model : (self::resolve(MorphAliases::ASSIGNABLES, $request->assigneeReference()) ?? $viewer->model);
                $this->authorize($viewer, 'assign', [$ticket, $assignee]);
                $request->claims() ? $handle->claim() : $handle->assign($assignee);
                break;
            case 'unassign':
                $this->authorize($viewer, 'unassign', [$ticket]);
                $handle->unassign();
                break;
            case 'transition':
                $this->authorize($viewer, 'transition', [$ticket, $request->transition()]);
                $handle->transition($request->transition(), $request->reason(), $request->payload());
                break;
            case 'priority':
                $this->authorize($viewer, 'update', [$ticket, ['priority']]);
                $handle->prioritize($request->priority());
                break;
            default:
                $this->authorize($viewer, 'tag', [$ticket]);
                $handle->tags()->attach($request->tagsToAttach())->detach($request->tagsToDetach());
        }
    }
}
