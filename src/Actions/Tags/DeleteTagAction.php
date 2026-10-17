<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tags;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\ChangeTicketTagsAction;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Soft-deletes a tag under the `lock:tags` advisory row. It drops off every ticket's tag list —
 * each live ticket the usual way, with an `untagged` activity entry and `TicketUntagged`; a
 * deleted ticket just loses the link. So the tag comes back empty when its name is created
 * again: its slug stays taken (sluggable keeps trashed slugs), its old tickets are not re-tagged.
 */
final readonly class DeleteTagAction
{
    public function __construct(
        private ChangeTicketTagsAction $tags,
    ) {}

    public function execute(TicketTag $tag, ?Model $actor = null): TicketTag
    {
        $actor = ActorResolver::resolve($actor);

        return TicketTransaction::run($tag, function () use ($tag, $actor): TicketTag {
            AdvisoryLock::acquire(AdvisoryLock::TAGS);

            $tickets = $tag->tickets()->orderBy((new (TicketModels::ticket()))->getQualifiedKeyName())->get();

            foreach ($tickets as $ticket) {
                $this->tags->execute($ticket, [], [(int) $tag->getKey() => $tag], $actor, system: $actor === null);
            }

            // What is left: the links of deleted tickets.
            $tag->tickets()->detach();
            $tag->delete();

            return $tag;
        });
    }
}
