<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Resources;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Tickets\Fields\FieldSetResolver;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\AttachmentBucket;

/**
 * A ticket, as the viewer may see it.
 *
 * Everyone who may view it gets the public view: subject, description, status, priority,
 * category, project, assignee, channel, due and resolution dates, the merge target and the
 * public message count. An agent also gets the internal clock (`assigned_at`,
 * `first_responded_at`, `last_activity_at` — a note moves it, so it would leak notes), the tags,
 * the requester in full and the count of every message. The detailed view adds the custom field
 * values (agent-only fields hidden from everyone else) and the attachments.
 *
 * @property Ticket $resource
 *
 * @internal
 */
final class TicketResource extends JsonResource
{
    private bool $detailed = false;

    /**
     * Include the custom field values and the attachments.
     */
    public function detailed(): self
    {
        $this->detailed = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ticket = $this->resource;
        $viewer = Viewer::of($request);
        $agent = $viewer->isAgentOn($ticket);
        $category = $ticket->category;

        $data = [
            'reference' => $ticket->reference,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'status' => Present::state($ticket->status),
            'priority' => Present::priority($ticket),
            'category' => $category === null ? null : ['id' => $category->getKey(), 'name' => $category->name, 'slug' => $category->slug],
            'project' => Party::project($ticket->project_type === null ? null : $ticket->project),
            'requester' => Party::requester($ticket->requester, $viewer, $agent),
            'assignee' => $ticket->assignee_type === null ? null : Party::person($ticket->assignee, $viewer, $agent),
            'channel' => Present::channel($ticket->channel),
            'due_at' => Present::date($ticket->due_at),
            'resolved_at' => Present::date($ticket->resolved_at),
            'closed_at' => Present::date($ticket->closed_at),
            'merged_into' => $ticket->merged_into_id === null ? null : $ticket->mergedInto?->reference,
            'messages_count' => self::count($ticket, $agent ? 'messages_count' : 'public_messages_count'),
            'created_at' => Present::date($ticket->created_at),
            'updated_at' => Present::date($ticket->updated_at),
        ];

        if ($agent) {
            $data['assigned_at'] = Present::date($ticket->assigned_at);
            $data['first_responded_at'] = Present::date($ticket->first_responded_at);
            $data['last_activity_at'] = Present::date($ticket->last_activity_at);
            $data['tags'] = $ticket->tags->map(static fn (TicketTag $tag): array => ['name' => $tag->name, 'slug' => $tag->slug, 'color' => $tag->color])->values()->all();
        }

        if ($this->detailed) {
            $data['fields'] = self::fields($ticket, $agent);
            $data['attachments'] = AttachmentBucket::all($ticket)
                ->map(static fn (Media $media): array => (new TicketAttachmentResource($media))->toArray($request))
                ->values()
                ->all();
        }

        return $data;
    }

    /**
     * The values of the ticket's own fields — every one for an agent, the requester-visible ones
     * for everyone else.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(Ticket $ticket, bool $agent): array
    {
        $ticket->unsetRelation('attachedAttributes');
        $fields = [];

        foreach (Container::getInstance()->make(FieldSetResolver::class)->forTicket($ticket) as $field) {
            if (! $agent && ! $field->requesterVisible) {
                continue;
            }

            $fields[] = [
                'key' => $field->key,
                'label' => self::label($field),
                'type' => $field->type->value,
                'value' => $ticket->hasAttachedAttribute($field->key) ? $ticket->getAttachedAttributeValue($field->key) : null,
            ];
        }

        return $fields;
    }

    private static function label(FieldDefinition $field): ?string
    {
        return $field->label[app()->getLocale()] ?? $field->label[(string) config('app.fallback_locale')] ?? (array_values($field->label)[0] ?? null);
    }

    private static function count(Ticket $ticket, string $attribute): ?int
    {
        $count = $ticket->getAttribute($attribute);

        return is_numeric($count) ? (int) $count : null;
    }
}
