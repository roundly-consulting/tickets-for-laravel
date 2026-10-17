<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Support\Facades\App;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Tickets\Contracts\TicketLifecycle;
use RoundlyConsulting\Tickets\Contracts\TicketState;
use RoundlyConsulting\Tickets\DataTransferObjects\LifecycleIssue;
use RoundlyConsulting\Tickets\Enums\Automation;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLifecycleException;
use RoundlyConsulting\Tickets\Lifecycle\TicketLifecycle as ShippedLifecycle;

/**
 * Checks the configured ticket lifecycle against what the package relies on: the ticket
 * model maps it to `status`; it compiles (lifecycle's own validation); every state is a
 * `Contracts\TicketState`; every non-null automation names a transition that allows system
 * context (automations run `asSystem()`), leaves every state of the categories it must work
 * from and lands in a category it may enter. `tickets:doctor` lists the issues; the first use
 * of a broken lifecycle throws.
 *
 * @internal
 */
final class LifecycleContract
{
    /** @var array<string, true> definitions already checked in this process */
    private static array $checked = [];

    /**
     * Throws {@see InvalidTicketLifecycleException} naming the first error; checked once per
     * definition class per process.
     */
    public static function assertValid(): void
    {
        $class = TicketsConfig::lifecycle();

        if (isset(self::$checked[$class])) {
            return;
        }

        foreach (self::issues() as $issue) {
            if ($issue->error) {
                throw InvalidTicketLifecycleException::broken($class, $issue->message);
            }
        }

        self::$checked[$class] = true;
    }

    /**
     * Every finding, errors first.
     *
     * @return list<LifecycleIssue>
     */
    public static function issues(): array
    {
        try {
            $class = TicketsConfig::lifecycle();
        } catch (InvalidTicketLifecycleException $exception) {
            return [new LifecycleIssue('not_a_definition', $exception->getMessage(), true)];
        }

        $issues = [];
        $model = TicketModels::ticket();

        if (array_key_first((new $model)->lifecycleDefinitions()) !== 'status') {
            $issues[] = self::error('not_status_attribute', ['model' => $model]);
        }

        $registry = App::make(DefinitionRegistry::class);
        $report = $registry->validate($class);

        foreach ($report->issues as $issue) {
            $issues[] = new LifecycleIssue('lifecycle.'.$issue->code->value, $issue->message(), $issue->isError());
        }

        if (! $report->isValid()) {
            return self::sorted($issues);
        }

        $definition = $registry->get($class);

        foreach ($definition->values() as $state) {
            if (! $state instanceof TicketState) {
                $issues[] = self::error('state_not_ticket_state', ['state' => is_string($state) ? $state : (string) $state->value]);
            }
        }

        $lifecycle = new $class;

        foreach (Automation::cases() as $automation) {
            $issues = [...$issues, ...self::automationIssues($lifecycle, $definition, $automation)];
        }

        return self::sorted($issues);
    }

    /**
     * The names of the default lifecycle's transitions — the named conveniences — the
     * configured lifecycle supports.
     *
     * @return list<string>
     */
    public static function supportedConveniences(): array
    {
        $definition = TicketStates::definition();

        return array_values(array_filter(
            [
                ShippedLifecycle::START,
                ShippedLifecycle::WAIT,
                ShippedLifecycle::HOLD,
                ShippedLifecycle::RESUME,
                ShippedLifecycle::RESOLVE,
                ShippedLifecycle::REOPEN,
                ShippedLifecycle::CLOSE,
                ShippedLifecycle::DISMISS,
            ],
            $definition->hasTransition(...),
        ));
    }

    /**
     * @internal for the suite: forget which definitions were already checked.
     */
    public static function flush(): void
    {
        self::$checked = [];
    }

    /**
     * @return list<LifecycleIssue>
     */
    private static function automationIssues(TicketLifecycle $lifecycle, CompiledDefinition $definition, Automation $automation): array
    {
        $name = $lifecycle->automation($automation);

        if ($name === null) {
            return [];
        }

        $transition = $definition->transition($name);
        $replace = ['automation' => $automation->value, 'transition' => $name];

        if ($transition === null) {
            return [self::error('automation_unknown', $replace)];
        }

        $issues = [];

        if (! $transition->allowsSystem()) {
            $issues[] = self::error('automation_not_system', $replace);
        }

        foreach (self::sourceStates($definition, $automation) as $key) {
            if (! $transition->leavesFrom($key)) {
                $issues[] = self::error('automation_source', [...$replace, 'state' => $key]);
            }
        }

        $target = $definition->value($transition->to);

        if (! $target instanceof TicketState || ! in_array($target->category(), $automation->targets(), true)) {
            $issues[] = self::error('automation_target', [...$replace, 'state' => $transition->to]);
        }

        return $issues;
    }

    /**
     * The state keys the automation's transition must leave: the initial state for
     * `Assigned`, every state of its source categories otherwise.
     *
     * @return list<string>
     */
    private static function sourceStates(CompiledDefinition $definition, Automation $automation): array
    {
        if ($automation === Automation::Assigned) {
            return [$definition->initial];
        }

        $keys = [];

        foreach ($definition->states as $state) {
            if ($state->value instanceof TicketState && in_array($state->value->category(), $automation->sources(), true)) {
                $keys[] = $state->key;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function error(string $code, array $replace): LifecycleIssue
    {
        return new LifecycleIssue($code, Messages::get('lifecycle.'.$code, $replace), true);
    }

    /**
     * @param  list<LifecycleIssue>  $issues
     * @return list<LifecycleIssue>
     */
    private static function sorted(array $issues): array
    {
        usort($issues, static fn (LifecycleIssue $a, LifecycleIssue $b): int => $b->error <=> $a->error);

        return $issues;
    }
}
