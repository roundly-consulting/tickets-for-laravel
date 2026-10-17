<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Internal;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\TicketProject;
use RoundlyConsulting\Tickets\DataTransferObjects\AllocatedReference;
use RoundlyConsulting\Tickets\Exceptions\InvalidProjectException;
use RoundlyConsulting\Tickets\Models\TicketSequence;
use RoundlyConsulting\Tickets\Support\LockedRow;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * Hands out the next reference of a sequence (`global`, or one per project) under a row
 * lock, inside the create transaction — so numbers are gap-free under concurrency and a
 * rolled-back create gives its number back.
 *
 * @internal
 */
final readonly class AllocateReferenceAction
{
    public function execute(?Model $project): AllocatedReference
    {
        [$scope, $prefix] = $project === null
            ? ['global', TicketsConfig::referencePrefix()]
            : [$project->getMorphClass().':'.$project->getKey(), self::prefixOf($project)];

        $sequence = LockedRow::mutex(TicketSequence::query(), ['scope' => $scope]);
        $number = $sequence->next_number;

        $sequence->next_number = $number + 1;
        $sequence->save();

        return new AllocatedReference($scope, $number, $prefix.'-'.$number);
    }

    private static function prefixOf(Model $project): string
    {
        if (! $project instanceof TicketProject) {
            throw InvalidProjectException::notAProject($project);
        }

        $prefix = $project->ticketReferencePrefix();

        if (preg_match(TicketsConfig::PREFIX_PATTERN, $prefix) !== 1) {
            throw InvalidProjectException::invalidPrefix($prefix);
        }

        return $prefix;
    }
}
