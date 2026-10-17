<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Guests;

use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Attributes\AttributesManager;
use RoundlyConsulting\Attributes\Exceptions\DuplicateAttributeValueException;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestField;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Support\GuestDirectory;
use RoundlyConsulting\Tickets\Support\GuestFields;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Finds the guest a submission belongs to, or creates one.
 *
 * Values are normalised (trimmed; e-mails lower-cased; phones without separators), then the
 * identifying fields are tried in config order. A match whose other identifying value
 * differs from the submission is a conflict: the submission becomes a NEW guest holding
 * only the identifying values no other guest owns — a ticket is never routed to another
 * person's contact. On a match, blank fields are filled (never with an identifying value
 * another guest holds) and differing non-identifying fields are updated only with
 * `tickets.guests.update_on_resolve`.
 *
 * Race-free through the unique index, not a lock: two first tickets from the same new
 * guest collide on the attributes' `unique_hash`, and the loser re-reads (a locking read)
 * and gets the winner.
 */
final readonly class ResolveGuestAction
{
    public function __construct(
        private AttributesManager $attributes,
    ) {}

    public function execute(GuestData $data): TicketGuest
    {
        $fields = GuestFields::all();
        $values = GuestDirectory::validated($data, $fields, partial: false);
        $class = TicketModels::guest();

        return TicketTransaction::run(new $class, function () use ($fields, $values): TicketGuest {
            $match = GuestDirectory::match($values, $fields, locking: false);

            if ($match !== null && ! GuestDirectory::conflicts($match, $values, $fields)) {
                return $this->fill($match, $values, $fields);
            }

            $create = $match === null ? $values : GuestDirectory::unclaimed($values, $fields);

            try {
                return $this->create($create);
            } catch (DuplicateAttributeValueException|UniqueConstraintViolationException $exception) {
                return GuestDirectory::match($values, $fields, locking: true) ?? throw $exception;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function create(array $values): TicketGuest
    {
        $class = TicketModels::guest();
        $guest = new $class;

        return $guest->getConnection()->transaction(function () use ($guest, $values): TicketGuest {
            $guest->save();

            $this->attributes->for($guest)->setMany(array_filter($values, static fn (mixed $value): bool => $value !== null));

            return $guest;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, GuestField>  $fields
     */
    private function fill(TicketGuest $guest, array $values, array $fields): TicketGuest
    {
        if ($guest->trashed()) {
            $guest->restore();
        }

        $changes = [];

        foreach ($values as $name => $value) {
            if ($value === null) {
                continue;
            }

            $field = $fields[$name];
            $current = $guest->getAttachedAttributeValue($name);

            if ($current === null) {
                if (! $field->identifying || ! GuestDirectory::heldByAnother($field, $value, $guest)) {
                    $changes[$name] = $value;
                }

                continue;
            }

            if (! $field->identifying && $current !== $value && GuestFields::updateOnResolve()) {
                $changes[$name] = $value;
            }
        }

        if ($changes !== []) {
            $this->attributes->for($guest)->setMany($changes);
        }

        return $guest;
    }
}
