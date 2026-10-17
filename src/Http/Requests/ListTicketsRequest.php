<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * `GET /tickets` — the page size. Filters and sorts are read by the allow-listed
 * `TicketIndexQuery`, which refuses anything it does not know.
 *
 * @internal
 */
final class ListTicketsRequest extends TicketsRequest
{
    /** The page size when none is asked for, capped at `tickets.routes.max_per_page`. */
    public const int DEFAULT_PER_PAGE = 20;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.TicketsConfig::maxPerPage()],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return $this->integerValue('per_page') ?? min(self::DEFAULT_PER_PAGE, TicketsConfig::maxPerPage());
    }
}
