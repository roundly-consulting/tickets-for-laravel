<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Accessors;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Fields\DefineFieldAction;
use RoundlyConsulting\Tickets\Actions\Fields\DeleteFieldAction;
use RoundlyConsulting\Tickets\Actions\Fields\PruneOrphanFieldValuesAction;
use RoundlyConsulting\Tickets\Actions\Fields\RestoreFieldAction;
use RoundlyConsulting\Tickets\Actions\Fields\UpdateFieldAction;
use RoundlyConsulting\Tickets\Builders\FieldBuilder;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldData;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateFieldData;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Fields\FieldSetResolver;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::fields()` — custom field definitions. A ticket's values are
 * `Tickets::for($ticket)->fields()`.
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

    /**
     * Change a field — its type and encryption are locked once tickets hold values, and its
     * options may then only grow.
     */
    public function update(TicketField $field, UpdateFieldData $data): TicketField
    {
        return $this->manager->perform(
            TicketOperation::UpdateField,
            UpdateFieldAction::class,
            static fn (UpdateFieldAction $action): TicketField => $action->execute($field, $data),
            ['field' => $field, 'data' => $data],
        );
    }

    /**
     * Soft-delete a field; its values stay hidden on the tickets until a restore or a prune.
     */
    public function delete(TicketField $field): TicketField
    {
        return $this->manager->perform(
            TicketOperation::DeleteField,
            DeleteFieldAction::class,
            static fn (DeleteFieldAction $action): TicketField => $action->execute($field),
            ['field' => $field],
        );
    }

    public function restore(TicketField $field): TicketField
    {
        return $this->manager->perform(
            TicketOperation::RestoreField,
            RestoreFieldAction::class,
            static fn (RestoreFieldAction $action): TicketField => $action->execute($field),
            ['field' => $field],
        );
    }

    /**
     * Forget the values no field of their ticket accounts for any more (a deleted field, a
     * category the ticket left); returns how many — with `$dryRun`, only counts them. Tickets
     * are walked `$chunk` at a time.
     */
    public function prune(bool $dryRun = false, int $chunk = 200): int
    {
        return $this->manager->perform(
            TicketOperation::PruneFields,
            PruneOrphanFieldValuesAction::class,
            static fn (PruneOrphanFieldValuesAction $action): int => $action->execute($dryRun, $chunk),
            ['dry_run' => $dryRun, 'chunk' => $chunk],
        );
    }

    /**
     * A live field by id or key. A key is unique among the global fields and one project's, so
     * with `$project` it names the project's field or the global one; without, the global
     * field only. An id is unambiguous — with `$project`, a field outside the project's set
     * (global ∪ its own) is not found.
     */
    public function find(int|string $idOrKey, ?Model $project = null): ?TicketField
    {
        $query = TicketModels::field()::query();

        if (is_int($idOrKey)) {
            $query->whereKey($idOrKey);
        } else {
            $query->where('key', $idOrKey);
        }

        [$type, $id] = TicketInput::morph($project);

        if ($type !== null) {
            $query->where(static fn (Builder $scope): Builder => $scope
                ->whereNull('project_type')
                ->orWhere(static fn (Builder $own): Builder => $own->where('project_type', $type)->where('project_id', $id)));
        } elseif (is_string($idOrKey)) {
            $query->whereNull('project_type');
        }

        return $query->orderByRaw('case when project_type is null then 1 else 0 end')->first();
    }

    /**
     * The fields that apply to a ticket, or to tickets of a category (in a project), in order.
     *
     * @return array<string, FieldDefinition>
     */
    public function for(Ticket|TicketCategory $subject, ?Model $project = null): array
    {
        $resolver = app(FieldSetResolver::class);

        if ($subject instanceof Ticket) {
            return $resolver->forTicket($subject);
        }

        [$type, $id] = $project === null
            ? [$subject->project_type, $subject->project_id]
            : TicketInput::morph($project);

        return $resolver->forScope($type, $id, (int) $subject->getKey());
    }
}
