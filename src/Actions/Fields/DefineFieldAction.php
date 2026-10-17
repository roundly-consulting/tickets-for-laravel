<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Fields;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldData;
use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;
use RoundlyConsulting\Tickets\Exceptions\InvalidFieldException;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\CategoryTree;
use RoundlyConsulting\Tickets\Support\FieldInput;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Defines a custom field — global, per project, or per category (and its descendants).
 *
 * A key is unique among live fields across a project's whole applicable set: global fields,
 * the project's fields and every category's. A ticket's fields union global, project,
 * ancestor and own-category definitions while attributes stores one value per name, so a
 * child category re-using its parent's key would give one value two definitions. No
 * portable partial unique index can hold that, so the check runs under `lock:fields`.
 */
final readonly class DefineFieldAction
{
    public function execute(FieldData $data): TicketField
    {
        $key = FieldInput::key($data->key);
        $label = FieldInput::label($key, $data->label);
        $options = FieldInput::options($key, $data->type, $data->options);
        $rules = FieldInput::rules($key, $data->rules);
        $class = TicketModels::field();
        $field = new $class;

        return TicketTransaction::run($field, function () use ($field, $data, $key, $label, $options, $rules): TicketField {
            AdvisoryLock::acquire(AdvisoryLock::FIELDS);

            [$projectType, $projectId] = TicketInput::morph($data->project);
            $category = $data->category === null ? null : CategoryTree::resolve($data->category);

            if ($category !== null && $category->project_type !== null) {
                if ($data->project !== null
                    && ($category->project_type !== $projectType || (string) $category->project_id !== (string) $projectId)) {
                    throw InvalidCategoryException::projectMismatch();
                }

                [$projectType, $projectId] = [$category->project_type, $category->project_id];
            }

            $taken = TicketModels::field()::query()->where('key', $key);

            // A project field collides with a global one and with its own project's fields; a
            // global field collides with every field of the key, in any project.
            if ($projectType !== null) {
                $taken->where(static fn (Builder $scope): Builder => $scope
                    ->whereNull('project_type')
                    ->orWhere(static fn (Builder $same): Builder => $same->where('project_type', $projectType)->where('project_id', $projectId)));
            }

            if ($taken->exists()) {
                throw InvalidFieldException::duplicateKey($key);
            }

            $field->forceFill([
                'project_type' => $projectType,
                'project_id' => $projectId,
                'category_id' => $category?->getKey(),
                'key' => $key,
                'label' => $label,
                'help' => $data->help,
                'type' => $data->type,
                'options' => $options,
                'rules' => $rules === [] ? null : $rules,
                'required' => $data->required,
                'encrypted' => $data->encrypted,
                'default' => $data->default,
                'requester_visible' => $data->requesterVisible,
                'requester_editable' => $data->requesterEditable,
                'position' => $data->position,
                'is_active' => $data->active,
            ])->save();

            return $field;
        });
    }
}
