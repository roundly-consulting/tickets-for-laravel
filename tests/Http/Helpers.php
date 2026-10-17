<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

/**
 * Shared set-up of the HTTP suite: the host's abilities, and the people a helpdesk has.
 */
function apiAbilities(bool $assign = true, bool $manage = true): void
{
    defineAgents();

    if ($assign) {
        Gate::define('tickets.assign', static fn (User $user): bool => $user->is_agent && $user->name !== 'Junior');
    }

    if ($manage) {
        Gate::define('tickets.manage', static fn (User $user): bool => $user->name === 'Admin');
    }
}

function apiUser(string $name = 'Ada'): User
{
    return User::query()->create(['name' => $name]);
}

function apiAgent(string $name = 'Grace'): User
{
    return User::query()->create(['name' => $name, 'is_agent' => true]);
}

function apiAdmin(): User
{
    return User::query()->create(['name' => 'Admin', 'is_agent' => true]);
}

function apiTicket(?User $requester = null, string $subject = 'Printer jammed'): Ticket
{
    return Tickets::make()->subject($subject)->requester($requester ?? apiUser())->asSystem()->create();
}

/**
 * A signed URL with one query parameter changed after signing.
 */
function guestTampered(string $url, string $parameter, string $value): string
{
    return (string) preg_replace('/([?&]'.$parameter.'=)[^&]*/', '${1}'.$value, $url);
}
