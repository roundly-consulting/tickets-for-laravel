<?php

declare(strict_types=1);

return [
    'http' => [
        'invalid_date' => 'Filter :filter musí byť dátum.',
        'invalid_value' => 'Pole :attribute musí byť jedna hodnota.',
        'invalid_key' => 'Pole :attribute musí byť kľúč záznamu.',
        'invalid_translations' => 'Pole :attribute musí byť text alebo mapa jazykov na texty.',
        'forbidden' => 'Na túto akciu nemáte oprávnenie.',
    ],

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
        'closed' => 'Tiket [:reference] je uzavretý a neprijíma ďalšie správy. Namiesto toho otvorte nadväzujúci tiket.',
    ],

    'conversation' => [
        'not_a_participant' => 'Do tiketu [:reference] môže písať iba žiadateľ, agent alebo sledujúci.',
        'not_an_agent' => 'Internú poznámku k tiketu [:reference] môže pridať iba agent.',
        'comment_not_found' => 'Komentár [:comment] nepatrí k tiketu [:reference].',
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
        'same_target' => 'Uveďte inú kategóriu, do ktorej sa tikety presunú — nie tú, ktorá sa odstraňuje.',
        'in_use' => 'Kategória ešte obsahuje tikety. Určte kategóriu, do ktorej ich presunúť.',
        'has_children' => 'Kategória ešte obsahuje podkategórie. Najprv ich presuňte alebo odstráňte.',
        'not_found' => 'Kategória [:key] sa nenašla.',
        'invalid_due_in' => 'Lehota [:value] musí byť kladné trvanie vo formáte ISO 8601, napríklad P2D alebo PT4H.',
        'invalid_priority' => 'Predvolená priorita [:value] nepatrí medzi nastavené priority.',
        'empty_name' => 'Kategória potrebuje názov.',
    ],

    'assignee' => [
        'no_actor' => 'Prevzatie tiketu potrebuje aktéra — zavolajte by() s používateľom, ktorý ho preberá.',
        'not_an_assignee' => 'Modelu [:class] nemožno priradiť tikety: musí implementovať kontrakt Assignee.',
        'ineligible' => 'Model [:class] nemôže byť riešiteľom tohto tiketu.',
    ],

    'alias' => [
        'unknown' => 'Typ [:alias] nie je povolený typ v zozname :kind.',
        'missing' => 'V zozname :kind neexistuje :alias s kľúčom [:key].',
        'unknown_kind' => 'Zoznam aliasov [:kind] neexistuje.',
    ],

    'field' => [
        'not_found' => 'Pole [:id] sa nenašlo.',
        'invalid_key' => 'Kľúč poľa [:key] musí začínať písmenom a obsahovať iba malé písmená, číslice a podčiarkovníky (najviac 64).',
        'duplicate_key' => 'Pole s kľúčom [:key] už v tomto rozsahu existuje.',
        'kept_values' => 'Odstránené pole ešte uchováva hodnoty pod kľúčom [:key]. Skôr než kľúč znova definujete, obnovte toto pole alebo zabudnite jeho hodnoty (tickets:prune-fields).',
        'missing_options' => 'Pole [:key] je výberové a potrebuje aspoň jednu možnosť.',
        'unexpected_options' => 'Pole [:key] nie je výberové a nemôže mať možnosti.',
        'rule_not_allowed' => 'Pravidlo [:rule] poľa [:key] nie je povolené.',
        'empty_label' => 'Pole [:key] potrebuje označenie.',
        'unknown' => 'Pole [:key] nie je poľom tohto tiketu.',
        'not_editable' => 'Pole [:key] môže meniť iba agent.',
        'type_locked' => 'Pole [:key] už obsahuje hodnoty, preto nemožno zmeniť jeho typ.',
        'encryption_locked' => 'Pole [:key] už obsahuje hodnoty, preto nemožno zmeniť jeho šifrovanie.',
        'options_in_use' => 'Možnosti [:options] poľa [:key] sa ešte používajú.',
        'not_filterable' => 'Tikety nemožno filtrovať podľa poľa [:key].',
        'not_filterable_encrypted' => 'Pole [:key] je šifrované a nemožno podľa neho filtrovať.',
    ],

    'guest' => [
        'link_invalid' => 'Tento odkaz je neplatný alebo mu vypršala platnosť.',
        'not_a_recipient' => 'Odkazy pre hostí k tiketu [:reference] dostáva len jeho žiadateľ, ktorý je hosťom, alebo hosť, ktorý ho sleduje.',
        'unknown_field' => 'Pole hosťa [:field] nie je nastavené.',
        'identifying_required' => 'Uveďte aspoň jeden kontakt, napríklad e-mailovú adresu alebo telefónne číslo.',
        'taken' => 'Toto pole :field už používa iný hosť.',
    ],

    'watcher' => [
        'no_actor' => 'Sledovanie tiketu potrebuje aktéra — zavolajte by() s používateľom, ktorý ho sleduje.',
    ],

    'tag' => [
        'not_found' => 'Štítok [:id] sa nenašiel.',
        'invalid_name' => 'Štítok potrebuje názov s najviac 64 znakmi.',
        'invalid_color' => 'Farba štítka [:color] musí byť hexadecimálna farba, napríklad #f24e29.',
        'duplicate' => 'Štítok s názvom [:name] tu už existuje.',
        'unknown' => 'Štítok s názvom [:name] neexistuje a nové štítky nemožno vytvárať priebežne.',
        'project_mismatch' => 'Štítok [:name] patrí inému projektu.',
    ],

    'link' => [
        'self' => 'Tiket nemožno prepojiť so sebou samým.',
        'cycle' => 'Toto prepojenie :type by uzavrelo cyklus.',
        'second_parent' => 'Tiket [:reference] už má nadradený tiket.',
        'cross_project' => 'Tikety rôznych projektov nemožno prepojiť.',
        'too_deep' => 'Reťazec prepojení je hlbší ako :max úrovní a nemožno ho skontrolovať na cykly.',
        'not_linked' => 'Prepojenie :type s tiketom [:reference] tu neexistuje.',
        'not_found' => 'Prepojenie [:link] nepatrí k tiketu [:reference].',
    ],

    'merge' => [
        'same' => 'Tiket nemožno zlúčiť so sebou samým.',
        'source_closed' => 'Tiket [:reference] je uzavretý a nemožno ho zlúčiť.',
        'source_merged' => 'Tiket [:reference] už bol zlúčený.',
        'target_closed' => 'Tiket [:reference] je uzavretý a nemožno doň zlučovať.',
        'target_merged' => 'Tiket [:reference] bol zlúčený do [:final]. Zlúčte ho radšej do toho tiketu.',
        'cross_project' => 'Tikety rôznych projektov nemožno zlúčiť.',
        'unauthorized' => 'Nemáte oprávnenie zlučovať tikety.',
        'note_on_target' => 'Tiket :source bol zlúčený do tohto tiketu.',
        'reply_on_source' => 'Tento tiket bol zlúčený do :target.',
    ],

    'attachment' => [
        'not_found' => 'Príloha [:media] nepatrí k tiketu [:reference].',
    ],

    'doctor' => [
        'policy_ok' => 'Politika tiketov je [:class].',
        'routes_off' => 'HTTP API je vypnuté (tickets.routes.enabled).',
        'routes_on' => 'HTTP API je zapnuté a chránené prihlásením.',
        'routes_unguarded' => 'HTTP API je zapnuté, ale tickets.routes.middleware neobsahuje middleware auth: každý endpoint by odpovedal anonymnému volajúcemu. Pridajte auth (alebo auth:sanctum).',
        'guest_links_unavailable' => 'Predvolený generátor odkazov pre hostí potrebuje trasy pre hostí (tickets.routes.enabled a tickets.guests.enabled): nemožno vydať žiadny odkaz pre hosťa. Zapnite ich alebo zaregistrujte vlastný Contracts\\GuestLinkGenerator.',
        'lifecycle_ok' => 'Životný cyklus [:class] spĺňa kontrakt tiketov aj všetky automatizácie.',
        'conveniences' => 'Podporované pomenované prechody: :list.',
        'meta_missing' => 'Tabuľka komentárov nemá stĺpec `meta`. Zverejnite a spustite migrácie comments-for-laravel 1.1.',
        'meta_ok' => 'Tabuľka komentárov má stĺpec `meta`, do ktorého sa zapisujú odpovede a poznámky.',
        'comments_authorization' => 'Nastavenie comments.authorization je zapnuté: komentáre overujú prihláseného používateľa pri každej odpovedi, poznámke, schválení a uzamknutí tiketu. Povoľte vo svojej CommentPolicy komentáre k tiketom, inak budú odpovede hostí, systémové poznámky aj automatické uzamknutie odmietnuté.',
        'attachments_public' => 'Prílohy tiketov sa ukladajú na disk [:disk], ktorý je dostupný z webu: súkromný súbor je dostupný pod /storage aj bez podpisu. Nastavte tickets.attachments.disk na súkromný disk, napríklad local.',
        'attachments_ok' => 'Prílohy tiketov sa ukladajú na súkromný disk [:disk].',
        'keys_ok' => 'Všetky typy kľúčov sa zhodujú: :type.',
        'key_invalid' => 'Nastavenie [:key] nie je typ kľúča (bigint, uuid alebo ulid).',
        'key_mismatch' => ':ours je :type, ale :theirs je :other: musia byť rovnaké, inak stĺpce ani vyhľadávanie nebudú sedieť.',
        'user_model_mismatch' => 'Váš model používateľa [:class] používa kľúč :model, ale tickets.key_type je :type: žiadatelia, riešitelia ani aktéri by sa neuložili správne.',
        'priority_ok' => 'Priority pochádzajú z [:class]; nový tiket má predvolene :default.',
        'priority_unknown' => '{1} :count tiket má prioritu, ktorú [:class] už nepozná: číta sa ako bez priority a radí sa na koniec.|[2,4] :count tikety majú prioritu, ktorú [:class] už nepozná: čítajú sa ako bez priority a radia sa na koniec.|[0,*] :count tiketov má prioritu, ktorú [:class] už nepozná: čítajú sa ako bez priority a radia sa na koniec.',
        'abilities_ok' => 'Oprávnenia :list sú definované.',
        'abilities_undefined' => 'Oprávnenia :list nie sú definované. Nedefinované oprávnenie zamieta, takže ho nemá nikto — iba ak ho udelí Gate::before (napríklad balík oprávnení). Definujte ich cez Gate::define().',
        'alias_contract' => 'V :key alias [:alias] odkazuje na [:class], ktorý neimplementuje :contract.',
        'aliases_ok' => 'Všetky zoznamy aliasov odkazujú na použiteľné modely.',
        'guest_fields_ok' => 'Polia hostí: :list.',
        'temporary_urls_stream' => 'Odkazy na stiahnutie príloh sú dočasné odkazy disku alebo podpísaná streamovacia trasa media-library.',
        'temporary_urls_native' => 'Odkazy na stiahnutie príloh: disky :list vytvárajú vlastné dočasné odkazy.',
        'temporary_urls_missing' => 'Nastavenie media.stream.enabled je vypnuté a disky :list nevytvárajú vlastné dočasné odkazy: každý odkaz na prílohu v API by zlyhal. Zapnite streamovaciu trasu alebo ukladajte prílohy na disk, ktorý vie podpisovať odkazy (napríklad s3).',
        'guest_links_notified' => 'Hostia dostávajú notifikácie (tickets.notifications), ale predvolený generátor odkazov pre hostí potrebuje trasy pre hostí (tickets.routes.enabled a tickets.guests.enabled): e-maily hosťom by neobsahovali odkaz. Zapnite ich, zaregistrujte vlastný Contracts\\GuestLinkGenerator alebo vypnite tickets.notifications.guests.',
        'notifications_on' => 'Notifikácie sú zapnuté: :list.',
        'notifications_off' => 'Notifikácie sú vypnuté (tickets.notifications.enabled).',
        'notification_types' => 'Všetky typy notifikácií sú použiteľné; vypnuté: :list.',
        'schedule' => 'Naplánujte tickets:sweep každých päť minút (po termíne a blížiaci sa termín) a lifecycle:sweep každú minútu (automatické uzavretie).',
    ],

    'sweep' => [
        'overdue' => '{1} :count tiket je po termíne.|[2,4] :count tikety sú po termíne.|[0,*] :count tiketov je po termíne.',
        'due_soon' => '{1} :count tiketu sa blíži termín.|[2,4] :count tiketom sa blíži termín.|[0,*] :count tiketom sa blíži termín.',
    ],

    'prune' => [
        'pruned' => '{1} Zabudnutá :count osirelá hodnota poľa.|[2,4] Zabudnuté :count osirelé hodnoty polí.|[0,*] Zabudnutých :count osirelých hodnôt polí.',
        'dry_run' => '{1} Zabudla by sa :count osirelá hodnota poľa.|[2,4] Zabudli by sa :count osirelé hodnoty polí.|[0,*] Zabudlo by sa :count osirelých hodnôt polí.',
        'invalid_chunk' => 'Voľba --chunk musí byť celé číslo od 1 do 999999.',
    ],

    'install' => [
        'published' => 'Zverejnené :tag',
        'key_primary' => 'tikety a hostia',
        'key_outbound' => 'žiadatelia, riešitelia a aktéri',
        'key_lifecycle' => 'subjekty životného cyklu',
        'key_lifecycle_actor' => 'aktéri životného cyklu',
        'key_comments' => 'autori a predmety komentárov',
        'key_attributes' => 'vlastníci atribútov',
        'key_types' => 'Pred spustením migrácií nastavte všetky typy kľúčov vyššie na rovnakú hodnotu (bigint, uuid alebo ulid) — stĺpce sa potom už nedajú zmeniť.',
        'next' => 'Ďalšie kroky',
        'abilities' => 'Definujte, kto je agent (a oprávnenia tickets.assign a tickets.manage):',
        'schedule' => 'Naplánujte kontroly (routes/console.php):',
        'routes' => 'JSON API je vypnuté: nastavte TICKETS_ROUTES=on, ak ho chcete sprístupniť, a php artisan vendor:publish --tag=tickets-routes, ak chcete upraviť jeho trasy.',
        'guests' => 'Kontrola hlási guest_links, kým e-maily hosťom nemôžu obsahovať odkaz: nastavte TICKETS_ROUTES=on, zaregistrujte vlastný Contracts\GuestLinkGenerator alebo nastavte TICKETS_NOTIFY_GUESTS=off.',
    ],
];
