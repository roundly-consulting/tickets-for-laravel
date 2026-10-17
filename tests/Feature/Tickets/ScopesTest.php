<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Enums\TicketPriority;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

/**
 * @param  Builder<Ticket>  $query
 * @return list<string>
 */
function references($query): array
{
    return $query->orderBy('number')->pluck('reference')->all();
}

it('filters by assignee, or by having none', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    $team = Team::query()->create(['name' => 'Hardware']);
    $mine = Ticket::factory()->assignedTo($ada)->create();
    $teams = Ticket::factory()->assignedTo($team)->create();
    $nobody = Ticket::factory()->create();

    expect(references(Ticket::query()->assignedTo($ada)))->toBe([$mine->reference])
        ->and(references(Ticket::query()->assignedTo($ada, $team)))->toBe([$mine->reference, $teams->reference])
        ->and(references(Ticket::query()->assignedTo()))->toBe([])
        ->and(references(Ticket::query()->unassigned()))->toBe([$nobody->reference]);
});

it('filters by requester and by project', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    $project = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
    $hers = Ticket::factory()->requestedBy($ada)->create();
    $scoped = Ticket::factory()->inProject($project)->create();

    expect(references(Ticket::query()->requestedBy($ada)))->toBe([$hers->reference])
        ->and(references(Ticket::query()->inProject($project)))->toBe([$scoped->reference])
        ->and(references(Ticket::query()->inProject(null)))->toBe([$hers->reference]);
});

it('filters by category, with or without its descendants', function (): void {
    $root = TicketCategory::factory()->create();
    $child = TicketCategory::factory()->under($root)->create();
    $grandchild = TicketCategory::factory()->under($child)->create();
    $other = TicketCategory::factory()->create();
    $inRoot = Ticket::factory()->create(['category_id' => $root->id]);
    $inGrandchild = Ticket::factory()->create(['category_id' => $grandchild->id]);
    Ticket::factory()->create(['category_id' => $other->id]);

    expect(references(Ticket::query()->inCategory($root)))->toBe([$inRoot->reference])
        ->and(references(Ticket::query()->inCategory($root->id, descendants: true)))->toBe([$inRoot->reference, $inGrandchild->reference]);
});

it('stops the descendant walk at the configured depth', function (): void {
    config()->set('tickets.categories.max_depth', 1);
    $root = TicketCategory::factory()->create();
    $child = TicketCategory::factory()->under($root)->create();
    $grandchild = TicketCategory::factory()->under($child)->create();
    $inChild = Ticket::factory()->create(['category_id' => $child->id]);
    Ticket::factory()->create(['category_id' => $grandchild->id]);

    expect(references(Ticket::query()->inCategory($root, descendants: true)))->toBe([$inChild->reference]);
});

it('filters by exact status and by status category', function (): void {
    $new = Ticket::factory()->create();
    $pending = Ticket::factory()->inStatus('pending')->create();
    $resolved = Ticket::factory()->inStatus('resolved')->create();
    $closed = Ticket::factory()->inStatus('closed')->create();

    expect(references(Ticket::query()->withStatus(TicketStatus::Pending, 'closed')))->toBe([$pending->reference, $closed->reference])
        ->and(references(Ticket::query()->withStatusCategory(StatusCategory::Waiting, StatusCategory::Resolved)))->toBe([$pending->reference, $resolved->reference])
        ->and(references(Ticket::query()->unresolved()))->toBe([$new->reference, $pending->reference]);
});

it('filters by priority and orders by its weight', function (): void {
    $low = Ticket::factory()->create(['priority' => 'low']);
    $urgent = Ticket::factory()->create(['priority' => 'urgent']);
    $normal = Ticket::factory()->create(['priority' => 'normal']);

    expect(references(Ticket::query()->withPriority(TicketPriority::Urgent, 'low')))->toBe([$low->reference, $urgent->reference])
        ->and(Ticket::query()->orderByPriority()->pluck('reference')->all())->toBe([$urgent->reference, $normal->reference, $low->reference])
        ->and(Ticket::query()->orderByPriority('ASC')->pluck('reference')->all())->toBe([$low->reference, $normal->reference, $urgent->reference]);
});

it('filters by tag slug or model, and by watcher', function (): void {
    $printer = TicketTag::factory()->create(['name' => 'Printer']);
    $network = TicketTag::factory()->create(['name' => 'Network']);
    $ada = User::query()->create(['name' => 'Ada']);
    $tagged = Ticket::factory()->create();
    $other = Ticket::factory()->create();
    $tagged->tags()->attach($printer->id, ['created_at' => now()]);
    $other->tags()->attach($network->id, ['created_at' => now()]);
    TicketWatcher::factory()->create(['ticket_id' => $other->id, 'watcher_type' => $ada->getMorphClass(), 'watcher_id' => $ada->id]);

    expect(references(Ticket::query()->taggedWith('printer')))->toBe([$tagged->reference])
        ->and(references(Ticket::query()->taggedWith($network)))->toBe([$other->reference])
        ->and(references(Ticket::query()->taggedWith('printer', $network)))->toBe([$tagged->reference, $other->reference])
        ->and(references(Ticket::query()->watchedBy($ada)))->toBe([$other->reference]);
});

it('filters by due date and finds overdue unresolved tickets', function (): void {
    CarbonImmutable::setTestNow('2030-06-01 12:00:00');
    $past = Ticket::factory()->create(['due_at' => '2030-05-01 00:00:00']);
    $future = Ticket::factory()->create(['due_at' => '2030-07-01 00:00:00']);
    Ticket::factory()->inStatus('resolved')->create(['due_at' => '2030-05-01 00:00:00']);
    $waiting = Ticket::factory()->inStatus('pending')->create(['due_at' => '2030-05-15 00:00:00']);

    expect(references(Ticket::query()->dueBefore(CarbonImmutable::parse('2030-06-01'))->unresolved()))->toBe([$past->reference, $waiting->reference])
        ->and(references(Ticket::query()->dueAfter(CarbonImmutable::parse('2030-06-01'))))->toBe([$future->reference])
        ->and(references(Ticket::query()->overdue()))->toBe([$past->reference, $waiting->reference]);

    CarbonImmutable::setTestNow();
});

it('filters merged and unmerged tickets', function (): void {
    $target = Ticket::factory()->create();
    $merged = Ticket::factory()->create(['merged_into_id' => $target->id]);

    expect(references(Ticket::query()->merged()))->toBe([$merged->reference])
        ->and(references(Ticket::query()->merged(false)))->toBe([$target->reference]);
});
