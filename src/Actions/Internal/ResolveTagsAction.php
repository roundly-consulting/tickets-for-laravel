<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Internal;

use RoundlyConsulting\Tickets\Actions\Tags\CreateTagAction;
use RoundlyConsulting\Tickets\DataTransferObjects\TagData;
use RoundlyConsulting\Tickets\Exceptions\InvalidTagException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\TagInput;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * Turns tag names and models into the live tags a ticket may carry: global, or of the
 * ticket's project. A name is looked up in the ticket's project first, then among the global
 * tags; an unknown name is created in the ticket's scope when `$create` and
 * `tickets.tags.free_form` allow it, refused otherwise — or, without `$create`, skipped.
 * Names take the `lock:tags` advisory row first, before any ticket row.
 *
 * @internal
 */
final readonly class ResolveTagsAction
{
    public function __construct(
        private CreateTagAction $create,
    ) {}

    /**
     * @param  list<string|TicketTag>  $tags
     * @return array<int, TicketTag> keyed by tag id
     */
    public function execute(Ticket $ticket, array $tags, bool $create = true): array
    {
        if (array_filter($tags, is_string(...)) !== []) {
            AdvisoryLock::acquire(AdvisoryLock::TAGS);
        }

        $resolved = [];

        foreach ($tags as $tag) {
            $model = $tag instanceof TicketTag ? $this->live($tag, $ticket) : $this->named($tag, $ticket, $create);

            if ($model !== null) {
                $resolved[(int) $model->getKey()] = $model;
            }
        }

        return $resolved;
    }

    private function live(TicketTag $tag, Ticket $ticket): TicketTag
    {
        if ($tag->trashed() || ! TagInput::fits($tag, $ticket)) {
            throw InvalidTagException::projectMismatch($tag->name);
        }

        return $tag;
    }

    private function named(string $name, Ticket $ticket, bool $create): ?TicketTag
    {
        $name = TagInput::name($name);
        $found = ($ticket->project_type === null ? null : TagInput::find($name, $ticket->project_type, $ticket->project_id))
            ?? TagInput::find($name, null, null);

        if ($found !== null && ! $found->trashed()) {
            return $found;
        }

        if (! $create) {
            return null;
        }

        if (! TicketsConfig::freeFormTags()) {
            throw InvalidTagException::unknown($name);
        }

        return $this->create->execute(new TagData($name, project: $ticket->project), orExisting: true);
    }
}
