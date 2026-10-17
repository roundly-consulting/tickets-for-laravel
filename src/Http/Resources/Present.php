<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Resources;

use BackedEnum;
use Carbon\CarbonInterface;
use RoundlyConsulting\Tickets\Contracts\Priority;
use RoundlyConsulting\Tickets\Contracts\TicketState;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The small value shapes the resources share: a state, a priority, an enum label, a date.
 *
 * @internal
 */
final class Present
{
    /**
     * @return array<string, mixed>|null
     */
    public static function state(?BackedEnum $state): ?array
    {
        if ($state === null) {
            return null;
        }

        return [
            'value' => $state->value,
            'label' => self::label($state),
            'category' => $state instanceof TicketState ? $state->category()->value : null,
        ];
    }

    /**
     * The stored priority with its label — a value the configured enum no longer has keeps its
     * value and loses its label.
     *
     * @return array<string, mixed>
     */
    public static function priority(Ticket $ticket): array
    {
        $case = $ticket->priority();

        return [
            'value' => $ticket->priority,
            'label' => $case instanceof BackedEnum ? self::label($case) : null,
            'weight' => $case instanceof Priority ? $case->weight() : 0,
        ];
    }

    public static function label(BackedEnum $case): string
    {
        $label = method_exists($case, 'label') ? $case->label() : null;

        return is_string($label) ? $label : (string) $case->value;
    }

    public static function date(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601String();
    }
}
