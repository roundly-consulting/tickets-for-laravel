<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use Illuminate\Http\UploadedFile;

/**
 * A message of the conversation: its body, channel and files (checked against the comment
 * attachments bucket).
 *
 * @internal
 */
abstract class ConversationRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:65535'],
            'channel' => ['nullable', 'string', 'max:32'],
            'attachments' => ['nullable', 'array', 'max:'.Attachments::MAX_FILES],
            'attachments.*' => Attachments::commentRules(),
        ];
    }

    public function body(): string
    {
        return (string) $this->stringValue('body');
    }

    public function channel(): ?string
    {
        return $this->stringValue('channel');
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        return Attachments::uploads($this->arrayValue('attachments'));
    }
}
