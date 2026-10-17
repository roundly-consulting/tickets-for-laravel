<?php

declare(strict_types=1);

/**
 * S-7 — mass assignment: the API hands actions only what its form requests validated.
 *
 *  - a controller reads a request only through the typed readers its form request declares —
 *    every one built on `validated()` — never Laravel's raw input methods, the `request()`
 *    helper or the Request facade;
 *  - a form request reads nothing but `validated()` (its `after()` hook reads the validator's
 *    data);
 *  - a controller writes no model itself — every write goes through the manager (which is why the
 *    status-write scan may skip the HTTP layer).
 */

/**
 * The PHP source of every file in the directory, comments stripped.
 *
 * @return array<string, string>
 */
function httpArchSources(string $directory): array
{
    $sources = [];

    foreach (glob($directory.'/*.php') ?: [] as $file) {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        $sources[basename($file)] = $code;
    }

    return $sources;
}

/**
 * The public methods the package's form requests declare.
 *
 * @return list<string>
 */
function httpArchReaders(): array
{
    $readers = [];

    foreach (httpArchSources(__DIR__.'/../../src/Http/Requests') as $source) {
        preg_match_all('/public\s+(?:static\s+)?function\s+(\w+)/', $source, $matches);
        $readers = [...$readers, ...$matches[1]];
    }

    return array_values(array_diff(array_unique($readers), ['rules', 'after', 'authorize', 'messages', 'attributes']));
}

it('lets controllers read a request only through the validated readers', function (): void {
    $readers = httpArchReaders();
    $violations = [];

    foreach (httpArchSources(__DIR__.'/../../src/Http/Controllers') as $file => $source) {
        preg_match_all('/\$request->(\w+)\s*\(/', $source, $calls);

        foreach ($calls[1] as $method) {
            if (! in_array($method, $readers, true)) {
                $violations[] = "{$file}: \$request->{$method}()";
            }
        }

        foreach (['request(', 'Request::', '$_GET', '$_POST', '$_REQUEST', '->validated('] as $banned) {
            if (str_contains($source, $banned)) {
                $violations[] = "{$file}: {$banned}";
            }
        }
    }

    expect($readers)->toContain('subject', 'perPage', 'touched', 'references')
        ->and($violations)->toBe([]);
});

it('lets form requests read nothing but validated()', function (): void {
    $violations = [];

    foreach (httpArchSources(__DIR__.'/../../src/Http/Requests') as $file => $source) {
        foreach (['input', 'all', 'query', 'post', 'get', 'only', 'except', 'boolean', 'string', 'integer', 'float', 'date', 'file', 'json', 'collect', 'keys'] as $method) {
            if (preg_match('/\$this->'.$method.'\s*\(/', $source) === 1) {
                $violations[] = "{$file}: \$this->{$method}()";
            }
        }
    }

    expect($violations)->toBe([]);
});

it('lets no controller write a model itself', function (): void {
    $violations = [];

    foreach (httpArchSources(__DIR__.'/../../src/Http/Controllers') as $file => $source) {
        foreach (['/->(forceFill|fill|save|saveQuietly|setAttribute|setRawAttributes|increment|decrement|forceDelete|touch)\s*\(/', '/->(update|create|insert|upsert)\s*\(\s*\[/', '/\bDB::/'] as $pattern) {
            if (preg_match($pattern, $source, $match) === 1) {
                $violations[] = "{$file}: {$match[0]}";
            }
        }
    }

    expect($violations)->toBe([]);
});
