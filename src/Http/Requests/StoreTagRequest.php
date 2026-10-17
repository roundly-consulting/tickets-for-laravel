<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;
use RoundlyConsulting\Tickets\DataTransferObjects\TagData;

/**
 * `POST /ticket-tags` — `{name, color?, project?}`.
 *
 * @internal
 */
final class StoreTagRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:7'],
            ...self::morphRules('project'),
        ];
    }

    public function projectReference(): ?MorphReference
    {
        return $this->morph('project');
    }

    public function toData(?Model $project): TagData
    {
        return new TagData((string) $this->stringValue('name'), $this->stringValue('color'), $project);
    }
}
