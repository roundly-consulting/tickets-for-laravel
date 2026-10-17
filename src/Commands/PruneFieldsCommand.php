<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Tickets\Support\Messages;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * Forgets the custom field values no field of their ticket accounts for any more — a deleted
 * field, a category the ticket left. They are kept hidden until then, so this is the one step
 * that cannot be undone; `--dry-run` only counts them.
 */
final class PruneFieldsCommand extends Command
{
    protected $signature = 'tickets:prune-fields
        {--dry-run : Count the orphaned values without forgetting them}
        {--chunk=200 : How many tickets to walk at a time}';

    protected $description = 'Forget orphaned custom field values of tickets';

    public function handle(TicketsManager $tickets): int
    {
        $chunk = $this->option('chunk');

        if (! is_string($chunk) || preg_match('/^[1-9][0-9]{0,5}$/', $chunk) !== 1) {
            $this->error(Messages::get('prune.invalid_chunk'));

            return self::INVALID;
        }

        $dryRun = $this->option('dry-run') === true;
        $count = $tickets->fields()->prune(dryRun: $dryRun, chunk: (int) $chunk);

        $this->info(Messages::choice($dryRun ? 'prune.dry_run' : 'prune.pruned', $count));

        return self::SUCCESS;
    }
}
