<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Handles;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Links\LinkTicketsAction;
use RoundlyConsulting\Tickets\Actions\Links\UnlinkTicketsAction;
use RoundlyConsulting\Tickets\DataTransferObjects\TicketLinkView;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketLink;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::for($ticket)->links()` — typed links to other tickets (a model or a reference).
 * Mutators return this accessor; `all()` reads every link from this ticket's side.
 */
final readonly class TicketLinks
{
    public function __construct(
        private TicketsManager $manager,
        private Ticket $ticket,
        private ?Model $actor = null,
        private bool $system = false,
    ) {}

    public function add(Ticket|string $other, LinkType $type): static
    {
        $related = $this->resolve($other);

        $this->manager->perform(
            TicketOperation::Link,
            LinkTicketsAction::class,
            fn (LinkTicketsAction $action): TicketLink => $action->execute($this->ticket, $related, $type, $this->actor, $this->system),
            $this->context($related, $type),
        );

        return $this;
    }

    public function remove(Ticket|string $other, LinkType $type): static
    {
        $related = $this->resolve($other);

        $this->manager->perform(
            TicketOperation::Unlink,
            UnlinkTicketsAction::class,
            fn (UnlinkTicketsAction $action): Ticket => $action->execute($this->ticket, $related, $type, $this->actor, $this->system),
            $this->context($related, $type),
        );

        return $this;
    }

    /**
     * Every link of the ticket, in the order they were made, as read from here: `blocks` on
     * the outgoing end, `blocked_by` on the incoming one. Links to a deleted ticket are left
     * out.
     *
     * @return list<TicketLinkView>
     */
    public function all(): array
    {
        $key = $this->ticket->getKey();
        $views = [];

        $links = TicketLink::query()
            ->where(static fn ($query) => $query->where('ticket_id', $key)->orWhere('related_ticket_id', $key))
            ->with(['ticket', 'related'])
            ->orderBy('id')
            ->get();

        foreach ($links as $link) {
            $outgoing = (string) $link->ticket_id === (string) $key;
            $other = $outgoing ? $link->related : $link->ticket;

            if ($other === null) {
                continue;
            }

            $views[] = new TicketLinkView(
                link: $link->id,
                ticket: $other,
                type: $link->type,
                label: $outgoing ? $link->type->value : $link->type->inverse(),
                direction: $outgoing ? TicketLinkView::OUTGOING : TicketLinkView::INCOMING,
            );
        }

        return $views;
    }

    public function ticket(): Ticket
    {
        return $this->ticket;
    }

    private function resolve(Ticket|string $other): Ticket
    {
        return is_string($other) ? $this->manager->findOrFail($other) : $other;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Ticket $related, LinkType $type): array
    {
        return ['ticket' => $this->ticket, 'actor' => $this->actor, 'system' => $this->system, 'related' => $related, 'type' => $type];
    }
}
