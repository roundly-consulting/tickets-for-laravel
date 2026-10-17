<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;

/**
 * The lock-first row pattern (lifecycle-for-laravel's `LockedRow::mutex`): the row with a
 * unique key, created when missing, locked `FOR UPDATE` until the transaction ends.
 *
 * Plain `firstOrCreate()` / `createOrFirst()` is not enough: their fallback is a
 * non-locking read, which under MySQL REPEATABLE READ can miss the row another transaction
 * just committed. Here an existing row is locked by its unique key; a missing one is
 * inserted with `INSERT … ON DUPLICATE KEY UPDATE` (MySQL) or `INSERT … ON CONFLICT DO
 * NOTHING` (elsewhere — pgsql waits for a racing insert to commit) and then locked with a
 * fresh, locking statement.
 *
 * @internal
 */
final class LockedRow
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  non-empty-array<non-empty-string, mixed>  $attributes  the unique key
     * @param  array<string, mixed>  $values  extra values for a fresh row
     * @return TModel
     */
    public static function mutex(Builder $query, array $attributes, array $values = []): Model
    {
        $existing = (clone $query)->where($attributes)->lockForUpdate()->first();

        if ($existing !== null) {
            return $existing;
        }

        $model = $query->getModel()->newInstance([...$attributes, ...$values]);

        if ($model->usesTimestamps()) {
            $model->updateTimestamps();
        }

        $driver = DatabaseDriver::tryFrom($model->getConnection()->getDriverName());

        $driver === DatabaseDriver::Mysql || $driver === DatabaseDriver::Mariadb
            ? $query->toBase()->upsert([$model->getAttributes()], array_keys($attributes), [array_key_first($attributes)])
            : $query->toBase()->insertOrIgnore([$model->getAttributes()]);

        return (clone $query)->where($attributes)->lockForUpdate()->firstOrFail();
    }
}
