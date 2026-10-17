<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use BackedEnum;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Tickets\Contracts\TicketState;
use RoundlyConsulting\Tickets\Enums\StatusCategory;

/**
 * The states of the configured ticket lifecycle, read by status category — the only thing
 * package logic knows about a (possibly host-swapped) lifecycle.
 *
 * @internal
 */
final class TicketStates
{
    public static function definition(): CompiledDefinition
    {
        return App::make(DefinitionRegistry::class)->get(TicketsConfig::lifecycle());
    }

    /**
     * The stored values of every state in one of the categories.
     *
     * @param  list<StatusCategory>  $categories
     * @return list<string|int>
     */
    public static function valuesIn(array $categories): array
    {
        $definition = self::definition();
        $values = [];

        foreach ($definition->values() as $state) {
            if ($state instanceof TicketState && in_array($state->category(), $categories, true)) {
                $values[] = $definition->encode($state);
            }
        }

        return $values;
    }

    /**
     * The stored value of a state given as a case or its raw value.
     */
    public static function stored(BackedEnum|string $state): string|int
    {
        return $state instanceof BackedEnum ? $state->value : $state;
    }
}
