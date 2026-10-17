<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tickets;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Tickets\DataTransferObjects\TransitionData;
use RoundlyConsulting\Tickets\Exceptions\UnknownTicketTransitionException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\LifecycleContract;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;
use RoundlyConsulting\Tickets\Support\TransitionContext;

/**
 * Applies a status transition through lifecycle-for-laravel — the only way a ticket's status
 * ever changes. The ticket row is locked first (lifecycle's own first lock), a trashed ticket
 * is refused, and the `status_changed` activity, the activity clock and the conversation lock
 * are written atomically with the state by the `LifecycleTransitioning` listener.
 *
 * Lifecycle's denials surface unchanged (`TransitionDeniedException`, with every denial and
 * a retry-after for rate limits) — except an unknown transition name, which becomes
 * {@see UnknownTicketTransitionException}.
 */
final readonly class TransitionTicketAction
{
    public function __construct(
        private LifecycleManager $lifecycles,
    ) {}

    public function execute(Ticket $ticket, TransitionData $data, ?Model $actor = null, bool $system = false): TransitionResult
    {
        LifecycleContract::assertValid();
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $data, $actor, $system): TransitionResult {
            TicketLocker::lock($ticket);

            $handle = $this->lifecycles->for($ticket, 'status');
            $handle = ($system ? $handle->asSystem() : $handle->by($actor))->because($data->reason)->with($data->payload);

            try {
                return TransitionContext::run($actor, null, static fn (): TransitionResult => $handle->apply($data->transition));
            } catch (TransitionDeniedException $exception) {
                $unknown = array_filter($exception->denials(), static fn (Denial $denial): bool => $denial->is(DenialCode::UnknownTransition));

                throw $unknown === [] ? $exception : UnknownTicketTransitionException::named($data->transition);
            }
        });
    }
}
