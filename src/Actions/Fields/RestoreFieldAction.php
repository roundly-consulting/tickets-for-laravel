<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Fields;

use RoundlyConsulting\Tickets\Fields\FieldSetResolver;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\FieldInput;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Restores a soft-deleted field under the `lock:fields` advisory row — refused when a live
 * field of its scope took the key meanwhile. Its kept values show again.
 */
final readonly class RestoreFieldAction
{
    public function __construct(
        private FieldSetResolver $fields,
    ) {}

    public function execute(TicketField $field): TicketField
    {
        return TicketTransaction::run($field, function () use ($field): TicketField {
            AdvisoryLock::acquire(AdvisoryLock::FIELDS);

            if ($field->trashed()) {
                FieldInput::assertKeyFree($field->key, $field->project_type, $field->project_id, (int) $field->getKey());

                $field->restore();
                $this->fields->flush();
            }

            return $field;
        });
    }
}
