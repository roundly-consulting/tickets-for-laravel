<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Http\Requests\TicketsRequest;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use Symfony\Component\Finder\Finder;

beforeEach(fn () => apiAbilities());

/**
 * The first message of the validation error under `$key`.
 */
function validationMessage(Closure $act, string $key): string
{
    try {
        $act();
    } catch (ValidationException $exception) {
        return (string) ($exception->errors()[$key][0] ?? 'no error under '.$key);
    }

    return 'no validation error';
}

it('names every FormRequest input in en and sk', function (string $locale): void {
    /** @var array{attributes: array<string, string>} $lines */
    $lines = require __DIR__."/../../resources/lang/{$locale}/validation.php";
    $missing = [];
    $requests = 0;

    foreach (Finder::create()->files()->in(__DIR__.'/../../src/Http/Requests')->name('*Request.php') as $file) {
        $class = 'RoundlyConsulting\\Tickets\\Http\\Requests\\'.$file->getBasename('.php');

        if ((new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $request = new $class;
        expect($request)->toBeInstanceOf(TicketsRequest::class);
        $requests++;

        foreach (array_keys($request->rules()) as $input) {
            if (! isset($lines['attributes'][$input])) {
                $missing[] = $file->getBasename('.php').': '.$input;
            }
        }
    }

    expect($requests)->toBeGreaterThan(20)
        ->and($missing)->toBe([]);
})->with(['en', 'sk']);

it('names the input of an API validation error, in English and Slovak', function (string $locale, string $name): void {
    app()->setLocale($locale);

    $response = $this->actingAs(apiAgent())->postJson('/api/tickets', ['subject' => '', 'due_at' => '2000-01-01'])->assertUnprocessable();

    expect($response->json('errors.subject.0'))->toContain($name)->not->toContain('subject field', 'predmet field')
        ->and($response->json('errors.due_at.0'))->not->toContain('due at');
})->with([
    'en' => ['en', 'The subject field'],
    'sk' => ['sk', 'predmet'],
]);

it('names a record key and a translation map by the input, not its path', function (): void {
    $this->actingAs(apiAgent())->postJson('/api/tickets', ['subject' => 'x', 'requester' => ['type' => 'user', 'id' => ['x']]])
        ->assertJsonPath('errors', ['requester.id' => ['The requester id must be a record key.']]);
    $this->actingAs(apiAdmin())->postJson('/api/ticket-categories', ['name' => 5])
        ->assertJsonPath('errors.name.0', 'The name must be a text or a map of locale to text.');
});

it('labels a custom-field error with the field label', function (string $locale, string $label): void {
    Tickets::fields()->make('asset_tag', FieldType::Text)->label(['en' => 'Asset tag', 'sk' => 'Inventárne číslo'])->rules(['max:5'])->define();
    Tickets::fields()->make('cost_centre', FieldType::Text)->label(['en' => 'Cost centre', 'sk' => 'Nákladové stredisko'])->required()->define();
    app()->setLocale($locale);
    $make = static fn (array $fields) => Tickets::make()->subject('x')->requester(User::query()->create())->fields($fields)->asSystem()->create();

    expect(validationMessage(fn () => $make(['asset_tag' => 'PRN-12345', 'cost_centre' => 'IT']), 'fields.asset_tag'))->toContain($label)->not->toContain('fields.asset_tag')
        ->and(validationMessage(fn () => $make(['asset_tag' => 'PRN']), 'fields.cost_centre'))->toContain($locale === 'en' ? 'Cost centre' : 'Nákladové stredisko');
})->with([
    'en' => ['en', 'Asset tag'],
    'sk' => ['sk', 'Inventárne číslo'],
]);

it('labels a guest field error, keyed as the input that carried it', function (): void {
    config()->set('tickets.guests.fields.external_id', ['type' => 'string', 'rules' => ['nullable', 'string', 'max:3']]);

    expect(validationMessage(fn () => Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'nope']), 'fields.email'))->toContain('email address')
        ->and(validationMessage(fn () => Tickets::make()->subject('x')->guest(['name' => 'Jo', 'email' => 'nope'])->asSystem()->create(), 'guest.email'))->toContain('email address')
        ->and(validationMessage(fn () => Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test', 'external_id' => 'ABCDE']), 'fields.external_id'))->toContain('external id');

    app()->setLocale('sk');

    expect(validationMessage(fn () => Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'nope']), 'fields.email'))->toContain('e-mailová adresa');
});
