<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Handles;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Tags\AttachTagsAction;
use RoundlyConsulting\Tickets\Actions\Tags\DetachTagsAction;
use RoundlyConsulting\Tickets\Actions\Tags\SyncTagsAction;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::for($ticket)->tags()` — the ticket's labels, by name (`printer`) or model.
 * Mutators return this accessor, so calls chain: `->tags()->attach(['a'])->detach(['b'])`.
 */
final readonly class TicketTags
{
    public function __construct(
        private TicketsManager $manager,
        private Ticket $ticket,
        private ?Model $actor = null,
        private bool $system = false,
    ) {}

    /**
     * @param  list<string|TicketTag>|string|TicketTag  $tags
     */
    public function attach(array|string|TicketTag $tags): static
    {
        $tags = self::list($tags);

        $this->manager->perform(
            TicketOperation::AttachTags,
            AttachTagsAction::class,
            fn (AttachTagsAction $action): Ticket => $action->execute($this->ticket, $tags, $this->actor, $this->system),
            $this->context($tags),
        );

        return $this;
    }

    /**
     * @param  list<string|TicketTag>|string|TicketTag  $tags
     */
    public function detach(array|string|TicketTag $tags): static
    {
        $tags = self::list($tags);

        $this->manager->perform(
            TicketOperation::DetachTags,
            DetachTagsAction::class,
            fn (DetachTagsAction $action): Ticket => $action->execute($this->ticket, $tags, $this->actor, $this->system),
            $this->context($tags),
        );

        return $this;
    }

    /**
     * @param  list<string|TicketTag>  $tags
     */
    public function sync(array $tags): static
    {
        $this->manager->perform(
            TicketOperation::SyncTags,
            SyncTagsAction::class,
            fn (SyncTagsAction $action): Ticket => $action->execute($this->ticket, $tags, $this->actor, $this->system),
            $this->context($tags),
        );

        return $this;
    }

    /**
     * @return Collection<int, TicketTag>
     */
    public function all(): Collection
    {
        return $this->ticket->tags()->orderBy('name')->get();
    }

    public function ticket(): Ticket
    {
        return $this->ticket;
    }

    /**
     * @param  list<string|TicketTag>|string|TicketTag  $tags
     * @return list<string|TicketTag>
     */
    private static function list(array|string|TicketTag $tags): array
    {
        return is_array($tags) ? $tags : [$tags];
    }

    /**
     * @param  list<string|TicketTag>  $tags
     * @return array<string, mixed>
     */
    private function context(array $tags): array
    {
        return [
            'ticket' => $this->ticket,
            'actor' => $this->actor,
            'system' => $this->system,
            'tags' => array_map(static fn (string|TicketTag $tag): string => $tag instanceof TicketTag ? $tag->slug : $tag, $tags),
        ];
    }
}
