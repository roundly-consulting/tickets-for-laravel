<?php

declare(strict_types=1);

return [
    'greeting' => 'Hello :name,',
    'greeting_anonymous' => 'Hello,',
    'action' => 'Open ticket :reference',
    'someone' => 'Someone',
    'internal' => 'This is internal: the requester does not see it.',

    'created' => [
        'subject' => '[:reference] We received your request: :subject',
        'line' => 'Thank you — your request “:subject” has been received as ticket :reference.',
        'follow_up' => 'Please mention :reference whenever you contact us about it.',
    ],

    'assigned' => [
        'subject' => '[:reference] Assigned: :subject',
        'line' => 'Ticket :reference — “:subject” — was assigned to :assignee.',
        'priority' => 'Priority: :priority.',
    ],

    'replied' => [
        'subject' => '[:reference] New reply: :subject',
        'line' => 'There is a new reply on your ticket :reference — “:subject”:',
    ],

    'requester_replied' => [
        'subject' => '[:reference] Requester reply: :subject',
        'line' => ':author replied on ticket :reference — “:subject”:',
    ],

    'note_added' => [
        'subject' => '[:reference] Internal note: :subject',
        'line' => ':author added an internal note to ticket :reference — “:subject”:',
    ],

    'mentioned' => [
        'subject' => '[:reference] You were mentioned: :subject',
        'line' => ':author mentioned you on ticket :reference — “:subject”:',
    ],

    'status_changed' => [
        'resolved' => [
            'subject' => '[:reference] Resolved: :subject',
            'line' => 'Ticket :reference — “:subject” — was resolved.',
        ],
        'closed' => [
            'subject' => '[:reference] Closed: :subject',
            'line' => 'Ticket :reference — “:subject” — was closed.',
        ],
        'reopened' => [
            'subject' => '[:reference] Reopened: :subject',
            'line' => 'Ticket :reference — “:subject” — was reopened.',
        ],
        'status' => 'Its status is now: :status.',
    ],

    'overdue' => [
        'subject' => '[:reference] Overdue: :subject',
        'line' => 'Ticket :reference — “:subject” — was due :due and is still open.',
    ],

    'due_soon' => [
        'subject' => '[:reference] Due soon: :subject',
        'line' => 'Ticket :reference — “:subject” — is due :due.',
    ],

    'merged' => [
        'subject' => '[:reference] Merged into :target',
        'line' => 'Your ticket :reference — “:subject” — was merged into ticket :target.',
        'follow_up' => 'The conversation continues on :target.',
    ],
];
