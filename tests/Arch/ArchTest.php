<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Tickets\Enums\NotificationType;
use RoundlyConsulting\Tickets\Lifecycle\TicketLifecycle;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Policies\TicketPolicy;
use RoundlyConsulting\Tickets\TicketsManager;

$src = __DIR__.'/../../src';

ArchPresets::strictTypes('RoundlyConsulting\Tickets');

/**
 * The deliberate extension points, each pinned elsewhere:
 *
 *  - the five config-swappable models (pinned non-final below);
 *  - TicketsManager, the facade root TicketsFake extends — an injected manager must still
 *    type-check under Tickets::fake();
 *  - TicketLifecycle, which a host extends to adjust one rule of the shipped statuses;
 *  - TicketPolicy, which `tickets.authorization.policy` swaps for a subclass;
 *  - the notification classes, which `tickets.notifications.types` swaps for subclasses (a
 *    replacement must extend the packaged class — `is_a`, pinned in NotificationsTest).
 */
ArchPresets::finalByDefault('RoundlyConsulting\Tickets', [
    Ticket::class,
    TicketCategory::class,
    TicketField::class,
    TicketTag::class,
    TicketGuest::class,
    TicketsManager::class,
    TicketLifecycle::class,
    TicketPolicy::class,
    ...array_map(static fn (NotificationType $type): string => $type->defaultClass(), NotificationType::cases()),
]);

ArchPresets::swappableModelsAreNotFinal([
    Ticket::class => 'tickets.models.ticket',
    TicketCategory::class => 'tickets.models.category',
    TicketField::class => 'tickets.models.field',
    TicketTag::class => 'tickets.models.tag',
    TicketGuest::class => 'tickets.models.guest',
]);

ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Tickets');

ArchPresets::modelsResolveThroughSeam($src, 'Support', [
    'tickets.models.ticket',
    'tickets.models.category',
    'tickets.models.field',
    'tickets.models.tag',
    'tickets.models.guest',
]);

ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../../database/migrations');

/**
 * The Dependency Policy as a test. No `alsoAllow`: `require` holds only php, illuminate and
 * roundly packages. If this goes red the graph is wrong — never widen the list to quiet it.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../../composer.json');

ArchPresets::noDebuggingLeftovers(srcDir: $src);

ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Tickets');
