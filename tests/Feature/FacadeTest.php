<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Comments\Facades\Comments;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Tickets\Actions\Fields\DefineFieldAction;
use RoundlyConsulting\Tickets\Actions\Tickets\CreateTicketAction;
use RoundlyConsulting\Tickets\Contracts\GuestLinkGenerator;
use RoundlyConsulting\Tickets\DataTransferObjects\CreateTicketData;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldData;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\DataTransferObjects\SweepResult;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateCategoryData;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateFieldData;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateTicketData;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Exceptions\TicketNotFoundException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Testing\TicketsFake;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
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

it('documents every fake assertion on the facade', function (): void {
    $fake = new ReflectionClass(TicketsFake::class);
    $offered = [];

    foreach ($fake->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() === TicketsFake::class && ! $method->isConstructor() && $method->name !== 'perform') {
            $offered[] = $method->name;
        }
    }

    preg_match_all('/@method static \S+ (\w+)\(/', (string) (new ReflectionClass(Tickets::class))->getDocComment(), $tags);
    $documented = array_values(array_filter($tags[1], static fn (string $name): bool => ! method_exists(TicketsManager::class, $name)));

    sort($offered);
    sort($documented);

    expect($offered)->toContain('recorded', 'assertNothingHappened', 'assertTransitioned')
        ->and($documented)->toBe($offered);
});

it('pairs every assertion with its negative, named by one rule', function (): void {
    // assert<Verb> pairs with assertNothing<Verb>; assert<Noun…><Verb> (two or more words)
    // pairs with assertNo<Noun…><Verb>. assertNothingHappened stands alone.
    $names = array_values(array_filter(
        get_class_methods(TicketsFake::class),
        static fn (string $name): bool => str_starts_with($name, 'assert') && $name !== 'assertNothingHappened',
    ));
    $negative = static fn (string $name): bool => (bool) preg_match('/^assertNo(thing)?[A-Z]/', $name);
    $positives = array_values(array_filter($names, static fn (string $name): bool => ! $negative($name)));
    $expected = [];

    foreach ($positives as $positive) {
        $rest = substr($positive, 6);
        $words = preg_match_all('/[A-Z]/', $rest);
        $expected[] = ($words >= 2 ? 'assertNo' : 'assertNothing').$rest;
    }

    $negatives = array_values(array_filter($names, $negative));

    sort($expected);
    sort($negatives);

    expect(count($positives))->toBeGreaterThan(40)
        ->and($negatives)->toBe($expected);
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

describe('lookups', function (): void {
    it('finds a ticket by reference, a soft-deleted one only when asked', function (): void {
        $ticket = fakeTicket();
        Tickets::for($ticket)->asSystem()->delete();

        expect(Tickets::find('TCK-1'))->toBeNull()
            ->and(Tickets::find('TCK-1', withTrashed: true)?->is($ticket))->toBeTrue()
            ->and(fn () => Tickets::findOrFail('TCK-1'))->toThrow(TicketNotFoundException::class)
            ->and(fn () => Tickets::for('TCK-1'))->toThrow(TicketNotFoundException::class)
            ->and(Tickets::for(Tickets::findOrFail('TCK-1', withTrashed: true))->asSystem()->restore()->ticket()->trashed())->toBeFalse()
            ->and(Tickets::find('TCK-404', withTrashed: true))->toBeNull();
    });

    it('finds a field by id or key, the project\'s before the global one', function (): void {
        $sup = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
        $fac = Project::query()->create(['name' => 'Facilities', 'key' => 'FAC']);
        $global = Tickets::fields()->make('impact', FieldType::Text)->define();
        $mine = Tickets::fields()->make('asset_tag', FieldType::Text)->project($sup)->define();
        $theirs = Tickets::fields()->make('asset_tag', FieldType::Text)->project($fac)->define();

        expect(Tickets::fields()->find('impact')?->is($global))->toBeTrue()
            ->and(Tickets::fields()->find('impact', $sup)?->is($global))->toBeTrue()
            ->and(Tickets::fields()->find('asset_tag'))->toBeNull()
            ->and(Tickets::fields()->find('asset_tag', $sup)?->is($mine))->toBeTrue()
            ->and(Tickets::fields()->find('asset_tag', $fac)?->is($theirs))->toBeTrue()
            ->and(Tickets::fields()->find($mine->id)?->is($mine))->toBeTrue()
            ->and(Tickets::fields()->find($mine->id, $sup)?->is($mine))->toBeTrue()
            ->and(Tickets::fields()->find($mine->id, $fac))->toBeNull()
            ->and(Tickets::fields()->find('nope'))->toBeNull();

        Tickets::fields()->delete($global);

        expect(Tickets::fields()->find('impact'))->toBeNull();
    });

    it('finds a tag by id, slug or name, the project\'s own before the global one', function (): void {
        $sup = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
        $fac = Project::query()->create(['name' => 'Facilities', 'key' => 'FAC']);
        $global = Tickets::tags()->create('Printer');
        $own = Tickets::tags()->create('Printer', project: $sup);
        $toner = Tickets::tags()->create('Toner', project: $fac);

        expect(Tickets::tags()->find('printer')?->is($global))->toBeTrue()
            ->and(Tickets::tags()->find('Printer')?->is($global))->toBeTrue()
            ->and(Tickets::tags()->find('printer', $sup)?->is($own))->toBeTrue()
            ->and(Tickets::tags()->find('printer', $fac)?->is($global))->toBeTrue()
            ->and(Tickets::tags()->find('toner'))->toBeNull()
            ->and(Tickets::tags()->find('toner', $fac)?->is($toner))->toBeTrue()
            ->and(Tickets::tags()->find($toner->id)?->is($toner))->toBeTrue()
            ->and(Tickets::tags()->find($toner->id, $sup))->toBeNull()
            ->and(Tickets::tags()->find($global->id, $sup)?->is($global))->toBeTrue();
    });

    it('returns the trashed tag from delete', function (): void {
        $tag = Tickets::tags()->create('Printer');

        $deleted = Tickets::tags()->delete($tag);

        expect($deleted->is($tag))->toBeTrue()
            ->and($deleted->trashed())->toBeTrue()
            ->and(Tickets::tags()->find('printer'))->toBeNull();
    });

    it('finds a guest read-only, by a normalised identifying value', function (): void {
        $fake = Tickets::fake();
        $jo = fakeGuest();
        $rows = TicketGuest::query()->count();

        expect(Tickets::guests()->find(['email' => ' JO@acme.test '])?->is($jo))->toBeTrue()
            ->and(Tickets::guests()->find(new GuestData(['name' => 'Someone else', 'email' => 'jo@acme.test']))?->is($jo))->toBeTrue()
            ->and(Tickets::guests()->find(['email' => 'ann@acme.test']))->toBeNull()
            ->and(Tickets::guests()->find(['name' => 'Jo']))->toBeNull()
            ->and(Tickets::guests()->find(['email' => '   ', 'shoe_size' => 42]))->toBeNull()
            ->and(TicketGuest::query()->count())->toBe($rows)
            ->and(fakeGuestFields($jo))->toBe(['email' => 'jo@acme.test', 'name' => 'Jo']);

        $fake->assertNoGuestResolved();

        $jo->delete();

        expect(Tickets::guests()->find(['email' => 'jo@acme.test']))->toBeNull();
    });

    it('issues a guest link by reference', function (): void {
        $fake = Tickets::fake();
        fakeGuestTicket();

        expect(Tickets::guests()->linkFor('TCK-1'))->toBe('https://helpdesk.test/TCK-1')
            ->and(fn () => Tickets::guests()->linkFor('TCK-404'))->toThrow(TicketNotFoundException::class);

        $fake->assertGuestLinkIssued('TCK-1');
    });
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
        'due set' => [fn () => Tickets::for(fakeTicket())->due(now()), 'assertDueSet', ['TCK-1'], 'assertNoDueSet'],
        'requester changed' => [fn () => Tickets::for(fakeTicket())->changeRequester(User::query()->create()), 'assertRequesterChanged', ['TCK-1'], 'assertNoRequesterChanged'],
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
        'assigned' => [fn () => Tickets::for(fakeTicket())->asSystem()->assign(fakeAgent()), 'assertAssigned', ['TCK-1'], 'assertNothingAssigned'],
        'unassigned' => [fn () => Tickets::for(fakeTicket())->asSystem()->unassign(), 'assertUnassigned', ['TCK-1'], 'assertNothingUnassigned'],
        'claimed' => [fn () => Tickets::for(fakeTicket())->by(fakeAgent())->claim(), 'assertClaimed', ['TCK-1'], 'assertNothingClaimed'],
        'watched' => [fn () => Tickets::for(fakeTicket())->asSystem()->watchers()->add(User::query()->create()), 'assertWatched', ['TCK-1'], 'assertNothingWatched'],
        'unwatched' => [fn () => Tickets::for(fakeTicket())->asSystem()->watchers()->remove(User::query()->create()), 'assertUnwatched', ['TCK-1'], 'assertNothingUnwatched'],
        'tagged' => [fn () => Tickets::for(fakeTicket())->asSystem()->tags()->attach('printer'), 'assertTagged', ['TCK-1', 'printer'], 'assertNothingTagged'],
        'untagged' => [fn () => Tickets::for(fakeTicket())->asSystem()->tags()->detach('printer'), 'assertUntagged', ['TCK-1', 'printer'], 'assertNothingUntagged'],
        'tags synced' => [fn () => Tickets::for(fakeTicket())->asSystem()->tags()->sync(['printer']), 'assertTagsSynced', ['TCK-1', 'printer'], 'assertNoTagsSynced'],
        'linked' => [fn () => Tickets::for(fakeTicket())->asSystem()->links()->add(Ticket::factory()->create(), LinkType::Blocks), 'assertLinked', ['TCK-1', LinkType::Blocks], 'assertNothingLinked'],
        'unlinked' => [fn () => Tickets::for(fakeTicket())->asSystem()->links()->add($other = Ticket::factory()->create(), LinkType::Blocks)->remove($other, LinkType::Blocks), 'assertUnlinked', ['TCK-1', LinkType::Blocks], 'assertNothingUnlinked'],
        'merged' => [fn () => Tickets::for(fakeTicket())->asSystem()->mergeInto(Ticket::factory()->create()), 'assertMerged', ['TCK-1'], 'assertNothingMerged'],
        'attached' => [fn () => fakeAttachment(), 'assertAttached', ['TCK-1'], 'assertNothingAttached'],
        'attachment removed' => [fn () => tap(fakeAttachment(), static fn (Media $media) => Tickets::for('TCK-1')->asSystem()->attachments()->remove($media)), 'assertAttachmentRemoved', ['TCK-1'], 'assertNoAttachmentRemoved'],
        'fields set' => [fn () => Tickets::for(fakeTicket())->asSystem()->fields()->set(fakeField()->key, 'PRN-1'), 'assertFieldsSet', ['TCK-1', 'asset_tag'], 'assertNoFieldsSet'],
        'fields forgotten' => [fn () => Tickets::for(fakeTicket())->asSystem()->fields()->forget(fakeField()->key), 'assertFieldsForgotten', ['TCK-1', 'asset_tag'], 'assertNoFieldsForgotten'],
        'field updated' => [fn () => Tickets::fields()->update(fakeField(), new UpdateFieldData(position: 2)), 'assertFieldUpdated', ['asset_tag'], 'assertNoFieldUpdated'],
        'field deleted' => [fn () => Tickets::fields()->delete(fakeField()), 'assertFieldDeleted', ['asset_tag'], 'assertNoFieldDeleted'],
        'field restored' => [fn () => Tickets::fields()->restore(fakeField()), 'assertFieldRestored', ['asset_tag'], 'assertNoFieldRestored'],
        'fields pruned' => [fn () => Tickets::fields()->prune(dryRun: true), 'assertFieldsPruned', [true], 'assertNoFieldsPruned'],
        'tag created' => [fn () => Tickets::tags()->findOrCreate('printer'), 'assertTagCreated', ['printer'], 'assertNoTagCreated'],
        'tag updated' => [fn () => Tickets::tags()->rename(fakeTag(), 'scanner'), 'assertTagUpdated', [], 'assertNoTagUpdated'],
        'tag deleted' => [fn () => Tickets::tags()->delete(fakeTag()), 'assertTagDeleted', [], 'assertNoTagDeleted'],
        'guest link issued' => [fn () => Tickets::for(fakeGuestTicket())->guestLink(), 'assertGuestLinkIssued', ['TCK-1'], 'assertNoGuestLinkIssued'],
        'guest link issued by the accessor' => [fn () => Tickets::guests()->linkFor(fakeGuestTicket()), 'assertGuestLinkIssued', ['TCK-1'], 'assertNoGuestLinkIssued'],
        'guest access revoked' => [fn () => Tickets::for(fakeTicket())->asSystem()->revokeGuestAccess(), 'assertGuestAccessRevoked', ['TCK-1'], 'assertNoGuestAccessRevoked'],
        'swept' => [fn () => Tickets::sweep(), 'assertSwept', [fn (SweepResult $result): bool => $result->total() === 0], 'assertNothingSwept'],
    ]);

    it('fails a guest-link assertion for another guest', function (): void {
        $fake = Tickets::fake();
        $ticket = fakeGuestTicket();
        $other = TicketGuest::factory()->create();

        Tickets::for($ticket)->guestLink();

        expect(fn () => $fake->assertGuestLinkIssued($ticket, $other))->toThrow(ExpectationFailedException::class, 'none matched');
        $fake->assertGuestLinkIssued($ticket);
    });

    it('matches a guest link that defaulted to the requester against the requester', function (Closure $issue): void {
        $fake = Tickets::fake();
        $ticket = fakeGuestTicket();
        $requester = $ticket->requester;

        $issue($ticket);

        expect($requester)->toBeInstanceOf(TicketGuest::class);
        $fake->assertGuestLinkIssued($ticket, $requester);
        expect(fn () => $fake->assertGuestLinkIssued($ticket, TicketGuest::factory()->create()))->toThrow(ExpectationFailedException::class, 'none matched');
    })->with([
        'the handle' => [fn (Ticket $ticket) => Tickets::for($ticket)->guestLink()],
        'the accessor' => [fn (Ticket $ticket) => Tickets::guests()->linkFor($ticket)],
    ]);

    it('fails a work-management assertion whose operation ran on something else', function (): void {
        $fake = Tickets::fake();
        $ticket = fakeTicket();
        $agent = fakeAgent();
        $other = User::query()->create(['name' => 'Other']);
        $otherTag = TicketTag::factory()->create();
        $target = Ticket::factory()->create();

        Tickets::for($ticket)->asSystem()->assign($agent)->watchers()->add($agent)->remove($agent);
        Tickets::for($ticket)->asSystem()->tags()->attach('printer')->detach('printer')->sync(['scanner']);
        Tickets::for($ticket)->asSystem()->links()->add($target, LinkType::Blocks)->remove($target, LinkType::Blocks);
        $field = fakeField();
        Tickets::for($ticket)->asSystem()->fields()->set('asset_tag', 'PRN-1')->forget('asset_tag');
        Tickets::fields()->update($field, new UpdateFieldData(position: 3));
        Tickets::fields()->delete($field);
        Tickets::fields()->restore($field);
        Tickets::fields()->prune();
        Tickets::tags()->create('toner');
        $tag = Tickets::tags()->create('drum');
        Tickets::tags()->rename($tag, 'drums');
        Tickets::tags()->delete($tag);
        Tickets::for($ticket)->asSystem()->mergeInto($target);

        $mismatches = [
            fn () => $fake->assertAssigned($ticket, $other),
            fn () => $fake->assertWatched($ticket, $other),
            fn () => $fake->assertUnwatched($ticket, $other),
            fn () => $fake->assertTagged($ticket, 'nope'),
            fn () => $fake->assertUntagged($ticket, 'nope'),
            fn () => $fake->assertTagsSynced($ticket, 'printer'),
            fn () => $fake->assertLinked($ticket, LinkType::ParentOf),
            fn () => $fake->assertUnlinked($ticket, LinkType::ParentOf),
            fn () => $fake->assertMerged($ticket, 'TCK-404'),
            fn () => $fake->assertMerged($ticket, $ticket),
            fn () => $fake->assertFieldsSet($ticket, 'nope'),
            fn () => $fake->assertFieldsForgotten($ticket, 'nope'),
            fn () => $fake->assertFieldUpdated('nope'),
            fn () => $fake->assertFieldDeleted('nope'),
            fn () => $fake->assertFieldRestored('nope'),
            fn () => $fake->assertFieldsPruned(true),
            fn () => $fake->assertTagCreated('nope'),
            fn () => $fake->assertTagUpdated($otherTag),
            fn () => $fake->assertTagDeleted($otherTag),
        ];

        foreach ($mismatches as $mismatch) {
            expect($mismatch)->toThrow(ExpectationFailedException::class, 'none matched');
        }

        $fake->assertAssigned($ticket, $agent);
        $fake->assertWatched($ticket, $agent);
        $fake->assertUnwatched($ticket, $agent);
        $fake->assertTagsSynced($ticket, 'scanner');
        $fake->assertLinked($ticket, LinkType::Blocks);
        $fake->assertMerged($ticket, $target);
        $fake->assertMerged($ticket, $target->reference);
        $fake->assertFieldUpdated('asset_tag');
        $fake->assertFieldsPruned(false);
        $fake->assertTagCreated('toner');
        $fake->assertTagUpdated($tag);
        $fake->assertTagDeleted($tag);
    });

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

    it('counts a published reply\'s explicit transition as a transition, and nothing else', function (): void {
        defineAgents();
        $fake = Tickets::fake();
        $ticket = fakeTicket();
        $agent = fakeAgent();

        Tickets::for($ticket)->asSystem()->assign($agent);

        // The assignment opened the ticket — an automation, not a recorded transition.
        $fake->assertNothingTransitioned();

        Tickets::for($ticket)->by($agent)->reply('Please send a photo.', transition: 'wait');

        $fake->assertTransitioned($ticket, 'wait');
        $fake->assertTransitioned('TCK-1');
        expect(fn () => $fake->assertTransitioned($ticket, 'resolve'))->toThrow(ExpectationFailedException::class, 'none matched')
            ->and(fn () => $fake->assertNothingTransitioned())->toThrow(ExpectationFailedException::class, '1 were recorded');
    });

    it('does not count the transition a held reply asked for', function (): void {
        defineAgents();
        config()->set('comments.blocklist', ['scam']);
        config()->set('comments.blocklist_action', 'pending');
        $fake = Tickets::fake();
        $ticket = fakeTicket();

        Tickets::for($ticket)->by(fakeAgent())->reply('A scam, surely.', transition: 'wait');

        expect(fn () => $fake->assertTransitioned($ticket, 'wait'))->toThrow(ExpectationFailedException::class, 'none matched')
            ->and(fn () => $fake->assertTransitioned())->toThrow(ExpectationFailedException::class, 'none matched');
        $fake->assertNothingTransitioned();
        $fake->assertReplied($ticket);
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

function fakeAgent(): User
{
    return User::query()->where('name', 'Grace')->first() ?? User::query()->create(['name' => 'Grace', 'is_agent' => true]);
}

/**
 * The `asset_tag` field, defined once — straight through the action, so the fake records
 * nothing for it.
 */
function fakeField(): TicketField
{
    return TicketField::withTrashed()->where('key', 'asset_tag')->first()
        ?? app(DefineFieldAction::class)->execute(new FieldData('asset_tag', FieldType::Text, 'Asset tag'));
}

function fakeTag(): TicketTag
{
    return TicketTag::query()->first() ?? TicketTag::factory()->create();
}

/**
 * A file on TCK-1, on a faked private disk.
 */
function fakeAttachment(): Media
{
    Storage::fake('local');

    $ticket = Ticket::query()->where('reference', 'TCK-1')->first() ?? fakeTicket();

    return Tickets::for($ticket)->asSystem()->attachments()->add(UploadedFile::fake()->createWithContent('a.txt', 'Some plain text.'));
}

/**
 * A ticket a guest filed, with a link generator that needs no routes.
 */
function fakeGuestTicket(): Ticket
{
    app()->bind(GuestLinkGenerator::class, static fn (): GuestLinkGenerator => new class implements GuestLinkGenerator
    {
        public function for(Ticket $ticket, Model $recipient): string
        {
            return 'https://helpdesk.test/'.$ticket->reference;
        }
    });

    return Ticket::query()->where('reference', 'TCK-1')->first()
        ?? Tickets::make()->subject('faked')->guest(['name' => 'Jo', 'email' => 'jo@acme.test'])->asSystem()->create();
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

/**
 * @return array<string, mixed>
 */
function fakeGuestFields(TicketGuest $guest): array
{
    return $guest->fresh()?->getAttachedAttributes()->sortKeys()->all() ?? [];
}
