<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Enums\Automation;

/**
 * Who triggered the transition lifecycle is applying right now, for the activity entry.
 * Automations run `asSystem()` (a host lifecycle's actor rules must never judge the
 * requester whose reply resumed a ticket), so the triggering actor and the automation travel
 * here instead — set around the apply, read by the `LifecycleTransitioning` listener.
 *
 * @internal
 */
final class TransitionContext
{
    /** @var list<array{actor: Model|null, automation: Automation|null}> */
    private static array $stack = [];

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(?Model $actor, ?Automation $automation, Closure $callback): mixed
    {
        self::$stack[] = ['actor' => $actor, 'automation' => $automation];

        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * @return array{actor: Model|null, automation: Automation|null}|null
     */
    public static function current(): ?array
    {
        return self::$stack === [] ? null : self::$stack[array_key_last(self::$stack)];
    }
}
