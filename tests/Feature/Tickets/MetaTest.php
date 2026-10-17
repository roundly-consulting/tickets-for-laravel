<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Tickets\DataTransferObjects\CreateTicketData;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateTicketData;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

describe('host metadata', function (): void {
    it('files a ticket with metadata, merging the builder\'s calls, and reads it by path', function (): void {
        $ticket = Tickets::make()->subject('Printer jammed')->requester(User::query()->create(['name' => 'Ada']))
            ->meta(['crm' => ['id' => 'C-1'], 'source' => 'kiosk'])->meta(['source' => 'web', 'campaign' => null])
            ->asSystem()->create();
        $stored = $ticket->fresh() ?? throw new RuntimeException('gone');

        expect(keySorted($stored->meta()))->toBe(['campaign' => null, 'crm' => ['id' => 'C-1'], 'source' => 'web'])
            ->and($stored->meta('crm.id'))->toBe('C-1')
            ->and($stored->meta('crm.region', 'eu'))->toBe('eu')
            ->and($stored->hasMeta('campaign'))->toBeTrue()
            ->and($stored->hasMeta('crm.region'))->toBeFalse()
            ->and(Tickets::create(new CreateTicketData('Plain', User::query()->create()))->fresh()?->meta())->toBe([])
            ->and(Tickets::create(new CreateTicketData('Plain', User::query()->create()))->getRawOriginal('meta'))->toBeNull();
    });

    it('leaves the public updated_at alone when only the metadata changes', function (): void {
        CarbonImmutable::setTestNow('2030-01-01 12:00:00');
        $ticket = Tickets::make()->subject('Printer jammed')->requester(User::query()->create(['name' => 'Ada']))->asSystem()->create();

        CarbonImmutable::setTestNow('2030-01-01 13:00:00');
        Tickets::for($ticket)->asSystem()->update(new UpdateTicketData(meta: ['crm' => 42]));

        expect($ticket->updated_at?->toDateTimeString())->toBe('2030-01-01 12:00:00')
            ->and($ticket->fresh()?->updated_at?->toDateTimeString())->toBe('2030-01-01 12:00:00')
            ->and($ticket->fresh()?->meta('crm'))->toBe(42)
            ->and($ticket->isDirty())->toBeFalse();

        // A public change moves it, metadata along or not.
        CarbonImmutable::setTestNow('2030-01-01 14:00:00');
        Tickets::for($ticket)->asSystem()->update(new UpdateTicketData(subject: 'Printer still jammed', meta: ['crm' => 43]));

        expect($ticket->fresh()?->updated_at?->toDateTimeString())->toBe('2030-01-01 14:00:00')
            ->and($ticket->fresh()?->meta('crm'))->toBe(43);
    })->after(fn () => CarbonImmutable::setTestNow());

    it('replaces and clears it, recording the key in an agent-only entry and never a value', function (): void {
        $ticket = Tickets::make()->subject('Printer jammed')->requester(User::query()->create(['name' => 'Ada']))
            ->meta(['crm' => ['id' => 'C-1', 'region' => 'eu']])->asSystem()->create();

        Tickets::for($ticket)->asSystem()->update(new UpdateTicketData(meta: ['crm' => ['region' => 'eu', 'id' => 'C-1']]));

        expect($ticket->activities()->where('type', ActivityType::Updated->value)->count())->toBe(0);

        Tickets::for($ticket)->asSystem()->update(new UpdateTicketData(subject: 'Printer jammed again', meta: ['crm' => ['id' => 'C-2']]));

        expect($ticket->fresh()?->meta())->toBe(['crm' => ['id' => 'C-2']]);

        Tickets::for($ticket)->asSystem()->update(new UpdateTicketData(meta: []));

        $entries = $ticket->activities()->where('type', ActivityType::Updated->value)->get();

        expect($ticket->fresh()?->meta())->toBe([])
            ->and($ticket->getRawOriginal('meta'))->toBeNull()
            ->and($entries->map(static fn (TicketActivity $entry): array => [$entry->visibility, $entry->properties])->all())->toBe([
                [ActivityVisibility::Public, ['changed' => ['subject']]],
                [ActivityVisibility::Internal, ['changed' => ['meta']]],
                [ActivityVisibility::Internal, ['changed' => ['meta']]],
            ])
            ->and(json_encode($entries->pluck('properties')->all()))->not->toContain('C-2');
    });
});
