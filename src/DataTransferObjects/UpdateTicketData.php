<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\Optional;

/**
 * A partial ticket update. A property left as {@see Optional} is not touched; `null` clears
 * a nullable value (the description, the category, the due date).
 */
final readonly class UpdateTicketData
{
    public function __construct(
        public string|Optional $subject = new Optional,
        public string|null|Optional $description = new Optional,
        public BackedEnum|string|Optional $priority = new Optional,
        public TicketCategory|int|null|Optional $category = new Optional,
        public CarbonInterface|null|Optional $dueAt = new Optional,
        public Model|GuestData|Optional $requester = new Optional,
    ) {}
}
