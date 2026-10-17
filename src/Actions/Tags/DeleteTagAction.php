<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tags;

use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Soft-deletes a tag under the `lock:tags` advisory row. It drops off every ticket's tag list;
 * its slug stays taken, as sluggable keeps trashed slugs.
 */
final readonly class DeleteTagAction
{
    public function execute(TicketTag $tag): TicketTag
    {
        return TicketTransaction::run($tag, static function () use ($tag): TicketTag {
            AdvisoryLock::acquire(AdvisoryLock::TAGS);

            $tag->delete();

            return $tag;
        });
    }
}
