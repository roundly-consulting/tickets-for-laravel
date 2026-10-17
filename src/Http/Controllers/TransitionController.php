<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransition;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Tickets\Http\Queries\TicketEagerLoads;
use RoundlyConsulting\Tickets\Http\Requests\TransitionRequest;
use RoundlyConsulting\Tickets\Http\Resources\TicketResource;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * `/tickets/{ticket}/transitions` — what the viewer may do with the status now, and doing it.
 *
 * @internal
 */
final class TransitionController extends Controller
{
    public function index(Request $request, string $ticket): JsonResponse
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);

        return new JsonResponse(['data' => self::available($model, $viewer, fn (string $name): bool => $this->allows($viewer->model, 'transition', [$model, $name]))]);
    }

    public function store(TransitionRequest $request, string $ticket, string $transition): TicketResource
    {
        $viewer = $this->viewer($request);
        $model = $this->ticket($ticket);
        $this->authorize($viewer, 'view', [$model]);
        $this->authorize($viewer, 'transition', [$model, $transition]);

        $this->handle($model, $viewer)->transition($transition, $request->reason(), $request->payload());

        return (new TicketResource(TicketEagerLoads::load($model, $viewer)))->detailed();
    }

    /**
     * The transitions the lifecycle allows the viewer now, narrowed by `$may` (the policy, or
     * the guest rule).
     *
     * @param  callable(string): bool  $may
     * @return list<array<string, mixed>>
     */
    public static function available(Ticket $ticket, Viewer $viewer, callable $may): array
    {
        $transitions = Container::getInstance()->make(LifecycleManager::class)->for($ticket, 'status')->by($viewer->model)->allowedTransitions();
        $available = [];

        foreach ($transitions as $transition) {
            if ($may($transition->name)) {
                $available[] = self::present($transition);
            }
        }

        return $available;
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(AvailableTransition $transition): array
    {
        return [
            'name' => $transition->name,
            'label' => $transition->label,
            'to' => is_string($transition->to) ? $transition->to : $transition->to->value,
            'to_label' => $transition->toLabel,
            'requires_reason' => $transition->requiresReason,
            'payload' => $transition->payloadFields,
        ];
    }
}
