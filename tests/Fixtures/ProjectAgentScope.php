<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\AgentScope;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;

/**
 * A host scope: an agent named "Scoped" works on the tickets of the SUP project only; every
 * other agent on all of them.
 */
final readonly class ProjectAgentScope implements AgentScope
{
    public function allows(Model $agent, Ticket $ticket): bool
    {
        if ($agent->getAttribute('name') !== 'Scoped') {
            return true;
        }

        $project = self::project();

        return $project !== null
            && $ticket->project_type === $project->getMorphClass()
            && (string) $ticket->project_id === (string) $project->getKey();
    }

    /**
     * @param  Builder<covariant Ticket>  $query
     */
    public function constrain(Builder $query, Model $agent): void
    {
        if ($agent->getAttribute('name') !== 'Scoped') {
            return;
        }

        $project = self::project();

        $query->where('project_type', $project?->getMorphClass())->where('project_id', $project?->getKey());
    }

    private static function project(): ?Project
    {
        return Project::query()->where('key', 'SUP')->first();
    }
}
