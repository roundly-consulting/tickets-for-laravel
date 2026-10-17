<?php

declare(strict_types=1);

return [
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
        'not_an_assignee' => 'The model [:class] cannot be assigned tickets: it must implement the Assignee contract.',
        'ineligible' => 'The model [:class] is not eligible to be assigned this ticket.',
    ],

    'alias' => [
        'unknown' => 'The type [:alias] is not an allowed :kind type.',
        'missing' => 'No :alias with the key [:key] exists among the :kind.',
        'unknown_kind' => 'There is no alias list named [:kind].',
    ],

    'field' => [
        'invalid_key' => 'The field key [:key] must start with a letter and hold only lower-case letters, digits and underscores (at most 64).',
        'duplicate_key' => 'A field with the key [:key] already exists in this scope.',
        'missing_options' => 'The field [:key] is a choice field and needs at least one option.',
        'unexpected_options' => 'The field [:key] is not a choice field and cannot have options.',
        'rule_not_allowed' => 'The rule [:rule] of the field [:key] is not allowed.',
        'empty_label' => 'The field [:key] needs a label.',
    ],

    'guest' => [
        'unknown_field' => 'The guest field [:field] is not configured.',
        'identifying_required' => 'Give at least one way to reach you, such as an e-mail address or a phone number.',
        'taken' => 'Another guest already uses this :field.',
    ],

    'doctor' => [
        'lifecycle_ok' => 'The lifecycle [:class] satisfies the ticket contract and every automation.',
        'conveniences' => 'Named transition conveniences supported: :list.',
    ],
];
