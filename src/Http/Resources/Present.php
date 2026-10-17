<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Resources;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Lang;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Tickets\Contracts\Priority;
use RoundlyConsulting\Tickets\Contracts\TicketState;
use RoundlyConsulting\Tickets\Enums\TicketChannel;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\EnumLabels;

/**
 * The small value shapes the resources share: a state, a priority, a channel, a date. Each
 * presented value carries its translated label and, when its enum has one, its colour.
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
            'label' => EnumLabels::of($state),
            'category' => $state instanceof TicketState ? $state->category()->value : null,
            'color' => $state instanceof HasColor ? $state->color() : null,
        ];
    }

    /**
     * The stored priority with its label and colour — a value the configured enum no longer has
     * keeps its value and loses its label and colour.
     *
     * @return array<string, mixed>
     */
    public static function priority(Ticket $ticket): array
    {
        $case = $ticket->priority();

        return [
            'value' => $ticket->priority,
            'label' => $case instanceof BackedEnum ? EnumLabels::of($case) : null,
            'weight' => $case instanceof Priority ? $case->weight() : 0,
            'color' => $case instanceof HasColor ? $case->color() : null,
        ];
    }

    /**
     * A channel with its label and colour: a shipped channel through {@see TicketChannel}; a
     * host channel (listed in `tickets.channels`) with the label the host published under
     * `tickets::enums.ticket_channel.<value>`, else its value, and no colour.
     *
     * @return array{value: string, label: string, color: string|null}|null
     */
    public static function channel(?string $channel): ?array
    {
        if ($channel === null) {
            return null;
        }

        $case = TicketChannel::tryFrom($channel);

        if ($case !== null) {
            return ['value' => $channel, 'label' => EnumLabels::of($case), 'color' => $case->color()];
        }

        $key = 'tickets::enums.ticket_channel.'.$channel;
        $label = ! str_contains($channel, '.') && Lang::has($key) ? Lang::get($key) : null;

        return ['value' => $channel, 'label' => is_string($label) && $label !== '' ? $label : $channel, 'color' => null];
    }

    public static function date(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601String();
    }
}
