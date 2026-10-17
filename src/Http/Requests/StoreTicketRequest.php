<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;
use RoundlyConsulting\Tickets\Support\Messages;

/**
 * `POST /tickets`. Anyone filing for themselves sends the subject, description, category,
 * priority, project, channel, their editable custom fields and attachments; an agent may also
 * file for someone else (`requester` or `guest`), assign it, set its due date, tags and watchers.
 *
 * @internal
 */
final class StoreTicketRequest extends TicketsRequest
{
    /** The inputs only an agent may send. */
    public const array AGENT_ONLY = ['requester', 'guest', 'assignee', 'due_at', 'tags', 'watchers'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            ...self::morphRules('requester'),
            'guest' => ['nullable', 'array', 'prohibits:requester'],
            'guest.*' => ['nullable', self::scalar()],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'priority' => ['nullable', 'string', 'max:32'],
            ...self::morphRules('project'),
            ...self::morphRules('assignee'),
            'due_at' => ['nullable', 'date', 'after:now'],
            'channel' => ['nullable', 'string', 'max:32'],
            'fields' => ['nullable', 'array'],
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:64'],
            'watchers' => ['nullable', 'array', 'max:50'],
            'watchers.*' => ['array:type,id'],
            'watchers.*.type' => ['required', 'string', 'max:255'],
            'watchers.*.id' => ['required', self::key()],
            'attachments' => ['nullable', 'array', 'max:'.Attachments::MAX_FILES],
            'attachments.*' => Attachments::ticketRules(),
        ];
    }

    /**
     * The agent-only inputs this request sends.
     *
     * @return list<string>
     */
    public function agentOnlyInputs(): array
    {
        return array_values(array_filter(self::AGENT_ONLY, fn (string $key): bool => $this->validated($key) !== null));
    }

    public function subject(): string
    {
        return (string) $this->stringValue('subject');
    }

    public function description(): ?string
    {
        return $this->stringValue('description');
    }

    public function requesterReference(): ?MorphReference
    {
        return $this->morph('requester');
    }

    public function guest(): ?GuestData
    {
        $fields = $this->validated('guest');

        if (! is_array($fields)) {
            return null;
        }

        $values = [];

        foreach ($fields as $name => $value) {
            $values[(string) $name] = is_scalar($value) ? $value : null;
        }

        return new GuestData($values);
    }

    public function categoryId(): ?int
    {
        return $this->integerValue('category_id');
    }

    public function priority(): ?string
    {
        return $this->stringValue('priority');
    }

    public function projectReference(): ?MorphReference
    {
        return $this->morph('project');
    }

    public function assigneeReference(): ?MorphReference
    {
        return $this->morph('assignee');
    }

    public function dueAt(): ?CarbonImmutable
    {
        $value = $this->stringValue('due_at');

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    public function channel(): ?string
    {
        return $this->stringValue('channel');
    }

    /**
     * @return array<string, mixed>
     */
    public function fieldValues(): array
    {
        $values = [];

        foreach ($this->arrayValue('fields') as $key => $value) {
            $values[(string) $key] = $value;
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->strings('tags');
    }

    /**
     * @return list<MorphReference>
     */
    public function watcherReferences(): array
    {
        $references = [];

        foreach ($this->arrayValue('watchers') as $watcher) {
            if (is_array($watcher) && is_string($watcher['type'] ?? null) && (is_int($watcher['id'] ?? null) || is_string($watcher['id'] ?? null))) {
                $references[] = new MorphReference($watcher['type'], $watcher['id']);
            }
        }

        return $references;
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        return Attachments::uploads($this->arrayValue('attachments'));
    }

    private static function scalar(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && ! is_scalar($value)) {
                $fail(Messages::get('http.invalid_value', ['attribute' => $attribute]));
            }
        };
    }
}
