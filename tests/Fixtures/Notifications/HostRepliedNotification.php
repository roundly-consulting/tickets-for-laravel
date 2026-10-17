<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use RoundlyConsulting\Tickets\Notifications\TicketRepliedNotification;

/**
 * A host's own reply mail: the packaged class, one line added.
 */
final class HostRepliedNotification extends TicketRepliedNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return parent::toMail($notifiable)->line('Sent by the host.');
    }
}
