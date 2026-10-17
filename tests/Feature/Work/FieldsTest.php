<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Attributes\Models\Attribute;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateFieldData;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Events\TicketFieldsChanged;
use RoundlyConsulting\Tickets\Exceptions\FieldNotEditableException;
use RoundlyConsulting\Tickets\Exceptions\FieldTypeLockedException;
use RoundlyConsulting\Tickets\Exceptions\InvalidFieldException;
use RoundlyConsulting\Tickets\Exceptions\UnknownTicketFieldException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

beforeEach(fn () => defineAgents());

function fieldTicket(?TicketCategory $category = null, ?Project $project = null, array $fields = [], ?User $requester = null, bool $system = true): Ticket
{
    $builder = Tickets::make()->subject('Monitor flickers')->requester($requester ?? User::query()->create(['name' => 'Ada']))
        ->category($category)->project($project)->fields($fields);

    return $system ? $builder->asSystem()->create() : $builder->by($requester)->create();
}

function fieldAgent(): User
{
    return User::query()->where('name', 'Grace')->first() ?? User::query()->create(['name' => 'Grace', 'is_agent' => true]);
}

/**
 * @return list<string>
 */
function fieldKeys(Ticket|TicketCategory $subject): array
{
    return array_keys(Tickets::fields()->for($subject));
}

describe('custom_field_rules', function (): void {
    it('applies global, project, ancestor and own-category fields — and nothing else', function (): void {
        $sup = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
        $fac = Project::query()->create(['name' => 'Facilities', 'key' => 'FAC']);
        $hardware = Tickets::categories()->make('Hardware')->project($sup)->create();
        $monitors = Tickets::categories()->make('Monitors')->under($hardware)->create();
        $software = Tickets::categories()->make('Software')->project($sup)->create();
        Tickets::fields()->make('impact', FieldType::Select)->options(['low' => 'Low', 'high' => 'High'])->position(1)->define();
        Tickets::fields()->make('asset_tag', FieldType::Text)->category($hardware)->position(2)->define();
        Tickets::fields()->make('panel_size', FieldType::Number)->category($monitors)->position(3)->define();
        Tickets::fields()->make('sla_tier', FieldType::Text)->project($sup)->position(4)->define();
        Tickets::fields()->make('license', FieldType::Text)->category($software)->define();
        Tickets::fields()->make('building', FieldType::Text)->project($fac)->define();
        Tickets::fields()->make('legacy', FieldType::Text)->inactive()->define();
        Tickets::fields()->delete(Tickets::fields()->make('gone', FieldType::Text)->define());

        $ticket = fieldTicket($monitors, $sup);

        expect(fieldKeys($ticket))->toBe(['impact', 'asset_tag', 'panel_size', 'sla_tier'])
            ->and(fieldKeys($hardware))->toBe(['impact', 'asset_tag', 'sla_tier'])
            ->and(fieldKeys(fieldTicket()))->toBe(['impact'])
            ->and(array_keys($ticket->attributeDefinitions()))->toBe(['impact', 'asset_tag', 'panel_size', 'sla_tier'])
            ->and(Tickets::for($ticket)->fields()->definitions()['panel_size']->type)->toBe(FieldType::Number);
    });

    it('refuses a key that is no field of the ticket, whatever attributes\' strict mode says', function (bool $strict): void {
        config()->set('attributes.strict', $strict);
        $software = Tickets::categories()->make('Software')->create();
        Tickets::fields()->make('license', FieldType::Text)->category($software)->define();
        $ticket = fieldTicket();

        expect(fn () => Tickets::for($ticket)->asSystem()->fields()->set('license', 'X-1'))->toThrow(FieldNotEditableException::class, 'license')
            ->and(fn () => Tickets::for($ticket)->asSystem()->fields()->set('nope', 'x'))->toThrow(FieldNotEditableException::class, 'nope')
            ->and(Attribute::query()->count())->toBe(0);
    })->with(['strict off' => false, 'strict on' => true]);

    it('requires a required field at creation and fills defaults', function (): void {
        Tickets::fields()->make('asset_tag', FieldType::Text)->required()->define();
        Tickets::fields()->make('impact', FieldType::Select)->options(['low' => 'Low', 'high' => 'High'])->default('low')->define();

        expect(fn () => fieldTicket())->toThrow(ValidationException::class)
            ->and(Ticket::query()->count())->toBe(0);

        try {
            fieldTicket();
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(['fields.asset_tag']);
        }

        $ticket = fieldTicket(fields: ['asset_tag' => 'PRN-0042']);

        expect(Tickets::for($ticket)->fields()->all())->toBe(['asset_tag' => 'PRN-0042', 'impact' => 'low']);
    });

    it('refuses a category change that brings a required field without a value', function (): void {
        $hardware = Tickets::categories()->make('Hardware')->create();
        $network = Tickets::categories()->make('Network')->create();
        Tickets::fields()->make('asset_tag', FieldType::Text)->category($hardware)->required()->define();
        $ticket = fieldTicket($network);

        expect(fn () => Tickets::for($ticket)->asSystem()->categorize($hardware))->toThrow(ValidationException::class)
            ->and($ticket->fresh()?->category_id)->toBe($network->id);

        $other = fieldTicket($network);
        Ticket::query()->whereKey($other->id)->update(['category_id' => $hardware->id]);
        Tickets::for($other->fresh() ?? $other)->asSystem()->fields()->set('asset_tag', 'PRN-1');
        Ticket::query()->whereKey($other->id)->update(['category_id' => $network->id]);

        expect(Tickets::for($other->fresh() ?? $other)->asSystem()->categorize($hardware)->ticket()->category_id)->toBe($hardware->id);
    });

    it('locks the type and encryption of a field that holds values', function (): void {
        $field = Tickets::fields()->make('impact', FieldType::Select)->options(['low' => 'Low', 'high' => 'High'])->define();
        $unused = Tickets::fields()->make('notes_ref', FieldType::Select)->options(['a' => 'A'])->define();
        Tickets::for(fieldTicket())->asSystem()->fields()->set('impact', 'high');

        expect(fn () => Tickets::fields()->update($field, new UpdateFieldData(type: FieldType::Text)))->toThrow(FieldTypeLockedException::class, 'type')
            ->and(fn () => Tickets::fields()->update($field, new UpdateFieldData(encrypted: true)))->toThrow(FieldTypeLockedException::class, 'encryption')
            ->and(Tickets::fields()->update($unused, new UpdateFieldData(type: FieldType::Text))->options)->toBeNull()
            ->and(Tickets::fields()->update($unused, new UpdateFieldData(type: FieldType::Multiselect, options: ['x' => 'X']))->type)->toBe(FieldType::Multiselect);
    });

    it('lets the options of a field in use only grow', function (): void {
        $field = Tickets::fields()->make('impact', FieldType::Select)->options(['low' => 'Low', 'mid' => 'Mid', 'high' => 'High'])->define();
        Tickets::for(fieldTicket())->asSystem()->fields()->set('impact', 'high');

        $grown = Tickets::fields()->update($field, new UpdateFieldData(options: ['low' => 'Low', 'high' => 'High', 'critical' => 'Critical']));

        expect(array_column($grown->options ?? [], 'value'))->toBe(['low', 'high', 'critical'])
            ->and(fn () => Tickets::fields()->update($field, new UpdateFieldData(options: ['low' => 'Low'])))->toThrow(FieldTypeLockedException::class, 'high');
    });

    it('keeps every option of a multiselect in use', function (): void {
        $field = Tickets::fields()->make('areas', FieldType::Multiselect)->options(['a' => 'A', 'b' => 'B'])->define();
        Tickets::for(fieldTicket())->asSystem()->fields()->set('areas', ['a']);

        Tickets::fields()->update($field, new UpdateFieldData(options: ['a' => 'A']));
    })->throws(FieldTypeLockedException::class, 'b');

    it('lets a requester set only requester-editable fields', function (): void {
        Tickets::fields()->make('contact_time', FieldType::Text)->requesterEditable()->define();
        Tickets::fields()->make('root_cause', FieldType::Text)->agentOnly()->define();
        $ada = User::query()->create(['name' => 'Ada']);
        $ticket = fieldTicket(requester: $ada);

        Tickets::for($ticket)->by($ada)->fields()->set('contact_time', 'mornings');
        Tickets::for($ticket)->by(fieldAgent())->fields()->set('root_cause', 'cable');

        expect(fn () => Tickets::for($ticket)->by($ada)->fields()->set('root_cause', 'user'))->toThrow(FieldNotEditableException::class, 'agent')
            ->and(fn () => fieldTicket(requester: $ada, fields: ['root_cause' => 'x'], system: false))->toThrow(FieldNotEditableException::class)
            ->and(Tickets::for($ticket)->fields()->all())->toBe(['contact_time' => 'mornings', 'root_cause' => 'cable']);
    });

    it('validates each value against its field, keyed fields.<key>', function (string $key, mixed $value): void {
        Tickets::fields()->make('email', FieldType::Email)->define();
        Tickets::fields()->make('count', FieldType::Number)->define();
        Tickets::fields()->make('ratio', FieldType::Decimal)->define();
        Tickets::fields()->make('urgent', FieldType::Boolean)->define();
        Tickets::fields()->make('seen_on', FieldType::Date)->define();
        Tickets::fields()->make('impact', FieldType::Select)->options(['low' => 'Low'])->define();
        Tickets::fields()->make('areas', FieldType::Multiselect)->options(['a' => 'A'])->define();
        Tickets::fields()->make('code', FieldType::Text)->rules(['max:5'])->define();

        try {
            Tickets::for(fieldTicket())->asSystem()->fields()->set($key, $value);
            $this->fail('No validation error');
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toContain(str_starts_with(array_key_first($exception->errors()) ?? '', 'fields.areas.') ? 'fields.areas.0' : 'fields.'.$key);
        }
    })->with([
        'email' => ['email', 'not-an-email'],
        'number' => ['count', 'abc'],
        'decimal' => ['ratio', 'abc'],
        'boolean' => ['urgent', 'perhaps'],
        'date' => ['seen_on', 'yesterday-ish'],
        'select' => ['impact', 'huge'],
        'multiselect item' => ['areas', ['z']],
        'multiselect shape' => ['areas', 'a'],
        'own rule' => ['code', 'TOO-LONG'],
    ]);

    it('reads, clears and forgets values', function (): void {
        Tickets::fields()->make('asset_tag', FieldType::Text)->define();
        Tickets::fields()->make('impact', FieldType::Select)->options(['low' => 'Low'])->required()->define();
        $ticket = fieldTicket(fields: ['impact' => 'low']);
        $fields = Tickets::for($ticket)->asSystem()->fields();

        $fields->setMany(['asset_tag' => 'PRN-1']);

        expect($fields->get('asset_tag'))->toBe('PRN-1')
            ->and($fields->get('unknown'))->toBeNull()
            ->and($fields->forget('asset_tag')->all())->toBe(['impact' => 'low'])
            ->and($fields->set('asset_tag', 'PRN-2')->set('asset_tag', null)->all())->toBe(['impact' => 'low'])
            ->and(fn () => $fields->forget(['impact']))->toThrow(ValidationException::class)
            ->and($fields->ticket())->toBe($ticket);
    });

    it('records the keys it changed — never a value', function (): void {
        Event::fake([TicketFieldsChanged::class]);
        Tickets::fields()->make('asset_tag', FieldType::Text)->define();
        $ticket = fieldTicket();

        Tickets::for($ticket)->asSystem()->fields()->set('asset_tag', 'PRN-0042');

        expect($ticket->activities()->where('type', ActivityType::FieldsChanged->value)->sole()->properties)->toBe(['keys' => ['asset_tag']]);
        Event::assertDispatched(TicketFieldsChanged::class, static fn (TicketFieldsChanged $event): bool => $event->keys === ['asset_tag']);
    });

    it('hides the values of a deleted field until it is restored, and prunes orphans on demand', function (): void {
        $field = Tickets::fields()->make('asset_tag', FieldType::Text)->define();
        $inactive = Tickets::fields()->make('legacy_code', FieldType::Text)->define();
        $ticket = fieldTicket(fields: ['asset_tag' => 'PRN-1', 'legacy_code' => 'L-1']);
        Tickets::fields()->update($inactive, new UpdateFieldData(active: false));

        Tickets::fields()->delete($field);

        expect(Tickets::for($ticket)->fields()->all())->toBe([])
            ->and(Tickets::for($ticket)->fields()->get('asset_tag'))->toBeNull();

        Tickets::fields()->restore($field);

        expect(Tickets::for($ticket)->fields()->all())->toBe(['asset_tag' => 'PRN-1']);

        Tickets::fields()->delete($field);

        expect(Tickets::fields()->prune(dryRun: true))->toBe(1)
            ->and(Attribute::query()->where('name', 'asset_tag')->count())->toBe(1)
            ->and(Tickets::fields()->prune())->toBe(1)
            ->and(Attribute::withTrashed()->where('name', 'asset_tag')->count())->toBe(0)
            ->and(Attribute::query()->where('name', 'legacy_code')->count())->toBe(1)
            ->and(Tickets::fields()->prune())->toBe(0);
    });

    it('refuses to restore a field whose key was taken meanwhile', function (): void {
        $field = Tickets::fields()->make('asset_tag', FieldType::Text)->define();
        Tickets::fields()->delete($field);
        Tickets::fields()->make('asset_tag', FieldType::Number)->define();

        Tickets::fields()->restore($field);
    })->throws(InvalidFieldException::class, 'asset_tag');

    it('updates a field definition', function (): void {
        $field = Tickets::fields()->make('asset_tag', FieldType::Text)->define();

        $updated = Tickets::fields()->update($field, new UpdateFieldData(
            label: ['en' => 'Asset', 'sk' => 'Majetok'],
            help: 'On the sticker',
            rules: ['max:12'],
            required: true,
            default: 'PRN-0',
            requesterVisible: false,
            requesterEditable: true,
            position: 4,
        ));

        expect($updated->getTranslations('label'))->toBe(['en' => 'Asset', 'sk' => 'Majetok'])
            ->and($updated->rules)->toBe(['max:12'])
            ->and($updated->required)->toBeTrue()
            ->and($updated->default)->toBe('PRN-0')
            ->and($updated->requester_visible)->toBeFalse()
            ->and($updated->requester_editable)->toBeTrue()
            ->and($updated->position)->toBe(4)
            ->and(Tickets::fields()->update($field, new UpdateFieldData(rules: []))->rules)->toBeNull();
    });
});

describe('field_keys_and_filters', function (): void {
    it('refuses a category field that redefines an ancestor\'s or a global key', function (): void {
        $hardware = Tickets::categories()->make('Hardware')->create();
        $printers = Tickets::categories()->make('Printers')->under($hardware)->create();
        Tickets::fields()->make('asset_tag', FieldType::Text)->category($hardware)->define();
        Tickets::fields()->make('impact', FieldType::Text)->define();

        expect(fn () => Tickets::fields()->make('asset_tag', FieldType::Number)->category($printers)->define())->toThrow(InvalidFieldException::class, 'asset_tag')
            ->and(fn () => Tickets::fields()->make('impact', FieldType::Number)->category($printers)->define())->toThrow(InvalidFieldException::class, 'impact');
    });

    it('casts a filter to the field type, so a request string matches typed rows', function (): void {
        $monitors = Tickets::categories()->make('Monitors')->create();
        Tickets::fields()->make('panel_size', FieldType::Number)->category($monitors)->define();
        Tickets::fields()->make('urgent', FieldType::Boolean)->category($monitors)->define();
        Tickets::fields()->make('seen_on', FieldType::Date)->category($monitors)->define();
        Tickets::fields()->make('ratio', FieldType::Decimal)->category($monitors)->define();
        Tickets::fields()->make('impact', FieldType::Select)->options(['low' => 'Low', 'high' => 'High'])->define();
        $small = fieldTicket($monitors, fields: ['panel_size' => 24, 'urgent' => false, 'seen_on' => '2030-01-05', 'ratio' => 1.5, 'impact' => 'low']);
        $large = fieldTicket($monitors, fields: ['panel_size' => 42, 'urgent' => true, 'seen_on' => '2030-03-01', 'ratio' => 2.25, 'impact' => 'high']);

        $ids = static fn (Closure $scope): array => $scope(Ticket::query())->orderBy('id')->pluck('id')->all();

        expect($ids(fn ($query) => $query->whereField('panel_size', '42')))->toBe([$large->id])
            ->and($ids(fn ($query) => $query->whereField('panel_size', 42)))->toBe([$large->id])
            ->and($ids(fn ($query) => $query->whereField('panel_size', 'abc')))->toBe([])
            ->and($ids(fn ($query) => $query->whereField('urgent', 'true')))->toBe([$large->id])
            ->and($ids(fn ($query) => $query->whereField('impact', 'low')))->toBe([$small->id])
            ->and($ids(fn ($query) => $query->whereField('seen_on', '2030-01-05')))->toBe([$small->id])
            ->and($ids(fn ($query) => $query->whereFieldBetween('panel_size', '30', null)))->toBe([$large->id])
            ->and($ids(fn ($query) => $query->whereFieldBetween('panel_size', null, '30')))->toBe([$small->id])
            ->and($ids(fn ($query) => $query->whereFieldBetween('panel_size', 20, 50)))->toBe([$small->id, $large->id])
            ->and($ids(fn ($query) => $query->whereFieldBetween('ratio', '2', null)))->toBe([$large->id])
            ->and($ids(fn ($query) => $query->whereFieldBetween('ratio', null, 2)))->toBe([$small->id])
            ->and($ids(fn ($query) => $query->whereFieldBetween('seen_on', '2030-02-01', null)))->toBe([$large->id])
            ->and($ids(fn ($query) => $query->whereFieldBetween('seen_on', null, '2030-02-01')))->toBe([$small->id])
            ->and($ids(fn ($query) => $query->whereFieldBetween('panel_size', 'abc', null)))->toBe([]);
    });

    it('matches a key defined with different types in different projects', function (): void {
        $sup = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
        $fac = Project::query()->create(['name' => 'Facilities', 'key' => 'FAC']);
        Tickets::fields()->make('level', FieldType::Number)->project($sup)->define();
        Tickets::fields()->make('level', FieldType::Text)->project($fac)->define();
        $numbered = fieldTicket(project: $sup, fields: ['level' => 3]);
        $texted = fieldTicket(project: $fac, fields: ['level' => '3']);

        expect(Ticket::query()->whereField('level', '3')->orderBy('id')->pluck('id')->all())->toBe([$numbered->id, $texted->id]);
    });

    it('refuses to filter on an unknown, multiselect or (for a range) text field', function (Closure $filter, string $message): void {
        Tickets::fields()->make('areas', FieldType::Multiselect)->options(['a' => 'A'])->define();
        Tickets::fields()->make('asset_tag', FieldType::Text)->define();

        expect(fn () => $filter(Ticket::query())->get())->toThrow(UnknownTicketFieldException::class, $message);
    })->with([
        'unknown' => [fn ($query) => $query->whereField('nope', 'x'), 'nope'],
        'multiselect' => [fn ($query) => $query->whereField('areas', 'a'), 'areas'],
        'text range' => [fn ($query) => $query->whereFieldBetween('asset_tag', 'a', 'z'), 'asset_tag'],
    ]);
});

describe('encrypted_fields_stay_opaque', function (): void {
    it('stores an encrypted value as ciphertext, records only its key, and never filters on it', function (): void {
        Event::fake([TicketFieldsChanged::class]);
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        Tickets::fields()->make('serial', FieldType::Text)->encrypted()->define();
        $ticket = fieldTicket();

        Tickets::for($ticket)->asSystem()->fields()->set('serial', 'SN-SECRET-123');

        $stored = Attribute::query()->where('name', 'serial')->sole();

        expect($stored->is_encrypted)->toBeTrue()
            ->and($stored->getRawOriginal('value'))->not->toContain('SN-SECRET-123')
            ->and(Tickets::for($ticket)->fields()->get('serial'))->toBe('SN-SECRET-123')
            ->and(json_encode($ticket->activities()->pluck('properties')->all()))->not->toContain('SN-SECRET')
            ->and(fn () => Ticket::query()->whereField('serial', 'SN-SECRET-123')->get())->toThrow(UnknownTicketFieldException::class, 'encrypted');

        Event::assertDispatched(TicketFieldsChanged::class, static fn (TicketFieldsChanged $event): bool => ! str_contains((string) json_encode($event->keys), 'SN-SECRET'));
    });
});

describe('field_rules_are_allow_listed', function (): void {
    it('takes allow-listed rules and enforces them', function (): void {
        Tickets::fields()->make('code', FieldType::Text)->rules(['min:3', 'max:8', 'regex:/^[A-Z]{3}-\d+$/'])->define();
        $ticket = fieldTicket();

        Tickets::for($ticket)->asSystem()->fields()->set('code', 'PRN-42');

        expect(Tickets::for($ticket)->fields()->get('code'))->toBe('PRN-42')
            ->and(fn () => Tickets::for($ticket)->asSystem()->fields()->set('code', 'prn-42'))->toThrow(ValidationException::class);
    });

    it('refuses any other rule — a database probe even when a host allow-lists it', function (mixed $rule): void {
        config()->set('tickets.fields.allowed_rules', ['min', 'max', 'regex', 'exists', 'unique']);

        expect(fn () => Tickets::fields()->make('code', FieldType::Text)->rules([$rule])->define())->toThrow(InvalidFieldException::class, 'not allowed')
            ->and(TicketField::query()->count())->toBe(0);
    })->with([
        'not allow-listed' => ['required_if:other,1'],
        'exists' => ['exists:users,id'],
        'unique' => ['unique:users,email'],
        'a rule object' => [new In(['a'])],
        'a regex too long' => ['regex:/'.str_repeat('a', 300).'/'],
        'a regex that does not compile' => ['regex:/(/'],
        'a regex without delimiters' => ['regex:abc'],
        'an empty regex' => ['regex:'],
    ]);
});

describe('field edges', function (): void {
    it('checks values only within a project field\'s own project', function (): void {
        $sup = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
        $field = Tickets::fields()->make('level', FieldType::Number)->project($sup)->define();
        Tickets::for(fieldTicket(project: $sup))->asSystem()->fields()->set('level', 2);

        Tickets::fields()->update($field, new UpdateFieldData(type: FieldType::Decimal));
    })->throws(FieldTypeLockedException::class);

    it('carries the options over when a choice field changes its choice type', function (): void {
        $field = Tickets::fields()->make('impact', FieldType::Select)->options(['low' => ['en' => 'Low']])->define();

        $updated = Tickets::fields()->update($field, new UpdateFieldData(type: FieldType::Multiselect));

        expect($updated->options)->toBe([['value' => 'low', 'label' => ['en' => 'Low']]]);
    });

    it('lets a field in use keep all its options', function (): void {
        $field = Tickets::fields()->make('impact', FieldType::Select)->options(['low' => 'Low'])->define();
        Tickets::for(fieldTicket())->asSystem()->fields()->set('impact', 'low');

        expect(Tickets::fields()->update($field, new UpdateFieldData(options: ['low' => 'Lowest']))->options[0]['label'] ?? null)->toBe(['en' => 'Lowest']);
    });

    it('casts date filters from dates, and matches nothing for a blank or broken one', function (): void {
        Tickets::fields()->make('seen_on', FieldType::Date)->define();
        $ticket = fieldTicket(fields: ['seen_on' => '2030-01-05']);

        expect(Ticket::query()->whereField('seen_on', CarbonImmutable::parse('2030-01-05'))->pluck('id')->all())->toBe([$ticket->id])
            ->and(Ticket::query()->whereField('seen_on', '')->count())->toBe(0)
            ->and(Ticket::query()->whereField('seen_on', 'not a date ###')->count())->toBe(0)
            ->and(Tickets::for($ticket)->fields()->ticket())->toBe($ticket);
    });
});
