<?php

declare(strict_types=1);

use RoundlyConsulting\Tickets\Contracts\Priority;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Enums\TicketPriority;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Exceptions\ActivityIsAppendOnlyException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketLink;
use RoundlyConsulting\Tickets\Models\TicketSequence;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

it('starts a ticket in the lifecycle initial state and binds it by reference', function (): void {
    $ticket = Ticket::factory()->create();

    expect($ticket->status)->toBe(TicketStatus::New)
        ->and($ticket->statusCategory())->toBe(StatusCategory::Open)
        ->and($ticket->getRouteKeyName())->toBe('reference')
        ->and($ticket->getRouteKey())->toBe($ticket->reference)
        ->and($ticket->lifecycleHistory()->count())->toBe(1);
});

it('reads its priority as the configured enum', function (): void {
    $ticket = Ticket::factory()->create(['priority' => 'urgent']);

    expect($ticket->priority())->toBe(TicketPriority::Urgent)
        ->and($ticket->priority())->toBeInstanceOf(Priority::class);
});

it('reads a stored priority the enum no longer has as null, never an error', function (): void {
    $ticket = Ticket::factory()->create(['priority' => 'critical']);

    expect($ticket->priority())->toBeNull();
});

it('has no status category before it has a state', function (): void {
    expect((new Ticket)->statusCategory())->toBeNull();
});

it('relates a ticket to its parties, category, tags, watchers, links and activity', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $project = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
    $category = TicketCategory::factory()->create();
    $ticket = Ticket::factory()->requestedBy($user)->assignedTo($user)->inProject($project)->create(['category_id' => $category->id]);
    $other = Ticket::factory()->create();
    $tag = TicketTag::factory()->create();

    $ticket->tags()->attach($tag->id, ['created_at' => now()]);
    $watcher = TicketWatcher::factory()->create(['ticket_id' => $ticket->id, 'watcher_type' => $user->getMorphClass(), 'watcher_id' => $user->id]);
    $link = TicketLink::factory()->create(['ticket_id' => $ticket->id, 'related_ticket_id' => $other->id, 'type' => LinkType::Blocks, 'created_by_type' => $user->getMorphClass(), 'created_by_id' => $user->id]);
    TicketActivity::factory()->create(['ticket_id' => $ticket->id, 'occurred_at' => now()->addMinute()]);
    TicketActivity::factory()->create(['ticket_id' => $ticket->id, 'type' => ActivityType::Created, 'occurred_at' => now()->subMinute(), 'actor_type' => $user->getMorphClass(), 'actor_id' => $user->id]);

    $ticket->refresh();

    expect($ticket->requester->is($user))->toBeTrue()
        ->and($ticket->assignee?->is($user))->toBeTrue()
        ->and($ticket->project?->is($project))->toBeTrue()
        ->and($ticket->category?->is($category))->toBeTrue()
        ->and($ticket->tags->pluck('id')->all())->toBe([$tag->id])
        ->and($tag->tickets()->pluck('tickets.id')->all())->toEqual([$ticket->id])
        ->and($ticket->watchers->sole()->watcher->is($user))->toBeTrue()
        ->and($watcher->ticket->is($ticket))->toBeTrue()
        ->and($ticket->links->sole()->type)->toBe(LinkType::Blocks)
        ->and($link->related->is($other))->toBeTrue()
        ->and($link->ticket->is($ticket))->toBeTrue()
        ->and($link->createdBy?->is($user))->toBeTrue()
        ->and($ticket->activities->pluck('type')->all())->toBe([ActivityType::Created, ActivityType::Updated])
        ->and($ticket->activities->first()?->actor?->is($user))->toBeTrue()
        ->and($ticket->activities->first()?->ticket->is($ticket))->toBeTrue();
});

it('builds a category tree with project and default assignee', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $project = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
    $root = TicketCategory::factory()->inProject($project)->create([
        'name' => ['en' => 'Hardware', 'sk' => 'Hardvér'],
        'default_assignee_type' => $user->getMorphClass(),
        'default_assignee_id' => $user->id,
    ]);
    $child = TicketCategory::factory()->under($root)->create(['name' => ['en' => 'Printers']]);
    $ticket = Ticket::factory()->create(['category_id' => $child->id]);

    app()->setLocale('sk');

    expect($root->fresh()?->name)->toBe('Hardvér')
        ->and($root->slug)->toBe('hardware')
        ->and($root->project?->is($project))->toBeTrue()
        ->and($root->defaultAssignee?->is($user))->toBeTrue()
        ->and($root->children->sole()->is($child))->toBeTrue()
        ->and($child->parent?->is($root))->toBeTrue()
        ->and($child->project_id)->toBe($project->id)
        ->and($child->tickets->sole()->is($ticket))->toBeTrue();
});

it('keeps category slugs unique among siblings', function (): void {
    $first = TicketCategory::factory()->create(['name' => ['en' => 'Billing']]);
    $second = TicketCategory::factory()->create(['name' => ['en' => 'Billing']]);
    $nested = TicketCategory::factory()->under($first)->create(['name' => ['en' => 'Billing']]);

    expect($first->slug)->toBe('billing')
        ->and($second->slug)->not->toBe('billing')
        ->and($nested->slug)->toBe('billing');
});

it('casts a field definition and scopes it to a category and project', function (): void {
    $project = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
    $category = TicketCategory::factory()->create();
    $field = TicketField::factory()->create([
        'category_id' => $category->id,
        'project_type' => $project->getMorphClass(),
        'project_id' => $project->id,
        'label' => ['en' => 'Asset tag', 'sk' => 'Inventárne číslo'],
        'options' => [['value' => 'a', 'label' => ['en' => 'A']]],
        'rules' => ['max:32'],
        'default' => 'X-1',
    ])->fresh();

    expect($field?->category?->is($category))->toBeTrue()
        ->and($field?->project?->is($project))->toBeTrue()
        ->and($field?->getTranslation('label', 'sk'))->toBe('Inventárne číslo')
        // jsonb reorders object keys, so compare by key, not by position.
        ->and($field?->options)->toEqual([['value' => 'a', 'label' => ['en' => 'A']]])
        ->and($field?->rules)->toBe(['max:32'])
        ->and($field?->default)->toBe('X-1')
        ->and($field?->required)->toBeFalse();
});

it('slugs tags within their project', function (): void {
    $project = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
    $global = TicketTag::factory()->create(['name' => 'Printer']);
    $scoped = TicketTag::factory()->create(['name' => 'Printer', 'project_type' => $project->getMorphClass(), 'project_id' => $project->id]);

    expect($global->slug)->toBe('printer')
        ->and($scoped->slug)->toBe('printer')
        ->and($scoped->project?->is($project))->toBeTrue();
});

it('stores guests with a configurable key and soft deletes', function (): void {
    $guest = TicketGuest::factory()->create();

    $guest->delete();

    expect(TicketGuest::query()->count())->toBe(0)
        ->and(TicketGuest::withTrashed()->count())->toBe(1)
        ->and($guest->getIncrementing())->toBeTrue();
});

it('keeps the activity timeline append-only', function (string $operation): void {
    $activity = TicketActivity::factory()->create();

    expect($activity->visibility)->toBe(ActivityVisibility::Public)
        ->and($activity->is_system)->toBeFalse();

    $operation === 'update' ? $activity->update(['is_system' => true]) : $activity->delete();
})->with(['update', 'delete'])->throws(ActivityIsAppendOnlyException::class);

it('lets a force-deleted ticket take its activity with it', function (): void {
    $activity = TicketActivity::factory()->create();

    $activity->ticket->forceDelete();

    expect(TicketActivity::query()->count())->toBe(0);
});

it('stores sequences', function (): void {
    $sequence = TicketSequence::factory()->create(['next_number' => 7]);

    expect($sequence->fresh()?->next_number)->toBe(7);
});
