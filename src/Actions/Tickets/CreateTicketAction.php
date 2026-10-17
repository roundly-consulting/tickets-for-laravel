<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tickets;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Tickets\Actions\Attachments\AddAttachmentAction;
use RoundlyConsulting\Tickets\Actions\Guests\ResolveGuestAction;
use RoundlyConsulting\Tickets\Actions\Internal\AllocateReferenceAction;
use RoundlyConsulting\Tickets\Actions\Internal\ApplyAutomationAction;
use RoundlyConsulting\Tickets\Actions\Internal\AutoWatchAction;
use RoundlyConsulting\Tickets\Actions\Internal\ChangeTicketTagsAction;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Actions\Internal\ResolveTagsAction;
use RoundlyConsulting\Tickets\Actions\Internal\WriteFieldValuesAction;
use RoundlyConsulting\Tickets\Actions\Watchers\AddWatcherAction;
use RoundlyConsulting\Tickets\Contracts\AssignmentStrategy;
use RoundlyConsulting\Tickets\Contracts\DueDateResolver;
use RoundlyConsulting\Tickets\Contracts\TicketProject;
use RoundlyConsulting\Tickets\DataTransferObjects\CreateTicketData;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\Automation;
use RoundlyConsulting\Tickets\Events\TicketAssigned;
use RoundlyConsulting\Tickets\Events\TicketCreated;
use RoundlyConsulting\Tickets\Exceptions\DuplicateTicketReferenceException;
use RoundlyConsulting\Tickets\Exceptions\InvalidProjectException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\Assignees;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Files a ticket. In one transaction: the guest requester is resolved, the category and
 * project are checked, the defaults the caller did not give are applied once (caller →
 * nearest category → config), the next reference of the ticket's sequence is allocated
 * under a row lock, the ticket is saved (lifecycle sets its initial state) and the
 * `created` activity is written; then the requester and assignee start watching
 * (`tickets.watchers.auto`), the custom field values are written (their defaults filled, the
 * required ones enforced), the tags, watchers and attachments added. `TicketCreated` follows
 * the commit.
 */
final readonly class CreateTicketAction
{
    public function __construct(
        private AllocateReferenceAction $allocate,
        private RecordActivityAction $record,
        private ResolveGuestAction $resolveGuest,
        private AssignmentStrategy $strategy,
        private DueDateResolver $dueDates,
        private ApplyAutomationAction $automations,
        private ResolveTagsAction $resolveTags,
        private ChangeTicketTagsAction $tags,
        private WriteFieldValuesAction $fields,
        private AutoWatchAction $watch,
        private AddWatcherAction $addWatcher,
        private AddAttachmentAction $addAttachment,
        private Dispatcher $events,
    ) {}

    public function execute(CreateTicketData $data): Ticket
    {
        $actor = ActorResolver::resolve($data->actor, $data->system);
        $subject = TicketInput::subject($data->subject);
        $channel = TicketInput::channel($data->channel);
        $project = $data->project;

        if ($project !== null && ! $project instanceof TicketProject) {
            throw InvalidProjectException::notAProject($project);
        }

        $class = TicketModels::ticket();
        $ticket = new $class;

        return TicketTransaction::run($ticket, function () use ($ticket, $data, $actor, $subject, $channel, $project): Ticket {
            $requester = $data->requester instanceof GuestData
                ? $this->resolveGuest->execute($data->requester)
                : $data->requester;

            [$projectType, $projectId] = TicketInput::morph($project);
            $category = $data->category === null ? null : TicketInput::category($data->category, $projectType, $projectId);

            $ticket->forceFill([
                'subject' => $subject,
                'description' => $data->description,
                'requester_type' => $requester->getMorphClass(),
                'requester_id' => $requester->getKey(),
                'project_type' => $projectType,
                'project_id' => $projectId,
                'category_id' => $category?->getKey(),
                'channel' => $channel,
                'guest_access_version' => 1,
            ]);

            $ticket->setRelation('category', $category);
            $ticket->forceFill([
                'priority' => $data->priority === null ? TicketInput::defaultPriority($category) : TicketInput::priority($data->priority),
            ]);

            $assignee = $this->assignee($ticket, $data->assignee);
            $dueAt = $data->dueAt !== null ? CarbonImmutable::instance($data->dueAt) : $this->dueDates->dueAt($ticket);

            // Tags first: names take the `lock:tags` advisory row, which comes before every
            // other row this transaction locks.
            $tags = $data->tags === [] ? [] : $this->resolveTags->execute($ticket, $data->tags);
            $allocated = $this->allocate->execute($project);

            if (TicketModels::ticket()::withTrashed()->where('reference', $allocated->reference)->exists()) {
                throw DuplicateTicketReferenceException::for($allocated->reference);
            }

            $now = CarbonImmutable::now();

            $ticket->forceFill([
                'reference' => $allocated->reference,
                'number' => $allocated->number,
                'sequence_scope' => $allocated->scope,
                'assignee_type' => $assignee?->getMorphClass(),
                'assignee_id' => $assignee?->getKey(),
                'assigned_at' => $assignee === null ? null : $now,
                'due_at' => $dueAt,
                'last_activity_at' => $now,
            ]);

            try {
                $ticket->save();
            } catch (UniqueConstraintViolationException) {
                throw DuplicateTicketReferenceException::for($allocated->reference);
            }

            $this->record->execute($ticket, ActivityType::Created, $actor, [
                'reference' => $ticket->reference,
                'channel' => $channel,
            ], system: $data->system);

            if (TicketsConfig::autoWatch('requester')) {
                $this->watch->execute($ticket, $requester);
            }

            $this->fields->execute($ticket, $data->fields, $actor);
            $this->fields->applyDefaults($ticket);
            $this->fields->assertRequiredFilled($ticket);

            if ($tags !== []) {
                $this->tags->execute($ticket, $tags, [], $actor, $data->system);
            }

            foreach ($data->watchers as $watcher) {
                $this->addWatcher->execute($ticket, $watcher, $actor, $data->system);
            }

            foreach ($data->attachments as $file) {
                $this->addAttachment->execute($ticket, $file, $actor, $data->system);
            }

            if ($assignee !== null) {
                $this->record->execute($ticket, ActivityType::Assigned, $actor, [
                    'to' => ['type' => $assignee->getMorphClass(), 'id' => $assignee->getKey()],
                ], system: $data->system);

                if (TicketsConfig::autoWatch('assignee')) {
                    $this->watch->execute($ticket, $assignee);
                }

                $this->events->dispatch(new TicketAssigned($ticket, $assignee, $actor));

                // Assigning a new ticket opens it (the `Assigned` automation).
                $this->automations->execute($ticket, Automation::Assigned, $actor);
            }

            $this->events->dispatch(new TicketCreated($ticket, $actor));

            return $ticket;
        });
    }

    /**
     * The caller's assignee (which must be assignable), else the strategy's pick when it is
     * assignable — an ineligible default leaves the ticket unassigned rather than refusing
     * the intake.
     */
    private function assignee(Ticket $ticket, ?Model $given): ?Model
    {
        if ($given !== null) {
            return Assignees::assertAssignable($given, $ticket);
        }

        $picked = $this->strategy->assigneeFor($ticket);

        return $picked !== null && Assignees::isAssignable($picked, $ticket) ? $picked : null;
    }
}
