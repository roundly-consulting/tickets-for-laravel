<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Fields;

use RoundlyConsulting\Tickets\Fields\FieldSetResolver;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Soft-deletes a field definition under the `lock:fields` advisory row. Its values stay on the
 * tickets, hidden — a restore brings them back; `Tickets::fields()->prune()` forgets them.
 */
final readonly class DeleteFieldAction
{
    public function __construct(
        private FieldSetResolver $fields,
    ) {}

    public function execute(TicketField $field): TicketField
    {
        return TicketTransaction::run($field, function () use ($field): TicketField {
            AdvisoryLock::acquire(AdvisoryLock::FIELDS);

            $field->delete();
            $this->fields->flush();

            return $field;
        });
    }
}
