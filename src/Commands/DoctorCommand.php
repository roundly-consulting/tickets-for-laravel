<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Tickets\DataTransferObjects\DoctorCheck;
use RoundlyConsulting\Tickets\Support\Doctor;

/**
 * Checks the installation and reports what would break, by section: key-type alignment, the
 * lifecycle contract and its automations (and the supported transition conveniences), the
 * priority enum and stored priorities it lost, the Gate abilities, the alias allow-lists, the
 * guest fields, the comments `meta` column and `comments.authorization`, the attachments disk
 * and its download URLs, the HTTP API and guest links, notifications, due dates and the
 * scheduled sweeps. Exits non-zero on any error; `--json` for machines.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'tickets:doctor {--json : Print the checks as JSON}';

    protected $description = 'Check the tickets installation and report what would break';

    public function handle(): int
    {
        $checks = Doctor::checks();
        $failed = array_filter($checks, static fn (DoctorCheck $check): bool => $check->isError()) !== [];

        if ($this->option('json') === true) {
            $this->line((string) json_encode([
                'ok' => ! $failed,
                'checks' => array_map(static fn (DoctorCheck $check): array => [
                    'section' => $check->section,
                    'code' => $check->code,
                    'level' => $check->level,
                    'message' => $check->message,
                ], $checks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        foreach ($checks as $check) {
            $line = sprintf('[%s] %s: %s', strtoupper($check->level), $check->section, $check->message);

            match ($check->level) {
                'error' => $this->error($line),
                'warning' => $this->warn($line),
                default => $this->info($line),
            };
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
