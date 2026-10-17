<?php

declare(strict_types=1);

use Illuminate\Support\Env;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The shipped config file hands env values to the toolkit's strict readers RAW. Converting
 * them first — `(bool) env()`, `filter_var(env(), FILTER_VALIDATE_BOOL)` — hides a typo:
 * `(bool) 'off'` is true and `filter_var('disabled', …)` is false, so the reader never sees
 * the bad value and a misconfigured switch silently takes a side. The same goes for numbers:
 * `(int) env()` turns `five` into 0 before the strict reader can refuse it. Keep one case per
 * key as the real configuration replaces these placeholders.
 */
afterEach(function (): void {
    foreach (['PACKAGE_TEMPLATE_ENABLED', 'PACKAGE_TEMPLATE_TIMEOUT', 'PACKAGE_TEMPLATE_MODE'] as $name) {
        Env::getRepository()->clear($name);
    }
});

/**
 * @return array<string, mixed>
 */
function shippedPackageConfig(): array
{
    return require __DIR__.'/../../config/package-template.php';
}

it('defaults the enabled flag on when the env is unset', function (): void {
    expect(shippedPackageConfig()['enabled'])->toBeTrue();
});

it('hands an unreadable switch to the reader raw (strict config)', function (): void {
    Env::getRepository()->set('PACKAGE_TEMPLATE_ENABLED', 'disabled');

    expect(shippedPackageConfig()['enabled'])->toBe('disabled');
});

it('defaults the integer and vocabulary settings when the env is unset', function (): void {
    expect(shippedPackageConfig()['timeout'])->toBe(30)
        ->and(shippedPackageConfig()['mode'])->toBe('sync');
});

it('reads a blank env line as not set, so every default applies (strict config)', function (): void {
    // A host's `KEY=` arrives as '' — the config file passes it through raw, and the strict
    // reader treats blank exactly like an absent key: the default, never false, 0 or a throw.
    foreach (['PACKAGE_TEMPLATE_ENABLED', 'PACKAGE_TEMPLATE_TIMEOUT', 'PACKAGE_TEMPLATE_MODE'] as $name) {
        Env::getRepository()->set($name, '');
    }

    config()->set('package-template', shippedPackageConfig());

    expect(config('package-template'))->toBe(['enabled' => '', 'timeout' => '', 'mode' => ''])
        ->and(Config::boolean('package-template.enabled', true))->toBeTrue()
        ->and(Config::integer('package-template.timeout', 30, min: 1, max: 300))->toBe(30)
        ->and(Config::oneOf('package-template.mode', ['sync', 'queue'], 'sync'))->toBe('sync');
});

it('hands a junk number and a typo to the reader raw (strict config)', function (string $env, string $key, string $value): void {
    Env::getRepository()->set($env, $value);

    expect(shippedPackageConfig()[$key])->toBe($value);
})->with([
    'timeout' => ['PACKAGE_TEMPLATE_TIMEOUT', 'timeout', 'five'],
    'mode' => ['PACKAGE_TEMPLATE_MODE', 'mode', 'queued'],
]);
