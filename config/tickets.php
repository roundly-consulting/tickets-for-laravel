<?php

declare(strict_types=1);

use RoundlyConsulting\Tickets\Enums\TicketPriority;
use RoundlyConsulting\Tickets\Lifecycle\TicketLifecycle;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketTag;

return [

    /*
    |--------------------------------------------------------------------------
    | Key types
    |--------------------------------------------------------------------------
    |
    | Set both BEFORE running the migrations: they pick the column types.
    |
    | `primary_key_type` keys the ticket and the guest — the models other
    | packages point at (lifecycle subject, commentable, attribute and media
    | owner). It must equal `lifecycle.key_type`, `attributes.key_type` and
    | `comments.key_type`.
    |
    | `key_type` keys the morphs tickets point OUT at — requester, assignee,
    | project, watcher, actor. It must equal your user model's key type and
    | `lifecycle.actor_key_type`. Because `comments.key_type` covers both the
    | comment author (a user) and the commentable (a ticket), all three must be
    | the same in one installation.
    |
    | Supported: bigint, uuid, ulid.
    |
    */

    'primary_key_type' => env('TICKETS_PRIMARY_KEY_TYPE', 'bigint'),

    'key_type' => env('TICKETS_KEY_TYPE', 'bigint'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Swap a model by extending the packaged class and naming the subclass here.
    |
    */

    'models' => [
        'ticket' => Ticket::class,
        'category' => TicketCategory::class,
        'field' => TicketField::class,
        'tag' => TicketTag::class,
        'guest' => TicketGuest::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lifecycle
    |--------------------------------------------------------------------------
    |
    | The lifecycle-for-laravel definition of the ticket statuses. A swap must
    | implement Contracts\TicketLifecycle, and its states enum
    | Contracts\TicketState.
    |
    */

    'lifecycle' => TicketLifecycle::class,

    /*
    |--------------------------------------------------------------------------
    | Priority
    |--------------------------------------------------------------------------
    |
    | `enum` is a string-backed enum implementing Contracts\Priority.
    |
    */

    'priority' => [
        'enum' => TicketPriority::class,
    ],

];
