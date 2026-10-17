<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * "An agent" without a ticket is the bare Gate ability (`Agents::is`); "an agent for this
 * ticket" is the ability narrowed by the agent scope (`TicketVisibility::isAgentFor`). Every
 * ticket-scoped decision must take the second, so the bare check may appear only where there is
 * no ticket to judge: the visibility helper that builds on it, the HTTP viewer and the policy's
 * ticket-less abilities (`create`, `viewTags`).
 */
it('keeps the unscoped agent check to decisions that have no ticket', function (): void {
    $allowed = [
        'Support/TicketVisibility.php',
        'Http/Viewer.php',
        'Policies/TicketPolicy.php',
    ];
    $src = realpath(__DIR__.'/../../src');
    $found = [];

    foreach (Finder::create()->files()->in((string) $src)->name('*.php') as $file) {
        if (str_contains($file->getContents(), 'Agents::is(')) {
            $found[] = str_replace('\\', '/', $file->getRelativePathname());
        }
    }

    sort($found);
    sort($allowed);

    expect($found)->toBe($allowed);
});

it('uses the unscoped check in the policy only for create and viewTags', function (): void {
    $policy = (string) file_get_contents(__DIR__.'/../../src/Policies/TicketPolicy.php');

    preg_match_all('/public function (\w+)\([^{]*\{(?:(?!public function).)*?Agents::is\(/s', $policy, $matches);

    expect($matches[1])->toBe(['create', 'viewTags']);
});
