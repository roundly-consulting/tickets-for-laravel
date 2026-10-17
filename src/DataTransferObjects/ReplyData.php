<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use Illuminate\Http\UploadedFile;

/**
 * A message posted on a ticket's conversation — a public reply or an internal note.
 */
final readonly class ReplyData
{
    /**
     * @param  list<UploadedFile|string>  $attachments  uploads or local paths, stored on the comment
     * @param  string|null  $channel  where the message came in (`tickets.channels`); null = the default channel
     * @param  array<string, mixed>  $meta  the host's own comment meta; the `tickets` key is the package's
     */
    public function __construct(
        public string $body,
        public array $attachments = [],
        public ?string $channel = null,
        public array $meta = [],
    ) {}
}
