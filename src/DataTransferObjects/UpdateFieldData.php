<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Support\Optional;

/**
 * A partial field update. The key, project and category are fixed at definition — they decide
 * which tickets the stored values belong to. Changing the type or the encryption of a field
 * that holds values is refused, and so is dropping an option a value still uses.
 */
final readonly class UpdateFieldData
{
    /**
     * @param  array<string, string>|string|Optional  $label
     * @param  array<string, string>|string|null|Optional  $help
     * @param  array<string, array<string, string>|string>|Optional  $options  option value => label
     * @param  list<string>|Optional  $rules
     */
    public function __construct(
        public array|string|Optional $label = new Optional,
        public array|string|null|Optional $help = new Optional,
        public FieldType|Optional $type = new Optional,
        public array|Optional $options = new Optional,
        public array|Optional $rules = new Optional,
        public bool|Optional $required = new Optional,
        public bool|Optional $encrypted = new Optional,
        public mixed $default = new Optional,
        public bool|Optional $requesterVisible = new Optional,
        public bool|Optional $requesterEditable = new Optional,
        public int|Optional $position = new Optional,
        public bool|Optional $active = new Optional,
    ) {}
}
