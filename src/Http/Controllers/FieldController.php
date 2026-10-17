<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Tickets\Exceptions\TicketRecordNotFoundException;
use RoundlyConsulting\Tickets\Http\Requests\ScopedListRequest;
use RoundlyConsulting\Tickets\Http\Requests\StoreFieldRequest;
use RoundlyConsulting\Tickets\Http\Requests\UpdateFieldRequest;
use RoundlyConsulting\Tickets\Http\Resources\TicketFieldResource;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Support\MorphAliases;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * `/ticket-fields` — custom field definitions. Anyone signed in lists them (with `?category=`
 * the fields that apply to tickets of that category, `?project=alias:key` a project's); an agent
 * sees every field, anyone else the active, requester-visible ones. The manage ability writes
 * them.
 *
 * @internal
 */
final class FieldController extends Controller
{
    public function index(ScopedListRequest $request): AnonymousResourceCollection
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'viewAny', [TicketModels::ticket()]);
        $project = self::resolve(MorphAliases::PROJECTS, $request->projectReference());
        $category = $request->category();

        $query = TicketModels::field()::query()->orderBy('position')->orderBy('id');

        if ($category !== null) {
            $node = TicketModels::category()::query()->find($category) ?? throw TicketRecordNotFoundException::category($category);
            $query->whereIn('id', array_map(
                static fn (FieldDefinition $field): int => $field->id,
                array_values($this->manager()->fields()->for($node, $project)),
            ));
        } else {
            [$type, $id] = TicketInput::morph($project);
            $query->where(static fn (Builder $scope): Builder => $type === null
                ? $scope->whereNull('project_type')
                : $scope->whereNull('project_type')->orWhere(static fn (Builder $own): Builder => $own->where('project_type', $type)->where('project_id', $id)));
        }

        if (! $viewer->isAgent()) {
            $query->where('is_active', true)->where('requester_visible', true);
        }

        return TicketFieldResource::collection($query->get());
    }

    public function store(StoreFieldRequest $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'manageFields', [TicketModels::ticket()]);

        $field = $this->manager()->fields()->define($request->toData(self::resolve(MorphAliases::PROJECTS, $request->projectReference())));

        return (new TicketFieldResource($field))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $field): TicketFieldResource
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'viewAny', [TicketModels::ticket()]);
        $model = self::field($field);

        if (! $viewer->isAgent() && (! $model->is_active || ! $model->requester_visible)) {
            throw TicketRecordNotFoundException::field($field);
        }

        return new TicketFieldResource($model);
    }

    public function update(UpdateFieldRequest $request, string $field): TicketFieldResource
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'manageFields', [TicketModels::ticket()]);

        return new TicketFieldResource($this->manager()->fields()->update(self::field($field), $request->toData()));
    }

    public function destroy(Request $request, string $field): JsonResponse
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'manageFields', [TicketModels::ticket()]);

        $this->manager()->fields()->delete(self::field($field));

        return new JsonResponse(null, 204);
    }

    private static function field(string $id): TicketField
    {
        $field = ctype_digit($id) ? TicketModels::field()::query()->find((int) $id) : null;

        return $field ?? throw TicketRecordNotFoundException::field($id);
    }
}
