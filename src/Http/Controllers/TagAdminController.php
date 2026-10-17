<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RoundlyConsulting\Tickets\Exceptions\TicketRecordNotFoundException;
use RoundlyConsulting\Tickets\Http\Requests\ScopedListRequest;
use RoundlyConsulting\Tickets\Http\Requests\StoreTagRequest;
use RoundlyConsulting\Tickets\Http\Requests\UpdateTagRequest;
use RoundlyConsulting\Tickets\Http\Resources\TicketTagResource;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\MorphAliases;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * `/ticket-tags` — agents list the tags (global, plus a project's with `?project=alias:key`);
 * the manage ability creates, renames, recolours and deletes them.
 *
 * @internal
 */
final class TagAdminController extends Controller
{
    public function index(ScopedListRequest $request): AnonymousResourceCollection
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'viewTags', [TicketModels::ticket()]);

        return TicketTagResource::collection($this->manager()->tags()->all(self::resolve(MorphAliases::PROJECTS, $request->projectReference())));
    }

    public function store(StoreTagRequest $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'manageTags', [TicketModels::ticket()]);

        $tag = $this->manager()->tags()->create($request->toData(self::resolve(MorphAliases::PROJECTS, $request->projectReference())));

        return (new TicketTagResource($tag))->response()->setStatusCode(201);
    }

    public function update(UpdateTagRequest $request, string $tag): TicketTagResource
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'manageTags', [TicketModels::ticket()]);

        return new TicketTagResource($this->manager()->tags()->update(self::tag($tag), $request->toData()));
    }

    public function destroy(Request $request, string $tag): JsonResponse
    {
        $viewer = $this->viewer($request);
        $this->authorize($viewer, 'manageTags', [TicketModels::ticket()]);

        $this->manager()->tags()->delete(self::tag($tag));

        return new JsonResponse(null, 204);
    }

    private static function tag(string $id): TicketTag
    {
        $tag = ctype_digit($id) ? TicketModels::tag()::query()->find((int) $id) : null;

        return $tag ?? throw TicketRecordNotFoundException::tag($id);
    }
}
