<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * `POST /tickets/{ticket}/attachments` — one file, checked against the ticket's bucket.
 *
 * @internal
 */
final class AttachmentRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', ...Attachments::ticketRules()],
        ];
    }

    public function upload(): UploadedFile
    {
        return Attachments::uploads([$this->validated('file')])[0]
            ?? throw ValidationException::withMessages(['file' => [(string) __('validation.required', ['attribute' => 'file'])]]);
    }
}
