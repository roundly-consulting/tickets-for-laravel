<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Tickets\Database\Factories\TicketSequenceFactory;

/**
 * A reference sequence (`global`, `{alias}:{key}`) or a `lock:*` advisory row. Rows are read
 * and written only under a row lock, inside the transaction that uses them.
 *
 * @property int $id
 * @property string $scope
 * @property int $next_number
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TicketSequence extends Model
{
    /** @use HasFactory<TicketSequenceFactory> */
    use HasFactory;

    protected $table = 'ticket_sequences';

    protected $guarded = [];

    protected static function newFactory(): TicketSequenceFactory
    {
        return TicketSequenceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
        ];
    }
}
