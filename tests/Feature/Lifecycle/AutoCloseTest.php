<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Comments\Facades\Comments;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Handles\TicketHandle;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

function resolvedHandle(): TicketHandle
{
    $ticket = Tickets::make()->subject('a')->requester(User::query()->create())->asSystem()->create();

    return Tickets::for($ticket)->by(User::query()->create(['is_agent' => true]))->resolve();
}

afterEach(fn () => CarbonImmutable::setTestNow());

describe('auto_close', function (): void {
    it('closes a resolved ticket through lifecycle:sweep once the TTL passes', function (): void {
        CarbonImmutable::setTestNow('2030-01-01 12:00:00');
        $handle = resolvedHandle();

        expect($handle->ticket()->lifecycle('status')->expiresAt()?->toDateTimeString())->toBe('2030-01-08 12:00:00');

        CarbonImmutable::setTestNow('2030-01-08 12:00:01');
        Artisan::call('lifecycle:sweep');

        $ticket = $handle->ticket()->fresh();

        expect($ticket?->status)->toBe(TicketStatus::Closed)
            ->and($ticket?->closed_at?->toDateTimeString())->toBe('2030-01-08 12:00:01')
            ->and(Comments::isLocked($handle->ticket()))->toBeTrue()
            ->and($handle->ticket()->activities()->where('type', 'status_changed')->reorder('id', 'desc')->first()?->properties['transition'] ?? null)->toBe('close');
    });

    it('cancels the pending auto-close on a reopen', function (): void {
        CarbonImmutable::setTestNow('2030-01-01 12:00:00');
        $handle = resolvedHandle()->reopen();

        CarbonImmutable::setTestNow('2030-02-01 00:00:00');
        Artisan::call('lifecycle:sweep');

        expect($handle->ticket()->fresh()?->status)->toBe(TicketStatus::Open)
            ->and($handle->ticket()->lifecycle('status')->expiresAt())->toBeNull();
    });

    it('schedules nothing with auto-close off', function (): void {
        config()->set('tickets.auto_close.after', 'off');

        expect(resolvedHandle()->ticket()->lifecycle('status')->expiresAt())->toBeNull();
    });

    it('reads a shorter duration for later resolutions only', function (): void {
        CarbonImmutable::setTestNow('2030-01-01 12:00:00');
        $first = resolvedHandle();
        config()->set('tickets.auto_close.after', 'PT12H');
        $second = resolvedHandle();

        expect($first->ticket()->lifecycle('status')->expiresAt()?->toDateTimeString())->toBe('2030-01-08 12:00:00')
            ->and($second->ticket()->lifecycle('status')->expiresAt()?->toDateTimeString())->toBe('2030-01-02 00:00:00');
    });

    it('throws a config error for a junk duration, never lifecycle\'s invalid TTL', function (): void {
        config()->set('tickets.auto_close.after', '7 days');

        expect(fn () => resolvedHandle())
            ->toThrow(InvalidTicketsConfigurationException::class, '[tickets.auto_close.after]')
            ->and(fn () => resolvedHandle())->not->toThrow(InvalidLifecycleUsageException::class);
    });
});
