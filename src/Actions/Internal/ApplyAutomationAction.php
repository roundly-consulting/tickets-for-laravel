<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Internal;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Tickets\Enums\Automation;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\DeferredFields;
use RoundlyConsulting\Tickets\Support\LifecycleContract;
use RoundlyConsulting\Tickets\Support\StartBlockers;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketStates;
use RoundlyConsulting\Tickets\Support\TransitionContext;

/**
 * Applies the transition the configured lifecycle maps an automation to, as the system —
 * `asSystem()`, never `by(null)`, so a host lifecycle's actor rules never judge the requester
 * whose reply triggered it. The triggering actor is recorded in the activity entry. Nothing
 * happens when the lifecycle switched the automation off (null), the transition cannot
 * leave the current state, or it would start a ticket an open blocker holds back
 * (`tickets.links.blockers_prevent_start`), or it would leave a deferred state
 * (`tickets.fields.defer_required_in`) while required fields are still empty. Runs inside the
 * calling action's transaction.
 *
 * @internal
 */
final readonly class ApplyAutomationAction
{
    public function __construct(
        private LifecycleManager $lifecycles,
        private WriteFieldValuesAction $fields,
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

        if (! $handle->can($transition) || StartBlockers::refuse($ticket, $transition) || $this->owesFields($ticket, $transition)) {
            return null;
        }

        return TransitionContext::run($trigger, $automation, static fn (): TransitionResult => $handle->apply($transition));
    }

    /**
     * Whether the transition leaves a deferred state while the ticket still misses a required
     * field — the system owes every one.
     */
    private function owesFields(Ticket $ticket, string $transition): bool
    {
        if (TicketsConfig::deferRequiredIn() === []) {
            return false;
        }

        $definition = TicketStates::definition();
        $target = $definition->transition($transition)?->to;

        return $target !== null
            && DeferredFields::leaving($ticket->status, $definition->value($target))
            && $this->fields->missingRequired($ticket) !== [];
    }
}
