<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Handles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Tickets\Actions\Attachments\AddAttachmentAction;
use RoundlyConsulting\Tickets\Actions\Attachments\RemoveAttachmentAction;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\AttachmentBucket;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::for($ticket)->attachments()` — files on the ticket itself, in its private bucket.
 * `add()` returns the stored media; `remove()` returns this accessor.
 */
final readonly class TicketAttachments
{
    public function __construct(
        private TicketsManager $manager,
        private Ticket $ticket,
        private ?Model $actor = null,
        private bool $system = false,
    ) {}

    public function add(UploadedFile|string $file): Media
    {
        return $this->manager->perform(
            TicketOperation::AddAttachment,
            AddAttachmentAction::class,
            fn (AddAttachmentAction $action): Media => $action->execute($this->ticket, $file, $this->actor, $this->system),
            ['ticket' => $this->ticket, 'actor' => $this->actor, 'system' => $this->system],
        );
    }

    /**
     * Remove one of this ticket's attachments, by model or uuid — another owner's is refused.
     */
    public function remove(Media|string $media): static
    {
        $this->manager->perform(
            TicketOperation::RemoveAttachment,
            RemoveAttachmentAction::class,
            fn (RemoveAttachmentAction $action): Ticket => $action->execute($this->ticket, $media, $this->actor, $this->system),
            ['ticket' => $this->ticket, 'actor' => $this->actor, 'system' => $this->system, 'media' => $media instanceof Media ? $media->uuid : $media],
        );

        return $this;
    }

    /**
     * @return Collection<int, Media>
     */
    public function all(): Collection
    {
        return AttachmentBucket::all($this->ticket);
    }

    public function ticket(): Ticket
    {
        return $this->ticket;
    }
}
