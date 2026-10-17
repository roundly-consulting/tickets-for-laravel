<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Fields\FieldSetResolver;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\Agents;
use RoundlyConsulting\Tickets\Support\Messages;
use RoundlyConsulting\Tickets\Support\RequesterTransitions;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketVisibility;

/**
 * Who may do what with a ticket — the authority behind the HTTP API. Registered for the
 * configured ticket model (`tickets.authorization.register_policy`); swap it with a subclass
 * (`tickets.authorization.policy`) to change a rule.
 *
 * Agents are users the `agent` ability allows, narrowed per ticket by the agent scope. The
 * other abilities (`assign`, `merge`, `manage`) are Gate abilities the host defines; an
 * undefined ability denies, so the policy fails closed.
 *
 * The PHP API (`Tickets::for(...)`) trusts its caller and never asks this policy — the host
 * decides there.
 */
class TicketPolicy
{
    /**
     * Anyone signed in may list tickets; the list is scoped to what they may view.
     */
    public function viewAny(Model $user): bool
    {
        return true;
    }

    /**
     * An agent the agent scope allows, the requester, or a watcher (`tickets.watchers.can_view`).
     * Anyone else is told the ticket does not exist.
     */
    public function view(Model $user, Ticket $ticket): Response
    {
        return TicketVisibility::canView($user, $ticket) ? Response::allow() : self::notFound($ticket);
    }

    /**
     * Anyone signed in files their own ticket (`tickets.authorization.self_service`); agents
     * always may.
     */
    public function create(Model $user): bool
    {
        return TicketsConfig::selfService() || Agents::is($user);
    }

    /**
     * An agent changes anything; the requester only the subject and description
     * (`requester_can_edit`) and their editable custom fields, while the ticket is unresolved.
     *
     * @param  list<string>  $attributes  what the update touches (`subject`, `description`,
     *                                    `priority`, `category`, `due_at`, `requester`, `fields`,
     *                                    `attachments`); none = may they edit anything at
     *                                    all — the subject and description, or an active field
     *                                    of the ticket's field set that is `requester_editable`
     */
    public function update(Model $user, Ticket $ticket, array $attributes = []): bool
    {
        if (TicketVisibility::isAgentFor($user, $ticket)) {
            return true;
        }

        if (! TicketVisibility::isRequester($user, $ticket) || ! self::unresolved($ticket)) {
            return false;
        }

        $editable = TicketsConfig::requesterCanEdit() ? ['subject', 'description', 'fields'] : ['fields'];

        if ($attributes === []) {
            return TicketsConfig::requesterCanEdit() || self::hasRequesterEditableField($ticket);
        }

        return array_diff($attributes, $editable) === [];
    }

    /**
     * The manage ability, held by an agent the agent scope lets work on the ticket — a manager
     * is scoped like every other agent.
     */
    public function delete(Model $user, Ticket $ticket): bool
    {
        return TicketVisibility::isAgentFor($user, $ticket) && self::allows($user, TicketsConfig::manageAbility());
    }

    /**
     * As {@see self::delete()}.
     */
    public function restore(Model $user, Ticket $ticket): bool
    {
        return TicketVisibility::isAgentFor($user, $ticket) && self::allows($user, TicketsConfig::manageAbility());
    }

    /**
     * An agent runs any transition the lifecycle allows; the requester (a watcher too, with
     * `participants_can_transition`) only the `requester_transitions`.
     */
    public function transition(Model $user, Ticket $ticket, ?string $transition = null): bool
    {
        return TicketVisibility::isAgentFor($user, $ticket) || RequesterTransitions::allows($user, $ticket, $transition);
    }

    /**
     * An agent with the `assign` ability assigns anyone; any agent may take the ticket
     * themselves (a claim).
     */
    public function assign(Model $user, Ticket $ticket, ?Model $assignee = null): bool
    {
        if (! TicketVisibility::isAgentFor($user, $ticket)) {
            return false;
        }

        if ($assignee !== null && self::same($assignee, $user)) {
            return true;
        }

        return self::allows($user, TicketsConfig::assignAbility());
    }

    /**
     * An agent with the `assign` ability, or the agent who holds the ticket handing it back.
     */
    public function unassign(Model $user, Ticket $ticket): bool
    {
        if (! TicketVisibility::isAgentFor($user, $ticket)) {
            return false;
        }

        $holds = $ticket->assignee_type === $user->getMorphClass() && (string) $ticket->assignee_id === (string) $user->getKey();

        return $holds || self::allows($user, TicketsConfig::assignAbility());
    }

    /**
     * Whoever may view the ticket replies to it — until it is closed (409 `ticket_closed`).
     */
    public function reply(Model $user, Ticket $ticket): Response
    {
        if (! TicketVisibility::canView($user, $ticket)) {
            return self::notFound($ticket);
        }

        return $ticket->statusCategory() === StatusCategory::Closed
            ? Response::denyWithStatus(409, Messages::get('ticket.closed', ['reference' => $ticket->reference]), 'ticket_closed')
            : Response::allow();
    }

    /**
     * Internal notes: agents only.
     */
    public function note(Model $user, Ticket $ticket): bool
    {
        return TicketVisibility::isAgentFor($user, $ticket);
    }

    /**
     * The internal thread and the internal activity entries: agents only.
     */
    public function viewInternal(Model $user, Ticket $ticket): bool
    {
        return TicketVisibility::isAgentFor($user, $ticket);
    }

    /**
     * An agent edits any message; anyone else only their own public reply.
     */
    public function updateComment(Model $user, Ticket $ticket, Comment $comment): bool
    {
        return TicketVisibility::isAgentFor($user, $ticket) || self::ownsPublicReply($user, $ticket, $comment);
    }

    /**
     * An agent deletes any message; anyone else only their own public reply.
     */
    public function deleteComment(Model $user, Ticket $ticket, Comment $comment): bool
    {
        return TicketVisibility::isAgentFor($user, $ticket) || self::ownsPublicReply($user, $ticket, $comment);
    }

    /**
     * An agent adds and removes anyone; whoever may view the ticket watches or unwatches it
     * themselves (`$watcher` = them).
     */
    public function manageWatchers(Model $user, Ticket $ticket, ?Model $watcher = null): bool
    {
        if (TicketVisibility::isAgentFor($user, $ticket)) {
            return true;
        }

        return $watcher !== null && self::same($watcher, $user) && TicketVisibility::canView($user, $ticket);
    }

    /**
     * Tags are agent-only labels.
     */
    public function tag(Model $user, Ticket $ticket): bool
    {
        return TicketVisibility::isAgentFor($user, $ticket);
    }

    /**
     * Links between tickets: agents only (the API asks for both tickets).
     */
    public function link(Model $user, Ticket $ticket): bool
    {
        return TicketVisibility::isAgentFor($user, $ticket);
    }

    /**
     * The `merge` ability, held by an agent the agent scope lets work on the ticket (the API asks
     * for both tickets).
     */
    public function merge(Model $user, Ticket $ticket): bool
    {
        return TicketVisibility::isAgentFor($user, $ticket) && self::allows($user, TicketsConfig::mergeAbility());
    }

    public function manageCategories(Model $user): bool
    {
        return self::allows($user, TicketsConfig::manageAbility());
    }

    public function manageFields(Model $user): bool
    {
        return self::allows($user, TicketsConfig::manageAbility());
    }

    public function manageTags(Model $user): bool
    {
        return self::allows($user, TicketsConfig::manageAbility());
    }

    /**
     * Whether the user may list the tags — agents, who are the ones applying them.
     */
    public function viewTags(Model $user): bool
    {
        return Agents::is($user);
    }

    protected static function allows(Model $user, string $ability): bool
    {
        return $user instanceof Authenticatable
            && Container::getInstance()->make(Gate::class)->forUser($user)->allows($ability);
    }

    protected static function unresolved(Ticket $ticket): bool
    {
        return $ticket->statusCategory()?->isUnresolved() ?? true;
    }

    /**
     * Whether the ticket's field set has an active field its requester may set.
     */
    protected static function hasRequesterEditableField(Ticket $ticket): bool
    {
        $fields = Container::getInstance()->make(FieldSetResolver::class)->forTicket($ticket);

        return array_filter($fields, static fn (FieldDefinition $field): bool => $field->requesterEditable) !== [];
    }

    protected static function notFound(Ticket $ticket): Response
    {
        return Response::denyAsNotFound(Messages::get('ticket.not_found', ['reference' => $ticket->reference]), 'ticket_not_found');
    }

    private static function same(Model $a, Model $b): bool
    {
        return $a->getMorphClass() === $b->getMorphClass() && (string) $a->getKey() === (string) $b->getKey();
    }

    private static function ownsPublicReply(Model $user, Ticket $ticket, Comment $comment): bool
    {
        return ! TicketComments::isInternal($comment)
            && $comment->actor_type === $user->getMorphClass()
            && (string) $comment->actor_id === (string) $user->getKey()
            && TicketVisibility::canView($user, $ticket);
    }
}
