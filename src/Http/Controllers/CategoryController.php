<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RoundlyConsulting\Tickets\DataTransferObjects\CategoryData;
use RoundlyConsulting\Tickets\Exceptions\TicketRecordNotFoundException;
use RoundlyConsulting\Tickets\Http\Requests\DeleteCategoryRequest;
use RoundlyConsulting\Tickets\Http\Requests\MoveCategoryRequest;
use RoundlyConsulting\Tickets\Http\Requests\ScopedListRequest;
use RoundlyConsulting\Tickets\Http\Requests\StoreCategoryRequest;
use RoundlyConsulting\Tickets\Http\Requests\UpdateCategoryRequest;
use RoundlyConsulting\Tickets\Http\Resources\TicketCategoryResource;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\MorphAliases;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * `/ticket-categories` — anyone signed in reads the tree (global, plus a project's with
 * `?project=alias:key`); the manage ability writes it. An inactive category (and its subtree)
 * is an agent's to see, as an inactive field is: anyone else gets the categories they can file
 * into, and 404 for an inactive one.
 *
 * @internal
 */
final class CategoryController extends Controller
{
    public function index(ScopedListRequest $request): AnonymousResourceCollection
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'viewAny', [TicketModels::ticket()]);

        $tree = $this->manager()->categories()->tree(self::resolve(MorphAliases::PROJECTS, $request->projectReference()));

        return TicketCategoryResource::collection($viewer->isAgent() ? $tree : $tree->where('is_active', true)->values());
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'manageCategories', [TicketModels::ticket()]);

        $category = $this->manager()->categories()->create(new CategoryData(
            name: $request->name(),
            description: $request->description(),
            parent: $request->parentId(),
            project: self::resolve(MorphAliases::PROJECTS, $request->projectReference()),
            position: $request->position(),
            active: $request->active(),
            defaultPriority: $request->defaultPriority(),
            defaultAssignee: self::resolve(MorphAliases::ASSIGNABLES, $request->defaultAssigneeReference()),
            defaultDueIn: $request->defaultDueIn(),
        ));

        return (new TicketCategoryResource($category))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $category): TicketCategoryResource
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'viewAny', [TicketModels::ticket()]);
        $model = self::category($category);

        if (! $viewer->isAgent() && ! $model->is_active) {
            throw TicketRecordNotFoundException::category($category);
        }

        return new TicketCategoryResource($model);
    }

    public function update(UpdateCategoryRequest $request, string $category): TicketCategoryResource
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'manageCategories', [TicketModels::ticket()]);
        $model = self::category($category);

        $updated = $this->manager()->categories()->update($model, $request->toData(self::resolve(MorphAliases::ASSIGNABLES, $request->defaultAssigneeReference())));

        return new TicketCategoryResource($updated);
    }

    public function move(MoveCategoryRequest $request, string $category): TicketCategoryResource
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'manageCategories', [TicketModels::ticket()]);

        return new TicketCategoryResource($this->manager()->categories()->move(self::category($category), $request->parentId(), $request->position()));
    }

    public function destroy(DeleteCategoryRequest $request, string $category): JsonResponse
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'manageCategories', [TicketModels::ticket()]);

        $this->manager()->categories()->delete(self::category($category), $request->reassignTo());

        return new JsonResponse(null, 204);
    }

    private static function category(string $id): TicketCategory
    {
        $category = ctype_digit($id) ? TicketModels::category()::query()->find((int) $id) : null;

        return $category ?? throw TicketRecordNotFoundException::category($id);
    }
}
