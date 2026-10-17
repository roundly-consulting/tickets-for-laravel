<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Merge;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Comments\CommentsManager;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\Tickets\Actions\Guests\RevokeGuestAccessAction;
use RoundlyConsulting\Tickets\Actions\Internal\ApplyAutomationAction;
use RoundlyConsulting\Tickets\Actions\Internal\AutoWatchAction;
use RoundlyConsulting\Tickets\Actions\Internal\ChangeTicketTagsAction;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Actions\Internal\WriteTicketCommentAction;
use RoundlyConsulting\Tickets\DataTransferObjects\MergeResult;
use RoundlyConsulting\Tickets\DataTransferObjects\ReplyData;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Enums\Automation;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Events\TicketMerged;
use RoundlyConsulting\Tickets\Exceptions\InvalidMergeException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketLink;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\AttachmentBucket;
use RoundlyConsulting\Tickets\Support\Messages;
use RoundlyConsulting\Tickets\Support\TagInput;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketTransaction;
use RoundlyConsulting\Tickets\Support\TicketVisibility;

/**
 * Merges `$source` into `$target`, in one transaction with both tickets locked in primary-key
 * order:
 *
 *  1. every comment of the source moves to the target (`Comments::transfer()`);
 *  2. then a system internal note goes on the target and a system public reply stays on the
 *     source (posted after the transfer, which moves every source comment);
 *  3. the target gains the source's watchers — and the source's requester, when different, so
 *     both requesters see the combined public thread (never its internal notes);
 *  4. the target gains the source's tags (those its project allows);
 *  5. the source's attachments move to the target (`tickets.merge.move_attachments`);
 *  6. a `duplicates` link source → target;
 *  7. the source records `merged_into_id` and its guest links are revoked
 *     (`RevokeGuestAccessAction`, so `GuestAccessRevoked` fires);
 *  8. the `Merged` automation dismisses the source (`merged:<target>`), which locks its
 *     conversation;
 *  9. public `merged` / `merged_from` activities that count only the messages the requester
 *     side may read and carry no reason — the full count and the reason go into internal
 *     entries of the same types — and `TicketMerged` after commit.
 *
 * Refused (`InvalidMergeException`): the same ticket, a Closed or already-merged source or
 * target, a cross-project merge with `tickets.merge.cross_project` off, and an actor the merge
 * ability does not allow or the agent scope keeps off either ticket. Custom field values are not merged.
 */
final readonly class MergeTicketsAction
{
    public function __construct(
        private CommentsManager $comments,
        private WriteTicketCommentAction $write,
        private AutoWatchAction $watch,
        private ChangeTicketTagsAction $tags,
        private ApplyAutomationAction $automations,
        private RecordActivityAction $record,
        private MediaLibraryManager $media,
        private RevokeGuestAccessAction $revokeGuestAccess,
        private Gate $gate,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $source, Ticket $target, ?string $reason = null, ?Model $actor = null, bool $system = false): MergeResult
    {
        $actor = ActorResolver::resolve($actor, $system);

        if ($source->is($target)) {
            throw InvalidMergeException::same();
        }

        if ($actor !== null && ! $this->mayMerge($actor, $source, $target)) {
            throw InvalidMergeException::unauthorized();
        }

        return TicketTransaction::run($source, function () use ($source, $target, $reason, $actor, $system): MergeResult {
            TicketLocker::lockInOrder($source, $target);

            $this->assertMergeable($source, $target);

            $published = $source->comments()->tap(TicketComments::wherePublished(...))->count();
            $comments = $this->comments->transfer($source, $target);
            $this->postSystemMessages($source, $target);
            $this->mergeWatchers($source, $target);
            $this->mergeTags($source, $target, $actor, $system);
            $attachments = $this->moveAttachments($source, $target);

            if (TicketLink::query()->where('ticket_id', $source->getKey())->where('related_ticket_id', $target->getKey())->where('type', LinkType::Duplicates->value)->doesntExist()) {
                TicketLink::query()->create([
                    'ticket_id' => $source->getKey(),
                    'related_ticket_id' => $target->getKey(),
                    'type' => LinkType::Duplicates,
                    'created_by_type' => $actor?->getMorphClass(),
                    'created_by_id' => $actor?->getKey(),
                    'created_at' => CarbonImmutable::now(),
                ]);
            }

            $source->forceFill(['merged_into_id' => $target->getKey()])->save();
            $source->setRelation('mergedInto', $target);

            // Every guest link to the source dies with it (`GuestAccessRevoked`).
            $this->revokeGuestAccess->execute($source, $actor, $system);

            $this->automations->execute($source, Automation::Merged, $actor, 'merged:'.$target->reference);

            $this->recordMerge($source, $target, $published, $comments, $attachments, $reason, $actor, $system);

            $result = new MergeResult($source, $target, $comments, $attachments);

            $this->events->dispatch(new TicketMerged($source, $target, $result, $actor));

            return $result;
        });
    }

    /**
     * The merge ability, held by an agent the agent scope lets work on both tickets.
     */
    private function mayMerge(Model $actor, Ticket $source, Ticket $target): bool
    {
        return $actor instanceof Authenticatable
            && $this->gate->forUser($actor)->allows(TicketsConfig::mergeAbility())
            && TicketVisibility::isAgentFor($actor, $source)
            && TicketVisibility::isAgentFor($actor, $target);
    }

    /**
     * The public entries count only what the requester side may read (S-1: a count never
     * reveals a note) and leave the agent's reason out; when the whole story differs, agents
     * get it in an internal entry of the same type.
     */
    private function recordMerge(Ticket $source, Ticket $target, int $published, int $comments, int $attachments, ?string $reason, ?Model $actor, bool $system): void
    {
        $this->record->execute($source, ActivityType::Merged, $actor, ['into' => $target->reference, 'comments' => $published, 'attachments' => $attachments], $system);
        $this->record->execute($target, ActivityType::MergedFrom, $actor, ['from' => $source->reference, 'comments' => $published], $system);

        if ($published === $comments && $reason === null) {
            return;
        }

        $extra = array_filter(['comments' => $comments, 'reason' => $reason], static fn (mixed $value): bool => $value !== null);

        $this->record->execute($source, ActivityType::Merged, $actor, ['into' => $target->reference, ...$extra, 'attachments' => $attachments], $system, ActivityVisibility::Internal);
        $this->record->execute($target, ActivityType::MergedFrom, $actor, ['from' => $source->reference, ...$extra], $system, ActivityVisibility::Internal);
    }

    private function assertMergeable(Ticket $source, Ticket $target): void
    {
        if ($source->merged_into_id !== null) {
            throw InvalidMergeException::sourceMerged($source);
        }

        if ($source->statusCategory() === StatusCategory::Closed) {
            throw InvalidMergeException::sourceClosed($source);
        }

        if ($target->merged_into_id !== null) {
            throw InvalidMergeException::targetMerged($target, $this->finalTarget($target)->reference);
        }

        if ($target->statusCategory() === StatusCategory::Closed) {
            throw InvalidMergeException::targetClosed($target);
        }

        if (! TicketsConfig::crossProjectMerge()
            && ($source->project_type !== $target->project_type || (string) $source->project_id !== (string) $target->project_id)) {
            throw InvalidMergeException::crossProject();
        }
    }

    /**
     * Where a merged ticket's chain ends — bounded, so corrupt data can never loop.
     */
    private function finalTarget(Ticket $ticket): Ticket
    {
        $seen = [];

        while ($ticket->merged_into_id !== null && count($seen) < 100) {
            $seen[(string) $ticket->getKey()] = true;
            $next = TicketModels::ticket()::withTrashed()->find($ticket->merged_into_id);

            if ($next === null || isset($seen[(string) $next->getKey()])) {
                break;
            }

            $ticket = $next;
        }

        return $ticket;
    }

    private function postSystemMessages(Ticket $source, Ticket $target): void
    {
        $channel = TicketInput::channel(null);

        $this->write->execute($target, null, AuthorRole::System, new ReplyData(
            Messages::get('merge.note_on_target', ['source' => $source->reference]),
        ), $channel, internal: true);

        $this->write->execute($source, null, AuthorRole::System, new ReplyData(
            Messages::get('merge.reply_on_source', ['target' => $target->reference]),
        ), $channel, internal: false);
    }

    private function mergeWatchers(Ticket $source, Ticket $target): void
    {
        foreach (TicketWatcher::query()->where('ticket_id', $source->getKey())->orderBy('id')->get() as $watch) {
            $this->watch->morph($target, $watch->watcher_type, $watch->watcher_id, $watch->auto);
        }

        if ($source->requester_type !== $target->requester_type || (string) $source->requester_id !== (string) $target->requester_id) {
            $this->watch->morph($target, $source->requester_type, $source->requester_id);
        }

        $target->unsetRelation('watchers');
    }

    private function mergeTags(Ticket $source, Ticket $target, ?Model $actor, bool $system): void
    {
        $tags = [];

        foreach ($source->tags()->get() as $tag) {
            if (TagInput::fits($tag, $target)) {
                $tags[(int) $tag->getKey()] = $tag;
            }
        }

        $this->tags->execute($target, $tags, [], $actor, $system);
    }

    private function moveAttachments(Ticket $source, Ticket $target): int
    {
        if (! TicketsConfig::mergeMovesAttachments()) {
            return 0;
        }

        $moved = 0;

        foreach (AttachmentBucket::all($source) as $media) {
            $this->media->move($media, $target, TicketsConfig::attachmentsBucket());
            $moved++;
        }

        return $moved;
    }
}
