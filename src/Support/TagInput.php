<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Sluggable\SlugManager;
use RoundlyConsulting\Tickets\Exceptions\InvalidTagException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketTag;

/**
 * Validation and lookup of tags, shared by the tag actions.
 *
 * @internal
 */
final class TagInput
{
    public static function name(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 64 || self::slug($name) === '') {
            throw InvalidTagException::invalidName();
        }

        return $name;
    }

    public static function color(?string $color): ?string
    {
        if ($color === null) {
            return null;
        }

        if (preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
            throw InvalidTagException::invalidColor($color);
        }

        return strtolower($color);
    }

    /**
     * The slug a tag of this name has — what lookups by name compare.
     */
    public static function slug(string $name): string
    {
        return App::make(SlugManager::class)->slugify($name);
    }

    /**
     * The tag with this name in one scope (a project, or the global tags with null) — a
     * soft-deleted one included, because its slug stays taken: a name is a tag's for good.
     */
    public static function find(string $name, ?string $projectType, int|string|null $projectId): ?TicketTag
    {
        return self::inScope(TicketModels::tag()::withTrashed(), $projectType, $projectId)
            ->where('slug', self::slug($name))
            ->first();
    }

    /**
     * Whether a tag may sit on the ticket: global, or of the ticket's own project.
     */
    public static function fits(TicketTag $tag, Ticket $ticket): bool
    {
        return $tag->project_type === null
            || ($tag->project_type === $ticket->project_type && (string) $tag->project_id === (string) $ticket->project_id);
    }

    /**
     * @template TBuilder of Builder<TicketTag>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function inScope(Builder $query, ?string $projectType, int|string|null $projectId): Builder
    {
        return $projectType === null
            ? $query->whereNull('project_type')
            : $query->where('project_type', $projectType)->where('project_id', $projectId);
    }
}
