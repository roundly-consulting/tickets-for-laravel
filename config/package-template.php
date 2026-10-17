<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Placeholder setting so the service provider has something real to merge,
    | publish, and report via `php artisan about`. Replace with the package's
    | real configuration — every key here must be read somewhere under src/,
    | and ConfigContractTest enforces both directions.
    |
    | Pass env values through raw — never `(bool) env()` or `filter_var()` —
    | and read them with the toolkit's `Config::boolean()`: a typo then throws
    | naming the key instead of silently becoming true or false. The false
    | spellings are false/0/off/no.
    |
    | Blank means not set: a host's `PACKAGE_TEMPLATE_ENABLED=` (an empty or
    | whitespace-only value) reads exactly like an absent key, so the default
    | applies. The same holds for every key below.
    |
    */

    'enabled' => env('PACKAGE_TEMPLATE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Timeout (an integer setting)
    |--------------------------------------------------------------------------
    |
    | Placeholder showing how every number is read. Pass the env through raw —
    | never `(int) env()`, `intval()` or an `is_numeric() ? … : default` guard,
    | which turn `five` into 0 (a timeout of none) — and read it with
    | `Config::integer($key, $default, min: …, max: …)`. An int or a canonical
    | integer string (`'30'`) passes; `'five'`, `'5.5'`, `'30s'` or a value out
    | of range throws naming the key. A key that is not set — absent, null or
    | blank (`PACKAGE_TEMPLATE_TIMEOUT=`) — takes the default. Bound every
    | number by its domain: a timeout or TTL of 0 must be impossible unless 0
    | means something and is documented.
    |
    */

    'timeout' => env('PACKAGE_TEMPLATE_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Mode (a fixed vocabulary)
    |--------------------------------------------------------------------------
    |
    | Placeholder showing how a setting with a fixed set of values is read:
    | `Config::oneOf($key, ['sync', 'queue'], 'sync')`, or — once the package
    | has a backed enum for it — `Config::enum($key, Mode::class, Mode::Sync)`.
    | Never `Enum::tryFrom(config(…)) ?? Default`, `in_array() ? … : default`
    | or `=== 'queue' ? A : B`: a typo must throw, listing the allowed values,
    | not quietly become the default. A blank `PACKAGE_TEMPLATE_MODE=` is not
    | set and takes the default.
    |
    */

    'mode' => env('PACKAGE_TEMPLATE_MODE', 'sync'),

];
