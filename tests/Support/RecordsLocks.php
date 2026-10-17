<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Support;

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;

/**
 * LockRecorder variant B: the locks sit in package actions — on the final TicketSequence and
 * on host-swappable rows — which a model-trait recorder cannot see. SQLite only: on a real
 * engine the engine locks for real and the race tests prove it.
 */
final class RecordsLocks
{
    public static function start(): void
    {
        $connection = DB::connection();
        $connection->setQueryGrammar(new LockRecordingGrammar($connection));

        LockRecorder::flush();
        LockRecorder::listenForMarkers();
    }

    public static function available(): bool
    {
        return DriverMatrix::driver() === 'sqlite';
    }

    /**
     * The tables locked `FOR UPDATE`, in order, with the depth each lock ran at.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return array_map(
            static fn (array $record): string => (string) preg_replace('/^.*?from\s+"?([a-z_]+)"?.*$/is', '$1', $record['sql']),
            LockRecorder::recorded(),
        );
    }
}
