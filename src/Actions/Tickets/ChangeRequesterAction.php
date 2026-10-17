<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tickets;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Tickets\Actions\Guests\ResolveGuestAction;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketRequesterChanged;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\GuestDirectory;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Hands a ticket to another requester — a host model, or guest fields resolved to a guest
 * (validation errors keyed `guest.<name>`). The same requester again is a no-op.
 */
final readonly class ChangeRequesterAction
{
    public function __construct(
        private ResolveGuestAction $resolveGuest,
        private RecordActivityAction $record,
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

            $this->events->dispatch(new TicketRequesterChanged($ticket, $to, $actor));

            return $ticket;
        });
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
