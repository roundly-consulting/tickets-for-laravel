<?php

declare(strict_types=1);

use RoundlyConsulting\Tickets\Assignment\CategoryDefaultStrategy;
use RoundlyConsulting\Tickets\Due\CategoryDueDateResolver;
use RoundlyConsulting\Tickets\Enums\TicketPriority;
use RoundlyConsulting\Tickets\Guests\SignedRouteLinkGenerator;
use RoundlyConsulting\Tickets\Lifecycle\TicketLifecycle;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Policies\TicketPolicy;

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
    | Auto-close
    |--------------------------------------------------------------------------
    |
    | How long a resolved ticket waits before it is closed: an ISO-8601
    | duration (P7D, PT12H), or `off` to never close automatically. Read when a
    | ticket is resolved. Needs `lifecycle:sweep` on the scheduler (every
    | minute) — the shipped lifecycle closes through its Resolved state's TTL.
    |
    */

    'auto_close' => [
        'after' => env('TICKETS_AUTO_CLOSE_AFTER', 'P7D'),
    ],

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
    | `tickets:sweep` (schedule it every five minutes) flags each unresolved
    | ticket past its due date once (TicketOverdue), and once more when it
    | comes within `soon_window` of it (TicketDueSoon) — an ISO-8601 duration,
    | or `off`. Moving a due date re-arms both.
    |
    */

    'assignment' => [
        'strategy' => CategoryDefaultStrategy::class,
    ],

    'due' => [
        'resolver' => CategoryDueDateResolver::class,
        'soon_window' => env('TICKETS_DUE_SOON_WINDOW', 'PT4H'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Morph alias allow-lists
    |--------------------------------------------------------------------------
    |
    | API input names a requester, assignee, watcher or project as
    | `{type: alias, id}`; only these aliases are accepted, never a class
    | name. The guest model is always a requester (`guest`). Assignables must
    | implement Contracts\Assignee, projects Contracts\TicketProject.
    | `watchables` null = every requester and assignable alias.
    |
    */

    'requesters' => ['user' => 'App\\Models\\User'],

    'assignables' => ['user' => 'App\\Models\\User'],

    'watchables' => null,

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
    | `enabled` serves the guest endpoints (with the routes on): a guest opens
    | a ticket through a temporary signed link, valid for `link_ttl` (an
    | ISO-8601 duration) until it is revoked — `revoke_on_close` revokes every
    | link when the ticket closes. `link_generator` builds the links; bind
    | your own Contracts\GuestLinkGenerator for a separate frontend.
    |
    */

    'guests' => [
        'enabled' => env('TICKETS_GUESTS', 'on'),
        'fields' => [
            'name' => ['type' => 'string', 'rules' => ['required', 'string', 'max:120'], 'display' => true],
            'email' => ['type' => 'string', 'rules' => ['nullable', 'email:rfc', 'max:191'], 'identifying' => true, 'route' => 'mail', 'normalize' => 'email'],
            'phone' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:32'], 'identifying' => true, 'route' => 'vonage', 'normalize' => 'phone', 'encrypted' => false],
        ],
        'require_one_identifying' => true,
        'update_on_resolve' => false,
        'link_ttl' => env('TICKETS_GUEST_LINK_TTL', 'P30D'),
        'revoke_on_close' => env('TICKETS_GUESTS_REVOKE_ON_CLOSE', 'off'),
        'link_generator' => SignedRouteLinkGenerator::class,
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
    | Conversation
    |--------------------------------------------------------------------------
    |
    | Replies and internal notes are comments-for-laravel comments. When the
    | host's `comments.require_approval` leaves a ticket reply pending,
    | `auto_approve` approves it at once — never a reply the host's blocklist
    | held. `reply_reopens`: a requester's reply reopens a resolved ticket.
    |
    */

    'comments' => [
        'auto_approve' => env('TICKETS_COMMENTS_AUTO_APPROVE', 'on'),
        'reply_reopens' => env('TICKETS_REPLY_REOPENS', 'on'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Watchers
    |--------------------------------------------------------------------------
    |
    | `can_view`: a watcher who is not an agent (a CC'd colleague, a guest)
    | may see the ticket and its public thread, and reply as a participant.
    | `auto`: who starts watching on their own — the requester on creation,
    | the assignee on assignment, an agent who writes on the ticket, and
    | (off by default) someone mentioned who may read the comment.
    |
    */

    'watchers' => [
        'can_view' => env('TICKETS_WATCHERS_CAN_VIEW', 'on'),
        'auto' => [
            'requester' => env('TICKETS_AUTO_WATCH_REQUESTER', 'on'),
            'assignee' => env('TICKETS_AUTO_WATCH_ASSIGNEE', 'on'),
            'agent_repliers' => env('TICKETS_AUTO_WATCH_AGENT_REPLIERS', 'on'),
            'mentioned' => env('TICKETS_AUTO_WATCH_MENTIONED', 'off'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | The Gate abilities the package asks about. Define them in your app
    | (`Gate::define('tickets.agent', fn ($user) => $user->is_support)`) or
    | through a permissions package. An undefined ability denies. `agent`
    | makes a user an agent; `assign` lets one assign others; `merge` lets an
    | actor merge tickets; `manage` deletes tickets and runs the categories,
    | fields and tags.
    |
    | `policy` (registered for the ticket model with `register_policy`; off =
    | register your own with Gate::policy()) must extend Policies\TicketPolicy. `agent_scope` (a Contracts\AgentScope; null
    | = agents see every ticket) narrows what an agent may work on. A requester
    | may run the `requester_transitions` on their own ticket (a watcher too,
    | with `participants_can_transition`). `self_service`: anyone signed in may
    | file a ticket; `requester_can_edit`: the requester may edit the subject
    | and description of an unresolved ticket.
    |
    */

    'authorization' => [
        'register_policy' => env('TICKETS_REGISTER_POLICY', 'on'),
        'policy' => TicketPolicy::class,
        'abilities' => [
            'agent' => 'tickets.agent',
            'assign' => 'tickets.assign',
            'merge' => 'tickets.agent',
            'manage' => 'tickets.manage',
        ],
        'agent_scope' => null,
        'requester_transitions' => ['close', 'reopen'],
        'participants_can_transition' => env('TICKETS_PARTICIPANTS_CAN_TRANSITION', 'off'),
        'self_service' => env('TICKETS_SELF_SERVICE', 'on'),
        'requester_can_edit' => env('TICKETS_REQUESTER_CAN_EDIT', 'on'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tags, links and merging
    |--------------------------------------------------------------------------
    |
    | `tags.free_form`: an unknown tag name is created on the fly; off = only
    | existing tags attach. `links.cross_project` allows links between tickets
    | of different projects; `links.max_depth` bounds the cycle check of
    | `blocks` and `parent_of`. `merge.cross_project` allows merging across
    | projects; `merge.move_attachments` moves the source's attachments.
    |
    */

    'tags' => [
        'free_form' => env('TICKETS_TAGS_FREE_FORM', 'on'),
    ],

    'links' => [
        'cross_project' => env('TICKETS_LINKS_CROSS_PROJECT', 'on'),
        'max_depth' => 50,
    ],

    'merge' => [
        'cross_project' => env('TICKETS_MERGE_CROSS_PROJECT', 'off'),
        'move_attachments' => env('TICKETS_MERGE_MOVE_ATTACHMENTS', 'on'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Files on the ticket itself (reply and note attachments live on their
    | comment). The bucket is private and its `disk` must NOT be web-served:
    | media-library's default `public` disk is reachable under /storage
    | without a signature. Laravel's `local` disk (storage/app/private) is
    | not. `mimes`: the accepted mime types ([] = any); `max_kb`: the size cap.
    |
    */

    'attachments' => [
        'bucket' => 'attachments',
        'disk' => env('TICKETS_ATTACHMENTS_DISK', 'local'),
        'mimes' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'text/plain', 'text/csv',
            'application/zip',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
        'max_kb' => 10240,
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

    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    |
    | The JSON API is off until you switch it on. `middleware` guards the
    | agent and requester endpoints (swap `auth` for `auth:sanctum` with
    | Sanctum); the `tickets-api` limiter allows `rate_limit` requests a
    | minute per user. The guest endpoints check the signed link themselves
    | and are limited per IP and ticket (`guest.rate_limit`). A list returns at
    | most `max_per_page` tickets; a bulk call takes at most `bulk.max`.
    |
    */

    'routes' => [
        // Blank means off: the route switch reads a blank value as on.
        'enabled' => env('TICKETS_ROUTES') ?: 'off',
        'prefix' => env('TICKETS_ROUTES_PREFIX', 'api'),
        'middleware' => ['api', 'auth', 'throttle:tickets-api'],
        'max_per_page' => 100,
        'rate_limit' => 120,
        'guest' => [
            'middleware' => ['api', 'throttle:tickets-guest'],
            'rate_limit' => 30,
        ],
        'bulk' => [
            'max' => 100,
        ],
    ],

];
