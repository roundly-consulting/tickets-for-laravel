<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Carbon\CarbonInterval;
use Throwable;

/**
 * ISO-8601 durations (`P2D`, `PT4H`, `P1DT12H`) — the one duration format the package
 * accepts, in config and on categories.
 *
 * @internal
 */
final class Durations
{
    /**
     * The positive interval an ISO-8601 duration spells, or null for anything else.
     */
    public static function parse(string $value): ?CarbonInterval
    {
        if (preg_match('/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/', $value) !== 1) {
            return null;
        }

        try {
            $interval = new CarbonInterval($value);
        } catch (Throwable) {
            return null;
        }

        return $interval->totalSeconds > 0 ? $interval : null;
    }
}
