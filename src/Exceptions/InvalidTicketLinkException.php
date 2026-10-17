<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

use RoundlyConsulting\Tickets\Enums\LinkType;

/**
 * Two tickets cannot be linked (or unlinked) this way: a self link, a cycle of `blocks` or
 * `parent_of`, a second parent, a cross-project link while those are off, a chain too deep to
 * check — or no such link on this ticket. Maps to 422.
 */
final class InvalidTicketLinkException extends TicketsException
{
    public static function self(): self
    {
        return new self(self::translate('link.self'));
    }

    public static function cycle(LinkType $type): self
    {
        return new self(self::translate('link.cycle', ['type' => $type->value]));
    }

    public static function secondParent(string $reference): self
    {
        return new self(self::translate('link.second_parent', ['reference' => $reference]));
    }

    public static function crossProject(): self
    {
        return new self(self::translate('link.cross_project'));
    }

    public static function tooDeep(int $max): self
    {
        return new self(self::translate('link.too_deep', ['max' => $max]));
    }

    public static function notLinked(string $reference, LinkType $type): self
    {
        return new self(self::translate('link.not_linked', ['reference' => $reference, 'type' => $type->value]));
    }
}
