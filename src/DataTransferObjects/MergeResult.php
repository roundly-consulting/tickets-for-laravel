<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * What a merge did: the source (now merged and dismissed), the target, how many comments moved
 * onto the target and how many attachments.
 */
final readonly class MergeResult
{
    public function __construct(
        public Ticket $source,
        public Ticket $target,
        public int $comments,
        public int $attachments,
    ) {}
}
