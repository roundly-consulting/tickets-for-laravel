<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Attachments;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketAttachmentAdded;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Attaches a file to the ticket — an upload or a local path — through media-library, into the
 * ticket's private attachments bucket (its mime types and size cap apply; the bucket refuses
 * anything else). The requester sees ticket attachments; agent-only files belong on an
 * internal note.
 */
final readonly class AddAttachmentAction
{
    public function __construct(
        private MediaLibraryManager $media,
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, UploadedFile|string $file, ?Model $actor = null, bool $system = false): Media
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $file, $actor, $system): Media {
            TicketLocker::lock($ticket);

            $media = $this->media->for($ticket)->add($file)->toBucket(TicketsConfig::attachmentsBucket());

            $this->record->execute($ticket, ActivityType::AttachmentAdded, $actor, ['media' => $media->uuid, 'name' => $media->file_name], $system);
            $this->events->dispatch(new TicketAttachmentAdded($ticket, $media, $actor));

            return $media;
        });
    }
}
