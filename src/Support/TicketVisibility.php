<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\AgentScope;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketWatcher;

/**
 * Who may see a ticket: an agent the agent scope lets work on it, its requester, or — with
 * `tickets.watchers.can_view` — one of its watchers. One source of truth for the policy's
 * `view`, the `visibleTo` query scope and every reader that must not show a ticket (or a
 * mention of it) to someone who cannot open it; a test pins the scope and the policy to the
 * same tickets.
 *
 * @internal
 */
final class TicketVisibility
{
    public static function canView(?Model $user, Ticket $ticket): bool
    {
        if ($user === null) {
            return false;
        }

        return self::isAgentFor($user, $ticket)
            || self::isRequester($user, $ticket)
            || (TicketsConfig::watchersCanView() && self::isWatcher($user, $ticket));
    }

    /**
     * An agent (the agent ability) whom the {@see AgentScope} lets work on the ticket.
     */
    public static function isAgentFor(?Model $user, Ticket $ticket): bool
    {
        return $user !== null && Agents::is($user) && self::scope()->allows($user, $ticket);
    }

    /**
     * Narrow a ticket query to the tickets {@see self::canView()} allows: the agent branch
     * (narrowed by the agent scope), the requester branch and the watcher branch, OR-ed in one
     * group.
     *
     * @param  Builder<covariant Ticket>  $query
     */
    public static function constrain(Builder $query, Model $user): void
    {
        $scope = Agents::is($user) ? self::scope() : null;
        $watchers = TicketsConfig::watchersCanView();

        $query->where(static function (Builder $any) use ($user, $scope, $watchers): void {
            // Always false on its own, so the group never ends up empty — an empty nested
            // where is dropped, which would turn the OR-ed branches below into the only ones.
            $any->whereRaw('1 = 0');

            if ($scope !== null) {
                $any->orWhere(static function (Builder $agent) use ($scope, $user): void {
                    // An unrestricted scope adds nothing; this keeps its branch from vanishing.
                    $agent->whereRaw('1 = 1');
                    $scope->constrain($agent, $user);
                });
            }

            $any->orWhere(static fn (Builder $requester): Builder => $requester
                ->where($requester->qualifyColumn('requester_type'), $user->getMorphClass())
                ->where($requester->qualifyColumn('requester_id'), $user->getKey()));

            if ($watchers) {
                $any->orWhereHas('watchers', static function (Builder $watch) use ($user): void {
                    $watch->where('watcher_type', $user->getMorphClass())->where('watcher_id', $user->getKey());
                });
            }
        });
    }

    public static function isRequester(Model $model, Ticket $ticket): bool
    {
        return $ticket->requester_type === $model->getMorphClass()
            && (string) $ticket->requester_id === (string) $model->getKey();
    }

    public static function isWatcher(Model $model, Ticket $ticket): bool
    {
        return TicketWatcher::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('watcher_type', $model->getMorphClass())
            ->where('watcher_id', $model->getKey())
            ->exists();
    }

    private static function scope(): AgentScope
    {
        return Container::getInstance()->make(AgentScope::class);
    }
}
