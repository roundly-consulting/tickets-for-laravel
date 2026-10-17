<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

/**
 * `PUT /tickets/{ticket}/fields` — custom field values by key; `null` clears one. Each value is
 * validated against its own field by the action.
 *
 * @internal
 */
final class FieldValuesRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'fields' => ['present', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $values = [];

        foreach ($this->arrayValue('fields') as $key => $value) {
            $values[(string) $key] = $value;
        }

        return $values;
    }
}
