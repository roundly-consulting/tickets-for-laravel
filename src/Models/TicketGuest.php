<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Attributes\Contracts\HasAttributes as HasAttributesContract;
use RoundlyConsulting\Attributes\Traits\HasAttributes as HasCustomFields;
use RoundlyConsulting\Tickets\Concerns\HasConfigurableKey;
use RoundlyConsulting\Tickets\Database\Factories\TicketGuestFactory;

/**
 * A requester without an account. It stores no fixed PII columns: every field (name,
 * email, phone, an external id, …) is declared in `tickets.guests.fields` and stored
 * through attributes-for-laravel.
 *
 * Swappable through `tickets.models.guest`.
 *
 * @property int|string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class TicketGuest extends Model implements HasAttributesContract
{
    use HasConfigurableKey;
    use HasCustomFields;

    /** @use HasFactory<TicketGuestFactory> */
    use HasFactory;

    use Notifiable;
    use SoftDeletes;

    protected $table = 'ticket_guests';

    protected $guarded = [];

    protected static function newFactory(): TicketGuestFactory
    {
        return TicketGuestFactory::new();
    }
}
