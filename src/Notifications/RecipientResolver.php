<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Contracts\Assignee;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Enums\NotificationType;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Support\Agents;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketVisibility;

/**
 * Who a ticket notification goes to. Each type picks its candidates by role — the requester,
 * the watchers who are (or are not) agents, whoever the assignee names — and every list is
 * then:
 *
 *  - **visibility-checked**: no recipient gets what they cannot see. Internal content (a note,
 *    a mention in one) reaches only agents the agent scope lets work on the ticket; anything
 *    else reaches only someone {@see TicketVisibility::canView()} allows;
 *  - **deduplicated** (one notification per person, however many roles they hold);
 *  - **without the one who acted**, unless `tickets.notifications.notify_actor`;
 *  - **without guests**, unless `tickets.notifications.guests`;
 *  - **notifiable** only — a model without `routeNotificationFor()` (a team, say) is skipped.
 */
final class RecipientResolver
{
    /**
     * The requester, for the receipt of a ticket they filed — even when they filed it
     * themselves.
     *
     * @return list<Model>
     */
    public function created(Ticket $ticket): array
    {
        return $this->resolve($ticket, $this->requester($ticket), internal: false, actor: null);
    }

    /**
     * Whoever the new assignee names (`ticketNotifiables()`).
     *
     * @return list<Model>
     */
    public function assigned(Ticket $ticket, Model $assignee, ?Model $actor): array
    {
        return $this->resolve($ticket, $this->namedBy($assignee), internal: false, actor: $actor);
    }

    /**
     * A public reply: the help desk's goes to the requester and the watchers who are not
     * agents; the requester side's goes to whoever the assignee names and the agents watching.
     *
     * @return list<Model>
     */
    public function replied(Ticket $ticket, AuthorRole $role, ?Model $actor): array
    {
        $candidates = $role === AuthorRole::Agent || $role === AuthorRole::System
            ? [...$this->requester($ticket), ...$this->watchers($ticket, agents: false)]
            : [...$this->assignee($ticket), ...$this->watchers($ticket, agents: true)];

        return $this->resolve($ticket, $candidates, internal: false, actor: $actor);
    }

    /**
     * An internal note: whoever the assignee names and the agents watching — agents only.
     *
     * @return list<Model>
     */
    public function noteAdded(Ticket $ticket, ?Model $actor): array
    {
        return $this->resolve($ticket, [...$this->assignee($ticket), ...$this->watchers($ticket, agents: true)], internal: true, actor: $actor);
    }

    /**
     * The mentioned person, when they may read the comment and its own notification (the
     * reply or note it is in) does not already reach them.
     *
     * @return list<Model>
     */
    public function mentioned(Ticket $ticket, Comment $comment, Model $mentioned): array
    {
        $internal = TicketComments::isInternal($comment);
        $author = $comment->actor;
        $recipients = $this->resolve($ticket, [$mentioned], $internal, $author);

        if ($recipients === []) {
            return [];
        }

        $role = TicketComments::role($comment) ?? AuthorRole::System;
        $told = $internal
            ? (TicketsConfig::notification(NotificationType::NoteAdded) === null ? [] : $this->noteAdded($ticket, $author))
            : (TicketsConfig::notification($this->replyType($role)) === null ? [] : $this->replied($ticket, $role, $author));

        foreach ($told as $recipient) {
            if (self::same($recipient, $mentioned)) {
                return [];
            }
        }

        return $recipients;
    }

    /**
     * Resolved, closed or reopened: the requester and every watcher.
     *
     * @return list<Model>
     */
    public function statusChanged(Ticket $ticket, ?Model $actor): array
    {
        return $this->resolve($ticket, [...$this->requester($ticket), ...$this->watchers($ticket)], internal: false, actor: $actor);
    }

    /**
     * Overdue or due soon: whoever the assignee names — nobody for an unassigned ticket.
     *
     * @return list<Model>
     */
    public function due(Ticket $ticket): array
    {
        return $this->resolve($ticket, $this->assignee($ticket), internal: false, actor: null);
    }

    /**
     * A merge: the source's requester.
     *
     * @return list<Model>
     */
    public function merged(Ticket $source, ?Model $actor): array
    {
        return $this->resolve($source, $this->requester($source), internal: false, actor: $actor);
    }

    /**
     * The notification type a reply by `$role` goes out as.
     */
    public function replyType(AuthorRole $role): NotificationType
    {
        return $role === AuthorRole::Agent || $role === AuthorRole::System
            ? NotificationType::Replied
            : NotificationType::RequesterReplied;
    }

    /**
     * @param  iterable<mixed>  $candidates
     * @return list<Model>
     */
    private function resolve(Ticket $ticket, iterable $candidates, bool $internal, ?Model $actor): array
    {
        $recipients = [];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof Model || ! method_exists($candidate, 'routeNotificationFor')) {
                continue;
            }

            $key = self::key($candidate);

            if (isset($recipients[$key])
                || ($actor !== null && ! TicketsConfig::notifyActor() && self::same($candidate, $actor))
                || ($candidate instanceof TicketGuest && ! TicketsConfig::notifyGuests())) {
                continue;
            }

            $sees = $internal
                ? TicketVisibility::isAgentFor($candidate, $ticket)
                : TicketVisibility::canView($candidate, $ticket);

            if ($sees) {
                $recipients[$key] = $candidate;
            }
        }

        return array_values($recipients);
    }

    /**
     * @return list<Model>
     */
    private function requester(Ticket $ticket): array
    {
        // Read fresh, never a relation cached before the change that is being told.
        $requester = $ticket->requester()->getResults();

        return $requester instanceof Model ? [$requester] : [];
    }

    /**
     * @return list<Model>
     */
    private function assignee(Ticket $ticket): array
    {
        $assignee = $ticket->assignee()->getResults();

        return $assignee instanceof Model ? $this->namedBy($assignee) : [];
    }

    /**
     * Whoever an assignee names — itself, for a model that is no {@see Assignee}.
     *
     * @return list<Model>
     */
    private function namedBy(Model $assignee): array
    {
        if (! $assignee instanceof Assignee) {
            return [$assignee];
        }

        $named = [];

        foreach ($assignee->ticketNotifiables() as $notifiable) {
            $named[] = $notifiable;
        }

        return $named;
    }

    /**
     * The watchers — with `$agents`, only those who are (true) or are not (false) agents.
     *
     * @return list<Model>
     */
    private function watchers(Ticket $ticket, ?bool $agents = null): array
    {
        $watchers = [];

        foreach (TicketWatcher::query()->where('ticket_id', $ticket->getKey())->orderBy('id')->with('watcher')->get() as $watch) {
            // A watcher whose model is gone loads as null.
            $watcher = $watch->getRelationValue('watcher');

            if ($watcher instanceof Model && ($agents === null || Agents::is($watcher) === $agents)) {
                $watchers[] = $watcher;
            }
        }

        return $watchers;
    }

    private static function key(Model $model): string
    {
        return $model->getMorphClass().'|'.(string) $model->getKey();
    }

    private static function same(Model $a, Model $b): bool
    {
        return self::key($a) === self::key($b);
    }
}
