<?php

declare(strict_types=1);

use RoundlyConsulting\Tickets\Assignment\CategoryDefaultStrategy;
use RoundlyConsulting\Tickets\Due\CategoryDueDateResolver;
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
        'default' => env('TICKETS_DEFAULT_PRIORITY', 'normal'),
    ],

    /*
    |--------------------------------------------------------------------------
    | References
    |--------------------------------------------------------------------------
    |
    | Tickets are numbered per sequence: `{prefix}-{number}` (TCK-42). A ticket
    | in a project uses the project's `ticketReferencePrefix()` and its own
    | sequence; every other ticket uses this prefix. A prefix is an upper-case
    | letter followed by up to nine upper-case letters or digits.
    |
    */

    'reference' => [
        'prefix' => env('TICKETS_REFERENCE_PREFIX', 'TCK'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    |
    | The intake channels a ticket may come in through, and the channel of a
    | ticket created without one.
    |
    */

    'channels' => ['web', 'email', 'api', 'phone', 'chat'],

    'default_channel' => 'api',

    /*
    |--------------------------------------------------------------------------
    | Defaults at creation
    |--------------------------------------------------------------------------
    |
    | A ticket created without an assignee asks the assignment strategy; one
    | created without a due date asks the due-date resolver. The defaults read
    | the nearest category that sets `default_assignee` / `default_due_in`.
    | Swap either with a class implementing Contracts\AssignmentStrategy /
    | Contracts\DueDateResolver.
    |
    */

    'assignment' => [
        'strategy' => CategoryDefaultStrategy::class,
    ],

    'due' => [
        'resolver' => CategoryDueDateResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Morph alias allow-lists
    |--------------------------------------------------------------------------
    |
    | API input names a requester, assignee or project as `{type: alias, id}`;
    | only these aliases are accepted, never a class name. The guest model is
    | always a requester (`guest`). Assignables must implement
    | Contracts\Assignee, projects Contracts\TicketProject.
    |
    */

    'requesters' => ['user' => 'App\\Models\\User'],

    'assignables' => ['user' => 'App\\Models\\User'],

    'projects' => [],

    /*
    |--------------------------------------------------------------------------
    | Guests
    |--------------------------------------------------------------------------
    |
    | A guest stores no fixed PII columns: every field is declared here and
    | stored through attributes-for-laravel. Exactly one field is the `display`
    | name. `identifying` fields find a returning guest (tried in this order)
    | and are unique among guests — they can never be `encrypted`. `route`
    | names the notification channel a field addresses; `normalize` (email,
    | phone) cleans a value before it is matched.
    |
    */

    'guests' => [
        'fields' => [
            'name' => ['type' => 'string', 'rules' => ['required', 'string', 'max:120'], 'display' => true],
            'email' => ['type' => 'string', 'rules' => ['nullable', 'email:rfc', 'max:191'], 'identifying' => true, 'route' => 'mail', 'normalize' => 'email'],
            'phone' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:32'], 'identifying' => true, 'route' => 'vonage', 'normalize' => 'phone', 'encrypted' => false],
        ],
        'require_one_identifying' => true,
        'update_on_resolve' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Categories and custom fields
    |--------------------------------------------------------------------------
    |
    | `max_depth` caps the category tree. `allowed_rules` lists the only
    | validation rules a custom field definition may carry — never a database
    | probe (`exists`, `unique`) or a closure.
    |
    */

    'categories' => [
        'max_depth' => 5,
    ],

    'fields' => [
        'allowed_rules' => ['min', 'max', 'between', 'regex', 'digits', 'digits_between', 'alpha', 'alpha_num', 'alpha_dash', 'starts_with', 'ends_with'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity timeline and actor
    |--------------------------------------------------------------------------
    |
    | Every change appends to the ticket's activity timeline unless switched
    | off. A call without an explicit actor is attributed to the authenticated
    | user of `guard` (blank = the default guard) when `from_auth` is on.
    |
    */

    'activity' => [
        'enabled' => env('TICKETS_ACTIVITY', 'on'),
    ],

    'actor' => [
        'from_auth' => env('TICKETS_ACTOR_FROM_AUTH', 'on'),
        'guard' => env('TICKETS_ACTOR_GUARD'),
    ],

];
