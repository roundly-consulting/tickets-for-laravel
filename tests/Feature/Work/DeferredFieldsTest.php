<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    defineAgents();
    Tickets::fields()->make('root_cause', FieldType::Text)->label('Root cause')->required()->agentOnly()->define();
    Tickets::fields()->make('asset_tag', FieldType::Text)->required()->requesterEditable()->define();
    Tickets::fields()->make('impact', FieldType::Select)->options(['low' => 'Low', 'high' => 'High'])->default('low')->define();
});

function deferredAgent(): User
{
    return User::query()->where('name', 'Grace')->first() ?? User::query()->create(['name' => 'Grace', 'is_agent' => true]);
}

function deferredRequester(): User
{
    return User::query()->where('name', 'Ada')->first() ?? User::query()->create(['name' => 'Ada']);
}

/**
 * @param  array<string, mixed>  $fields
 */
function deferredTicket(array $fields = [], ?User $by = null): Ticket
{
    $builder = Tickets::make()->subject('Printer jammed')->requester(deferredRequester())->fields($fields);

    return $by === null ? $builder->asSystem()->create() : $builder->by($by)->create();
}

/**
 * @return array<string, list<string>>
 */
function deferredErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('No ValidationException thrown.');
}

describe('defer_required_in', function (): void {
    it('defers nothing by default', function (): void {
        expect(config('tickets.fields.defer_required_in'))->toBe([])
            ->and(array_keys(deferredErrors(fn () => deferredTicket())))->toBe(['fields.root_cause', 'fields.asset_tag'])
            ->and(Ticket::query()->count())->toBe(0);

        $ticket = deferredTicket(['root_cause' => 'Toner', 'asset_tag' => 'PRN-1']);
        Tickets::for($ticket)->asSystem()->transition('open');

        expect($ticket->fresh()?->status)->toBe(TicketStatus::Open);
    });

    it('creates a ticket in a deferred state with its required fields empty', function (?string $by): void {
        config()->set('tickets.fields.defer_required_in', ['new']);
        $actor = match ($by) {
            'agent' => deferredAgent(),
            'requester' => deferredRequester(),
            default => null,
        };

        $ticket = deferredTicket(['asset_tag' => null], $actor);

        expect($ticket->status)->toBe(TicketStatus::New)
            ->and(Tickets::for($ticket)->fields()->all())->toBe(['impact' => 'low'])
            ->and(Tickets::for($ticket)->fields()->missing())->toBe(['root_cause', 'asset_tag']);
    })->with(['system' => [null], 'agent' => ['agent'], 'requester' => ['requester']]);

    it('still validates every value given in a deferred state', function (): void {
        config()->set('tickets.fields.defer_required_in', ['new']);

        expect(deferredErrors(fn () => deferredTicket(['impact' => 'urgent'])))->toHaveKey('fields.impact')
            ->and(Ticket::query()->count())->toBe(0);

        $ticket = deferredTicket();

        expect(deferredErrors(fn () => Tickets::for($ticket)->asSystem()->fields()->set('impact', 'urgent')))->toHaveKey('fields.impact');
    });

    it('lets agents save, clear and recategorise a draft without the required fields', function (): void {
        config()->set('tickets.fields.defer_required_in', ['new']);
        $hardware = Tickets::categories()->make('Hardware')->create();
        Tickets::fields()->make('serial', FieldType::Text)->category($hardware)->required()->define();
        $ticket = deferredTicket(['asset_tag' => 'PRN-1']);

        Tickets::for($ticket)->by(deferredAgent())->fields()->setMany(['impact' => 'high', 'asset_tag' => null]);
        Tickets::for($ticket)->by(deferredAgent())->categorize($hardware);

        expect(Tickets::for($ticket)->fields()->all())->toBe(['impact' => 'high'])
            ->and($ticket->fresh()?->category_id)->toBe($hardware->id)
            ->and(Tickets::for($ticket)->fields()->missing())->toBe(['root_cause', 'asset_tag', 'serial']);
    });

    it('refuses to leave a deferred state while a required field is empty — 422 fields.<key>, state kept', function (): void {
        config()->set('tickets.fields.defer_required_in', ['new']);
        $ticket = deferredTicket(['asset_tag' => 'PRN-1']);

        $exception = null;

        try {
            Tickets::for($ticket)->by(deferredAgent())->transition('open');
        } catch (ValidationException $caught) {
            $exception = $caught;
        }

        expect($exception)->toBeInstanceOf(ValidationException::class)
            ->and($exception?->status)->toBe(422)
            ->and($exception?->errors())->toBe(['fields.root_cause' => ['The Root cause field is required.']])
            ->and($ticket->status)->toBe(TicketStatus::New)
            ->and($ticket->fresh()?->status)->toBe(TicketStatus::New)
            ->and($ticket->activities()->where('type', ActivityType::StatusChanged->value)->count())->toBe(0);
    });

    it('leaves a deferred state once the required fields are filled', function (): void {
        config()->set('tickets.fields.defer_required_in', ['new']);
        $ticket = deferredTicket();

        Tickets::for($ticket)->by(deferredAgent())->fields()->setMany(['root_cause' => 'Toner', 'asset_tag' => 'PRN-1']);
        Tickets::for($ticket)->by(deferredAgent())->transition('open');

        expect($ticket->fresh()?->status)->toBe(TicketStatus::Open);
    });

    it('lets a requester leave the draft owing only the requester_editable fields', function (): void {
        config()->set('tickets.fields.defer_required_in', ['new']);
        $requester = deferredRequester();
        $ticket = deferredTicket(by: $requester);

        expect(deferredErrors(fn () => Tickets::for($ticket)->by($requester)->transition('open')))->toBe(['fields.asset_tag' => ['The Asset Tag field is required.']])
            ->and($ticket->fresh()?->status)->toBe(TicketStatus::New);

        Tickets::for($ticket)->by($requester)->fields()->set('asset_tag', 'PRN-1');

        // An agent leaving owes the agent-only field too; the requester does not.
        expect(array_keys(deferredErrors(fn () => Tickets::for($ticket)->by(deferredAgent())->transition('open'))))->toBe(['fields.root_cause']);

        Tickets::for($ticket)->by($requester)->transition('open');

        expect($ticket->fresh()?->status)->toBe(TicketStatus::Open);
    });

    it('moves between two deferred states without the required fields', function (): void {
        config()->set('tickets.fields.defer_required_in', ['new', 'on_hold']);
        $ticket = deferredTicket();

        Tickets::for($ticket)->by(deferredAgent())->transition('hold', 'Waiting for parts');

        expect($ticket->fresh()?->status)->toBe(TicketStatus::OnHold)
            ->and(array_keys(deferredErrors(fn () => Tickets::for($ticket)->asSystem()->transition('resume'))))->toBe(['fields.root_cause', 'fields.asset_tag']);
    });

    it('keeps a state that is not deferred as strict as before', function (): void {
        config()->set('tickets.fields.defer_required_in', ['pending']);

        expect(array_keys(deferredErrors(fn () => deferredTicket(['asset_tag' => 'PRN-1']))))->toBe(['fields.root_cause']);

        $ticket = deferredTicket(['root_cause' => 'Toner', 'asset_tag' => 'PRN-1']);

        expect(deferredErrors(fn () => Tickets::for($ticket)->by(deferredAgent())->fields()->set('asset_tag', null)))->toHaveKey('fields.asset_tag');

        // Entering a deferred state is never checked; leaving it is.
        Tickets::for($ticket)->by(deferredAgent())->transition('wait');
        Tickets::for($ticket)->by(deferredAgent())->fields()->set('asset_tag', null);

        expect(array_keys(deferredErrors(fn () => Tickets::for($ticket)->by(deferredAgent())->transition('start'))))->toBe(['fields.asset_tag'])
            ->and($ticket->fresh()?->status)->toBe(TicketStatus::Pending);
    });

    it('skips an automation that would leave a draft with required fields empty', function (): void {
        config()->set('tickets.fields.defer_required_in', ['new']);
        $agent = deferredAgent();

        $draft = Tickets::make()->subject('Draft')->requester(deferredRequester())->assignee($agent)->asSystem()->create();
        $filled = Tickets::make()->subject('Ready')->requester(deferredRequester())->assignee($agent)
            ->fields(['root_cause' => 'Toner', 'asset_tag' => 'PRN-1'])->asSystem()->create();

        expect($draft->fresh()?->status)->toBe(TicketStatus::New)
            ->and($filled->fresh()?->status)->toBe(TicketStatus::Open);
    });

    it('refuses a state the lifecycle does not declare', function (): void {
        config()->set('tickets.fields.defer_required_in', ['draft']);

        expect(fn () => deferredTicket())->toThrow(InvalidTicketsConfigurationException::class);
    });
});

describe('deferRequiredFields', function (): void {
    it('files one ticket with its required fields empty, without a draft state', function (): void {
        $ticket = Tickets::make()->subject('Wizard step 1')->requester(deferredRequester())->deferRequiredFields()->asSystem()->create();

        expect($ticket->status)->toBe(TicketStatus::New)
            ->and(Tickets::for($ticket)->fields()->all())->toBe(['impact' => 'low'])
            ->and(fn () => Tickets::make()->subject('x')->requester(deferredRequester())->deferRequiredFields()->fields(['impact' => 'urgent'])->asSystem()->create())
            ->toThrow(ValidationException::class)
            ->and(fn () => Tickets::make()->subject('x')->requester(deferredRequester())->deferRequiredFields()->deferRequiredFields(false)->asSystem()->create())
            ->toThrow(ValidationException::class, 'Root cause');
    });
});

describe('fields()->assertRequired()', function (): void {
    it('checks the required fields whatever the state, owed by the given actor', function (): void {
        config()->set('tickets.fields.defer_required_in', ['new']);
        $requester = deferredRequester();
        $agent = deferredAgent();
        $ticket = deferredTicket();
        $fields = Tickets::for($ticket)->fields();

        expect(array_keys(deferredErrors(fn () => $fields->assertRequired())))->toBe(['fields.root_cause', 'fields.asset_tag'])
            ->and(array_keys(deferredErrors(fn () => $fields->assertRequired($agent))))->toBe(['fields.root_cause', 'fields.asset_tag'])
            ->and(deferredErrors(fn () => $fields->assertRequired($requester)))->toBe(['fields.asset_tag' => ['The Asset Tag field is required.']])
            ->and(array_keys(deferredErrors(fn () => Tickets::for($ticket)->by($requester)->fields()->assertRequired())))->toBe(['fields.asset_tag'])
            ->and(array_keys(deferredErrors(fn () => Tickets::for($ticket)->by($requester)->fields()->assertRequired($agent))))->toBe(['fields.root_cause', 'fields.asset_tag'])
            ->and($fields->missing($requester))->toBe(['asset_tag']);

        $fields->set('asset_tag', 'PRN-1');

        expect($fields->assertRequired($requester))->toBe($fields)
            ->and($fields->missing())->toBe(['root_cause'])
            ->and(fn () => $fields->assertRequired())->toThrow(ValidationException::class, 'Root cause');

        $fields->set('root_cause', 'Toner');

        expect($fields->assertRequired()->missing())->toBe([]);
    });
});
