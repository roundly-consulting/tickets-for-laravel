<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLinkException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketLink;

/**
 * Reads over the link graph shared by the link and merge actions.
 *
 * @internal
 */
final class LinkGraph
{
    /**
     * The pair as stored: a symmetric link puts the lower primary key first, so A–B and B–A
     * are one row.
     *
     * @return array{0: Ticket, 1: Ticket}
     */
    public static function stored(Ticket $ticket, Ticket $related, LinkType $type): array
    {
        if ($type->isSymmetric() && TicketLocker::compareKeys($related->getKey(), $ticket->getKey()) < 0) {
            return [$related, $ticket];
        }

        return [$ticket, $related];
    }

    public static function find(Ticket $from, Ticket $to, LinkType $type): ?TicketLink
    {
        return TicketLink::query()
            ->where('ticket_id', $from->getKey())
            ->where('related_ticket_id', $to->getKey())
            ->where('type', $type->value)
            ->first();
    }

    /**
     * Whether `$to` already reaches `$from` along `$type` links — adding `$from → $to` would
     * then close a cycle. Walks breadth-first, at most `tickets.links.max_depth` levels; a
     * chain deeper than that is refused rather than assumed acyclic.
     */
    public static function reaches(Ticket $to, Ticket $from, LinkType $type): bool
    {
        $target = (string) $from->getKey();
        $frontier = [(string) $to->getKey()];
        $seen = array_flip($frontier);
        $max = TicketsConfig::maxLinkDepth();

        for ($depth = 0; $frontier !== []; $depth++) {
            if ($depth >= $max) {
                throw InvalidTicketLinkException::tooDeep($max);
            }

            $next = [];

            foreach (TicketLink::query()->whereIn('ticket_id', $frontier)->where('type', $type->value)->pluck('related_ticket_id') as $key) {
                $key = (string) $key;

                if ($key === $target) {
                    return true;
                }

                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $next[] = $key;
                }
            }

            $frontier = $next;
        }

        return false;
    }
}
