<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * `POST /tickets/bulk` — one action on up to `tickets.routes.bulk.max` tickets:
 *
 *  - `assign` with `assignee: {type, id}` or `claim: true`;
 *  - `unassign`;
 *  - `transition` with `transition`, and the optional `reason` and `payload`;
 *  - `priority` with `priority`;
 *  - `tags` with `tags.attach` and/or `tags.detach` (names).
 *
 * @internal
 */
final class BulkRequest extends TicketsRequest
{
    public const array ACTIONS = ['assign', 'unassign', 'transition', 'priority', 'tags'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'tickets' => ['required', 'array', 'min:1', 'max:'.TicketsConfig::bulkMax()],
            'tickets.*' => ['required', 'string', 'max:32', 'distinct'],
            ...self::morphRules('assignee'),
            'claim' => ['nullable', 'boolean'],
            'transition' => ['required_if:action,transition', 'nullable', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'reason' => ['nullable', 'string', 'max:5000'],
            'payload' => ['nullable', 'array'],
            'priority' => ['required_if:action,priority', 'nullable', 'string', 'max:32'],
            'tags' => ['required_if:action,tags', 'nullable', 'array:attach,detach'],
            'tags.attach' => ['nullable', 'array', 'max:50'],
            'tags.attach.*' => ['string', 'max:64'],
            'tags.detach' => ['nullable', 'array', 'max:50'],
            'tags.detach.*' => ['string', 'max:64'],
        ];
    }

    /**
     * An assignment names an assignee or claims.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            static function (Validator $validator): void {
                $data = $validator->getData();
                $claim = filter_var($data['claim'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if (($data['action'] ?? null) === 'assign' && ($data['assignee'] ?? null) === null && ! $claim) {
                    $validator->errors()->add('assignee', (string) __('validation.required', ['attribute' => $validator->getDisplayableAttribute('assignee')]));
                }
            },
        ];
    }

    public function action(): string
    {
        return (string) $this->stringValue('action');
    }

    /**
     * @return list<string>
     */
    public function references(): array
    {
        return $this->strings('tickets');
    }

    public function claims(): bool
    {
        return $this->flag('claim') === true;
    }

    public function assigneeReference(): ?MorphReference
    {
        return $this->morph('assignee');
    }

    public function transition(): string
    {
        return (string) $this->stringValue('transition');
    }

    public function reason(): ?string
    {
        return $this->stringValue('reason');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach ($this->arrayValue('payload') as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }

    public function priority(): string
    {
        return (string) $this->stringValue('priority');
    }

    /**
     * @return list<string>
     */
    public function tagsToAttach(): array
    {
        return $this->strings('tags.attach');
    }

    /**
     * @return list<string>
     */
    public function tagsToDetach(): array
    {
        return $this->strings('tags.detach');
    }
}
