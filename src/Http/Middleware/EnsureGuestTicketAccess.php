<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use RoundlyConsulting\Tickets\Http\ErrorResponse;
use RoundlyConsulting\Tickets\Http\GuestLinks;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Support\GuestAccess;
use RoundlyConsulting\Tickets\Support\TicketModels;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate of every guest endpoint. It checks the signed link itself — not Laravel's `signed`
 * middleware, whose own error would tell an expired link from a revoked one — and only then
 * that the link's guest is the ticket's guest requester or one of its guest watchers, and that
 * its `v` is the ticket's current `guest_access_version`. An invalid, expired, tampered, revoked,
 * misdirected or deleted-ticket link all get the same 403 `guest_link_invalid` body: no oracle.
 *
 * @internal
 */
final readonly class EnsureGuestTicketAccess
{
    public function __construct(
        private UrlGenerator $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $links = $this->links($request);

        if ($links === null) {
            return ErrorResponse::guestLinkInvalid()->toResponse();
        }

        $links->attach($request);
        Viewer::guest($links->guest())->attach($request);

        $response = $next($request);

        return $response instanceof Response ? $response : ErrorResponse::guestLinkInvalid()->toResponse();
    }

    private function links(Request $request): ?GuestLinks
    {
        $reference = $request->route('ticket');
        $guest = $request->query('guest');
        $version = $request->query('v');
        $expires = $request->query('expires');

        if (! is_string($reference) || ! is_string($guest) || ! is_string($version) || ! is_string($expires) || ! ctype_digit($expires)
            || ! $this->urls->hasValidSignature($request)) {
            return null;
        }

        $ticket = TicketModels::ticket()::query()->where('reference', $reference)->first();

        if ($ticket === null || (string) $ticket->guest_access_version !== $version) {
            return null;
        }

        $recipient = GuestAccess::recipient($ticket, $guest);

        return $recipient === null ? null : new GuestLinks($ticket, $recipient, CarbonImmutable::createFromTimestamp((int) $expires));
    }
}
