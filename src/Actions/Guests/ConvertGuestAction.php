<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Guests;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Attributes\AttributesManager;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestField;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\GuestConverted;
use RoundlyConsulting\Tickets\Events\TicketRequesterChanged;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\GuestFields;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * A guest registered: every ticket they requested and every ticket they watch moves to the
 * user, in one transaction. A watch the user already has — or gains meanwhile — makes the
 * guest's row redundant, so it is deleted rather than colliding on the unique index. The
 * guest's identifying values are force-detached — free for whoever writes in as a guest next —
 * and the guest is soft-deleted. Comments the guest wrote keep the guest as author (history).
 *
 * Returns how many tickets moved.
 */
final readonly class ConvertGuestAction
{
    public function __construct(
        private AttributesManager $attributes,
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(TicketGuest $guest, Model $user, ?Model $actor = null): int
    {
        $actor = ActorResolver::resolve($actor);

        return TicketTransaction::run($guest, function () use ($guest, $user, $actor): int {
            $tickets = TicketModels::ticket()::withTrashed()
                ->where('requester_type', $guest->getMorphClass())
                ->where('requester_id', $guest->getKey())
                ->orderBy((new (TicketModels::ticket()))->getKeyName())
                ->lockForUpdate()
                ->get();

            foreach ($tickets as $ticket) {
                $ticket->forceFill([
                    'requester_type' => $user->getMorphClass(),
                    'requester_id' => $user->getKey(),
                ])->save();

                $this->record->execute($ticket, ActivityType::RequesterChanged, $actor, [
                    'from' => ['type' => $guest->getMorphClass(), 'id' => $guest->getKey()],
                    'to' => ['type' => $user->getMorphClass(), 'id' => $user->getKey()],
                    'converted' => true,
                ]);

                $this->events->dispatch(new TicketRequesterChanged($ticket, $user, $actor));
            }

            $this->moveWatches($guest, $user);

            $identifying = array_map(static fn (GuestField $field): string => $field->name, GuestFields::identifying());

            if ($identifying !== []) {
                $this->attributes->for($guest)->forget($identifying, forceDelete: true);
            }

            $guest->delete();

            $this->events->dispatch(new GuestConverted($guest, $user, $tickets->count(), $actor));

            return $tickets->count();
        });
    }

    private function moveWatches(TicketGuest $guest, Model $user): void
    {
        $watches = TicketWatcher::query()
            ->where('watcher_type', $guest->getMorphClass())
            ->where('watcher_id', $guest->getKey())
            ->lockForUpdate()
            ->get();

        // Insert the user's row unless it exists, then drop the guest's — never check, then
        // re-point: a ticket the guest only watches is not locked here, so another session may
        // add the user as its watcher in between, and the update would hit the unique index and
        // roll the whole conversion back.
        foreach ($watches as $watch) {
            TicketWatcher::query()->toBase()->insertOrIgnore([
                'ticket_id' => $watch->ticket_id,
                'watcher_type' => $user->getMorphClass(),
                'watcher_id' => $user->getKey(),
                'auto' => $watch->auto,
                'created_at' => $watch->created_at,
            ]);

            $watch->delete();
        }
    }
}
