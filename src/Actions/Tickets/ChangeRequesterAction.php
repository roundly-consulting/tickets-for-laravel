<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tickets;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Tickets\Actions\Guests\ResolveGuestAction;
use RoundlyConsulting\Tickets\Actions\Internal\BumpGuestAccessAction;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketRequesterChanged;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\GuestDirectory;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Hands a ticket to another requester — a host model, or guest fields resolved to a guest
 * (validation errors keyed `guest.<name>`). The same requester again is a no-op.
 *
 * The ticket leaves the previous requester: their automatic watch (`tickets.watchers.auto`)
 * goes, so they no longer view it or hear of it — a watch an agent added on purpose stays — and
 * when they were a guest, every guest link to the ticket is revoked (`GuestAccessRevoked`).
 */
final readonly class ChangeRequesterAction
{
    public function __construct(
        private ResolveGuestAction $resolveGuest,
        private RecordActivityAction $record,
        private BumpGuestAccessAction $revokeGuestAccess,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, Model|GuestData $requester, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $requester, $actor, $system): Ticket {
            TicketLocker::lock($ticket);

            $to = $requester instanceof GuestData ? $this->resolveGuest($requester) : $requester;
            $from = ['type' => $ticket->requester_type, 'id' => $ticket->requester_id];

            if ($from['type'] === $to->getMorphClass() && (string) $from['id'] === (string) $to->getKey()) {
                return $ticket;
            }

            $ticket->forceFill(['requester_type' => $to->getMorphClass(), 'requester_id' => $to->getKey()])->save();
            $ticket->setRelation('requester', $to);

            $this->record->execute($ticket, ActivityType::RequesterChanged, $actor, [
                'from' => $from,
                'to' => ['type' => $to->getMorphClass(), 'id' => $to->getKey()],
            ], $system);

            $this->leave($ticket, $from['type'], $from['id'], $actor, $system);

            $this->events->dispatch(new TicketRequesterChanged($ticket, $to, $actor));

            return $ticket;
        });
    }

    /**
     * The previous requester's automatic watch goes (a chosen one stays); a guest's links die.
     */
    private function leave(Ticket $ticket, string $type, int|string $key, ?Model $actor, bool $system): void
    {
        TicketWatcher::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('watcher_type', $type)
            ->where('watcher_id', $key)
            ->where('auto', true)
            ->delete();

        $ticket->unsetRelation('watchers');

        if ($type === (new (TicketModels::guest()))->getMorphClass()) {
            $this->revokeGuestAccess->execute($ticket, $actor, $system);
        }
    }

    /**
     * Guest errors keyed `guest.<name>`, as when filing a ticket.
     */
    private function resolveGuest(GuestData $guest): Model
    {
        try {
            return $this->resolveGuest->execute($guest);
        } catch (ValidationException $exception) {
            throw GuestDirectory::rekey($exception, 'guest');
        }
    }
}
