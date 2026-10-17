<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use Illuminate\Container\Container;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\Comments\Support\CommentModel;
use RoundlyConsulting\Comments\Support\CommentsConfig;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * The upload rules of the ticket's attachments bucket and of a message's comment bucket — the
 * buckets' own mime types and size caps, through media-library.
 *
 * @internal
 */
final class Attachments
{
    /** At most this many files per request. */
    public const int MAX_FILES = 10;

    /**
     * @return list<string>
     */
    public static function ticketRules(): array
    {
        return Container::getInstance()->make(MediaLibraryManager::class)->rulesFor(TicketModels::ticket(), TicketsConfig::attachmentsBucket());
    }

    /**
     * @return list<string>
     */
    public static function commentRules(): array
    {
        return Container::getInstance()->make(MediaLibraryManager::class)->rulesFor(CommentModel::class(), CommentsConfig::attachmentsBucket());
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return list<UploadedFile>
     */
    public static function uploads(array $files): array
    {
        return array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));
    }
}
