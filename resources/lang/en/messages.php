<?php

declare(strict_types=1);

return [
    'http' => [
        'invalid_date' => 'The :filter filter must be a date.',
        'invalid_value' => 'The :attribute must be a single value.',
        'invalid_key' => 'The :attribute must be a record key.',
        'invalid_translations' => 'The :attribute must be a text or a map of locale to text.',
        'forbidden' => 'You may not do that.',
    ],

    'config' => [
        'invalid' => 'The tickets setting [:key] must be :expected.',
    ],

    'lifecycle' => [
        'not_a_definition' => 'The ticket lifecycle [:class] must extend a lifecycle definition and implement the TicketLifecycle contract.',
        'broken' => 'The ticket lifecycle [:class] cannot be used: :problem',
        'unknown_transition' => 'The ticket lifecycle has no transition [:transition]. Use transition() with one of its own names.',
        'not_status_attribute' => 'The ticket model [:model] must map its lifecycle to the `status` attribute, first in lifecycleDefinitions().',
        'state_not_ticket_state' => 'The state [:state] does not implement the TicketState contract.',
        'automation_unknown' => 'The :automation automation names the transition [:transition], which the lifecycle does not have.',
        'automation_not_system' => 'The :automation automation\'s transition [:transition] must allow system context (allowSystem() or systemOnly()).',
        'automation_source' => 'The :automation automation\'s transition [:transition] must leave the state [:state].',
        'automation_target' => 'The :automation automation\'s transition [:transition] leads to [:state], a status category it may not enter.',
    ],

    'activity' => [
        'append_only' => 'Ticket activity is append-only: an entry cannot be changed or deleted.',
    ],

    'ticket' => [
        'not_found' => 'Ticket [:reference] was not found.',
        'trashed' => 'Ticket [:reference] is deleted. Restore it before changing it.',
        'duplicate_reference' => 'The reference [:reference] is already taken. Two projects must not share a reference prefix.',
        'invalid_subject' => 'The subject is required and may not be longer than 255 characters.',
        'unknown_channel' => 'The channel [:channel] is not one of the configured channels.',
        'unknown_priority' => 'The priority is not one of the configured priorities.',
        'requester_required' => 'A ticket needs a requester: a user or guest fields.',
        'closed' => 'Ticket [:reference] is closed and takes no more messages. Open a follow-up ticket instead.',
    ],

    'conversation' => [
        'not_a_participant' => 'Only the requester, an agent or a watcher may write on ticket [:reference].',
        'not_an_agent' => 'Only an agent may add an internal note to ticket [:reference].',
        'comment_not_found' => 'Comment [:comment] is not part of ticket [:reference].',
    ],

    'project' => [
        'not_a_project' => 'The project [:class] must implement the TicketProject contract.',
        'invalid_prefix' => 'The reference prefix [:prefix] must be an upper-case letter followed by up to nine upper-case letters or digits.',
    ],

    'category' => [
        'cycle' => 'A category cannot be moved under itself or one of its descendants.',
        'too_deep' => 'Categories may be nested at most :max levels deep.',
        'project_mismatch' => 'The category belongs to another project.',
        'inactive' => 'The category is inactive and cannot take new tickets.',
        'in_use' => 'The category still has tickets. Name a category to move them to.',
        'has_children' => 'The category still has subcategories. Move or delete them first.',
        'not_found' => 'Category [:key] was not found.',
        'invalid_due_in' => 'The due offset [:value] must be a positive ISO-8601 duration, such as P2D or PT4H.',
        'invalid_priority' => 'The default priority [:value] is not one of the configured priorities.',
        'empty_name' => 'A category needs a name.',
    ],

    'assignee' => [
        'no_actor' => 'Claiming a ticket needs an actor — call by() with the user who takes it.',
        'not_an_assignee' => 'The model [:class] cannot be assigned tickets: it must implement the Assignee contract.',
        'ineligible' => 'The model [:class] is not eligible to be assigned this ticket.',
    ],

    'alias' => [
        'unknown' => 'The type [:alias] is not an allowed :kind type.',
        'missing' => 'No :alias with the key [:key] exists among the :kind.',
        'unknown_kind' => 'There is no alias list named [:kind].',
    ],

    'field' => [
        'not_found' => 'Field [:id] was not found.',
        'invalid_key' => 'The field key [:key] must start with a letter and hold only lower-case letters, digits and underscores (at most 64).',
        'duplicate_key' => 'A field with the key [:key] already exists in this scope.',
        'kept_values' => 'A deleted field still keeps values under the key [:key]. Restore that field, or prune its values (tickets:prune-fields), before you define the key again.',
        'missing_options' => 'The field [:key] is a choice field and needs at least one option.',
        'unexpected_options' => 'The field [:key] is not a choice field and cannot have options.',
        'rule_not_allowed' => 'The rule [:rule] of the field [:key] is not allowed.',
        'empty_label' => 'The field [:key] needs a label.',
        'unknown' => 'The field [:key] is not a field of this ticket.',
        'not_editable' => 'The field [:key] can only be changed by an agent.',
        'type_locked' => 'The field [:key] already holds values, so its type cannot change.',
        'encryption_locked' => 'The field [:key] already holds values, so its encryption cannot change.',
        'options_in_use' => 'The options [:options] of the field [:key] are still in use.',
        'not_filterable' => 'Tickets cannot be filtered by the field [:key].',
        'not_filterable_encrypted' => 'The field [:key] is encrypted and cannot be filtered.',
    ],

    'guest' => [
        'link_invalid' => 'This link is invalid or has expired.',
        'not_a_recipient' => 'Guest links to ticket [:reference] go only to its guest requester or a guest watcher.',
        'unknown_field' => 'The guest field [:field] is not configured.',
        'identifying_required' => 'Give at least one way to reach you, such as an e-mail address or a phone number.',
        'taken' => 'Another guest already uses this :field.',
    ],

    'watcher' => [
        'no_actor' => 'Watching a ticket needs an actor — call by() with the user who watches it.',
    ],

    'tag' => [
        'not_found' => 'Tag [:id] was not found.',
        'invalid_name' => 'A tag needs a name of at most 64 characters.',
        'invalid_color' => 'The tag colour [:color] must be a hex colour such as #f24e29.',
        'duplicate' => 'A tag named [:name] already exists here.',
        'unknown' => 'There is no tag named [:name], and new tags cannot be created on the fly.',
        'project_mismatch' => 'The tag [:name] belongs to another project.',
    ],

    'link' => [
        'self' => 'A ticket cannot be linked to itself.',
        'cycle' => 'That :type link would close a cycle.',
        'second_parent' => 'Ticket [:reference] already has a parent.',
        'cross_project' => 'Tickets of different projects cannot be linked.',
        'too_deep' => 'The link chain is deeper than :max levels and cannot be checked for cycles.',
        'not_linked' => 'There is no :type link with ticket [:reference] here.',
        'not_found' => 'Link [:link] is not a link of ticket [:reference].',
    ],

    'merge' => [
        'same' => 'A ticket cannot be merged into itself.',
        'source_closed' => 'Ticket [:reference] is closed and cannot be merged.',
        'source_merged' => 'Ticket [:reference] was already merged.',
        'target_closed' => 'Ticket [:reference] is closed and cannot take a merge.',
        'target_merged' => 'Ticket [:reference] was merged into [:final]. Merge into that ticket instead.',
        'cross_project' => 'Tickets of different projects cannot be merged.',
        'unauthorized' => 'You may not merge tickets.',
        'note_on_target' => 'Ticket :source was merged into this ticket.',
        'reply_on_source' => 'This ticket was merged into :target.',
    ],

    'attachment' => [
        'not_found' => 'Attachment [:media] is not part of ticket [:reference].',
    ],

    'doctor' => [
        'policy_ok' => 'The ticket policy is [:class].',
        'routes_off' => 'The HTTP API is off (tickets.routes.enabled).',
        'routes_on' => 'The HTTP API is on, behind authentication.',
        'routes_unguarded' => 'The HTTP API is on, but tickets.routes.middleware holds no auth middleware: every endpoint would answer an anonymous caller. Add auth (or auth:sanctum).',
        'guest_links_unavailable' => 'The default guest-link generator needs the guest routes (tickets.routes.enabled and tickets.guests.enabled): no guest link can be issued. Switch them on, or bind your own Contracts\\GuestLinkGenerator.',
        'lifecycle_ok' => 'The lifecycle [:class] satisfies the ticket contract and every automation.',
        'conveniences' => 'Named transition conveniences supported: :list.',
        'meta_missing' => 'The comments table has no `meta` column. Publish and run the comments-for-laravel 1.1 migrations.',
        'meta_ok' => 'The comments table carries the `meta` column replies and notes are written with.',
        'comments_authorization' => 'comments.authorization is on: comments checks the authenticated user on every ticket reply, note, approval and lock. Let your CommentPolicy allow ticket commentables, or guest replies, system notes and the auto-close lock will be refused.',
        'attachments_public' => 'Ticket attachments go to the [:disk] disk, which is served on the web: a private file is reachable under /storage without a signature. Set tickets.attachments.disk to a private disk such as local.',
        'attachments_ok' => 'Ticket attachments go to the private [:disk] disk.',
        'keys_ok' => 'Every key type lines up: :type.',
        'key_invalid' => 'The setting [:key] is not a key type (bigint, uuid or ulid).',
        'key_mismatch' => ':ours is :type, but :theirs is :other: they must be the same, or the columns and lookups will not match.',
        'user_model_mismatch' => 'Your user model [:class] is keyed by :model, but tickets.key_type is :type: requesters, assignees and actors would not be stored right.',
        'priority_ok' => 'Priorities come from [:class]; a new ticket defaults to :default.',
        'priority_unknown' => '{1} :count ticket carries a priority [:class] no longer has: it reads as no priority and sorts last.|[0,*] :count tickets carry a priority [:class] no longer has: they read as no priority and sort last.',
        'abilities_ok' => 'The abilities :list are defined.',
        'abilities_undefined' => 'The abilities :list are not defined. An undefined ability denies, so nobody holds it — unless a Gate::before (such as a permissions package) grants it. Define them with Gate::define().',
        'alias_contract' => 'In :key, the alias [:alias] names [:class], which does not implement :contract.',
        'aliases_ok' => 'Every alias list names usable models.',
        'guest_fields_ok' => 'Guest fields: :list.',
        'temporary_urls_stream' => 'Attachment download URLs are the disk\'s own temporary URLs, or media-library\'s signed stream route.',
        'temporary_urls_native' => 'Attachment download URLs: the disks :list mint temporary URLs of their own.',
        'temporary_urls_missing' => 'media.stream.enabled is off and the disks :list mint no temporary URLs of their own: every attachment URL the API renders would throw. Switch the stream route on, or keep attachments on a disk that presigns (such as s3).',
        'guest_links_notified' => 'Guests are notified (tickets.notifications), but the default guest-link generator needs the guest routes (tickets.routes.enabled and tickets.guests.enabled): guest mails would carry no link. Switch them on, bind your own Contracts\\GuestLinkGenerator, or set tickets.notifications.guests off.',
        'notifications_on' => 'Notifications are on: :list.',
        'notifications_off' => 'Notifications are off (tickets.notifications.enabled).',
        'notification_types' => 'Every notification type is usable; switched off: :list.',
        'schedule' => 'Schedule tickets:sweep every five minutes (overdue and due soon) and lifecycle:sweep every minute (auto-close).',
    ],

    'sweep' => [
        'overdue' => '{1} :count ticket became overdue.|[0,*] :count tickets became overdue.',
        'due_soon' => '{1} :count ticket is due soon.|[0,*] :count tickets are due soon.',
    ],

    'prune' => [
        'pruned' => '{1} Forgot :count orphaned field value.|[0,*] Forgot :count orphaned field values.',
        'dry_run' => '{1} :count orphaned field value would be forgotten.|[0,*] :count orphaned field values would be forgotten.',
        'invalid_chunk' => 'The --chunk option must be a whole number from 1 to 999999.',
    ],

    'install' => [
        'published' => 'Published :tag',
        'key_primary' => 'tickets and guests',
        'key_outbound' => 'requesters, assignees and actors',
        'key_lifecycle' => 'lifecycle subjects',
        'key_lifecycle_actor' => 'lifecycle actors',
        'key_comments' => 'comment authors and subjects',
        'key_attributes' => 'attribute owners',
        'key_types' => 'Set every key type above to the same value (bigint, uuid or ulid) before running the migrations — the columns cannot change afterwards.',
        'next' => 'Next steps',
        'abilities' => 'Define who is an agent (and the tickets.assign and tickets.manage abilities):',
        'schedule' => 'Schedule the sweeps (routes/console.php):',
        'routes' => 'The JSON API is off: set TICKETS_ROUTES=on to serve it, and php artisan vendor:publish --tag=tickets-routes to customise its routes.',
        'guests' => 'The doctor reports guest_links until guest mails can carry a link: set TICKETS_ROUTES=on, bind your own Contracts\GuestLinkGenerator, or set TICKETS_NOTIFY_GUESTS=off.',
    ],
];
