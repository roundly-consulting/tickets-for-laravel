<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tags;

use RoundlyConsulting\Tickets\DataTransferObjects\TagData;
use RoundlyConsulting\Tickets\Exceptions\InvalidTagException;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\TagInput;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Creates a tag — global, or of one project — under the `lock:tags` advisory row: a global
 * tag's slug scope is NULL, which no unique index can hold, so two agents creating `printer`
 * at once would otherwise get `printer` and `printer-2`. A name its scope already holds is
 * refused — or, with `$orExisting`, that tag is returned (find-or-create). A name a deleted
 * tag holds brings that tag back: its slug stays taken, so a name is a tag's for good.
 */
final readonly class CreateTagAction
{
    public function execute(TagData $data, bool $orExisting = false): TicketTag
    {
        $name = TagInput::name($data->name);
        $color = TagInput::color($data->color);
        $class = TicketModels::tag();

        return TicketTransaction::run(new $class, function () use ($class, $data, $name, $color, $orExisting): TicketTag {
            // A fresh model per attempt: a deadlock retry rolls back the row, not the model.
            $tag = new $class;

            AdvisoryLock::acquire(AdvisoryLock::TAGS);

            [$projectType, $projectId] = TicketInput::morph($data->project);
            $existing = TagInput::find($name, $projectType, $projectId);

            if ($existing !== null && $existing->trashed()) {
                $existing->restore();

                if ($color !== null) {
                    $existing->forceFill(['color' => $color])->save();
                }

                return $existing;
            }

            if ($existing !== null) {
                return $orExisting ? $existing : throw InvalidTagException::duplicate($name);
            }

            $tag->forceFill([
                'project_type' => $projectType,
                'project_id' => $projectId,
                'name' => $name,
                'color' => $color,
            ])->save();

            return $tag;
        });
    }
}
