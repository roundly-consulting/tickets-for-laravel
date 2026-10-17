<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

/**
 * Both directions: every `tickets.*` key the code reads is shipped, and every shipped leaf
 * is read. `src/` plus the sibling `database/` (the migrations read the key types).
 *
 * Two kinds of section are read wholesale and proven by other means:
 *
 *  - the guest field definitions, read per field through `$spec[...]` offsets in
 *    GuestFields — mapped through `sectionVariables`, so every attribute of every shipped
 *    field is still proven read;
 *  - the alias allow-lists `requesters` / `assignables`, whose leaves are host aliases
 *    (`user`), not keys the package reads by name — `allowUnread`, one entry per shipped
 *    alias.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/tickets.php')->toSatisfyConfigContract(__DIR__.'/../../src', [
        'sectionVariables' => ['GuestFields.php' => ['$spec' => 'tickets.guests.fields.*']],
        'allowUnread' => ['tickets.requesters.user', 'tickets.assignables.user'],
    ]);
});

it('never claims a config handle Laravel ships itself', function (): void {
    $framework = dirname((string) (new ReflectionClass(Application::class))->getFileName(), 4);

    expect($framework.'/config/app.php')->toBeFile()
        ->and($framework.'/config/tickets.php')->not->toBeFile();
});
