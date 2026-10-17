<?php

declare(strict_types=1);

return [
    'greeting' => 'Dobrý deň, :name,',
    'greeting_anonymous' => 'Dobrý deň,',
    'action' => 'Otvoriť tiket :reference',
    'someone' => 'Niekto',
    'internal' => 'Toto je interné: žiadateľ to nevidí.',

    'created' => [
        'subject' => '[:reference] Vašu požiadavku sme prijali: :subject',
        'line' => 'Ďakujeme — vašu požiadavku „:subject“ sme prijali ako tiket :reference.',
        'follow_up' => 'Keď nás budete v tejto veci kontaktovať, uveďte, prosím, :reference.',
    ],

    'assigned' => [
        'subject' => '[:reference] Priradené: :subject',
        'line' => 'Tiket :reference — „:subject“ — bol priradený: :assignee.',
        'priority' => 'Priorita: :priority.',
    ],

    'replied' => [
        'subject' => '[:reference] Nová odpoveď: :subject',
        'line' => 'K vášmu tiketu :reference — „:subject“ — pribudla nová odpoveď:',
    ],

    'requester_replied' => [
        'subject' => '[:reference] Odpoveď žiadateľa: :subject',
        'line' => 'Odpoveď od :author k tiketu :reference — „:subject“:',
    ],

    'note_added' => [
        'subject' => '[:reference] Interná poznámka: :subject',
        'line' => 'Interná poznámka od :author k tiketu :reference — „:subject“:',
    ],

    'mentioned' => [
        'subject' => '[:reference] Zmienka o vás: :subject',
        'line' => 'Zmienka o vás od :author v tikete :reference — „:subject“:',
    ],

    'status_changed' => [
        'resolved' => [
            'subject' => '[:reference] Vyriešené: :subject',
            'line' => 'Tiket :reference — „:subject“ — bol vyriešený.',
        ],
        'closed' => [
            'subject' => '[:reference] Uzavreté: :subject',
            'line' => 'Tiket :reference — „:subject“ — bol uzavretý.',
        ],
        'reopened' => [
            'subject' => '[:reference] Znovu otvorené: :subject',
            'line' => 'Tiket :reference — „:subject“ — bol znovu otvorený.',
        ],
        'status' => 'Jeho stav je teraz: :status.',
    ],

    'overdue' => [
        'subject' => '[:reference] Po termíne: :subject',
        'line' => 'Tiket :reference — „:subject“ — mal termín :due a stále je otvorený.',
    ],

    'due_soon' => [
        'subject' => '[:reference] Blíži sa termín: :subject',
        'line' => 'Tiket :reference — „:subject“ — má termín :due.',
    ],

    'merged' => [
        'subject' => '[:reference] Zlúčené do :target',
        'line' => 'Váš tiket :reference — „:subject“ — bol zlúčený do tiketu :target.',
        'follow_up' => 'Konverzácia pokračuje v tikete :target.',
    ],
];
