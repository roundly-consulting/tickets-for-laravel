<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Accessors;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Guests\ConvertGuestAction;
use RoundlyConsulting\Tickets\Actions\Guests\IssueGuestLinkAction;
use RoundlyConsulting\Tickets\Actions\Guests\ResolveGuestAction;
use RoundlyConsulting\Tickets\Actions\Guests\UpdateGuestAction;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Support\GuestDirectory;
use RoundlyConsulting\Tickets\Support\GuestFields;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::guests()` — requesters without an account, and the links they open tickets with.
 */
final readonly class Guests
{
    public function __construct(
        private TicketsManager $manager,
    ) {}

    /**
     * The guest the fields identify, created when none matches.
     *
     * @param  GuestData|array<string, scalar|null>  $fields
     */
    public function resolve(GuestData|array $fields): TicketGuest
    {
        $data = $fields instanceof GuestData ? $fields : new GuestData($fields);

        return $this->manager->perform(
            TicketOperation::ResolveGuest,
            ResolveGuestAction::class,
            static fn (ResolveGuestAction $action): TicketGuest => $action->execute($data),
            ['data' => $data],
        );
    }

    /**
     * The guest the fields identify, or null — a read-only lookup: the identifying values are
     * normalised and tried in config order, exactly as `resolve()` matches, but nothing is
     * created, filled or validated, unknown names are ignored, and a soft-deleted guest is not
     * returned. Without an identifying value, null.
     *
     * @param  GuestData|array<string, scalar|null>  $fields
     */
    public function find(GuestData|array $fields): ?TicketGuest
    {
        $data = $fields instanceof GuestData ? $fields : new GuestData($fields);
        $definitions = GuestFields::all();
        $values = [];

        foreach ($data->fields as $name => $value) {
            if (isset($definitions[$name])) {
                $values[$name] = $definitions[$name]->normalise($value);
            }
        }

        $guest = GuestDirectory::match($values, $definitions, locking: false);

        return $guest === null || $guest->trashed() ? null : $guest;
    }

    /**
     * @param  GuestData|array<string, scalar|null>  $fields
     */
    public function update(TicketGuest $guest, GuestData|array $fields): TicketGuest
    {
        $data = $fields instanceof GuestData ? $fields : new GuestData($fields);

        return $this->manager->perform(
            TicketOperation::UpdateGuest,
            UpdateGuestAction::class,
            static fn (UpdateGuestAction $action): TicketGuest => $action->execute($guest, $data),
            ['guest' => $guest, 'data' => $data],
        );
    }

    /**
     * A temporary link `$guest` opens the ticket (a model or a reference) with — the ticket's
     * guest requester by default, or one of its guest watchers. The same call as
     * `Tickets::for($ticket)->guestLink($guest)`.
     */
    public function linkFor(Ticket|string $ticket, ?TicketGuest $guest = null): string
    {
        $ticket = is_string($ticket) ? $this->manager->findOrFail($ticket) : $ticket;

        return $this->manager->perform(
            TicketOperation::IssueGuestLink,
            IssueGuestLinkAction::class,
            static fn (IssueGuestLinkAction $action): string => $action->execute($ticket, $guest),
            ['ticket' => $ticket, 'guest' => $guest],
        );
    }

    /**
     * Move a guest's tickets and watches to a user who registered; returns how many tickets
     * moved.
     */
    public function convert(TicketGuest $guest, Model $user): int
    {
        return $this->manager->perform(
            TicketOperation::ConvertGuest,
            ConvertGuestAction::class,
            static fn (ConvertGuestAction $action): int => $action->execute($guest, $user),
            ['guest' => $guest, 'user' => $user],
        );
    }
}
