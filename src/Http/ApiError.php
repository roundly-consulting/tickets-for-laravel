<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http;

use Illuminate\Http\JsonResponse;

/**
 * One API error: the HTTP status, a stable machine `code` and a translated `message`, plus any
 * detail the code carries (`denials` of a refused transition). Rendered as
 * `{message, code, ...detail}`.
 *
 * @internal
 */
final readonly class ApiError
{
    /**
     * @param  array<string, mixed>  $detail  extra keys of the JSON body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public string $code,
        public string $message,
        public array $detail = [],
        public array $headers = [],
    ) {}

    public function toResponse(): JsonResponse
    {
        return new JsonResponse(['message' => $this->message, 'code' => $this->code, ...$this->detail], $this->status, $this->headers);
    }
}
