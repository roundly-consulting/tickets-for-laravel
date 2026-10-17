<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Fields;

use RoundlyConsulting\Attributes\AttributesManager;
use RoundlyConsulting\Tickets\Fields\FieldSetResolver;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * Forgets the custom field values no field of the ticket accounts for any more — the field was
 * deleted, or the ticket moved to a category the field does not cover. Those values are kept
 * hidden until now (reversible); this drops them for good. With `$dryRun` it only counts.
 * Values of an inactive field are kept: the field still exists.
 *
 * Returns how many values were (or, dry, would be) forgotten.
 */
final readonly class PruneOrphanFieldValuesAction
{
    public function __construct(
        private FieldSetResolver $fields,
        private AttributesManager $attributes,
    ) {}

    public function execute(bool $dryRun = false, int $chunk = 200): int
    {
        $pruned = 0;

        TicketModels::ticket()::withTrashed()
            ->whereHas('attachedAttributes')
            ->chunkById(max(1, $chunk), function (iterable $tickets) use ($dryRun, &$pruned): void {
                foreach ($tickets as $ticket) {
                    $pruned += $this->prune($ticket, $dryRun);
                }
            });

        return $pruned;
    }

    private function prune(Ticket $ticket, bool $dryRun): int
    {
        $known = $this->fields->knownKeys($ticket);
        $orphans = array_values(array_diff(
            array_map(strval(...), $ticket->attachedAttributes()->pluck('name')->all()),
            $known,
        ));

        if (! $dryRun && $orphans !== []) {
            $this->attributes->for($ticket)->forget($orphans, forceDelete: true);
        }

        return count($orphans);
    }
}
