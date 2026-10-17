<?php

declare(strict_types=1);

return [
    'config' => [
        'invalid' => 'The tickets setting [:key] must be :expected.',
    ],

    'lifecycle' => [
        'not_a_definition' => 'The ticket lifecycle [:class] must extend a lifecycle definition and implement the TicketLifecycle contract.',
    ],

    'activity' => [
        'append_only' => 'Ticket activity is append-only: an entry cannot be changed or deleted.',
    ],
];
