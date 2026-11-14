<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Handles;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Fields\ForgetFieldValuesAction;
use RoundlyConsulting\Tickets\Actions\Fields\SetFieldValuesAction;
use RoundlyConsulting\Tickets\Actions\Internal\WriteFieldValuesAction;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Fields\FieldSetResolver;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::for($ticket)->fields()` — the ticket's custom field values, limited to the fields
 * that apply to it (global, its project's, its category's and its ancestors'). Mutators return
 * this accessor, so calls chain.
 */
final readonly class TicketFields
{
    public function __construct(
        private TicketsManager $manager,
        private Ticket $ticket,
        private ?Model $actor = null,
        private bool $system = false,
    ) {}

    public function set(string $key, mixed $value): static
    {
        return $this->setMany([$key => $value]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): static
    {
        $this->manager->perform(
            TicketOperation::SetFields,
            SetFieldValuesAction::class,
            fn (SetFieldValuesAction $action): Ticket => $action->execute($this->ticket, $values, $this->actor, $this->system),
            ['ticket' => $this->ticket, 'actor' => $this->actor, 'system' => $this->system, 'keys' => array_keys($values)],
        );

        return $this;
    }

    /**
     * @param  list<string>|string  $keys
     */
    public function forget(array|string $keys): static
    {
        $keys = is_array($keys) ? $keys : [$keys];

        $this->manager->perform(
            TicketOperation::ForgetFields,
            ForgetFieldValuesAction::class,
            fn (ForgetFieldValuesAction $action): Ticket => $action->execute($this->ticket, $keys, $this->actor, $this->system),
            ['ticket' => $this->ticket, 'actor' => $this->actor, 'system' => $this->system, 'keys' => $keys],
        );

        return $this;
    }

    /**
     * Refuse the ticket while it misses a value of a required field — a `ValidationException`
     * keyed `fields.<key>`, worded with the field's label — whatever its state defers. Owed by
     * `$actor` (else the handle's `by()` actor; else the system): someone who is not an agent
     * for the ticket owes only the `requester_editable` fields, an agent or the system every one.
     */
    public function assertRequired(?Model $actor = null): static
    {
        Container::getInstance()->make(WriteFieldValuesAction::class)->assertRequiredFilled($this->ticket, $this->owedBy($actor));

        return $this;
    }

    /**
     * The keys of the required fields the ticket holds no value for, in field order — of those
     * owed as {@see self::assertRequired()} reads them. `[]` when nothing is missing.
     *
     * @return list<string>
     */
    public function missing(?Model $actor = null): array
    {
        return array_keys(Container::getInstance()->make(WriteFieldValuesAction::class)->missingRequired($this->ticket, $this->owedBy($actor)));
    }

    /**
     * The value of one of the ticket's fields (its default when unset), or null for a key no
     * field of the ticket has.
     */
    public function get(string $key): mixed
    {
        return isset($this->definitions()[$key]) ? $this->ticket->getAttachedAttributeValue($key) : null;
    }

    /**
     * Every value the ticket holds for its own fields, in field order — values of a deleted
     * field or of a category it left stay hidden.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->ticket->unsetRelation('attachedAttributes');
        $values = [];

        foreach (array_keys($this->definitions()) as $key) {
            if ($this->ticket->hasAttachedAttribute($key)) {
                $values[$key] = $this->ticket->getAttachedAttributeValue($key);
            }
        }

        return $values;
    }

    /**
     * The fields that apply to the ticket, keyed by key, in order.
     *
     * @return array<string, FieldDefinition>
     */
    public function definitions(): array
    {
        return Container::getInstance()->make(FieldSetResolver::class)->forTicket($this->ticket);
    }

    public function ticket(): Ticket
    {
        return $this->ticket;
    }

    private function owedBy(?Model $actor): ?Model
    {
        return $actor ?? ($this->system ? null : $this->actor);
    }
}
