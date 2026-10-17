<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Tickets\Enums\TicketPriority;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

/**
 * The README's ```php blocks, verbatim — the scheduler lines and the usage example.
 *
 * @return list<string>
 */
function readmePhpBlocks(): array
{
    preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents(__DIR__.'/../../README.md'), $matches);

    return $matches[1];
}

it('ships two php blocks: the scheduler lines and the usage example', function (): void {
    expect(readmePhpBlocks())->toHaveCount(2);
});

it('schedules only commands that exist', function (): void {
    eval(readmePhpBlocks()[0]);

    $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");

    expect($commands)->toContain('tickets:sweep')->toContain('lifecycle:sweep')
        ->and(Artisan::all())->toHaveKeys(['tickets:install', 'tickets:sweep', 'lifecycle:sweep']);
});

/**
 * Every promise the example's comments make: the category defaults, the TCK-1 reference, the
 * claim, the automations, the resolve and the thread without the note.
 */
it('runs the README usage example verbatim', function (): void {
    $customer = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
    $agent = User::query()->create(['name' => 'Grace', 'email' => 'grace@example.test', 'is_agent' => true]);

    eval(readmePhpBlocks()[1]);

    $ticket = Ticket::query()->where('reference', 'TCK-1')->firstOrFail();

    expect($ticket->priority())->toBe(TicketPriority::High)
        ->and($ticket->due_at?->toDateTimeString())->toBe($ticket->created_at?->addDay()->toDateTimeString())
        ->and($ticket->assignee?->is($agent))->toBeTrue()
        ->and($ticket->first_responded_at)->not->toBeNull()
        ->and(Tickets::for('TCK-1')->status())->toBe(TicketStatus::Resolved)
        ->and(Tickets::for('TCK-1')->thread()->pluck('comment')->all())->toBe(['A technician is on the way.', 'Works again, thanks!'])
        ->and(Tickets::for('TCK-1')->thread(internal: true)->pluck('comment')->all())->toContain('Roller worn out, order a spare.');
});
