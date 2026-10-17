<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Testing;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * One operation recorded by {@see TicketsFake}: what ran, with which arguments, and what it
 * returned. Context keys: `ticket`, `actor`, `system` (handle calls); `data` (DTOs);
 * `priority`, `category`, `due_at`, `requester`, `transition` (ticket changes); `comment`
 * (conversation edits); `parent`, `reassign_to` (categories); `guest`, `user` (guests).
 */
final readonly class RecordedTicketOperation
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public TicketOperation $operation,
        public array $context,
        public mixed $result,
    ) {}

    /**
     * The ticket the operation ran on (or created).
     */
    public function ticket(): ?Ticket
    {
        $ticket = $this->context['ticket'] ?? $this->result;

        return $ticket instanceof Ticket ? $ticket : null;
    }

    /**
     * Whether the operation concerns `$ticket` — a model, or a reference.
     */
    public function concerns(Ticket|string|null $ticket): bool
    {
        if ($ticket === null) {
            return true;
        }

        $recorded = $this->ticket();

        if ($recorded === null) {
            return false;
        }

        return is_string($ticket) ? $recorded->reference === $ticket : $recorded->is($ticket);
    }

    public function model(string $key): ?Model
    {
        $value = $this->context[$key] ?? null;

        return $value instanceof Model ? $value : null;
    }
}
