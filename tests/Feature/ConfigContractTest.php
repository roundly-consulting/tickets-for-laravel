<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

/**
 * Both directions: every `tickets.*` key the code reads is shipped, and every shipped leaf
 * is read. `src/` plus the sibling `database/` (the migrations read the key types).
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/tickets.php')->toSatisfyConfigContract(__DIR__.'/../../src');
});

it('never claims a config handle Laravel ships itself', function (): void {
    $framework = dirname((string) (new ReflectionClass(Application::class))->getFileName(), 4);

    expect($framework.'/config/app.php')->toBeFile()
        ->and($framework.'/config/tickets.php')->not->toBeFile();
});
