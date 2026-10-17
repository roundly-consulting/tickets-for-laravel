<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use RoundlyConsulting\Comments\Support\CommentModel;
use RoundlyConsulting\PackageToolkit\Support\Config;
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
        return [...self::lifecycle(), ...self::conversation()];
    }

    /**
     * The comments-for-laravel side of the conversation: the 1.1 `meta` column replies and notes
     * are written with, and `comments.authorization`, which checks the authenticated user —
     * not tickets' own policy — on every reply, note, approval and lock.
     *
     * @return list<DoctorCheck>
     */
    private static function conversation(): array
    {
        $comment = CommentModel::class();
        $model = new $comment;

        $checks = [$model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'meta')
            ? new DoctorCheck('conversation', 'meta', Messages::get('doctor.meta_ok'), 'ok')
            : new DoctorCheck('conversation', 'meta', Messages::get('doctor.meta_missing'), 'error')];

        if (Config::boolean('comments.authorization')) {
            $checks[] = new DoctorCheck('conversation', 'comments_authorization', Messages::get('doctor.comments_authorization'), 'warning');
        }

        return $checks;
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
