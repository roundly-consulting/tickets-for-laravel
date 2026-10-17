<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Comments\Facades\Comments;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Actions\Tickets\CreateTicketAction;
use RoundlyConsulting\Tickets\DataTransferObjects\CreateTicketData;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateCategoryData;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateTicketData;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Testing\TicketsFake;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * The public API contract — Actions → Manager → Facade (+ fake).
 */
it('pins the facade contract', function (): void {
    expect(Tickets::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

it('runs the same create through the facade, an injected manager and the action', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $manager = app(TicketsManager::class);

    $viaFacade = Tickets::create(new CreateTicketData('a', $user));
    $viaManager = $manager->create(new CreateTicketData('b', $user));
    $viaAction = app(CreateTicketAction::class)->execute(new CreateTicketData('c', $user));

    expect($manager)->toBe(app(TicketsManager::class))
        ->and([$viaFacade->reference, $viaManager->reference, $viaAction->reference])->toBe(['TCK-1', 'TCK-2', 'TCK-3']);
});

it('resolves every action through the container so a host override applies', function (): void {
    app()->bind(CreateTicketAction::class, static fn (): never => throw new RuntimeException('overridden'));

    Tickets::make()->subject('a')->requester(User::query()->create())->create();
})->throws(RuntimeException::class, 'overridden');

it('declares the global alias for the facade and never a core facade name', function (): void {
    /** @var array{extra: array{laravel: array{aliases: array<string, string>}}} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    $aliases = $composer['extra']['laravel']['aliases'];
    $coreAliases = array_map(strtolower(...), array_keys(Facade::defaultAliases()->all()));

    expect($aliases)->toBe(['Tickets' => Tickets::class])
        ->and(class_exists('Illuminate\\Support\\Facades\\Tickets'))->toBeFalse()
        ->and($coreAliases)->not->toContain('tickets');
});

describe('the fake', function (): void {
    it('records facade, injected, handle, accessor and builder calls while still running them', function (): void {
        $fake = Tickets::fake();
        $user = User::query()->create(['name' => 'Ada']);

        $ticket = Tickets::make()->subject('a')->requester($user)->create();
        app(TicketsManager::class)->for($ticket)->prioritize('high');

        expect(app(TicketsManager::class))->toBeInstanceOf(TicketsFake::class)
            ->and($ticket->exists)->toBeTrue()
            ->and($ticket->priority)->toBe('high')
            ->and($fake->recorded())->toHaveCount(2)
            ->and($fake->recorded(TicketOperation::Create)[0]->ticket()?->is($ticket))->toBeTrue()
            ->and($fake->recorded(TicketOperation::ChangePriority)[0]->model('ticket')?->is($ticket))->toBeTrue()
            ->and($fake->recorded(TicketOperation::ChangePriority)[0]->model('actor'))->toBeNull();

        $fake->assertCreated();
        $fake->assertCreated(static fn (Ticket $created): bool => $created->is($ticket));
        Tickets::assertCreated(static fn (Ticket $created): bool => $created->subject === 'a');
        $fake->assertPrioritized($ticket, 'high');
        $fake->assertPrioritized('TCK-1');
    });

    it('records nothing for a call that throws', function (): void {
        $fake = Tickets::fake();

        try {
            Tickets::make()->subject('')->requester(User::query()->create())->create();
        } catch (Throwable) {
        }

        $fake->assertNothingHappened();
        $fake->assertNothingCreated();
    });

    it('passes and fails every assertion', function (Closure $act, string $assert, array $arguments, string $nothing): void {
        $fake = Tickets::fake();

        expect(fn () => $fake->{$assert}(...$arguments))->toThrow(ExpectationFailedException::class, 'none was recorded');

        $fake->{$nothing}();

        $act();

        $fake->{$assert}();
        $fake->{$assert}(...$arguments);

        expect(fn () => $fake->{$nothing}())->toThrow(ExpectationFailedException::class, 'were recorded')
            ->and(fn () => $fake->assertNothingHappened())->toThrow(ExpectationFailedException::class);
    })->with([
        'created' => [fn () => fakeTicket(), 'assertCreated', [fn (Ticket $ticket): bool => $ticket->subject === 'faked'], 'assertNothingCreated'],
        'updated' => [fn () => Tickets::for(fakeTicket())->update(new UpdateTicketData(subject: 'x')), 'assertUpdated', ['TCK-1'], 'assertNothingUpdated'],
        'prioritized' => [fn () => Tickets::for(fakeTicket())->prioritize('low'), 'assertPrioritized', ['TCK-1', 'low'], 'assertNothingPrioritized'],
        'categorized' => [fn () => Tickets::for(fakeTicket())->categorize(TicketCategory::factory()->create()), 'assertCategorized', ['TCK-1'], 'assertNothingCategorized'],
        'due set' => [fn () => Tickets::for(fakeTicket())->due(now()), 'assertDueSet', ['TCK-1'], 'assertNothingDueSet'],
        'requester changed' => [fn () => Tickets::for(fakeTicket())->changeRequester(User::query()->create()), 'assertRequesterChanged', ['TCK-1'], 'assertNothingRequesterChanged'],
        'deleted' => [fn () => Tickets::for(fakeTicket())->delete(), 'assertDeleted', ['TCK-1'], 'assertNothingDeleted'],
        'restored' => [fn () => Tickets::for(fakeTicket())->restore(), 'assertRestored', ['TCK-1'], 'assertNothingRestored'],
        'category created' => [fn () => Tickets::categories()->make('Hardware')->create(), 'assertCategoryCreated', [fn (TicketCategory $category): bool => $category->slug === 'hardware'], 'assertNoCategoryCreated'],
        'category updated' => [fn () => Tickets::categories()->update(fakeCategory(), new UpdateCategoryData(position: 2)), 'assertCategoryUpdated', [], 'assertNoCategoryUpdated'],
        'category moved' => [fn () => Tickets::categories()->move(fakeCategory(), null), 'assertCategoryMoved', [], 'assertNoCategoryMoved'],
        'category deleted' => [fn () => Tickets::categories()->delete(fakeCategory()), 'assertCategoryDeleted', [], 'assertNoCategoryDeleted'],
        'category restored' => [fn () => Tickets::categories()->restore(fakeCategory()), 'assertCategoryRestored', [], 'assertNoCategoryRestored'],
        'field defined' => [fn () => Tickets::fields()->make('asset_tag', FieldType::Text)->define(), 'assertFieldDefined', ['asset_tag'], 'assertNoFieldDefined'],
        'guest resolved' => [fn () => Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']), 'assertGuestResolved', [fn (TicketGuest $guest): bool => $guest->displayName() === 'Jo'], 'assertNoGuestResolved'],
        'guest updated' => [fn () => Tickets::guests()->update(fakeGuest(), ['name' => 'Joanna']), 'assertGuestUpdated', [], 'assertNoGuestUpdated'],
        'guest converted' => [fn () => Tickets::guests()->convert(fakeGuest(), User::query()->create()), 'assertGuestConverted', [], 'assertNoGuestConverted'],
        'replied' => [fn () => Tickets::for(fakeTicket())->asSystem()->reply('Hello.'), 'assertReplied', ['TCK-1', 'system'], 'assertNothingReplied'],
        'noted' => [fn () => Tickets::for(fakeTicket())->asSystem()->note('Psst.'), 'assertNoted', ['TCK-1'], 'assertNothingNoted'],
        'comment updated' => [fn () => Tickets::for(fakeTicket())->asSystem()->editComment(fakeComment(), 'Edited.'), 'assertCommentUpdated', [], 'assertNoCommentUpdated'],
        'comment deleted' => [fn () => Tickets::for(fakeTicket())->asSystem()->deleteComment(fakeComment()), 'assertCommentDeleted', [], 'assertNoCommentDeleted'],
    ]);

    it('fails an assertion whose operation ran, but on something else', function (): void {
        $fake = Tickets::fake();
        $ticket = fakeTicket();
        $other = Ticket::factory()->create();
        $category = fakeCategory();
        $otherCategory = TicketCategory::factory()->create();
        $guest = fakeGuest();
        $otherGuest = TicketGuest::factory()->create();

        Tickets::for($ticket)->prioritize('low')->categorize($category);
        Tickets::categories()->update($category, new UpdateCategoryData(position: 3));
        Tickets::guests()->update($guest, ['name' => 'Joanna']);
        Tickets::fields()->make('asset_tag', FieldType::Text)->define();
        $reply = Tickets::for($ticket)->asSystem()->reply('Hello.');
        $otherReply = Tickets::for($ticket)->asSystem()->reply('Another.');
        Tickets::for($ticket)->asSystem()->editComment($reply, 'Edited.');
        Tickets::for($ticket)->asSystem()->deleteComment($reply);
        Tickets::for($ticket)->asSystem()->note('Psst.');

        $mismatches = [
            fn () => $fake->assertCreated(static fn (): bool => false),
            fn () => $fake->assertPrioritized($other),
            fn () => $fake->assertPrioritized($ticket, 'urgent'),
            fn () => $fake->assertCategorized($ticket, $otherCategory),
            fn () => $fake->assertCategoryUpdated($otherCategory),
            fn () => $fake->assertGuestUpdated($otherGuest),
            fn () => $fake->assertFieldDefined('serial'),
            fn () => $fake->assertReplied($ticket, AuthorRole::Agent),
            fn () => $fake->assertReplied($other),
            fn () => $fake->assertNoted('TCK-404'),
            fn () => $fake->assertCommentUpdated($otherReply),
            fn () => $fake->assertCommentDeleted($otherReply),
        ];

        foreach ($mismatches as $mismatch) {
            expect($mismatch)->toThrow(ExpectationFailedException::class, 'none matched');
        }

        $fake->assertCategorized($ticket, $category->id);
        $fake->assertReplied($ticket, AuthorRole::System);
        $fake->assertReplied($ticket, 'system');
        $fake->assertCommentUpdated($reply);
        $fake->assertCommentDeleted($reply);
        $fake->assertCategoryUpdated($category);
        $fake->assertGuestUpdated($guest);
    });

    it('treats an operation without a ticket as concerning none', function (): void {
        $fake = Tickets::fake();

        Tickets::categories()->make('Hardware')->create();

        expect($fake->recorded()[0]->ticket())->toBeNull()
            ->and($fake->recorded()[0]->concerns('TCK-1'))->toBeFalse()
            ->and($fake->recorded()[0]->concerns(null))->toBeTrue();
    });
});

function fakeTicket(): Ticket
{
    return Tickets::make()->subject('faked')->requester(User::query()->create(['name' => 'Ada']))->asSystem()->create();
}

/**
 * The one comment of the faked ticket, written once — straight through comments, so the fake
 * records nothing for it.
 */
function fakeComment(): Comment
{
    return Comment::query()->first() ?? Comments::on(Ticket::query()->firstOrFail())->body('Original.')->post();
}

function fakeCategory(): TicketCategory
{
    return TicketCategory::factory()->create();
}

function fakeGuest(): TicketGuest
{
    return app(TicketsManager::class) instanceof TicketsFake
        ? (new TicketsManager(app()))->guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test'])
        : Tickets::guests()->resolve(['name' => 'Jo', 'email' => 'jo@acme.test']);
}
