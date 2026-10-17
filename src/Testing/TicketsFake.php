<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Testing;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\DataTransferObjects\SweepResult;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * A recording, still-performing manager, installed by `Tickets::fake()`. Every operation
 * runs for real (rows, events, policies) and each successful one is recorded — however it
 * was called: the facade, an injected manager, a handle, a sub-accessor, a builder or a
 * model method. A call that throws records nothing.
 */
final class TicketsFake extends TicketsManager
{
    /** @var list<RecordedTicketOperation> */
    private array $recorded = [];

    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    /**
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @param  array<string, mixed>  $context
     * @return TResult
     */
    public function perform(TicketOperation $operation, string $action, Closure $execute, array $context = []): mixed
    {
        $result = parent::perform($operation, $action, $execute, $context);

        $this->recorded[] = new RecordedTicketOperation($operation, $context, $result);

        return $result;
    }

    /**
     * Every recorded operation, optionally of one kind, in call order.
     *
     * @return list<RecordedTicketOperation>
     */
    public function recorded(?TicketOperation $operation = null): array
    {
        return array_values(array_filter(
            $this->recorded,
            static fn (RecordedTicketOperation $recorded): bool => $operation === null || $recorded->operation === $operation,
        ));
    }

    public function assertNothingHappened(): void
    {
        PHPUnit::assertEmpty(
            $this->recorded,
            sprintf('Expected no ticket operations, but %d were recorded.', count($this->recorded)),
        );
    }

    // Tickets -------------------------------------------------------------------------

    /**
     * @param  (Closure(Ticket): bool)|null  $callback
     */
    public function assertCreated(?Closure $callback = null): void
    {
        $this->assertRecorded(TicketOperation::Create, static function (RecordedTicketOperation $recorded) use ($callback): bool {
            $ticket = $recorded->ticket();

            return $callback === null || ($ticket !== null && $callback($ticket));
        });
    }

    public function assertNothingCreated(): void
    {
        $this->assertNotRecorded(TicketOperation::Create);
    }

    public function assertUpdated(Ticket|string|null $ticket = null): void
    {
        $this->assertRecorded(TicketOperation::Update, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket));
    }

    public function assertNothingUpdated(): void
    {
        $this->assertNotRecorded(TicketOperation::Update);
    }

    public function assertPrioritized(Ticket|string|null $ticket = null, BackedEnum|string|null $priority = null): void
    {
        $expected = $priority instanceof BackedEnum ? $priority->value : $priority;

        $this->assertRecorded(TicketOperation::ChangePriority, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket)
            && ($expected === null || $recorded->context['priority'] === $expected));
    }

    public function assertNothingPrioritized(): void
    {
        $this->assertNotRecorded(TicketOperation::ChangePriority);
    }

    public function assertCategorized(Ticket|string|null $ticket = null, TicketCategory|int|null $category = null): void
    {
        $expected = $category instanceof TicketCategory ? $category->getKey() : $category;

        $this->assertRecorded(TicketOperation::ChangeCategory, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket)
            && ($expected === null || $recorded->context['category'] === $expected));
    }

    public function assertNothingCategorized(): void
    {
        $this->assertNotRecorded(TicketOperation::ChangeCategory);
    }

    public function assertDueSet(Ticket|string|null $ticket = null): void
    {
        $this->assertRecorded(TicketOperation::SetDueDate, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket));
    }

    public function assertNothingDueSet(): void
    {
        $this->assertNotRecorded(TicketOperation::SetDueDate);
    }

    public function assertRequesterChanged(Ticket|string|null $ticket = null): void
    {
        $this->assertRecorded(TicketOperation::ChangeRequester, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket));
    }

    public function assertNothingRequesterChanged(): void
    {
        $this->assertNotRecorded(TicketOperation::ChangeRequester);
    }

    public function assertDeleted(Ticket|string|null $ticket = null): void
    {
        $this->assertRecorded(TicketOperation::Delete, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket));
    }

    public function assertNothingDeleted(): void
    {
        $this->assertNotRecorded(TicketOperation::Delete);
    }

    public function assertRestored(Ticket|string|null $ticket = null): void
    {
        $this->assertRecorded(TicketOperation::Restore, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket));
    }

    public function assertNothingRestored(): void
    {
        $this->assertNotRecorded(TicketOperation::Restore);
    }

    public function assertTransitioned(Ticket|string|null $ticket = null, ?string $transition = null): void
    {
        $this->assertRecorded(TicketOperation::Transition, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket)
            && ($transition === null || $recorded->context['transition'] === $transition));
    }

    public function assertNothingTransitioned(): void
    {
        $this->assertNotRecorded(TicketOperation::Transition);
    }

    // Conversation --------------------------------------------------------------------

    /**
     * A public reply was posted — on `$ticket`, by an author holding `$role`.
     */
    public function assertReplied(Ticket|string|null $ticket = null, AuthorRole|string|null $role = null): void
    {
        $expected = $role instanceof AuthorRole ? $role : ($role === null ? null : AuthorRole::from($role));

        $this->assertRecorded(TicketOperation::Reply, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket)
            && ($expected === null || ($recorded->result instanceof Comment && TicketComments::role($recorded->result) === $expected)));
    }

    public function assertNothingReplied(): void
    {
        $this->assertNotRecorded(TicketOperation::Reply);
    }

    public function assertNoted(Ticket|string|null $ticket = null): void
    {
        $this->assertRecorded(TicketOperation::Note, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket));
    }

    public function assertNothingNoted(): void
    {
        $this->assertNotRecorded(TicketOperation::Note);
    }

    public function assertCommentUpdated(?Comment $comment = null): void
    {
        $this->assertRecorded(TicketOperation::UpdateComment, static fn (RecordedTicketOperation $recorded): bool => $comment === null
            || ($recorded->model('comment')?->is($comment) ?? false));
    }

    public function assertNoCommentUpdated(): void
    {
        $this->assertNotRecorded(TicketOperation::UpdateComment);
    }

    public function assertCommentDeleted(?Comment $comment = null): void
    {
        $this->assertRecorded(TicketOperation::DeleteComment, static fn (RecordedTicketOperation $recorded): bool => $comment === null
            || ($recorded->model('comment')?->is($comment) ?? false));
    }

    public function assertNoCommentDeleted(): void
    {
        $this->assertNotRecorded(TicketOperation::DeleteComment);
    }

    // Work management -----------------------------------------------------------------

    public function assertAssigned(Ticket|string|null $ticket = null, ?Model $assignee = null): void
    {
        $this->assertRecorded(TicketOperation::Assign, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket)
            && ($assignee === null || ($recorded->model('assignee')?->is($assignee) ?? false)));
    }

    public function assertNothingAssigned(): void
    {
        $this->assertNotRecorded(TicketOperation::Assign);
    }

    public function assertUnassigned(Ticket|string|null $ticket = null): void
    {
        $this->assertRecorded(TicketOperation::Unassign, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket));
    }

    public function assertNothingUnassigned(): void
    {
        $this->assertNotRecorded(TicketOperation::Unassign);
    }

    public function assertClaimed(Ticket|string|null $ticket = null): void
    {
        $this->assertRecorded(TicketOperation::Claim, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket));
    }

    public function assertNothingClaimed(): void
    {
        $this->assertNotRecorded(TicketOperation::Claim);
    }

    public function assertWatched(Ticket|string|null $ticket = null, ?Model $watcher = null): void
    {
        $this->assertRecorded(TicketOperation::AddWatcher, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket)
            && ($watcher === null || ($recorded->model('watcher')?->is($watcher) ?? false)));
    }

    public function assertNothingWatched(): void
    {
        $this->assertNotRecorded(TicketOperation::AddWatcher);
    }

    public function assertUnwatched(Ticket|string|null $ticket = null, ?Model $watcher = null): void
    {
        $this->assertRecorded(TicketOperation::RemoveWatcher, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket)
            && ($watcher === null || ($recorded->model('watcher')?->is($watcher) ?? false)));
    }

    public function assertNothingUnwatched(): void
    {
        $this->assertNotRecorded(TicketOperation::RemoveWatcher);
    }

    /**
     * Tags were attached — on `$ticket`, `$tag` (a name or slug) among them.
     */
    public function assertTagged(Ticket|string|null $ticket = null, ?string $tag = null): void
    {
        $this->assertTagOperation(TicketOperation::AttachTags, $ticket, $tag);
    }

    public function assertNothingTagged(): void
    {
        $this->assertNotRecorded(TicketOperation::AttachTags);
    }

    public function assertUntagged(Ticket|string|null $ticket = null, ?string $tag = null): void
    {
        $this->assertTagOperation(TicketOperation::DetachTags, $ticket, $tag);
    }

    public function assertNothingUntagged(): void
    {
        $this->assertNotRecorded(TicketOperation::DetachTags);
    }

    public function assertTagsSynced(Ticket|string|null $ticket = null, ?string $tag = null): void
    {
        $this->assertTagOperation(TicketOperation::SyncTags, $ticket, $tag);
    }

    public function assertNothingTagsSynced(): void
    {
        $this->assertNotRecorded(TicketOperation::SyncTags);
    }

    public function assertLinked(Ticket|string|null $ticket = null, ?LinkType $type = null): void
    {
        $this->assertRecorded(TicketOperation::Link, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket)
            && ($type === null || $recorded->context['type'] === $type));
    }

    public function assertNothingLinked(): void
    {
        $this->assertNotRecorded(TicketOperation::Link);
    }

    public function assertUnlinked(Ticket|string|null $ticket = null, ?LinkType $type = null): void
    {
        $this->assertRecorded(TicketOperation::Unlink, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket)
            && ($type === null || $recorded->context['type'] === $type));
    }

    public function assertNothingUnlinked(): void
    {
        $this->assertNotRecorded(TicketOperation::Unlink);
    }

    /**
     * `$source` was merged — into `$target`.
     */
    public function assertMerged(Ticket|string|null $source = null, Ticket|string|null $target = null): void
    {
        $this->assertRecorded(TicketOperation::Merge, static function (RecordedTicketOperation $recorded) use ($source, $target): bool {
            $into = $recorded->model('target');

            return $recorded->concerns($source)
                && ($target === null || ($into instanceof Ticket && (is_string($target) ? $into->reference === $target : $into->is($target))));
        });
    }

    public function assertNothingMerged(): void
    {
        $this->assertNotRecorded(TicketOperation::Merge);
    }

    public function assertAttached(Ticket|string|null $ticket = null): void
    {
        $this->assertRecorded(TicketOperation::AddAttachment, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket));
    }

    public function assertNothingAttached(): void
    {
        $this->assertNotRecorded(TicketOperation::AddAttachment);
    }

    public function assertAttachmentRemoved(Ticket|string|null $ticket = null): void
    {
        $this->assertRecorded(TicketOperation::RemoveAttachment, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket));
    }

    public function assertNoAttachmentRemoved(): void
    {
        $this->assertNotRecorded(TicketOperation::RemoveAttachment);
    }

    /**
     * Custom field values were set — on `$ticket`, `$key` among them.
     */
    public function assertFieldsSet(Ticket|string|null $ticket = null, ?string $key = null): void
    {
        $this->assertRecorded(TicketOperation::SetFields, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket)
            && ($key === null || in_array($key, (array) $recorded->context['keys'], true)));
    }

    public function assertNoFieldsSet(): void
    {
        $this->assertNotRecorded(TicketOperation::SetFields);
    }

    public function assertFieldsForgotten(Ticket|string|null $ticket = null, ?string $key = null): void
    {
        $this->assertRecorded(TicketOperation::ForgetFields, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket)
            && ($key === null || in_array($key, (array) $recorded->context['keys'], true)));
    }

    public function assertNoFieldsForgotten(): void
    {
        $this->assertNotRecorded(TicketOperation::ForgetFields);
    }

    // Categories ----------------------------------------------------------------------

    /**
     * @param  (Closure(TicketCategory): bool)|null  $callback
     */
    public function assertCategoryCreated(?Closure $callback = null): void
    {
        $this->assertRecorded(TicketOperation::CreateCategory, static fn (RecordedTicketOperation $recorded): bool => $callback === null
            || ($recorded->result instanceof TicketCategory && $callback($recorded->result)));
    }

    public function assertNoCategoryCreated(): void
    {
        $this->assertNotRecorded(TicketOperation::CreateCategory);
    }

    public function assertCategoryUpdated(?TicketCategory $category = null): void
    {
        $this->assertCategoryOperation(TicketOperation::UpdateCategory, $category);
    }

    public function assertNoCategoryUpdated(): void
    {
        $this->assertNotRecorded(TicketOperation::UpdateCategory);
    }

    public function assertCategoryMoved(?TicketCategory $category = null): void
    {
        $this->assertCategoryOperation(TicketOperation::MoveCategory, $category);
    }

    public function assertNoCategoryMoved(): void
    {
        $this->assertNotRecorded(TicketOperation::MoveCategory);
    }

    public function assertCategoryDeleted(?TicketCategory $category = null): void
    {
        $this->assertCategoryOperation(TicketOperation::DeleteCategory, $category);
    }

    public function assertNoCategoryDeleted(): void
    {
        $this->assertNotRecorded(TicketOperation::DeleteCategory);
    }

    public function assertCategoryRestored(?TicketCategory $category = null): void
    {
        $this->assertCategoryOperation(TicketOperation::RestoreCategory, $category);
    }

    public function assertNoCategoryRestored(): void
    {
        $this->assertNotRecorded(TicketOperation::RestoreCategory);
    }

    // Fields --------------------------------------------------------------------------

    public function assertFieldDefined(?string $key = null): void
    {
        $this->assertRecorded(TicketOperation::DefineField, static fn (RecordedTicketOperation $recorded): bool => $key === null
            || ($recorded->result instanceof TicketField && $recorded->result->key === $key));
    }

    public function assertNoFieldDefined(): void
    {
        $this->assertNotRecorded(TicketOperation::DefineField);
    }

    public function assertFieldUpdated(?string $key = null): void
    {
        $this->assertFieldOperation(TicketOperation::UpdateField, $key);
    }

    public function assertNoFieldUpdated(): void
    {
        $this->assertNotRecorded(TicketOperation::UpdateField);
    }

    public function assertFieldDeleted(?string $key = null): void
    {
        $this->assertFieldOperation(TicketOperation::DeleteField, $key);
    }

    public function assertNoFieldDeleted(): void
    {
        $this->assertNotRecorded(TicketOperation::DeleteField);
    }

    public function assertFieldRestored(?string $key = null): void
    {
        $this->assertFieldOperation(TicketOperation::RestoreField, $key);
    }

    public function assertNoFieldRestored(): void
    {
        $this->assertNotRecorded(TicketOperation::RestoreField);
    }

    /**
     * Orphaned field values were pruned — for real, or with `$dryRun`, counted.
     */
    public function assertFieldsPruned(?bool $dryRun = null): void
    {
        $this->assertRecorded(TicketOperation::PruneFields, static fn (RecordedTicketOperation $recorded): bool => $dryRun === null
            || $recorded->context['dry_run'] === $dryRun);
    }

    public function assertNoFieldsPruned(): void
    {
        $this->assertNotRecorded(TicketOperation::PruneFields);
    }

    // Tags ----------------------------------------------------------------------------

    /**
     * A tag was created (or found, with `findOrCreate()`) — named `$name`.
     */
    public function assertTagCreated(?string $name = null): void
    {
        $this->assertRecorded(TicketOperation::CreateTag, static fn (RecordedTicketOperation $recorded): bool => $name === null
            || ($recorded->result instanceof TicketTag && $recorded->result->name === $name));
    }

    public function assertNoTagCreated(): void
    {
        $this->assertNotRecorded(TicketOperation::CreateTag);
    }

    public function assertTagUpdated(?TicketTag $tag = null): void
    {
        $this->assertRecorded(TicketOperation::UpdateTag, static fn (RecordedTicketOperation $recorded): bool => $tag === null
            || ($recorded->model('tag')?->is($tag) ?? false));
    }

    public function assertNoTagUpdated(): void
    {
        $this->assertNotRecorded(TicketOperation::UpdateTag);
    }

    public function assertTagDeleted(?TicketTag $tag = null): void
    {
        $this->assertRecorded(TicketOperation::DeleteTag, static fn (RecordedTicketOperation $recorded): bool => $tag === null
            || ($recorded->model('tag')?->is($tag) ?? false));
    }

    public function assertNoTagDeleted(): void
    {
        $this->assertNotRecorded(TicketOperation::DeleteTag);
    }

    // Guests --------------------------------------------------------------------------

    /**
     * @param  (Closure(TicketGuest): bool)|null  $callback
     */
    public function assertGuestResolved(?Closure $callback = null): void
    {
        $this->assertRecorded(TicketOperation::ResolveGuest, static fn (RecordedTicketOperation $recorded): bool => $callback === null
            || ($recorded->result instanceof TicketGuest && $callback($recorded->result)));
    }

    public function assertNoGuestResolved(): void
    {
        $this->assertNotRecorded(TicketOperation::ResolveGuest);
    }

    public function assertGuestUpdated(?TicketGuest $guest = null): void
    {
        $this->assertRecorded(TicketOperation::UpdateGuest, static fn (RecordedTicketOperation $recorded): bool => $guest === null
            || ($recorded->model('guest')?->is($guest) ?? false));
    }

    public function assertNoGuestUpdated(): void
    {
        $this->assertNotRecorded(TicketOperation::UpdateGuest);
    }

    public function assertGuestConverted(?TicketGuest $guest = null): void
    {
        $this->assertRecorded(TicketOperation::ConvertGuest, static fn (RecordedTicketOperation $recorded): bool => $guest === null
            || ($recorded->model('guest')?->is($guest) ?? false));
    }

    public function assertNoGuestConverted(): void
    {
        $this->assertNotRecorded(TicketOperation::ConvertGuest);
    }

    /**
     * A guest link was issued — for `$ticket`, to `$guest` (null in the call = the requester).
     */
    public function assertGuestLinkIssued(Ticket|string|null $ticket = null, ?TicketGuest $guest = null): void
    {
        $this->assertRecorded(TicketOperation::IssueGuestLink, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket)
            && ($guest === null || ($recorded->model('guest')?->is($guest) ?? false)));
    }

    public function assertNoGuestLinkIssued(): void
    {
        $this->assertNotRecorded(TicketOperation::IssueGuestLink);
    }

    public function assertGuestAccessRevoked(Ticket|string|null $ticket = null): void
    {
        $this->assertRecorded(TicketOperation::RevokeGuestAccess, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket));
    }

    public function assertNoGuestAccessRevoked(): void
    {
        $this->assertNotRecorded(TicketOperation::RevokeGuestAccess);
    }

    // Due dates -----------------------------------------------------------------------

    /**
     * The due-date sweep ran — and, with `$callback`, its result matched.
     *
     * @param  (Closure(SweepResult): bool)|null  $callback
     */
    public function assertSwept(?Closure $callback = null): void
    {
        $this->assertRecorded(TicketOperation::Sweep, static fn (RecordedTicketOperation $recorded): bool => $callback === null
            || ($recorded->result instanceof SweepResult && $callback($recorded->result)));
    }

    public function assertNothingSwept(): void
    {
        $this->assertNotRecorded(TicketOperation::Sweep);
    }

    // Plumbing ------------------------------------------------------------------------

    private function assertTagOperation(TicketOperation $operation, Ticket|string|null $ticket, ?string $tag): void
    {
        $this->assertRecorded($operation, static fn (RecordedTicketOperation $recorded): bool => $recorded->concerns($ticket)
            && ($tag === null || in_array($tag, (array) $recorded->context['tags'], true)));
    }

    private function assertFieldOperation(TicketOperation $operation, ?string $key): void
    {
        $this->assertRecorded($operation, static function (RecordedTicketOperation $recorded) use ($key): bool {
            $field = $recorded->model('field');

            return $key === null || ($field instanceof TicketField && $field->key === $key);
        });
    }

    private function assertCategoryOperation(TicketOperation $operation, ?TicketCategory $category): void
    {
        $this->assertRecorded($operation, static fn (RecordedTicketOperation $recorded): bool => $category === null
            || ($recorded->model('category')?->is($category) ?? false));
    }

    /**
     * @param  Closure(RecordedTicketOperation): bool  $matches
     */
    private function assertRecorded(TicketOperation $operation, Closure $matches): void
    {
        $recorded = $this->recorded($operation);

        PHPUnit::assertNotEmpty($recorded, "Expected a [{$operation->value}] ticket operation, but none was recorded.");
        PHPUnit::assertNotEmpty(
            array_filter($recorded, $matches),
            "A [{$operation->value}] ticket operation was recorded, but none matched.",
        );
    }

    private function assertNotRecorded(TicketOperation $operation): void
    {
        $count = count($this->recorded($operation));

        PHPUnit::assertSame(0, $count, "Expected no [{$operation->value}] ticket operation, but {$count} were recorded.");
    }
}
