<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;
use RoundlyConsulting\Tickets\Exceptions\TicketNotFoundException;
use RoundlyConsulting\Tickets\Handles\TicketHandle;
use RoundlyConsulting\Tickets\Http\ErrorResponse;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\MorphAliases;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\TicketsManager;
use Throwable;

/**
 * The base of the package's controllers. Every action runs inside the API's error mapping
 * ({@see ErrorResponse}) — the host's exception handler is never touched — and every write goes
 * through the manager, so `Tickets::fake()` records API calls too. Tickets are looked up by
 * reference here rather than through route model binding, so the API answers the same with or
 * without the host's `SubstituteBindings` and never touches a global route binding.
 *
 * @internal
 */
abstract class Controller
{
    /**
     * @param  array<array-key, mixed>  $parameters
     */
    public function callAction(string $method, array $parameters): mixed
    {
        try {
            return $this->{$method}(...array_values($parameters));
        } catch (Throwable $exception) {
            return ErrorResponse::from($exception)?->toResponse() ?? throw $exception;
        }
    }

    protected function viewer(Request $request): Viewer
    {
        return Viewer::of($request)->attach($request);
    }

    protected function manager(): TicketsManager
    {
        return Container::getInstance()->make(TicketsManager::class);
    }

    /**
     * The live ticket with the reference (a soft-deleted one with `$withTrashed`).
     */
    protected function ticket(string $reference, bool $withTrashed = false): Ticket
    {
        $query = TicketModels::ticket()::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->where('reference', $reference)->first() ?? throw TicketNotFoundException::forReference($reference);
    }

    /**
     * A handle on the ticket that credits every write to the viewer.
     */
    protected function handle(Ticket $ticket, Viewer $viewer): TicketHandle
    {
        return $this->manager()->for($ticket)->by($viewer->model);
    }

    /**
     * @param  array<int, mixed>|mixed  $arguments
     */
    protected function authorize(Viewer $viewer, string $ability, mixed $arguments = []): void
    {
        Container::getInstance()->make(Gate::class)->forUser($viewer->model)->authorize($ability, $arguments);
    }

    protected function allows(Model $user, string $ability, mixed $arguments = []): bool
    {
        return Container::getInstance()->make(Gate::class)->forUser($user)->allows($ability, $arguments);
    }

    /**
     * The model an untrusted `{type, id}` names, through its alias allow-list — a class name or
     * an unknown alias is refused (422 `unknown_<kind>_type`).
     */
    protected static function resolve(string $kind, ?MorphReference $reference): ?Model
    {
        return $reference === null ? null : MorphAliases::resolve($kind, $reference->type, $reference->id);
    }
}
