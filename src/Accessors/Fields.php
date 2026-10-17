<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Accessors;

use RoundlyConsulting\Tickets\Actions\Fields\DefineFieldAction;
use RoundlyConsulting\Tickets\Builders\FieldBuilder;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldData;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::fields()` — custom field definitions.
 */
final readonly class Fields
{
    public function __construct(
        private TicketsManager $manager,
    ) {}

    /**
     * A fluent builder for a new field; the key is validated at once.
     */
    public function make(string $key, FieldType $type): FieldBuilder
    {
        return new FieldBuilder($this->manager, $key, $type);
    }

    public function define(FieldData $data): TicketField
    {
        return $this->manager->perform(
            TicketOperation::DefineField,
            DefineFieldAction::class,
            static fn (DefineFieldAction $action): TicketField => $action->execute($data),
            ['data' => $data],
        );
    }
}
