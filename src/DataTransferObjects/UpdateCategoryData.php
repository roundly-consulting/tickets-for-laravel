<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Support\Optional;

/**
 * A partial category update. Moving it in the tree is `Tickets::categories()->move()`; its
 * project is fixed at creation.
 */
final readonly class UpdateCategoryData
{
    /**
     * @param  array<string, string>|string|Optional  $name
     * @param  array<string, string>|string|null|Optional  $description
     */
    public function __construct(
        public array|string|Optional $name = new Optional,
        public array|string|null|Optional $description = new Optional,
        public int|Optional $position = new Optional,
        public bool|Optional $active = new Optional,
        public BackedEnum|string|null|Optional $defaultPriority = new Optional,
        public Model|null|Optional $defaultAssignee = new Optional,
        public string|null|Optional $defaultDueIn = new Optional,
    ) {}
}
