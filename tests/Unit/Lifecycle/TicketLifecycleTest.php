<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Tickets\Enums\Automation;
use RoundlyConsulting\Tickets\Lifecycle\TicketLifecycle;

it('maps every automation to a shipped transition', function (): void {
    $lifecycle = new TicketLifecycle;

    expect(array_map(static fn (Automation $hook): ?string => $lifecycle->automation($hook), Automation::cases()))
        ->toBe(['open', 'resume', 'reopen', 'close', 'dismiss']);
});

it('declares the nine transitions under their constant names', function (): void {
    $definition = app(DefinitionRegistry::class)->get(TicketLifecycle::class);

    foreach ([
        TicketLifecycle::OPEN, TicketLifecycle::START, TicketLifecycle::WAIT, TicketLifecycle::HOLD,
        TicketLifecycle::RESUME, TicketLifecycle::RESOLVE, TicketLifecycle::REOPEN, TicketLifecycle::CLOSE,
        TicketLifecycle::DISMISS,
    ] as $name) {
        expect($definition->hasTransition($name))->toBeTrue($name);
    }
});
