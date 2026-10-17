<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use RoundlyConsulting\Tickets\Enums\TicketStatus;

/**
 * English and Slovak ship the identical key set and placeholders, and Slovak really
 * resolves through the package namespace.
 */
$files = array_map(
    static fn (string $path): string => basename($path, '.php'),
    glob(__DIR__.'/../../resources/lang/en/*.php') ?: [],
);

it('ships at least one translation file', function () use ($files): void {
    expect($files)->not->toBeEmpty();
});

it('ships the same keys in english and slovak', function (string $file): void {
    /** @var array<string, mixed> $en */
    $en = require __DIR__."/../../resources/lang/en/{$file}.php";
    /** @var array<string, mixed> $sk */
    $sk = require __DIR__."/../../resources/lang/sk/{$file}.php";

    expect(array_keys(Arr::dot($sk)))->toEqualCanonicalizing(array_keys(Arr::dot($en)));
})->with($files);

it('ships the same placeholders per key in english and slovak', function (string $file): void {
    /** @var array<string, string> $en */
    $en = Arr::dot(require __DIR__."/../../resources/lang/en/{$file}.php");
    /** @var array<string, string> $sk */
    $sk = Arr::dot(require __DIR__."/../../resources/lang/sk/{$file}.php");

    $placeholders = static function (string $line): array {
        preg_match_all('/:([a-z_]+)/', $line, $matches);
        $names = array_unique($matches[1]);
        sort($names);

        return $names;
    };

    foreach ($en as $key => $line) {
        expect($placeholders((string) ($sk[$key] ?? '')))->toBe($placeholders((string) $line), "placeholders of {$file}.{$key}");
    }
})->with($files);

it('resolves slovak through the package namespace', function (): void {
    app()->setLocale('sk');

    expect(__('tickets::enums.ticket_status.resolved'))->toBe('Vyriešený')
        ->and(TicketStatus::Pending->label())->toBe('Čaká na žiadateľa');
});
