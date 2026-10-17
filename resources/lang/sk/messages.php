<?php

declare(strict_types=1);

return [
    'config' => [
        'invalid' => 'Nastavenie tiketov [:key] musí byť :expected.',
    ],

    'lifecycle' => [
        'not_a_definition' => 'Životný cyklus tiketov [:class] musí rozširovať definíciu životného cyklu a implementovať kontrakt TicketLifecycle.',
        'broken' => 'Životný cyklus tiketov [:class] nemožno použiť: :problem',
        'unknown_transition' => 'Životný cyklus tiketov nemá prechod [:transition]. Použite transition() s niektorým z jeho vlastných názvov.',
        'not_status_attribute' => 'Model tiketu [:model] musí mapovať svoj životný cyklus na atribút `status`, ako prvý v lifecycleDefinitions().',
        'state_not_ticket_state' => 'Stav [:state] neimplementuje kontrakt TicketState.',
        'automation_unknown' => 'Automatizácia :automation odkazuje na prechod [:transition], ktorý životný cyklus nemá.',
        'automation_not_system' => 'Prechod [:transition] automatizácie :automation musí povoľovať systémový kontext (allowSystem() alebo systemOnly()).',
        'automation_source' => 'Prechod [:transition] automatizácie :automation musí vychádzať zo stavu [:state].',
        'automation_target' => 'Prechod [:transition] automatizácie :automation vedie do stavu [:state], do ktorého kategórie nesmie viesť.',
    ],

    'activity' => [
        'append_only' => 'Aktivita tiketu sa iba dopĺňa: záznam nemožno zmeniť ani odstrániť.',
    ],

    'ticket' => [
        'not_found' => 'Tiket [:reference] sa nenašiel.',
        'trashed' => 'Tiket [:reference] je odstránený. Pred zmenou ho obnovte.',
        'duplicate_reference' => 'Označenie [:reference] je už obsadené. Dva projekty nesmú mať rovnakú predponu označenia.',
        'invalid_subject' => 'Predmet je povinný a môže mať najviac 255 znakov.',
        'unknown_channel' => 'Kanál [:channel] nepatrí medzi nastavené kanály.',
        'unknown_priority' => 'Priorita nepatrí medzi nastavené priority.',
        'requester_required' => 'Tiket potrebuje žiadateľa: používateľa alebo údaje hosťa.',
    ],

    'project' => [
        'not_a_project' => 'Projekt [:class] musí implementovať kontrakt TicketProject.',
        'invalid_prefix' => 'Predpona označenia [:prefix] musí začínať veľkým písmenom, za ktorým nasleduje najviac deväť veľkých písmen alebo číslic.',
    ],

    'category' => [
        'cycle' => 'Kategóriu nemožno presunúť pod ňu samotnú ani pod jej podkategóriu.',
        'too_deep' => 'Kategórie možno vnoriť najviac do hĺbky :max úrovní.',
        'project_mismatch' => 'Kategória patrí inému projektu.',
        'inactive' => 'Kategória je neaktívna a nemôže prijímať nové tikety.',
        'in_use' => 'Kategória ešte obsahuje tikety. Určte kategóriu, do ktorej ich presunúť.',
        'has_children' => 'Kategória ešte obsahuje podkategórie. Najprv ich presuňte alebo odstráňte.',
        'not_found' => 'Kategória [:key] sa nenašla.',
        'invalid_due_in' => 'Lehota [:value] musí byť kladné trvanie vo formáte ISO 8601, napríklad P2D alebo PT4H.',
        'invalid_priority' => 'Predvolená priorita [:value] nepatrí medzi nastavené priority.',
        'empty_name' => 'Kategória potrebuje názov.',
    ],

    'assignee' => [
        'not_an_assignee' => 'Modelu [:class] nemožno priradiť tikety: musí implementovať kontrakt Assignee.',
        'ineligible' => 'Model [:class] nemôže byť riešiteľom tohto tiketu.',
    ],

    'alias' => [
        'unknown' => 'Typ [:alias] nie je povolený typ v zozname :kind.',
        'missing' => 'V zozname :kind neexistuje :alias s kľúčom [:key].',
        'unknown_kind' => 'Zoznam aliasov [:kind] neexistuje.',
    ],

    'field' => [
        'invalid_key' => 'Kľúč poľa [:key] musí začínať písmenom a obsahovať iba malé písmená, číslice a podčiarkovníky (najviac 64).',
        'duplicate_key' => 'Pole s kľúčom [:key] už v tomto rozsahu existuje.',
        'missing_options' => 'Pole [:key] je výberové a potrebuje aspoň jednu možnosť.',
        'unexpected_options' => 'Pole [:key] nie je výberové a nemôže mať možnosti.',
        'rule_not_allowed' => 'Pravidlo [:rule] poľa [:key] nie je povolené.',
        'empty_label' => 'Pole [:key] potrebuje označenie.',
    ],

    'guest' => [
        'unknown_field' => 'Pole hosťa [:field] nie je nastavené.',
        'identifying_required' => 'Uveďte aspoň jeden kontakt, napríklad e-mailovú adresu alebo telefónne číslo.',
        'taken' => 'Toto pole :field už používa iný hosť.',
    ],

    'doctor' => [
        'lifecycle_ok' => 'Životný cyklus [:class] spĺňa kontrakt tiketov aj všetky automatizácie.',
        'conveniences' => 'Podporované pomenované prechody: :list.',
    ],
];
