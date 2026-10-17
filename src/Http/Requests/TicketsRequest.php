<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;
use RoundlyConsulting\Tickets\Support\Messages;

/**
 * Base of the API's form requests: validation only — authorization is the controller's, against
 * the policy, inside the API's error mapping — and typed readers over `validated()`, never the
 * raw input.
 *
 * @internal
 */
abstract class TicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Whether the validated input holds the key (even as null) — a partial update sets only
     * what was sent.
     */
    public function sent(string $key): bool
    {
        return array_key_exists($key, $this->validated());
    }

    protected function stringValue(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) ? $value : null;
    }

    protected function integerValue(string $key): ?int
    {
        $value = $this->validated($key);

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    protected function flag(string $key): ?bool
    {
        $value = $this->validated($key);

        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function arrayValue(string $key): array
    {
        $value = $this->validated($key);

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    protected function strings(string $key): array
    {
        return array_values(array_filter($this->arrayValue($key), is_string(...)));
    }

    protected function morph(string $key): ?MorphReference
    {
        $value = $this->validated($key);

        if (! is_array($value)) {
            return null;
        }

        $type = $value['type'] ?? null;
        $id = $value['id'] ?? null;

        return is_string($type) && (is_int($id) || is_string($id)) ? new MorphReference($type, $id) : null;
    }

    /**
     * The rules of a `{type, id}` pair under `$key`.
     *
     * @return array<string, list<mixed>>
     */
    protected static function morphRules(string $key, bool $required = false): array
    {
        return [
            $key => [$required ? 'required' : 'nullable', 'array:type,id'],
            $key.'.type' => ['required_with:'.$key, 'string', 'max:255'],
            $key.'.id' => ['required_with:'.$key, self::key()],
        ];
    }

    /**
     * A record key: an integer or a string of at most 64 characters.
     */
    protected static function key(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value) && ! (is_string($value) && $value !== '' && mb_strlen($value) <= 64)) {
                $fail(Messages::get('http.invalid_key', ['attribute' => $attribute]));
            }
        };
    }

    /**
     * Text in one language (a string) or several (a locale => string map).
     */
    protected static function translated(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value)) {
                return;
            }

            if (! is_array($value) || $value === []) {
                $fail(Messages::get('http.invalid_translations', ['attribute' => $attribute]));

                return;
            }

            foreach ($value as $locale => $text) {
                if (! is_string($locale) || preg_match('/^[a-z]{2,3}([_-][A-Za-z]{2,4})?$/', $locale) !== 1 || ! is_string($text)) {
                    $fail(Messages::get('http.invalid_translations', ['attribute' => $attribute]));

                    return;
                }
            }
        };
    }

    /**
     * @return array<string, string>|string|null
     */
    protected function translations(string $key): array|string|null
    {
        $value = $this->validated($key);

        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $map = [];

        foreach ($value as $locale => $text) {
            $map[(string) $locale] = is_string($text) ? $text : '';
        }

        return $map;
    }
}
