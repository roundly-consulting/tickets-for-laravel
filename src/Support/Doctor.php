<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use RoundlyConsulting\Tickets\DataTransferObjects\DoctorCheck;
use RoundlyConsulting\Tickets\DataTransferObjects\LifecycleIssue;

/**
 * The installation checks behind `tickets:doctor`, read-only. The lifecycle section checks
 * the configured lifecycle against the package's contract and lists which named transition
 * conveniences it supports.
 *
 * @internal
 */
final class Doctor
{
    /**
     * @return list<DoctorCheck>
     */
    public static function checks(): array
    {
        return self::lifecycle();
    }

    /**
     * @return list<DoctorCheck>
     */
    private static function lifecycle(): array
    {
        $issues = LifecycleContract::issues();

        $checks = array_map(
            static fn (LifecycleIssue $issue): DoctorCheck => new DoctorCheck('lifecycle', $issue->code, $issue->message, $issue->error ? 'error' : 'warning'),
            $issues,
        );

        if (array_filter($issues, static fn (LifecycleIssue $issue): bool => $issue->error) !== []) {
            return $checks;
        }

        $checks[] = new DoctorCheck('lifecycle', 'contract', Messages::get('doctor.lifecycle_ok', [
            'class' => TicketsConfig::lifecycle(),
        ]), 'ok');

        $supported = LifecycleContract::supportedConveniences();

        $checks[] = new DoctorCheck('lifecycle', 'conveniences', Messages::get('doctor.conveniences', [
            'list' => $supported === [] ? '—' : implode(', ', $supported),
        ]), count($supported) === 8 ? 'ok' : 'warning');

        return $checks;
    }
}
