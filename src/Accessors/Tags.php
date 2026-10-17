<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Accessors;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Tags\CreateTagAction;
use RoundlyConsulting\Tickets\Actions\Tags\DeleteTagAction;
use RoundlyConsulting\Tickets\Actions\Tags\UpdateTagAction;
use RoundlyConsulting\Tickets\DataTransferObjects\TagData;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateTagData;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::tags()` — the labels tickets carry, global or per project. Putting them on a ticket
 * is `Tickets::for($ticket)->tags()`.
 */
final readonly class Tags
{
    public function __construct(
        private TicketsManager $manager,
    ) {}

    /**
     * Create a tag; its scope must not hold the name yet (a deleted tag of the name comes back).
     */
    public function create(TagData|string $name, ?string $color = null, ?Model $project = null): TicketTag
    {
        $data = $name instanceof TagData ? $name : new TagData($name, $color, $project);

        return $this->manager->perform(
            TicketOperation::CreateTag,
            CreateTagAction::class,
            static fn (CreateTagAction $action): TicketTag => $action->execute($data),
            ['data' => $data],
        );
    }

    /**
     * The tag of this name in the scope, created when there is none.
     */
    public function findOrCreate(string $name, ?Model $project = null): TicketTag
    {
        $data = new TagData($name, project: $project);

        return $this->manager->perform(
            TicketOperation::CreateTag,
            CreateTagAction::class,
            static fn (CreateTagAction $action): TicketTag => $action->execute($data, orExisting: true),
            ['data' => $data],
        );
    }

    public function rename(TicketTag $tag, string $name): TicketTag
    {
        return $this->update($tag, new UpdateTagData(name: $name));
    }

    public function recolor(TicketTag $tag, ?string $color): TicketTag
    {
        return $this->update($tag, new UpdateTagData(color: $color));
    }

    public function update(TicketTag $tag, UpdateTagData $data): TicketTag
    {
        return $this->manager->perform(
            TicketOperation::UpdateTag,
            UpdateTagAction::class,
            static fn (UpdateTagAction $action): TicketTag => $action->execute($tag, $data),
            ['tag' => $tag, 'data' => $data],
        );
    }

    public function delete(TicketTag $tag): void
    {
        $this->manager->perform(
            TicketOperation::DeleteTag,
            DeleteTagAction::class,
            static fn (DeleteTagAction $action): TicketTag => $action->execute($tag),
            ['tag' => $tag],
        );
    }

    /**
     * The tags a ticket of the project may carry — the global ones and the project's own — by
     * name. Without a project, the global tags.
     *
     * @return Collection<int, TicketTag>
     */
    public function all(?Model $project = null): Collection
    {
        [$type, $id] = TicketInput::morph($project);

        return TicketModels::tag()::query()
            ->where(static fn (Builder $scope): Builder => $type === null
                ? $scope->whereNull('project_type')
                : $scope->whereNull('project_type')->orWhere(static fn (Builder $own): Builder => $own->where('project_type', $type)->where('project_id', $id)))
            ->orderBy('name')
            ->get();
    }
}
