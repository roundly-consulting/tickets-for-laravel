<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Guests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use RoundlyConsulting\Tickets\Contracts\GuestLinkGenerator;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * The default {@see GuestLinkGenerator}: a temporary signed URL of the package's guest endpoint
 * (`tickets.guest.show`), valid for `tickets.guests.link_ttl`, carrying the guest's key and the
 * ticket's `guest_access_version`. It needs the guest routes — `tickets.routes.enabled` and
 * `tickets.guests.enabled`; without them it refuses rather than mint a dead link.
 */
final readonly class SignedRouteLinkGenerator implements GuestLinkGenerator
{
    public function __construct(
        private UrlGenerator $urls,
        private Router $router,
    ) {}

    public function for(Ticket $ticket, Model $recipient): string
    {
        if (! TicketsConfig::routesEnabled() || ! TicketsConfig::guestsEnabled() || ! $this->router->has('tickets.guest.show')) {
            throw InvalidTicketsConfigurationException::invalid(
                'tickets.routes.enabled',
                'on, with tickets.guests.enabled on, for the default guest link generator — or bind your own Contracts\GuestLinkGenerator',
            );
        }

        $key = $recipient->getKey();

        return $this->urls->temporarySignedRoute('tickets.guest.show', CarbonImmutable::now()->add(TicketsConfig::guestLinkTtl()), [
            'ticket' => $ticket->reference,
            'guest' => is_int($key) || is_string($key) ? $key : '',
            'v' => $ticket->guest_access_version,
        ]);
    }
}
