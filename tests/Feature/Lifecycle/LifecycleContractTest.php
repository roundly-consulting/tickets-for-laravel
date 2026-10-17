<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLifecycleException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\LifecycleContract;
use RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles\BrokenLifecycle;
use RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles\HostLifecycle;
use RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles\InvalidLifecycle;
use RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles\StringStatesLifecycle;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\StatelessTicket;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

beforeEach(fn () => LifecycleContract::flush());

/**
 * The doctor's verdict, its checks narrowed to one section (null = every section).
 *
 * @return array{exit: int, output: array{ok: bool, checks: list<array{section: string, code: string, level: string, message: string}>}}
 */
function doctor(?string $section = 'lifecycle'): array
{
    $exit = Artisan::call('tickets:doctor', ['--json' => true]);

    /** @var array{ok: bool, checks: list<array{section: string, code: string, level: string, message: string}>} $output */
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    if ($section !== null) {
        $output['checks'] = array_values(array_filter($output['checks'], static fn (array $check): bool => $check['section'] === $section));
    }

    return ['exit' => $exit, 'output' => $output];
}

describe('swapped_lifecycle_is_validated', function (): void {
    it('passes the shipped lifecycle and lists every convenience', function (): void {
        $doctor = doctor();

        expect($doctor['exit'])->toBe(0)
            ->and($doctor['output']['ok'])->toBeTrue()
            ->and(array_column($doctor['output']['checks'], 'level', 'code'))->toBe(['lifecycle.ambiguous_target' => 'warning', 'contract' => 'ok', 'conveniences' => 'ok'])
            ->and($doctor['output']['checks'][2]['message'])->toContain('start, wait, hold, resume, resolve, reopen, close, dismiss');
    });

    it('passes a valid host lifecycle and warns that it supports no convenience', function (): void {
        config()->set('tickets.lifecycle', HostLifecycle::class);

        $doctor = doctor();

        expect($doctor['exit'])->toBe(0)
            ->and(array_column($doctor['output']['checks'], 'level', 'code'))->toBe(['contract' => 'ok', 'conveniences' => 'warning'])
            ->and($doctor['output']['checks'][1]['message'])->toContain('—');
    });

    it('reports every broken automation as an error and fails', function (): void {
        config()->set('tickets.lifecycle', BrokenLifecycle::class);

        $doctor = doctor();
        $codes = array_column($doctor['output']['checks'], 'code');

        expect($doctor['exit'])->toBe(1)
            ->and($doctor['output']['ok'])->toBeFalse()
            ->and($codes)->toContain('automation_unknown', 'automation_not_system', 'automation_source', 'automation_target')
            ->and(implode(' ', array_column($doctor['output']['checks'], 'message')))->toContain('[vanish]');
    });

    it('refuses to transition or automate under a broken lifecycle', function (): void {
        config()->set('tickets.lifecycle', BrokenLifecycle::class);
        $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $ticket = Tickets::make()->subject('a')->requester(User::query()->create())->asSystem()->create();

        expect(fn () => Tickets::for($ticket)->by($agent)->transition('open'))->toThrow(InvalidTicketLifecycleException::class, BrokenLifecycle::class)
            ->and(fn () => Tickets::make()->subject('b')->requester(User::query()->create())->assignee($agent)->create())
            ->toThrow(InvalidTicketLifecycleException::class);
    });

    it('reports states without status categories', function (): void {
        config()->set('tickets.lifecycle', StringStatesLifecycle::class);

        $doctor = doctor();

        expect($doctor['exit'])->toBe(1)
            ->and(array_column($doctor['output']['checks'], 'code'))->toBe(['state_not_ticket_state', 'state_not_ticket_state']);
    });

    it('reports a definition lifecycle itself refuses', function (): void {
        config()->set('tickets.lifecycle', InvalidLifecycle::class);

        $doctor = doctor();

        expect($doctor['exit'])->toBe(1)
            ->and($doctor['output']['checks'][0]['code'])->toStartWith('lifecycle.');
    });

    it('reports a lifecycle class that is not a ticket lifecycle', function (): void {
        config()->set('tickets.lifecycle', stdClass::class);

        $doctor = doctor();

        expect($doctor['exit'])->toBe(1)
            ->and($doctor['output']['checks'][0]['code'])->toBe('not_a_definition');
    });

    it('reports a ticket model that maps another attribute first', function (): void {
        config()->set('tickets.models.ticket', StatelessTicket::class);

        expect(array_column(doctor()['output']['checks'], 'code'))->toContain('not_status_attribute');
    });

    it('prints a readable report without --json', function (): void {
        config()->set('tickets.lifecycle', BrokenLifecycle::class);

        expect(Artisan::call('tickets:doctor'))->toBe(1)
            ->and(Artisan::output())->toContain('[ERROR] lifecycle:');

        config()->set('tickets.lifecycle', HostLifecycle::class);

        expect(Artisan::call('tickets:doctor'))->toBe(0)
            ->and(Artisan::output())->toContain('[OK] lifecycle:')->toContain('[WARNING] lifecycle:');
    });

    it('checks a valid lifecycle once per process', function (): void {
        LifecycleContract::assertValid();
        config()->set('tickets.models.ticket', StatelessTicket::class);

        // Cached: the broken model swap is not re-checked for the same definition class.
        LifecycleContract::assertValid();

        expect(Ticket::class)->toBeString();
    });
});
