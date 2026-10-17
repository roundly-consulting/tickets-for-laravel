<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use RoundlyConsulting\Tickets\Notifications\TicketRepliedNotification;
use RuntimeException;

/**
 * A reply mail whose mail server is down.
 */
final class ExplodingRepliedNotification extends TicketRepliedNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        throw new RuntimeException('The mail server is down.');
    }
}
