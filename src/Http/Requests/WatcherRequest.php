<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;

/**
 * `POST /tickets/{ticket}/watchers` — `{watcher: {type, id}}`, a `tickets.watchables` alias.
 *
 * @internal
 */
final class WatcherRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return self::morphRules('watcher', required: true);
    }

    public function watcherReference(): MorphReference
    {
        return $this->morph('watcher') ?? new MorphReference('', '');
    }
}
