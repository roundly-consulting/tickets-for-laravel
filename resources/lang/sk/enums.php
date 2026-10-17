<?php

declare(strict_types=1);

return [
    'ticket_status' => [
        'new' => 'Nový',
        'open' => 'Otvorený',
        'in_progress' => 'Rieši sa',
        'pending' => 'Čaká na žiadateľa',
        'on_hold' => 'Pozastavený',
        'resolved' => 'Vyriešený',
        'closed' => 'Uzavretý',
    ],

    'status_category' => [
        'open' => 'Otvorené',
        'active' => 'Aktívne',
        'waiting' => 'Čakajúce',
        'resolved' => 'Vyriešené',
        'closed' => 'Uzavreté',
    ],

    'automation' => [
        'assigned' => 'Priradenie',
        'requester_replied' => 'Odpoveď žiadateľa',
        'requester_reopened' => 'Opätovné otvorenie žiadateľom',
        'auto_close' => 'Automatické uzavretie',
        'merged' => 'Zlúčenie',
    ],

    'ticket_priority' => [
        'low' => 'Nízka',
        'normal' => 'Normálna',
        'high' => 'Vysoká',
        'urgent' => 'Urgentná',
    ],

    'field_type' => [
        'text' => 'Text',
        'textarea' => 'Dlhý text',
        'email' => 'E-mail',
        'url' => 'URL adresa',
        'number' => 'Celé číslo',
        'decimal' => 'Desatinné číslo',
        'boolean' => 'Áno/Nie',
        'date' => 'Dátum',
        'datetime' => 'Dátum a čas',
        'select' => 'Výber',
        'multiselect' => 'Viacnásobný výber',
    ],

    'link_type' => [
        'relates_to' => 'Súvisí s',
        'duplicates' => 'Duplikuje',
        'blocks' => 'Blokuje',
        'parent_of' => 'Nadradený k',
    ],

    'link_inverse' => [
        'relates_to' => 'Súvisí s',
        'duplicated_by' => 'Duplikovaný tiketom',
        'blocked_by' => 'Blokovaný tiketom',
        'child_of' => 'Podradený k',
    ],

    'activity_type' => [
        'created' => 'Vytvorený',
        'updated' => 'Upravený',
        'status_changed' => 'Zmena stavu',
        'priority_changed' => 'Zmena priority',
        'category_changed' => 'Zmena kategórie',
        'assigned' => 'Priradený',
        'unassigned' => 'Zrušené priradenie',
        'due_changed' => 'Zmena termínu',
        'fields_changed' => 'Zmena polí',
        'tagged' => 'Pridané štítky',
        'untagged' => 'Odobraté štítky',
        'linked' => 'Prepojený',
        'unlinked' => 'Zrušené prepojenie',
        'merged' => 'Zlúčený',
        'merged_from' => 'Pripojený tiket',
        'watcher_added' => 'Pridaný sledujúci',
        'watcher_removed' => 'Odobratý sledujúci',
        'attachment_added' => 'Pridaná príloha',
        'attachment_removed' => 'Odobratá príloha',
        'replied' => 'Odpoveď',
        'note_added' => 'Interná poznámka',
        'requester_changed' => 'Zmena žiadateľa',
        'guest_access_revoked' => 'Zrušený prístup hosťa',
        'overdue' => 'Po termíne',
        'deleted' => 'Odstránený',
        'restored' => 'Obnovený',
    ],

    'activity_visibility' => [
        'public' => 'Verejné',
        'internal' => 'Interné',
    ],

    'author_role' => [
        'agent' => 'Agent',
        'requester' => 'Žiadateľ',
        'participant' => 'Účastník',
        'system' => 'Systém',
    ],

    'ticket_channel' => [
        'web' => 'Web',
        'email' => 'E-mail',
        'api' => 'API',
        'phone' => 'Telefón',
        'chat' => 'Chat',
    ],

    'ticket_operation' => [
        'create' => 'Vytvorenie',
        'update' => 'Úprava',
        'change_priority' => 'Zmena priority',
        'change_category' => 'Zmena kategórie',
        'set_due_date' => 'Nastavenie termínu',
        'change_requester' => 'Zmena žiadateľa',
        'delete' => 'Odstránenie',
        'restore' => 'Obnovenie',
        'transition' => 'Prechod stavu',
        'create_category' => 'Vytvorenie kategórie',
        'update_category' => 'Úprava kategórie',
        'move_category' => 'Presun kategórie',
        'delete_category' => 'Odstránenie kategórie',
        'restore_category' => 'Obnovenie kategórie',
        'define_field' => 'Definícia poľa',
        'resolve_guest' => 'Určenie hosťa',
        'update_guest' => 'Úprava hosťa',
        'convert_guest' => 'Prevod hosťa',
    ],
];
