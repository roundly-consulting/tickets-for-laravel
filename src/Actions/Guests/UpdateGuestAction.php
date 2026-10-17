<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Guests;

use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Attributes\AttributesManager;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Support\GuestDirectory;
use RoundlyConsulting\Tickets\Support\GuestFields;
use RoundlyConsulting\Tickets\Support\Messages;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Changes some of a guest's fields. Only the given fields are touched; `null` clears one.
 * An identifying value another guest holds is refused (422).
 */
final readonly class UpdateGuestAction
{
    public function __construct(
        private AttributesManager $attributes,
    ) {}

    public function execute(TicketGuest $guest, GuestData $data): TicketGuest
    {
        $fields = GuestFields::all();
        $values = GuestDirectory::validated($data, $fields, partial: true);

        foreach ($values as $name => $value) {
            if ($value !== null && $fields[$name]->identifying && GuestDirectory::heldByAnother($fields[$name], $value, $guest)) {
                throw ValidationException::withMessages(['fields.'.$name => [Messages::get('guest.taken', ['field' => $name])]]);
            }
        }

        return TicketTransaction::run($guest, function () use ($guest, $values): TicketGuest {
            $set = array_filter($values, static fn (mixed $value): bool => $value !== null);
            $clear = array_keys(array_diff_key($values, $set));

            if ($set !== []) {
                $this->attributes->for($guest)->setMany($set);
            }

            if ($clear !== []) {
                $this->attributes->for($guest)->forget($clear, forceDelete: true);
            }

            return $guest;
        });
    }
}
