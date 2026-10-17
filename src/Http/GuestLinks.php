<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;

/**
 * The signed action URLs a guest's ticket view embeds. A signature covers one route, so the
 * emailed link opens only the ticket view; replying, running a transition and downloading a file
 * each need their own URL — signed for the same ticket, guest, `guest_access_version` and expiry
 * as the link that opened the view, so revoking access voids them all at once.
 *
 * @internal
 */
final readonly class GuestLinks
{
    public function __construct(
        private Ticket $ticket,
        private TicketGuest $guest,
        private CarbonImmutable $expires,
    ) {}

    /**
     * The links of the guest request being served, or null outside the guest endpoints.
     */
    public static function of(Request $request): ?self
    {
        $links = $request->attributes->get(self::class);

        return $links instanceof self ? $links : null;
    }

    public function ticket(): Ticket
    {
        return $this->ticket;
    }

    public function guest(): TicketGuest
    {
        return $this->guest;
    }

    public function attach(Request $request): self
    {
        $request->attributes->set(self::class, $this);

        return $this;
    }

    public function show(): string
    {
        return $this->sign('tickets.guest.show');
    }

    public function reply(): string
    {
        return $this->sign('tickets.guest.reply');
    }

    public function transition(string $transition): string
    {
        return $this->sign('tickets.guest.transition', ['transition' => $transition]);
    }

    public function attachment(string $uuid): string
    {
        return $this->sign('tickets.guest.attachment', ['media' => $uuid]);
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function sign(string $route, array $parameters = []): string
    {
        $key = $this->guest->getKey();

        return Container::getInstance()->make(UrlGenerator::class)->temporarySignedRoute($route, $this->expires, [
            'ticket' => $this->ticket->reference,
            ...$parameters,
            'guest' => is_int($key) || is_string($key) ? $key : '',
            'v' => $this->ticket->guest_access_version,
        ]);
    }
}
