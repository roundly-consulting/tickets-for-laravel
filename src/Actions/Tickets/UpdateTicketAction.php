<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tickets;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateTicketData;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Events\TicketUpdated;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\Optional;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * A partial update in one transaction. The subject, description and host metadata are written
 * here (an `updated` activity naming the changed keys, never a value — the metadata's in an
 * agent-only entry of its own, and a metadata-only change leaves the public `updated_at` alone); priority, category, due date and requester
 * run through their own actions, so each records its own activity and event.
 */
final readonly class UpdateTicketAction
{
    public function __construct(
        private ChangePriorityAction $changePriority,
        private ChangeCategoryAction $changeCategory,
        private SetDueDateAction $setDueDate,
        private ChangeRequesterAction $changeRequester,
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, UpdateTicketData $data, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);
        $subject = Optional::given($data->subject) ? TicketInput::subject($data->subject) : null;

        return TicketTransaction::run($ticket, function () use ($ticket, $data, $actor, $system, $subject): Ticket {
            TicketLocker::lock($ticket);

            $changes = [];

            if ($subject !== null && $subject !== $ticket->subject) {
                $changes['subject'] = $subject;
            }

            if (Optional::given($data->description) && $data->description !== $ticket->description) {
                $changes['description'] = $data->description;
            }

            if (Optional::given($data->meta) && self::sorted($data->meta) !== self::sorted($ticket->meta ?? [])) {
                $changes['meta'] = $data->meta === [] ? null : $data->meta;
            }

            if ($changes !== []) {
                $visible = array_values(array_diff(array_keys($changes), ['meta']));
                $this->write($ticket, $changes, public: $visible !== []);

                if ($visible !== []) {
                    $this->record->execute($ticket, ActivityType::Updated, $actor, ['changed' => $visible], $system);
                }

                // The host's metadata is its own business: its change is an agent-only entry.
                if (array_key_exists('meta', $changes)) {
                    $this->record->execute($ticket, ActivityType::Updated, $actor, ['changed' => ['meta']], $system, ActivityVisibility::Internal);
                }

                $this->events->dispatch(new TicketUpdated($ticket, array_keys($changes), $actor));
            }

            if (Optional::given($data->priority)) {
                $this->changePriority->execute($ticket, $data->priority, $actor, $system);
            }

            if (Optional::given($data->category)) {
                $this->changeCategory->execute($ticket, $data->category, $actor, $system);
            }

            if (Optional::given($data->dueAt)) {
                $this->setDueDate->execute($ticket, $data->dueAt, $actor, $system);
            }

            if (Optional::given($data->requester)) {
                $this->changeRequester->execute($ticket, $data->requester, $actor, $system);
            }

            return $ticket;
        });
    }

    /**
     * A public change saves (and moves `updated_at`). The metadata alone is the host's agent-only
     * business, so it goes in as a plain query: `updated_at` is a public field and must not tell
     * a requester something changed that they can't see.
     *
     * @param  array<string, mixed>  $changes
     */
    private function write(Ticket $ticket, array $changes, bool $public): void
    {
        $ticket->forceFill($changes);

        if ($public) {
            $ticket->save();

            return;
        }

        $ticket->newQueryWithoutScopes()->whereKey($ticket->getKey())->toBase()->update(['meta' => $ticket->getAttributes()['meta']]);
        $ticket->syncOriginalAttribute('meta');
    }

    /**
     * The metadata with its object keys sorted, recursively — jsonb and MySQL's json reorder
     * keys, so an unchanged map must still compare equal.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(static fn (mixed $item): mixed => is_array($item) ? self::sorted($item) : $item, $value);
    }
}
