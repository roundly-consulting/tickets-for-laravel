<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;

/**
 * How two tickets relate. Read from the other end, a link shows its inverse label.
 */
#[TranslatedLabels('tickets::enums.link_type')]
enum LinkType: string implements HasColor
{
    use Helpers;

    case RelatesTo = 'relates_to';
    case Duplicates = 'duplicates';
    case Blocks = 'blocks';
    case ParentOf = 'parent_of';

    /** Symmetric links read the same from both ends and are stored once, canonically. */
    public function isSymmetric(): bool
    {
        return $this === self::RelatesTo;
    }

    /** `blocks` and `parent_of` must never form a cycle. */
    public function isAcyclic(): bool
    {
        return $this === self::Blocks || $this === self::ParentOf;
    }

    /** The label key the link carries when read from the related ticket. */
    public function inverse(): string
    {
        return match ($this) {
            self::RelatesTo => 'relates_to',
            self::Duplicates => 'duplicated_by',
            self::Blocks => 'blocked_by',
            self::ParentOf => 'child_of',
        };
    }

    /** The translated inverse label. */
    public function inverseLabel(): string
    {
        $line = __('tickets::enums.link_inverse.'.$this->inverse());

        return is_string($line) ? $line : $this->inverse();
    }

    public function color(): string
    {
        return match ($this) {
            self::RelatesTo => 'gray',
            self::Duplicates => 'purple',
            self::Blocks => 'red',
            self::ParentOf => 'blue',
        };
    }
}
