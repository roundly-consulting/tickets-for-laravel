<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RoundlyConsulting\Tickets\Actions\Guests\IssueGuestLinkAction;
use RoundlyConsulting\Tickets\Enums\NotificationType;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Exceptions\GuestAccessDeniedException;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Exceptions\TicketTrashedException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Support\DisplayName;
use RoundlyConsulting\Tickets\Support\MailText;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketVisibility;

/**
 * What every ticket notification shares: queued after the change commits (on
 * `tickets.notifications.queue`), sent on `tickets.notifications.channels`, a plain
 * {@see MailMessage} (no views) and a `toArray()` for the database channel, every string from
 * `tickets::notifications`.
 *
 * Visibility is checked again when the queue delivers: a recipient who has since lost sight of
 * the ticket (a removed watcher, an agent the agent scope now excludes), a deleted ticket or a
 * withdrawn message gets nothing. Internal content ({@see self::internal()}) reaches agents only.
 *
 * A guest's mail links a freshly issued signed guest link; anyone else's opens
 * `tickets.notifications.url` (no button when that is blank). To change a notification, extend
 * its class and name the subclass in `tickets.notifications.types`.
 */
abstract class TicketNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** A ticket deleted for good before the queue got to it leaves nothing to tell. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Ticket $ticket,
    ) {
        $this->afterCommit();

        $queue = TicketsConfig::notificationQueue();

        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    abstract public function type(): NotificationType;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return TicketsConfig::notificationChannels();
    }

    /**
     * Whether the recipient may still see what this notification tells — re-checked on
     * delivery, after the queue.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof Model || $this->ticket->trashed()) {
            return false;
        }

        return $this->internal()
            ? TicketVisibility::isAgentFor($notifiable, $this->ticket)
            : TicketVisibility::canView($notifiable, $this->ticket);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $notifiable instanceof Model ? DisplayName::of($notifiable) : null;

        $message = (new MailMessage)
            ->subject($this->subject())
            ->greeting($name === null
                ? self::line('greeting_anonymous')
                : self::line('greeting', ['name' => MailText::escape($name)]));

        foreach ($this->lines($notifiable) as $line) {
            $message->line($line);
        }

        $url = $this->url($notifiable);

        if ($url !== null) {
            $message->action(self::line('action', ['reference' => $this->linkedTicket()->reference]), $url);
        }

        return $message;
    }

    /**
     * The database / broadcast payload: the type, the ticket, and what the type adds.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type()->value,
            'ticket' => $this->ticket->reference,
            'subject' => $this->ticket->subject,
            ...$this->data(),
        ];
    }

    /**
     * Whether the notification carries internal content (a note, a mention in one) — agents
     * only.
     */
    public function internal(): bool
    {
        return false;
    }

    /**
     * The mail subject.
     */
    abstract protected function subject(): string;

    /**
     * The mail's lines, user-written text escaped ({@see MailText}).
     *
     * @return list<string>
     */
    abstract protected function lines(object $notifiable): array;

    /**
     * What the type adds to {@see self::toArray()}.
     *
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        return [];
    }

    /**
     * The ticket the mail's button opens — the notified ticket, unless a type points elsewhere.
     */
    protected function linkedTicket(): Ticket
    {
        return $this->ticket;
    }

    /**
     * The mail's button: a signed guest link for a guest, `tickets.notifications.url` for anyone
     * else, or none.
     */
    protected function url(object $notifiable): ?string
    {
        $ticket = $this->linkedTicket();

        if ($notifiable instanceof TicketGuest) {
            return $this->guestLink($ticket, $notifiable);
        }

        return TicketsConfig::notificationUrl($ticket->reference);
    }

    /**
     * A fresh guest link — the same checks as `Tickets::for($ticket)->guestLink($guest)` (the
     * recipient must be the guest requester or a guest watcher; never a deleted ticket), minted
     * through the action so `Tickets::fake()` records no link the host never asked for. None for
     * a ticket whose links closing revoked (`tickets.guests.revoke_on_close`), or when no link
     * can be issued (no guest routes — `tickets:doctor` reports that).
     */
    protected function guestLink(Ticket $ticket, TicketGuest $guest): ?string
    {
        if (TicketsConfig::revokeGuestsOnClose() && $ticket->statusCategory() === StatusCategory::Closed) {
            return null;
        }

        try {
            return Container::getInstance()->make(IssueGuestLinkAction::class)->execute($ticket, $guest);
        } catch (GuestAccessDeniedException|InvalidTicketsConfigurationException|TicketTrashedException) {
            return null;
        }
    }

    /**
     * The ticket's subject, escaped for a mail line.
     */
    protected function escapedSubject(): string
    {
        return MailText::escape($this->ticket->subject);
    }

    /**
     * A line of `tickets::notifications`.
     *
     * @param  array<string, string|int>  $replace
     */
    protected static function line(string $key, array $replace = []): string
    {
        $line = __('tickets::notifications.'.$key, $replace);

        return is_string($line) ? $line : $key;
    }
}
