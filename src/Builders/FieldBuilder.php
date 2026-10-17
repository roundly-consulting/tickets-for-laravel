<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Builders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldData;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Exceptions\InvalidFieldException;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Support\FieldInput;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::fields()->make($key, $type)` — fluent sugar over {@see FieldData}. The key and
 * the rules are validated at once, and a select type without options is refused before
 * anything is written; `define()` runs the full action, so the DTO form and this one produce
 * the same row. Without a label, the field is labelled with its key as a headline.
 */
final class FieldBuilder
{
    /** @var array<string, string>|string|null */
    private array|string|null $label = null;

    /** @var array<string, string>|string|null */
    private array|string|null $help = null;

    /** @var array<string, array<string, string>|string> */
    private array $options = [];

    /** @var list<string> */
    private array $rules = [];

    private bool $required = false;

    private bool $encrypted = false;

    private mixed $default = null;

    private bool $requesterVisible = true;

    private bool $requesterEditable = false;

    private int $position = 0;

    private bool $active = true;

    private ?Model $project = null;

    private TicketCategory|int|null $category = null;

    public function __construct(
        private readonly TicketsManager $manager,
        private readonly string $key,
        private readonly FieldType $type,
    ) {
        FieldInput::key($key);
    }

    /**
     * @param  array<string, string>|string  $label
     */
    public function label(array|string $label): self
    {
        $this->label = FieldInput::label($this->key, $label);

        return $this;
    }

    /**
     * @param  array<string, string>|string|null  $help
     */
    public function help(array|string|null $help): self
    {
        $this->help = $help;

        return $this;
    }

    /**
     * The choices of a select type: option value => label (a locale map or a string).
     *
     * @param  array<string, array<string, string>|string>  $options
     */
    public function options(array $options): self
    {
        FieldInput::options($this->key, $this->type, $options);
        $this->options = $options;

        return $this;
    }

    /**
     * @param  list<string>  $rules
     */
    public function rules(array $rules): self
    {
        $this->rules = FieldInput::rules($this->key, $rules);

        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function encrypted(bool $encrypted = true): self
    {
        $this->encrypted = $encrypted;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;

        return $this;
    }

    /**
     * Requesters see the field (and, unless `$editable` is false, may set it).
     */
    public function requesterEditable(bool $editable = true): self
    {
        $this->requesterEditable = $editable;
        $this->requesterVisible = $this->requesterVisible || $editable;

        return $this;
    }

    public function requesterVisible(bool $visible = true): self
    {
        $this->requesterVisible = $visible;
        $this->requesterEditable = $this->requesterEditable && $visible;

        return $this;
    }

    /**
     * Agents only: requesters neither see nor set it.
     */
    public function agentOnly(): self
    {
        return $this->requesterVisible(false);
    }

    public function position(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function active(bool $active = true): self
    {
        $this->active = $active;

        return $this;
    }

    public function inactive(): self
    {
        return $this->active(false);
    }

    public function project(?Model $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function category(TicketCategory|int|null $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function data(): FieldData
    {
        if ($this->type->hasOptions() && $this->options === []) {
            throw InvalidFieldException::missingOptions($this->key);
        }

        return new FieldData(
            key: $this->key,
            type: $this->type,
            label: $this->label ?? Str::headline($this->key),
            help: $this->help,
            options: $this->options,
            rules: $this->rules,
            required: $this->required,
            encrypted: $this->encrypted,
            default: $this->default,
            requesterVisible: $this->requesterVisible,
            requesterEditable: $this->requesterEditable,
            position: $this->position,
            active: $this->active,
            project: $this->project,
            category: $this->category,
        );
    }

    public function define(): TicketField
    {
        return $this->manager->fields()->define($this->data());
    }
}
