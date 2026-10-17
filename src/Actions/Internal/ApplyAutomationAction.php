<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Internal;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Tickets\Enums\Automation;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\LifecycleContract;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TransitionContext;

/**
 * Applies the transition the configured lifecycle maps an automation to, as the system —
 * `asSystem()`, never `by(null)`, so a host lifecycle's actor rules never judge the requester
 * whose reply triggered it. The triggering actor is recorded in the activity entry. Nothing
 * happens when the lifecycle switched the automation off (null) or the transition cannot
 * leave the current state. Runs inside the calling action's transaction.
 *
 * @internal
 */
final readonly class ApplyAutomationAction
{
    public function __construct(
        private LifecycleManager $lifecycles,
    ) {}

    public function execute(Ticket $ticket, Automation $automation, ?Model $trigger = null, ?string $reason = null): ?TransitionResult
    {
        LifecycleContract::assertValid();

        $class = TicketsConfig::lifecycle();
        $transition = (new $class)->automation($automation);

        if ($transition === null) {
            return null;
        }

        $handle = $this->lifecycles->for($ticket, 'status')->asSystem()->because($reason ?? 'automation:'.$automation->value);

        if (! $handle->can($transition)) {
            return null;
        }

        return TransitionContext::run($trigger, $automation, static fn (): TransitionResult => $handle->apply($transition));
    }
}
