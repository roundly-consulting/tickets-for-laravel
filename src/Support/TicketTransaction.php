<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Every mutating action runs here: on the model's connection, retried after a deadlock
 * when this is the outermost transaction (Laravel never retries a nested level).
 *
 * @internal
 */
final class TicketTransaction
{
    public const int ATTEMPTS = 3;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Model $model, Closure $callback): mixed
    {
        return $model->getConnection()->transaction($callback, self::ATTEMPTS);
    }
}
