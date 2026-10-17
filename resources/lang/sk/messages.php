<?php

declare(strict_types=1);

return [
    'config' => [
        'invalid' => 'Nastavenie tiketov [:key] musí byť :expected.',
    ],

    'lifecycle' => [
        'not_a_definition' => 'Životný cyklus tiketov [:class] musí rozširovať definíciu životného cyklu a implementovať kontrakt TicketLifecycle.',
    ],

    'activity' => [
        'append_only' => 'Aktivita tiketu sa iba dopĺňa: záznam nemožno zmeniť ani odstrániť.',
    ],
];
