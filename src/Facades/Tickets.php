<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Tickets\Testing\TicketsFake;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * The ticketing API. Sugar over {@see TicketsManager} — inject the manager for the same API,
 * or call the actions directly.
 *
 * @method static \RoundlyConsulting\Tickets\Builders\TicketBuilder make()
 * @method static \RoundlyConsulting\Tickets\Models\Ticket create(\RoundlyConsulting\Tickets\DataTransferObjects\CreateTicketData $data)
 * @method static \RoundlyConsulting\Tickets\Models\Ticket|null find(string $reference, bool $withTrashed = false)
 * @method static \RoundlyConsulting\Tickets\Models\Ticket findOrFail(string $reference, bool $withTrashed = false)
 * @method static \RoundlyConsulting\Tickets\Handles\TicketHandle for(\RoundlyConsulting\Tickets\Models\Ticket|string $ticket)
 * @method static \Illuminate\Database\Eloquent\Builder<\RoundlyConsulting\Tickets\Models\Ticket> visibleTo(\Illuminate\Database\Eloquent\Model $user)
 * @method static \RoundlyConsulting\Tickets\Handles\ProjectTickets project(\Illuminate\Database\Eloquent\Model&\RoundlyConsulting\Tickets\Contracts\TicketProject $project)
 * @method static \RoundlyConsulting\Tickets\Accessors\Categories categories()
 * @method static \RoundlyConsulting\Tickets\Accessors\Fields fields()
 * @method static \RoundlyConsulting\Tickets\Accessors\Tags tags()
 * @method static \RoundlyConsulting\Tickets\Accessors\Guests guests()
 * @method static \RoundlyConsulting\Tickets\DataTransferObjects\SweepResult sweep()
 *
 * The recording fake, after `Tickets::fake()`:
 * @method static list<\RoundlyConsulting\Tickets\Testing\RecordedTicketOperation> recorded(\RoundlyConsulting\Tickets\Enums\TicketOperation|null $operation = null)
 * @method static void assertNothingHappened()
 * @method static void assertCreated(\Closure|null $callback = null)
 * @method static void assertNothingCreated()
 * @method static void assertUpdated(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null)
 * @method static void assertNothingUpdated()
 * @method static void assertPrioritized(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, \BackedEnum|string|null $priority = null)
 * @method static void assertNothingPrioritized()
 * @method static void assertCategorized(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, \RoundlyConsulting\Tickets\Models\TicketCategory|int|null $category = null)
 * @method static void assertNothingCategorized()
 * @method static void assertDueSet(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null)
 * @method static void assertNoDueSet()
 * @method static void assertRequesterChanged(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null)
 * @method static void assertNoRequesterChanged()
 * @method static void assertDeleted(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null)
 * @method static void assertNothingDeleted()
 * @method static void assertRestored(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null)
 * @method static void assertNothingRestored()
 * @method static void assertTransitioned(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, string|null $transition = null)
 * @method static void assertNothingTransitioned()
 * @method static void assertReplied(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, \RoundlyConsulting\Tickets\Enums\AuthorRole|string|null $role = null)
 * @method static void assertNothingReplied()
 * @method static void assertNoted(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null)
 * @method static void assertNothingNoted()
 * @method static void assertCommentUpdated(\RoundlyConsulting\Comments\Models\Comment|null $comment = null)
 * @method static void assertNoCommentUpdated()
 * @method static void assertCommentDeleted(\RoundlyConsulting\Comments\Models\Comment|null $comment = null)
 * @method static void assertNoCommentDeleted()
 * @method static void assertAssigned(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, \Illuminate\Database\Eloquent\Model|null $assignee = null)
 * @method static void assertNothingAssigned()
 * @method static void assertUnassigned(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null)
 * @method static void assertNothingUnassigned()
 * @method static void assertClaimed(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null)
 * @method static void assertNothingClaimed()
 * @method static void assertWatched(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, \Illuminate\Database\Eloquent\Model|null $watcher = null)
 * @method static void assertNothingWatched()
 * @method static void assertUnwatched(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, \Illuminate\Database\Eloquent\Model|null $watcher = null)
 * @method static void assertNothingUnwatched()
 * @method static void assertTagged(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, string|null $tag = null)
 * @method static void assertNothingTagged()
 * @method static void assertUntagged(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, string|null $tag = null)
 * @method static void assertNothingUntagged()
 * @method static void assertTagsSynced(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, string|null $tag = null)
 * @method static void assertNoTagsSynced()
 * @method static void assertLinked(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, \RoundlyConsulting\Tickets\Enums\LinkType|null $type = null)
 * @method static void assertNothingLinked()
 * @method static void assertUnlinked(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, \RoundlyConsulting\Tickets\Enums\LinkType|null $type = null)
 * @method static void assertNothingUnlinked()
 * @method static void assertMerged(\RoundlyConsulting\Tickets\Models\Ticket|string|null $source = null, \RoundlyConsulting\Tickets\Models\Ticket|string|null $target = null)
 * @method static void assertNothingMerged()
 * @method static void assertAttached(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null)
 * @method static void assertNothingAttached()
 * @method static void assertAttachmentRemoved(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null)
 * @method static void assertNoAttachmentRemoved()
 * @method static void assertFieldsSet(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, string|null $key = null)
 * @method static void assertNoFieldsSet()
 * @method static void assertFieldsForgotten(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, string|null $key = null)
 * @method static void assertNoFieldsForgotten()
 * @method static void assertCategoryCreated(\Closure|null $callback = null)
 * @method static void assertNoCategoryCreated()
 * @method static void assertCategoryUpdated(\RoundlyConsulting\Tickets\Models\TicketCategory|null $category = null)
 * @method static void assertNoCategoryUpdated()
 * @method static void assertCategoryMoved(\RoundlyConsulting\Tickets\Models\TicketCategory|null $category = null)
 * @method static void assertNoCategoryMoved()
 * @method static void assertCategoryDeleted(\RoundlyConsulting\Tickets\Models\TicketCategory|null $category = null)
 * @method static void assertNoCategoryDeleted()
 * @method static void assertCategoryRestored(\RoundlyConsulting\Tickets\Models\TicketCategory|null $category = null)
 * @method static void assertNoCategoryRestored()
 * @method static void assertFieldDefined(string|null $key = null)
 * @method static void assertNoFieldDefined()
 * @method static void assertFieldUpdated(string|null $key = null)
 * @method static void assertNoFieldUpdated()
 * @method static void assertFieldDeleted(string|null $key = null)
 * @method static void assertNoFieldDeleted()
 * @method static void assertFieldRestored(string|null $key = null)
 * @method static void assertNoFieldRestored()
 * @method static void assertFieldsPruned(bool|null $dryRun = null, int|null $chunk = null)
 * @method static void assertNoFieldsPruned()
 * @method static void assertTagCreated(string|null $name = null)
 * @method static void assertNoTagCreated()
 * @method static void assertTagUpdated(\RoundlyConsulting\Tickets\Models\TicketTag|null $tag = null)
 * @method static void assertNoTagUpdated()
 * @method static void assertTagDeleted(\RoundlyConsulting\Tickets\Models\TicketTag|null $tag = null)
 * @method static void assertNoTagDeleted()
 * @method static void assertGuestResolved(\Closure|null $callback = null)
 * @method static void assertNoGuestResolved()
 * @method static void assertGuestUpdated(\RoundlyConsulting\Tickets\Models\TicketGuest|null $guest = null)
 * @method static void assertNoGuestUpdated()
 * @method static void assertGuestConverted(\RoundlyConsulting\Tickets\Models\TicketGuest|null $guest = null)
 * @method static void assertNoGuestConverted()
 * @method static void assertGuestLinkIssued(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null, \RoundlyConsulting\Tickets\Models\TicketGuest|null $guest = null)
 * @method static void assertNoGuestLinkIssued()
 * @method static void assertGuestAccessRevoked(\RoundlyConsulting\Tickets\Models\Ticket|string|null $ticket = null)
 * @method static void assertNoGuestAccessRevoked()
 * @method static void assertSwept(\Closure|null $callback = null)
 * @method static void assertNothingSwept()
 *
 * @see TicketsManager
 */
final class Tickets extends Facade
{
    /**
     * Swap the manager for a recording fake — behind the facade and in the container, so an
     * injected TicketsManager is faked too. Every call still runs; each one is recorded.
     */
    public static function fake(): TicketsFake
    {
        $fake = app(TicketsFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return TicketsManager::class;
    }
}
