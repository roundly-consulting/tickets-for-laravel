<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Tickets\DataTransferObjects\CategoryData;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldData;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Exceptions\InvalidAssigneeException;
use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;
use RoundlyConsulting\Tickets\Exceptions\InvalidFieldException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

/**
 * The comparable shape of a stored row: everything but the keys, slugs and timestamps.
 *
 * @return array<string, mixed>
 */
function storedShape(TicketCategory|TicketField $model): array
{
    $attributes = $model->fresh()?->getAttributes() ?? [];

    unset($attributes['id'], $attributes['slug'], $attributes['key'], $attributes['created_at'], $attributes['updated_at']);

    return array_map(static fn (mixed $value): mixed => is_string($value) && json_validate($value) ? json_decode($value, true) : $value, $attributes);
}

describe('admin_builders_match_dtos', function (): void {
    it('builds the same category row as the DTO', function (): void {
        $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $project = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
        $parent = TicketCategory::factory()->inProject($project)->create();

        $built = Tickets::categories()->make(['en' => 'Hardware', 'sk' => 'Hardvér'])
            ->description(['en' => 'Laptops, printers, peripherals'])
            ->project($project)
            ->under($parent)
            ->position(10)
            ->defaults(priority: 'high', assignee: $agent, dueIn: 'P1D')
            ->create();

        $dto = Tickets::categories()->create(new CategoryData(
            name: ['en' => 'Hardware', 'sk' => 'Hardvér'],
            description: ['en' => 'Laptops, printers, peripherals'],
            parent: $parent,
            project: $project,
            position: 10,
            defaultPriority: 'high',
            defaultAssignee: $agent,
            defaultDueIn: 'P1D',
        ));

        expect(storedShape($built))->toBe(storedShape($dto))
            ->and($built->slug)->toBe('hardware')
            ->and($dto->slug)->not->toBe('hardware');
    });

    it('builds the same field row as the DTO', function (): void {
        $category = TicketCategory::factory()->create();

        $built = Tickets::fields()->make('asset_tag', FieldType::Text)
            ->label(['en' => 'Asset tag', 'sk' => 'Inventárne číslo'])
            ->help(['en' => 'On the sticker'])
            ->category($category)
            ->required()
            ->requesterEditable()
            ->rules(['max:32'])
            ->position(2)
            ->define();

        $built->forceFill(['key' => 'compared'])->save();

        $dto = Tickets::fields()->define(new FieldData(
            key: 'asset_tag',
            type: FieldType::Text,
            label: ['en' => 'Asset tag', 'sk' => 'Inventárne číslo'],
            help: ['en' => 'On the sticker'],
            rules: ['max:32'],
            required: true,
            requesterVisible: true,
            requesterEditable: true,
            position: 2,
            category: $category,
        ));

        expect(storedShape($built))->toBe(storedShape($dto));
    });

    it('builds agent-only select fields with options, defaults, encryption and inactive state', function (): void {
        $project = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);

        $field = Tickets::fields()->make('root_cause', FieldType::Select)
            ->options(['hardware' => ['en' => 'Hardware'], 'user' => 'User error'])
            ->agentOnly()
            ->project($project)
            ->default('hardware')
            ->encrypted()
            ->inactive()
            ->define();

        expect($field->label)->toBe('Root Cause')
            ->and($field->options)->toEqual([
                ['value' => 'hardware', 'label' => ['en' => 'Hardware']],
                ['value' => 'user', 'label' => ['en' => 'User error']],
            ])
            ->and($field->requester_visible)->toBeFalse()
            ->and($field->requester_editable)->toBeFalse()
            ->and($field->default)->toBe('hardware')
            ->and($field->encrypted)->toBeTrue()
            ->and($field->is_active)->toBeFalse()
            ->and($field->project_id)->toBe($project->id);
    });

    it('validates eagerly in the setters', function (Closure $build, string $exception): void {
        expect($build)->toThrow($exception);
    })->with([
        'bad field key' => [fn () => Tickets::fields()->make('Asset Tag', FieldType::Text), InvalidFieldException::class],
        'empty label' => [fn () => Tickets::fields()->make('asset', FieldType::Text)->label(''), InvalidFieldException::class],
        'select without options' => [fn () => Tickets::fields()->make('cause', FieldType::Select)->define(), InvalidFieldException::class],
        'empty option list' => [fn () => Tickets::fields()->make('cause', FieldType::Select)->options([]), InvalidFieldException::class],
        'options on a text field' => [fn () => Tickets::fields()->make('note', FieldType::Text)->options(['a' => 'A']), InvalidFieldException::class],
        'disallowed rule' => [fn () => Tickets::fields()->make('note', FieldType::Text)->rules(['exists:users,id']), InvalidFieldException::class],
        'default of another type' => [fn () => Tickets::fields()->make('seats', FieldType::Number)->default('many'), ValidationException::class],
        'default not an option' => [fn () => Tickets::fields()->make('plan', FieldType::Select)->options(['basic' => 'Basic'])->default('gold'), ValidationException::class],
        'options without the default' => [fn () => Tickets::fields()->make('plan', FieldType::Select)->default('gold')->options(['basic' => 'Basic']), ValidationException::class],
        'a rule the default breaks' => [fn () => Tickets::fields()->make('code', FieldType::Text)->default('TOO-LONG')->rules(['max:3']), ValidationException::class],
        'empty category name' => [fn () => Tickets::categories()->make(''), InvalidCategoryException::class],
        'bad due offset' => [fn () => Tickets::categories()->make('x')->defaults(dueIn: 'tomorrow'), InvalidCategoryException::class],
        'bad default priority' => [fn () => Tickets::categories()->make('x')->defaults(priority: 'critical'), InvalidCategoryException::class],
        'non-assignee default' => [fn () => Tickets::categories()->make('x')->defaults(assignee: Project::query()->create(['name' => 'p', 'key' => 'P'])), InvalidAssigneeException::class],
    ]);

    it('builds an inactive category and a re-activated one', function (): void {
        expect(Tickets::categories()->make('Archive')->inactive()->create()->is_active)->toBeFalse()
            ->and(Tickets::categories()->make('Live')->inactive()->active()->create()->is_active)->toBeTrue();
    });

    it('keeps visibility and editability consistent', function (): void {
        $hidden = Tickets::fields()->make('a', FieldType::Text)->requesterEditable()->requesterVisible(false)->data();
        $shown = Tickets::fields()->make('b', FieldType::Text)->agentOnly()->requesterEditable()->data();
        $readOnly = Tickets::fields()->make('c', FieldType::Text)->requesterEditable()->requesterEditable(false)->data();

        expect([$hidden->requesterVisible, $hidden->requesterEditable])->toBe([false, false])
            ->and([$shown->requesterVisible, $shown->requesterEditable])->toBe([true, true])
            ->and([$readOnly->requesterVisible, $readOnly->requesterEditable])->toBe([true, false]);
    });
});

describe('field_keys', function (): void {
    it('refuses a key a global field or the same project already uses', function (): void {
        $support = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
        $ops = Project::query()->create(['name' => 'Ops', 'key' => 'OPS']);
        $root = TicketCategory::factory()->create();
        $child = TicketCategory::factory()->under($root)->create();

        Tickets::fields()->make('asset_tag', FieldType::Text)->category($root)->define();
        Tickets::fields()->make('serial', FieldType::Text)->project($support)->define();
        Tickets::fields()->make('serial', FieldType::Text)->project($ops)->define();

        expect(fn () => Tickets::fields()->make('asset_tag', FieldType::Text)->category($child)->define())->toThrow(InvalidFieldException::class, 'asset_tag')
            ->and(fn () => Tickets::fields()->make('asset_tag', FieldType::Text)->project($support)->define())->toThrow(InvalidFieldException::class)
            ->and(fn () => Tickets::fields()->make('serial', FieldType::Text)->project($support)->define())->toThrow(InvalidFieldException::class)
            ->and(fn () => Tickets::fields()->make('serial', FieldType::Text)->define())->toThrow(InvalidFieldException::class);
    });

    it('scopes a field on a project category to that project', function (): void {
        $project = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
        $category = TicketCategory::factory()->inProject($project)->create();

        $field = Tickets::fields()->make('kiosk', FieldType::Boolean)->category($category)->define();

        expect($field->project_id)->toBe($project->id)
            ->and(fn () => Tickets::fields()->make('other', FieldType::Text)->category($category)->project(Project::query()->create(['name' => 'Ops', 'key' => 'OPS']))->define())
            ->toThrow(InvalidCategoryException::class);
    });
});
