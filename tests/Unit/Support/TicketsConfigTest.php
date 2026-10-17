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
use RoundlyConsulting\Tickets\Policies\TicketPolicy;
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

it('reads the work-management settings, blank as the default', function (): void {
    expect(TicketsConfig::mergeAbility())->toBe('tickets.agent')
        ->and(TicketsConfig::autoWatch('requester'))->toBeTrue()
        ->and(TicketsConfig::autoWatch('mentioned'))->toBeFalse()
        ->and(TicketsConfig::freeFormTags())->toBeTrue()
        ->and(TicketsConfig::crossProjectLinks())->toBeTrue()
        ->and(TicketsConfig::maxLinkDepth())->toBe(50)
        ->and(TicketsConfig::crossProjectMerge())->toBeFalse()
        ->and(TicketsConfig::mergeMovesAttachments())->toBeTrue()
        ->and(TicketsConfig::attachmentsBucket())->toBe('attachments')
        ->and(TicketsConfig::attachmentsDisk())->toBe('local')
        ->and(TicketsConfig::attachmentMimes())->toContain('application/pdf')
        ->and(TicketsConfig::attachmentMaxKilobytes())->toBe(10240);
});

it('falls back to the shipped mime allow-list when a published config drops the key, never to any type', function (): void {
    $shipped = TicketsConfig::attachmentMimes();

    // mergeConfigFrom fills only missing top-level keys: a published `attachments` array without
    // `mimes` stays without it.
    config()->set('tickets.attachments', ['bucket' => 'attachments', 'disk' => 'local', 'max_kb' => 10240]);

    expect(TicketsConfig::attachmentMimes())->toBe($shipped)
        ->and($shipped)->toBe((require __DIR__.'/../../../config/tickets.php')['attachments']['mimes']);

    // An explicit empty list is still the documented "any type".
    config()->set('tickets.attachments.mimes', []);

    expect(TicketsConfig::attachmentMimes())->toBe([]);
});

it('refuses a broken work-management setting, naming the key', function (string $key, mixed $value, Closure $read): void {
    config()->set($key, $value);

    expect($read)->toThrow(InvalidTicketsConfigurationException::class, $key);
})->with([
    'auto-watch flag' => ['tickets.watchers.auto.requester', 'maybe', fn () => TicketsConfig::autoWatch('requester')],
    'bucket' => ['tickets.attachments.bucket', 'Bad Bucket', fn () => TicketsConfig::attachmentsBucket()],
    'mime' => ['tickets.attachments.mimes', ['not a mime'], fn () => TicketsConfig::attachmentMimes()],
    'max kb' => ['tickets.attachments.max_kb', 0, fn () => TicketsConfig::attachmentMaxKilobytes()],
    'link depth' => ['tickets.links.max_depth', 0, fn () => TicketsConfig::maxLinkDepth()],
]);

it('refuses an auto-watch trigger it does not know', function (): void {
    TicketsConfig::autoWatch('everyone');
})->throws(InvalidTicketsConfigurationException::class, 'tickets.watchers.auto.everyone');

it('reads the authorization, guest and route settings, blank as the default', function (): void {
    foreach (['register_policy', 'policy', 'abilities.assign', 'abilities.manage', 'participants_can_transition', 'self_service', 'requester_can_edit'] as $key) {
        config()->set('tickets.authorization.'.$key, '');
    }

    foreach (['enabled', 'link_ttl', 'revoke_on_close'] as $key) {
        config()->set('tickets.guests.'.$key, '');
    }

    foreach (['prefix', 'max_per_page', 'rate_limit', 'guest.rate_limit', 'bulk.max'] as $key) {
        config()->set('tickets.routes.'.$key, '');
    }

    config()->set('tickets.authorization.requester_transitions', null);

    expect(TicketsConfig::registerPolicy())->toBeTrue()
        ->and(TicketsConfig::policy())->toBe(TicketPolicy::class)
        ->and(TicketsConfig::assignAbility())->toBe('tickets.assign')
        ->and(TicketsConfig::manageAbility())->toBe('tickets.manage')
        ->and(TicketsConfig::requesterTransitions())->toBe(['close', 'reopen'])
        ->and(TicketsConfig::participantsCanTransition())->toBeFalse()
        ->and(TicketsConfig::selfService())->toBeTrue()
        ->and(TicketsConfig::requesterCanEdit())->toBeTrue()
        ->and(TicketsConfig::guestsEnabled())->toBeTrue()
        ->and(TicketsConfig::guestLinkTtl()->spec())->toBe('P30D')
        ->and(TicketsConfig::revokeGuestsOnClose())->toBeFalse()
        ->and(TicketsConfig::routePrefix())->toBe('api')
        ->and(TicketsConfig::routeMiddleware())->toBe(['api', 'auth', 'throttle:tickets-api'])
        ->and(TicketsConfig::guestRouteMiddleware())->toBe(['api', 'throttle:tickets-guest'])
        ->and(TicketsConfig::maxPerPage())->toBe(100)
        ->and(TicketsConfig::apiRateLimit())->toBe(120)
        ->and(TicketsConfig::guestRateLimit())->toBe(30)
        ->and(TicketsConfig::bulkMax())->toBe(100);
});

it('reads requester transitions from a list or a comma string, and [] as none', function (mixed $value, array $expected): void {
    config()->set('tickets.authorization.requester_transitions', $value);

    expect(TicketsConfig::requesterTransitions())->toBe($expected);
})->with([
    'a list' => [['close', ' reopen ', 'close'], ['close', 'reopen']],
    'a comma string' => ['close, reopen', ['close', 'reopen']],
    'a blank string' => ['', []],
    'none' => [[], []],
]);

it('refuses a broken authorization, guest or route setting, naming the key', function (string $key, mixed $value, Closure $read): void {
    config()->set($key, $value);

    expect($read)->toThrow(InvalidTicketsConfigurationException::class, '['.$key.']');
})->with([
    'a policy outside the package policy' => ['tickets.authorization.policy', stdClass::class, fn () => TicketsConfig::policy()],
    'requester transitions not a list' => ['tickets.authorization.requester_transitions', 42, fn () => TicketsConfig::requesterTransitions()],
    'a requester transition that is no name' => ['tickets.authorization.requester_transitions', ['Close!'], fn () => TicketsConfig::requesterTransitions()],
    'a link ttl that is no duration' => ['tickets.guests.link_ttl', '30 days', fn () => TicketsConfig::guestLinkTtl()],
    'a prefix with a space' => ['tickets.routes.prefix', 'my api', fn () => TicketsConfig::routePrefix()],
    'a page size too big' => ['tickets.routes.max_per_page', 5000, fn () => TicketsConfig::maxPerPage()],
    'a bulk size of zero' => ['tickets.routes.bulk.max', 0, fn () => TicketsConfig::bulkMax()],
    'a junk switch' => ['tickets.guests.enabled', 'maybe', fn () => TicketsConfig::guestsEnabled()],
]);
