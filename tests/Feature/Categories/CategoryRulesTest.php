<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Tickets\DataTransferObjects\CategoryData;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateCategoryData;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\TicketPriority;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Events\TicketCategoryChanged;
use RoundlyConsulting\Tickets\Exceptions\InvalidAssigneeException;
use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketSequence;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Support\RecordsLocks;

function category(string $name, ?TicketCategory $parent = null, ?Project $project = null): TicketCategory
{
    return Tickets::categories()->create(new CategoryData(name: ['en' => $name], parent: $parent, project: $project));
}

it('creates a category with translated names, a slug and defaults', function (): void {
    $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);

    $category = Tickets::categories()->create(new CategoryData(
        name: ['en' => 'Hardware', 'sk' => 'Hardvér'],
        description: ['en' => 'Laptops and printers'],
        position: 3,
        defaultPriority: TicketPriority::High,
        defaultAssignee: $agent,
        defaultDueIn: 'P1D',
    ));

    expect($category->getTranslation('name', 'sk'))->toBe('Hardvér')
        ->and($category->slug)->toBe('hardware')
        ->and($category->position)->toBe(3)
        ->and($category->default_priority)->toBe('high')
        ->and($category->defaultAssignee?->is($agent))->toBeTrue()
        ->and($category->default_due_in)->toBe('P1D')
        ->and(Tickets::categories()->find($category->id)?->is($category))->toBeTrue()
        ->and(Tickets::categories()->find(999))->toBeNull();
});

it('takes a due offset as an interval and stores the ISO-8601 duration', function (): void {
    $built = Tickets::categories()->make('Network')->defaults(dueIn: CarbonInterval::hours(4))->create();
    $dto = Tickets::categories()->create(new CategoryData('Printers', defaultDueIn: CarbonInterval::days(2)->addHours(12)));
    $created = $dto->default_due_in;

    Tickets::categories()->update($dto, new UpdateCategoryData(defaultDueIn: CarbonInterval::minutes(90)));

    expect($built->default_due_in)->toBe('PT4H')
        ->and($created)->toBe('P2DT12H')
        ->and($dto->fresh()?->default_due_in)->toBe('PT90M');
});

it('refuses a due offset that is no positive duration', function (CarbonInterval|string $dueIn): void {
    Tickets::categories()->make('Network')->defaults(dueIn: $dueIn)->create();
})->throws(InvalidCategoryException::class)->with([
    'a malformed string' => ['4 hours'],
    'a zero interval' => [CarbonInterval::seconds(0)],
    'a negative interval' => [CarbonInterval::hours(-4)],
    'under a second' => [CarbonInterval::milliseconds(500)],
]);

it('lets a child inherit its parent project', function (): void {
    $project = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
    $parent = category('Hardware', project: $project);

    $child = category('Printers', $parent);

    expect($child->project_id)->toBe($project->id)
        ->and($child->project_type)->toBe($project->getMorphClass());
});

it('lets a global parent have a project child', function (): void {
    $project = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);

    expect(category('Printers', category('Hardware'), $project)->project_id)->toBe($project->id);
});

describe('category_rules', function (): void {
    it('refuses moving a category under itself or a descendant', function (): void {
        $root = category('Root');
        $child = category('Child', $root);
        $grandchild = category('Grandchild', $child);

        expect(fn () => Tickets::categories()->move($root, $grandchild))->toThrow(InvalidCategoryException::class, 'descendants')
            ->and(fn () => Tickets::categories()->move($root, $root))->toThrow(InvalidCategoryException::class);
    });

    it('caps the depth on create and on move', function (): void {
        config()->set('tickets.categories.max_depth', 3);
        $one = category('One');
        $two = category('Two', $one);
        $three = category('Three', $two);
        $branch = category('Branch');
        category('Leaf', $branch);

        expect(fn () => category('Four', $three))->toThrow(InvalidCategoryException::class, '3 levels')
            ->and(fn () => Tickets::categories()->move($branch, $two))->toThrow(InvalidCategoryException::class, '3 levels');

        expect(Tickets::categories()->move($branch, $one)->parent_id)->toBe($one->id);
    });

    it('refuses a child of another project than its parent', function (): void {
        $support = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
        $ops = Project::query()->create(['name' => 'Ops', 'key' => 'OPS']);
        $parent = category('Hardware', project: $support);
        $foreign = category('Foreign', project: $ops);
        $global = category('Global');

        expect(fn () => category('Child', $parent, $ops))->toThrow(InvalidCategoryException::class, 'another project')
            ->and(fn () => Tickets::categories()->move($foreign, $parent))->toThrow(InvalidCategoryException::class)
            ->and(fn () => Tickets::categories()->move($global, $parent))->toThrow(InvalidCategoryException::class);
    });

    it('refuses an inactive category for new tickets and keeps it on existing ones', function (): void {
        $category = category('Hardware');
        $ticket = Tickets::make()->subject('a')->requester(User::query()->create())->category($category)->create();

        Tickets::categories()->update($category, new UpdateCategoryData(active: false));

        expect($ticket->fresh()?->category_id)->toBe($category->id)
            ->and(fn () => Tickets::make()->subject('b')->requester(User::query()->create())->category($category)->create())
            ->toThrow(InvalidCategoryException::class, 'inactive');
    });

    it('refuses deleting a category in use unless its tickets are reassigned', function (): void {
        $category = category('Hardware');
        $target = category('Other');
        $ticket = Tickets::make()->subject('a')->requester(User::query()->create())->category($category)->create();

        expect(fn () => Tickets::categories()->delete($category))->toThrow(InvalidCategoryException::class, 'still has tickets');

        Tickets::categories()->delete($category, reassignTo: $target);

        expect($category->fresh()?->trashed())->toBeTrue()
            ->and($ticket->fresh()?->category_id)->toBe($target->id)
            ->and(keySorted($ticket->activities()->where('type', 'category_changed')->first()?->properties))->toBe(['from' => $category->id, 'to' => $target->id]);
    });

    it('refuses a reassignment target that is the category itself, inactive or of another project — and says which', function (Closure $target, string $message): void {
        $category = category('Hardware');
        Tickets::make()->subject('a')->requester(User::query()->create())->category($category)->create();

        expect(fn () => Tickets::categories()->delete($category, reassignTo: $target($category)))->toThrow(InvalidCategoryException::class, $message)
            ->and($category->fresh()?->trashed())->toBeFalse();
    })->with([
        'itself' => [fn (TicketCategory $category) => $category, 'not the one being deleted'],
        'inactive' => [fn () => TicketCategory::factory()->inactive()->create(), 'inactive'],
        'another project' => [fn () => TicketCategory::factory()->inProject(Project::query()->create(['name' => 'p', 'key' => 'P']))->create(), 'another project'],
    ]);

    it('moves each ticket as a category change does: the event, and the target\'s required fields', function (): void {
        defineAgents();
        Event::fake([TicketCategoryChanged::class]);
        $old = category('Hardware');
        $new = category('Network');
        $filled = Tickets::make()->subject('a')->requester(User::query()->create())->category($old)->asSystem()->create();
        Tickets::fields()->make('serial', FieldType::Text)->category($new)->required()->define();
        Ticket::query()->whereKey($filled->id)->update(['category_id' => $new->id]);
        Tickets::for($filled->fresh() ?? $filled)->asSystem()->fields()->set('serial', 'S-1');
        Ticket::query()->whereKey($filled->id)->update(['category_id' => $old->id]);
        $missing = Tickets::make()->subject('b')->requester(User::query()->create())->category($old)->asSystem()->create();

        // `categorize()` refuses the move for the ticket without a serial — so does the delete.
        expect(fn () => Tickets::for($missing)->asSystem()->categorize($new))->toThrow(ValidationException::class)
            ->and(fn () => Tickets::categories()->delete($old, reassignTo: $new))->toThrow(ValidationException::class)
            ->and($old->fresh()?->trashed())->toBeFalse()
            ->and($filled->fresh()?->category_id)->toBe($old->id);

        Tickets::for($missing)->asSystem()->delete();
        Tickets::categories()->delete($old, reassignTo: $new);

        expect($filled->fresh()?->category_id)->toBe($new->id);
        Event::assertDispatchedTimes(TicketCategoryChanged::class, 1);
        Event::assertDispatched(TicketCategoryChanged::class, static fn (TicketCategoryChanged $event): bool => $event->ticket->is($filled) && $event->to === $new->id);
    });

    it('refuses deleting a category that still has subcategories', function (): void {
        $root = category('Root');
        category('Child', $root);

        Tickets::categories()->delete($root);
    })->throws(InvalidCategoryException::class, 'subcategories');

    it('soft-deletes a category without tickets and restores it', function (): void {
        $category = category('Hardware');

        Tickets::categories()->delete($category->id === 0 ? $category : $category);

        expect($category->trashed())->toBeTrue();

        Tickets::categories()->restore($category);
        Tickets::categories()->restore($category);

        expect($category->fresh()?->trashed())->toBeFalse();
    });

    it('validates names, priorities, due offsets and assignees', function (Closure $create, string $exception): void {
        expect($create)->toThrow($exception);
    })->with([
        'empty name' => [fn () => category('  '), InvalidCategoryException::class],
        'empty locale map' => [fn () => Tickets::categories()->create(new CategoryData(name: ['en' => ''])), InvalidCategoryException::class],
        'unknown priority' => [fn () => Tickets::categories()->create(new CategoryData(name: 'x', defaultPriority: 'critical')), InvalidCategoryException::class],
        'foreign enum priority' => [fn () => Tickets::categories()->create(new CategoryData(name: 'x', defaultPriority: TicketStatus::Open)), InvalidCategoryException::class],
        'junk due offset' => [fn () => Tickets::categories()->create(new CategoryData(name: 'x', defaultDueIn: '2 days')), InvalidCategoryException::class],
        'zero due offset' => [fn () => Tickets::categories()->create(new CategoryData(name: 'x', defaultDueIn: 'PT0S')), InvalidCategoryException::class],
        'not an assignee' => [fn () => Tickets::categories()->create(new CategoryData(name: 'x', defaultAssignee: Project::query()->create(['name' => 'p', 'key' => 'P']))), InvalidAssigneeException::class],
        'missing parent' => [fn () => Tickets::categories()->create(new CategoryData(name: 'x', parent: 999)), InvalidCategoryException::class],
    ]);
});

it('updates names, defaults and position, clearing a default with null', function (): void {
    $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
    $category = Tickets::categories()->create(new CategoryData(name: 'Hardware', defaultPriority: 'high', defaultDueIn: 'P1D'));

    Tickets::categories()->update($category, new UpdateCategoryData(
        name: ['en' => 'Devices'],
        description: 'Everything with a plug',
        position: 7,
        defaultPriority: null,
        defaultAssignee: $agent,
        defaultDueIn: 'PT8H',
    ));

    expect($category->fresh()?->getTranslation('name', 'en'))->toBe('Devices')
        ->and($category->description)->toBe('Everything with a plug')
        ->and($category->position)->toBe(7)
        ->and($category->default_priority)->toBeNull()
        ->and($category->default_assignee_id)->toBe($agent->id)
        ->and($category->default_due_in)->toBe('PT8H');

    Tickets::categories()->update($category, new UpdateCategoryData(defaultAssignee: null));

    expect($category->fresh()?->default_assignee_type)->toBeNull();
});

it('moves a category to the root with an explicit position', function (): void {
    $root = category('Root');
    $child = category('Child', $root);

    Tickets::categories()->move($child, null, position: 4);

    expect($child->parent_id)->toBeNull()
        ->and($child->position)->toBe(4);
});

it('reads the tree: global roots, and a project tree with the project branches', function (): void {
    $support = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
    $ops = Project::query()->create(['name' => 'Ops', 'key' => 'OPS']);
    $hardware = Tickets::categories()->create(new CategoryData(name: 'Hardware', position: 2));
    $billing = Tickets::categories()->create(new CategoryData(name: 'Billing', position: 1));
    $printers = category('Printers', $hardware);
    $supportOnly = category('Support kiosks', $hardware, $support);
    category('Ops racks', $hardware, $ops);
    $supportRoot = category('Support root', project: $support);
    category('Deep', $printers);

    $global = Tickets::categories()->tree();
    $project = Tickets::categories()->tree($support);

    expect($global->pluck('slug')->all())->toBe(['billing', 'hardware'])
        ->and($global[1]->children->pluck('id')->all())->toBe([$printers->id])
        ->and($global[1]->children[0]->children->pluck('slug')->all())->toBe(['deep'])
        ->and($project->pluck('id')->all())->toContain($supportRoot->id)
        ->and($project->firstWhere('id', $hardware->id)?->children->pluck('id')->sort()->values()->all())->toBe([$printers->id, $supportOnly->id]);
});

it('loads only the root level when the tree is one level deep', function (): void {
    config()->set('tickets.categories.max_depth', 1);
    category('Root');

    expect(Tickets::categories()->tree()->first()?->relationLoaded('children'))->toBeFalse();
});

describe('advisory_locks_categories', function (): void {
    it('serialises every category write on the lock:categories advisory row', function (Closure $write): void {
        $root = category('Root');
        $child = category('Child', $root);
        RecordsLocks::start();

        $write($root, $child);

        expect(RecordsLocks::tables())->toContain('ticket_sequences')
            ->and(collect(LockRecorder::recorded())->first(static fn (array $lock): bool => str_contains($lock['sql'], 'ticket_sequences'))['transactionDepth'] ?? 0)->toBeGreaterThanOrEqual(1);
    })->with([
        'create' => [fn (TicketCategory $root) => category('Another', $root)],
        'update' => [fn (TicketCategory $root) => Tickets::categories()->update($root, new UpdateCategoryData(position: 2))],
        'move' => [fn (TicketCategory $root, TicketCategory $child) => Tickets::categories()->move($child, null)],
        'delete' => [fn (TicketCategory $root, TicketCategory $child) => Tickets::categories()->delete($child)],
        'restore' => [fn (TicketCategory $root, TicketCategory $child) => Tickets::categories()->restore($child)],
    ])->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');

    it('keeps one advisory row however often it is taken', function (): void {
        category('One');
        category('Two');

        expect(TicketSequence::query()->where('scope', 'lock:categories')->count())->toBe(1);
    });
});

it('keeps tickets pointing at a soft-deleted category through the FK', function (): void {
    $category = category('Hardware');
    $target = category('Other');
    $ticket = Ticket::factory()->create(['category_id' => $category->id]);
    $ticket->delete();

    Tickets::categories()->delete($category, $target);

    expect(Ticket::withTrashed()->find($ticket->id)?->category_id)->toBe($category->id);
});
