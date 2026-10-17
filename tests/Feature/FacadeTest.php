<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Tickets\Actions\ExampleTicketsAction;
use RoundlyConsulting\Tickets\DataTransferObjects\ExampleTicketsData;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Testing\TicketsFake;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * The public API contract — Actions → Manager → Facade (+ fake) — pinned from day one.
 * Grow these tests with the API: every facade and sub-accessor method through the facade,
 * one DI resolution of the manager, cross-scope refusals, and a passing plus a failing case
 * for every fake assert.
 */
it('pins the facade contract', function (): void {
    expect(Tickets::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

it('runs the example through the facade', function (): void {
    expect(Tickets::example(new ExampleTicketsData('Ada')))->toBe('Hello, Ada!');
});

it('runs the example through an injected manager', function (): void {
    $manager = app(TicketsManager::class);

    expect($manager)->toBe(app(TicketsManager::class))
        ->and($manager->example(new ExampleTicketsData('Ada')))->toBe('Hello, Ada!');
});

it('runs the example action directly', function (): void {
    expect(app(ExampleTicketsAction::class)->execute(new ExampleTicketsData('Ada')))
        ->toBe('Hello, Ada!');
});

it('resolves the action through the container so a host override applies', function (): void {
    app()->bind(ExampleTicketsAction::class, static fn (): never => throw new RuntimeException('overridden'));

    Tickets::example(new ExampleTicketsData('Ada'));
})->throws(RuntimeException::class, 'overridden');

it('records facade and injected calls under the fake without running the action', function (): void {
    app()->bind(ExampleTicketsAction::class, static fn (): never => throw new RuntimeException('ran'));

    $fake = Tickets::fake();

    Tickets::example(new ExampleTicketsData('Ada'));
    app(TicketsManager::class)->example(new ExampleTicketsData('Grace'));

    expect(app(TicketsManager::class))->toBeInstanceOf(TicketsFake::class);

    $fake->assertExampleCalled();
    $fake->assertExampleCalled(static fn (ExampleTicketsData $data): bool => $data->name === 'Ada');
    Tickets::assertExampleCalled(static fn (ExampleTicketsData $data): bool => $data->name === 'Grace');
});

it('fails assertExampleCalled when nothing was called', function (): void {
    Tickets::fake()->assertExampleCalled();
})->throws(ExpectationFailedException::class, 'Expected example() to be called, but it was not.');

it('fails assertExampleCalled when no call matches', function (): void {
    $fake = Tickets::fake();

    Tickets::example(new ExampleTicketsData('Ada'));

    $fake->assertExampleCalled(static fn (ExampleTicketsData $data): bool => $data->name === 'Grace');
})->throws(ExpectationFailedException::class, 'no call matched');

it('passes assertNothingCalled on an untouched fake', function (): void {
    Tickets::fake()->assertNothingCalled();
});

it('fails assertNothingCalled after a call', function (): void {
    $fake = Tickets::fake();

    Tickets::example(new ExampleTicketsData('Ada'));

    $fake->assertNothingCalled();
})->throws(ExpectationFailedException::class, 'example() was called 1 time(s)');

it('declares the global alias for the facade and never a core facade name', function (): void {
    /** @var array{extra: array{laravel: array{aliases: array<string, string>}}} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    $aliases = $composer['extra']['laravel']['aliases'];
    $alias = (string) array_key_first($aliases);

    // Both halves: a facade class Laravel ships, and a global alias it registers without one
    // there (`Str`, `Number`, `Js`, …). Compared case-insensitively, as PHP resolves class names.
    $coreAliases = array_map(strtolower(...), array_keys(Facade::defaultAliases()->all()));

    expect($aliases)->toBe([class_basename(Tickets::class) => Tickets::class])
        ->and(class_exists('Illuminate\\Support\\Facades\\'.$alias))->toBeFalse()
        ->and($coreAliases)->not->toContain(strtolower($alias));
});
