<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Contracts;

/**
 * Implemented by the backed enum of ticket priorities. The weight orders tickets: a higher
 * weight sorts first under `orderByPriority('desc')`.
 */
interface Priority
{
    public function weight(): int;
}
