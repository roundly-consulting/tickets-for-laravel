<?php

declare(strict_types=1);

use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Enums\TicketPriority;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLifecycleException;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Lifecycle\TicketLifecycle;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Fixtures\PlainLifecycle;

it('reads the shipped lifecycle and priority enum', function (): void {
    expect(TicketsConfig::lifecycle())->toBe(TicketLifecycle::class)
        ->and(TicketsConfig::priorityEnum())->toBe(TicketPriority::class);
});

it('falls back to the shipped classes when the keys are blank', function (): void {
    config()->set('tickets.lifecycle', '');
    config()->set('tickets.priority.enum', null);

    expect(TicketsConfig::lifecycle())->toBe(TicketLifecycle::class)
        ->and(TicketsConfig::priorityEnum())->toBe(TicketPriority::class);
});

it('refuses a lifecycle that is not a ticket lifecycle definition', function (mixed $value): void {
    config()->set('tickets.lifecycle', $value);

    TicketsConfig::lifecycle();
})->with([
    'not a class' => ['App\\Missing'],
    'not a definition' => [stdClass::class],
    'a definition without the contract' => [PlainLifecycle::class],
    'not a string' => [42],
])->throws(InvalidTicketLifecycleException::class);

it('refuses a priority enum that is not a backed Priority enum', function (mixed $value): void {
    config()->set('tickets.priority.enum', $value);

    TicketsConfig::priorityEnum();
})->with([
    'not a class' => ['App\\Missing'],
    'a class' => [stdClass::class],
    'an enum without the contract' => [LinkType::class],
    'not a string' => [true],
])->throws(InvalidTicketsConfigurationException::class);

it('names the key in the configuration error', function (): void {
    config()->set('tickets.priority.enum', stdClass::class);

    expect(fn () => TicketsConfig::priorityEnum())->toThrow(InvalidTicketsConfigurationException::class, '[tickets.priority.enum]');
});

it('resolves every model through the seam', function (): void {
    expect(TicketModels::ticket())->toBe(Ticket::class)
        ->and(TicketModels::category())->toBe(TicketCategory::class)
        ->and(TicketModels::field())->toBe(TicketField::class)
        ->and(TicketModels::tag())->toBe(TicketTag::class)
        ->and(TicketModels::guest())->toBe(TicketGuest::class);
});

it('refuses a swap that does not extend the packaged model', function (): void {
    config()->set('tickets.models.ticket', User::class);

    TicketModels::ticket();
})->throws(InvalidConfigurationException::class);

it('reads the conversation switches and the agent ability, blank as the default', function (): void {
    expect(TicketsConfig::agentAbility())->toBe('tickets.agent')
        ->and(TicketsConfig::autoApproveReplies())->toBeTrue()
        ->and(TicketsConfig::replyReopens())->toBeTrue()
        ->and(TicketsConfig::watchersCanView())->toBeTrue();

    config()->set('tickets.authorization.abilities.agent', '   ');
    config()->set('tickets.comments.auto_approve', 'off');

    expect(TicketsConfig::agentAbility())->toBe('tickets.agent')
        ->and(TicketsConfig::autoApproveReplies())->toBeFalse();
});
