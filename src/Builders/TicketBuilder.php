<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Builders;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Tickets\DataTransferObjects\CreateTicketData;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\Messages;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::make()` — fluent sugar over {@see CreateTicketData}: `create()` builds the DTO
 * and calls `Tickets::create()`, so both forms run the same code.
 */
final class TicketBuilder
{
    private string $subject = '';

    private ?string $description = null;

    private Model|GuestData|null $requester = null;

    private TicketCategory|int|null $category = null;

    private BackedEnum|string|null $priority = null;

    private ?Model $project = null;

    private ?Model $assignee = null;

    private ?CarbonInterface $dueAt = null;

    private ?string $channel = null;

    private ?Model $actor = null;

    private bool $system = false;

    /** @var array<string, mixed> */
    private array $fields = [];

    /** @var list<string|TicketTag> */
    private array $tags = [];

    /** @var list<Model> */
    private array $watchers = [];

    /** @var list<UploadedFile|string> */
    private array $attachments = [];

    /** @var array<string, mixed> */
    private array $meta = [];

    public function __construct(
        private readonly TicketsManager $manager,
    ) {}

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Who the ticket is for — a host model (a user, a contact, …).
     */
    public function requester(Model $requester): self
    {
        $this->requester = $requester;

        return $this;
    }

    /**
     * A guest requester, by the fields declared in `tickets.guests.fields`.
     *
     * @param  GuestData|array<string, scalar|null>  $fields
     */
    public function guest(GuestData|array $fields): self
    {
        $this->requester = $fields instanceof GuestData ? $fields : new GuestData($fields);

        return $this;
    }

    public function category(TicketCategory|int|null $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function priority(BackedEnum|string|null $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function project(?Model $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function assignee(?Model $assignee): self
    {
        $this->assignee = $assignee;

        return $this;
    }

    public function due(?CarbonInterface $at): self
    {
        $this->dueAt = $at;

        return $this;
    }

    public function channel(?string $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    /**
     * Custom field values, by key — merged over earlier calls.
     *
     * @param  array<string, mixed>  $values
     */
    public function fields(array $values): self
    {
        $this->fields = [...$this->fields, ...$values];

        return $this;
    }

    /**
     * Tags, by name or model — an unknown name is created (`tickets.tags.free_form`).
     *
     * @param  list<string|TicketTag>|string|TicketTag  $tags
     */
    public function tags(array|string|TicketTag $tags): self
    {
        $this->tags = [...$this->tags, ...(is_array($tags) ? $tags : [$tags])];

        return $this;
    }

    /**
     * Who follows the ticket from the start (the requester and the assignee watch on their own).
     */
    public function watchers(Model ...$watchers): self
    {
        $this->watchers = [...$this->watchers, ...array_values($watchers)];

        return $this;
    }

    /**
     * Attach files — uploads or local paths — to the ticket.
     */
    public function attach(UploadedFile|string ...$files): self
    {
        $this->attachments = [...$this->attachments, ...array_values($files)];

        return $this;
    }

    /**
     * Host-owned metadata — a CRM id, a source URL. Top-level keys merge with earlier calls;
     * read it back with `$ticket->meta('crm_id')`. Never shown in the API.
     *
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): self
    {
        $this->meta = array_replace($this->meta, $meta);

        return $this;
    }

    /**
     * Who files it (default: the authenticated user, with `tickets.actor.from_auth`).
     */
    public function by(?Model $actor): self
    {
        $this->actor = $actor;
        $this->system = false;

        return $this;
    }

    /**
     * File it as the system: no actor, no fallback to the authenticated user.
     */
    public function asSystem(): self
    {
        $this->actor = null;
        $this->system = true;

        return $this;
    }

    /**
     * The DTO this builder describes.
     */
    public function data(): CreateTicketData
    {
        if ($this->requester === null) {
            throw ValidationException::withMessages(['requester' => [Messages::get('ticket.requester_required')]]);
        }

        return new CreateTicketData(
            subject: $this->subject,
            requester: $this->requester,
            description: $this->description,
            category: $this->category,
            priority: $this->priority,
            project: $this->project,
            assignee: $this->assignee,
            dueAt: $this->dueAt,
            channel: $this->channel,
            actor: $this->actor,
            system: $this->system,
            fields: $this->fields,
            tags: $this->tags,
            watchers: $this->watchers,
            attachments: $this->attachments,
            meta: $this->meta,
        );
    }

    public function create(): Ticket
    {
        return $this->manager->create($this->data());
    }
}
