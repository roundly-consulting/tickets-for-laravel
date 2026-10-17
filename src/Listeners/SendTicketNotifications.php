<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Listeners;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Enums\NotificationType;
use RoundlyConsulting\Tickets\Events\TicketAssigned;
use RoundlyConsulting\Tickets\Events\TicketClosed;
use RoundlyConsulting\Tickets\Events\TicketCreated;
use RoundlyConsulting\Tickets\Events\TicketDueSoon;
use RoundlyConsulting\Tickets\Events\TicketMentioned;
use RoundlyConsulting\Tickets\Events\TicketMerged;
use RoundlyConsulting\Tickets\Events\TicketNoteAdded;
use RoundlyConsulting\Tickets\Events\TicketOverdue;
use RoundlyConsulting\Tickets\Events\TicketReopened;
use RoundlyConsulting\Tickets\Events\TicketReplied;
use RoundlyConsulting\Tickets\Events\TicketResolved;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Notifications\RecipientResolver;
use RoundlyConsulting\Tickets\Notifications\TicketNotification;
use RoundlyConsulting\Tickets\Notifications\TicketStatusChangedNotification;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use Throwable;

/**
 * Turns the package's events into notifications (`tickets.notifications`). The events fire
 * after the change commits, so nothing is sent for a change that rolled back; the
 * notifications are queued on top. A failure here — a broken notification setting, an
 * unreachable queue, a mail that throws on the sync queue — is reported, never thrown: the
 * change it would tell about has already committed and must not look failed.
 *
 * @internal
 */
final readonly class SendTicketNotifications
{
    /**
     * The event each handler listens to.
     *
     * @var array<class-string, string>
     */
    public const array EVENTS = [
        TicketCreated::class => 'created',
        TicketAssigned::class => 'assigned',
        TicketReplied::class => 'replied',
        TicketNoteAdded::class => 'noteAdded',
        TicketMentioned::class => 'mentioned',
        TicketResolved::class => 'resolved',
        TicketClosed::class => 'closed',
        TicketReopened::class => 'reopened',
        TicketOverdue::class => 'overdue',
        TicketDueSoon::class => 'dueSoon',
        TicketMerged::class => 'merged',
    ];

    public function __construct(
        private RecipientResolver $recipients,
    ) {}

    public function created(TicketCreated $event): void
    {
        $this->send(NotificationType::Created, $event->ticket, fn (): array => $this->recipients->created($event->ticket));
    }

    public function assigned(TicketAssigned $event): void
    {
        $this->send(
            NotificationType::Assigned,
            $event->ticket,
            fn (): array => $this->recipients->assigned($event->ticket, $event->assignee, $event->actor),
            ['assignee' => $event->assignee],
        );
    }

    public function replied(TicketReplied $event): void
    {
        $this->send(
            $this->recipients->replyType($event->role),
            $event->ticket,
            fn (): array => $this->recipients->replied($event->ticket, $event->role, $event->actor),
            ['comment' => $event->comment],
        );
    }

    public function noteAdded(TicketNoteAdded $event): void
    {
        $this->send(
            NotificationType::NoteAdded,
            $event->ticket,
            fn (): array => $this->recipients->noteAdded($event->ticket, $event->actor),
            ['comment' => $event->comment],
        );
    }

    public function mentioned(TicketMentioned $event): void
    {
        $this->send(
            NotificationType::Mentioned,
            $event->ticket,
            fn (): array => $this->recipients->mentioned($event->ticket, $event->comment, $event->mentioned),
            ['comment' => $event->comment],
        );
    }

    public function resolved(TicketResolved $event): void
    {
        $this->statusChanged($event->ticket, TicketStatusChangedNotification::RESOLVED, $event->actor);
    }

    public function closed(TicketClosed $event): void
    {
        // A merge closes its source: TicketMerged tells the requester about that instead.
        if ($event->ticket->merged_into_id !== null) {
            return;
        }

        $this->statusChanged($event->ticket, TicketStatusChangedNotification::CLOSED, $event->actor);
    }

    public function reopened(TicketReopened $event): void
    {
        $this->statusChanged($event->ticket, TicketStatusChangedNotification::REOPENED, $event->actor);
    }

    public function overdue(TicketOverdue $event): void
    {
        $this->send(NotificationType::Overdue, $event->ticket, fn (): array => $this->recipients->due($event->ticket), ['dueAt' => $event->dueAt]);
    }

    public function dueSoon(TicketDueSoon $event): void
    {
        $this->send(NotificationType::DueSoon, $event->ticket, fn (): array => $this->recipients->due($event->ticket), ['dueAt' => $event->dueAt]);
    }

    public function merged(TicketMerged $event): void
    {
        $this->send(
            NotificationType::Merged,
            $event->source,
            fn (): array => $this->recipients->merged($event->source, $event->actor),
            ['target' => $event->target],
        );
    }

    private function statusChanged(Ticket $ticket, string $change, ?Model $actor): void
    {
        $this->send(
            NotificationType::StatusChanged,
            $ticket,
            fn (): array => $this->recipients->statusChanged($ticket, $actor),
            ['change' => $change],
        );
    }

    /**
     * @param  Closure(): list<Model>  $recipients
     * @param  array<string, mixed>  $arguments  the notification's constructor arguments besides the ticket
     */
    private function send(NotificationType $type, Ticket $ticket, Closure $recipients, array $arguments = []): void
    {
        try {
            if (! TicketsConfig::notificationsEnabled() || $ticket->trashed()) {
                return;
            }

            $class = TicketsConfig::notification($type);

            if ($class === null) {
                return;
            }

            $notifiables = $recipients();

            if ($notifiables === []) {
                return;
            }

            $container = Container::getInstance();
            $notification = $container->make($class, ['ticket' => $ticket, ...$arguments]);

            if ($notification instanceof TicketNotification) {
                $container->make(Dispatcher::class)->send($notifiables, $notification);
            }
        } catch (Throwable $exception) {
            Container::getInstance()->make(ExceptionHandler::class)->report($exception);
        }
    }
}
