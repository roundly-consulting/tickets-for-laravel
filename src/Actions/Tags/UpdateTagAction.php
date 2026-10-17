<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tags;

use RoundlyConsulting\Tickets\DataTransferObjects\UpdateTagData;
use RoundlyConsulting\Tickets\Exceptions\InvalidTagException;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\Optional;
use RoundlyConsulting\Tickets\Support\TagInput;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Renames or recolours a tag under the `lock:tags` advisory row. A new name its scope already
 * holds — a deleted tag's included — is refused; the slug follows the name, so lookups by
 * name keep finding the tag.
 */
final readonly class UpdateTagAction
{
    public function execute(TicketTag $tag, UpdateTagData $data): TicketTag
    {
        $name = Optional::given($data->name) ? TagInput::name($data->name) : null;
        $color = Optional::given($data->color) ? ['color' => TagInput::color($data->color)] : [];

        return TicketTransaction::run($tag, function () use ($tag, $name, $color): TicketTag {
            AdvisoryLock::acquire(AdvisoryLock::TAGS);

            if ($name !== null) {
                $taken = TagInput::find($name, $tag->project_type, $tag->project_id);

                if ($taken !== null && ! $taken->is($tag)) {
                    throw InvalidTagException::duplicate($name);
                }
            }

            $tag->forceFill([...($name === null ? [] : ['name' => $name]), ...$color])->save();

            return $tag;
        });
    }
}
