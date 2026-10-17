<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Attachments;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketAttachmentRemoved;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\AttachmentBucket;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Deletes one of the ticket's attachments — row and files. A media of another owner, or of
 * another bucket, is refused as not found.
 */
final readonly class RemoveAttachmentAction
{
    public function __construct(
        private MediaLibraryManager $media,
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, Media|string $media, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $media, $actor, $system): Ticket {
            TicketLocker::lock($ticket);

            $owned = AttachmentBucket::owned($ticket, $media);

            $this->media->delete($owned);

            $this->record->execute($ticket, ActivityType::AttachmentRemoved, $actor, ['media' => $owned->uuid, 'name' => $owned->file_name], $system);
            $this->events->dispatch(new TicketAttachmentRemoved($ticket, $owned, $actor));

            return $ticket;
        });
    }
}
