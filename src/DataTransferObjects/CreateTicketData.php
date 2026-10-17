<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketTag;

/**
 * Everything a new ticket can be created with. Only `subject` and `requester` are required;
 * the category, priority, assignee and due date fall back to the category defaults, then to
 * config; custom fields fall back to their own defaults.
 */
final readonly class CreateTicketData
{
    /**
     * @param  Model|GuestData  $requester  a host model, or guest fields resolved to a TicketGuest
     * @param  Model|null  $actor  who files it; null = the authenticated user (unless `$system`)
     * @param  array<string, mixed>  $fields  custom field values, by key
     * @param  list<string|TicketTag>  $tags  tag names or models
     * @param  list<Model>  $watchers  who follows it from the start
     * @param  list<UploadedFile|string>  $attachments  uploads or local paths
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
        public array $fields = [],
        public array $tags = [],
        public array $watchers = [],
        public array $attachments = [],
    ) {}
}
