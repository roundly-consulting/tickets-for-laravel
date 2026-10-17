<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Builders;

use BackedEnum;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\DataTransferObjects\CategoryData;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\CategoryInput;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::categories()->make($name)` — fluent sugar over {@see CategoryData}. Setters
 * validate what they can at once (the name, the default priority, the ISO-8601 due offset,
 * the assignee contract); `create()` runs the full action, so the DTO form and this one
 * produce the same row.
 */
final class CategoryBuilder
{
    /** @var array<string, string>|string */
    private array|string $name;

    /** @var array<string, string>|string|null */
    private array|string|null $description = null;

    private TicketCategory|int|null $parent = null;

    private ?Model $project = null;

    private int $position = 0;

    private bool $active = true;

    private ?string $defaultPriority = null;

    private ?Model $defaultAssignee = null;

    private ?string $defaultDueIn = null;

    /**
     * @param  array<string, string>|string  $name
     */
    public function __construct(
        private readonly TicketsManager $manager,
        array|string $name,
    ) {
        $this->name = CategoryInput::name($name);
    }

    /**
     * @param  array<string, string>|string|null  $description
     */
    public function description(array|string|null $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function project(?Model $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function under(TicketCategory|int|null $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    public function position(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function active(bool $active = true): self
    {
        $this->active = $active;

        return $this;
    }

    public function inactive(): self
    {
        return $this->active(false);
    }

    /**
     * What a new ticket in this category (or a descendant without its own) inherits.
     *
     * @param  CarbonInterval|string|null  $dueIn  an ISO-8601 duration (`P1D`, `PT4H`) or a positive
     *                                             interval (`CarbonInterval::hours(4)`), stored as the duration
     */
    public function defaults(BackedEnum|string|null $priority = null, ?Model $assignee = null, CarbonInterval|string|null $dueIn = null): self
    {
        $this->defaultPriority = CategoryInput::priority($priority);
        CategoryInput::assignee($assignee);
        $this->defaultAssignee = $assignee;
        $this->defaultDueIn = CategoryInput::dueIn($dueIn);

        return $this;
    }

    public function data(): CategoryData
    {
        return new CategoryData(
            name: $this->name,
            description: $this->description,
            parent: $this->parent,
            project: $this->project,
            position: $this->position,
            active: $this->active,
            defaultPriority: $this->defaultPriority,
            defaultAssignee: $this->defaultAssignee,
            defaultDueIn: $this->defaultDueIn,
        );
    }

    public function create(): TicketCategory
    {
        return $this->manager->categories()->create($this->data());
    }
}
