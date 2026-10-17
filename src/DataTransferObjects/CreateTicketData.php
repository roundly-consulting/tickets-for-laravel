<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\TicketCategory;

/**
 * Everything a new ticket can be created with. Only `subject` and `requester` are required;
 * the category, priority, assignee and due date fall back to the category defaults, then to
 * config.
 */
final readonly class CreateTicketData
{
    /**
     * @param  Model|GuestData  $requester  a host model, or guest fields resolved to a TicketGuest
     * @param  Model|null  $actor  who files it; null = the authenticated user (unless `$system`)
     */
    public function __construct(
        public string $subject,
        public Model|GuestData $requester,
        public ?string $description = null,
        public TicketCategory|int|null $category = null,
        public BackedEnum|string|null $priority = null,
        public ?Model $project = null,
        public ?Model $assignee = null,
        public ?CarbonInterface $dueAt = null,
        public ?string $channel = null,
        public ?Model $actor = null,
        public bool $system = false,
    ) {}
}
