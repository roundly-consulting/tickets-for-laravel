<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;

/**
 * A package-triggered transition. The configured lifecycle maps each hook to one of its
 * transition names, or to null to switch the automation off.
 */
#[TranslatedLabels('tickets::enums.automation')]
enum Automation: string implements HasColor
{
    use Helpers;

    /** An unassigned (new) ticket got an assignee. */
    case Assigned = 'assigned';
    /** The requester or a participant replied while the ticket waited on them. */
    case RequesterReplied = 'requester_replied';
    /** The requester or a participant replied to a resolved ticket. */
    case RequesterReopened = 'requester_reopened';
    /** A resolved ticket's auto-close timer ran out. */
    case AutoClose = 'auto_close';
    /** The ticket was merged into another one. */
    case Merged = 'merged';

    /**
     * The categories the automation's transition must be able to leave. `Merged` must work
     * from every category but Closed, the others from one category each.
     *
     * @return list<StatusCategory>
     */
    public function sources(): array
    {
        return match ($this) {
            self::Assigned => [StatusCategory::Open],
            self::RequesterReplied => [StatusCategory::Waiting],
            self::RequesterReopened, self::AutoClose => [StatusCategory::Resolved],
            self::Merged => [StatusCategory::Open, StatusCategory::Active, StatusCategory::Waiting, StatusCategory::Resolved],
        };
    }

    /**
     * The categories the automation's transition may land in.
     *
     * @return list<StatusCategory>
     */
    public function targets(): array
    {
        return match ($this) {
            self::Assigned, self::RequesterReplied => [StatusCategory::Open, StatusCategory::Active],
            self::RequesterReopened => [StatusCategory::Open, StatusCategory::Active, StatusCategory::Waiting],
            self::AutoClose, self::Merged => [StatusCategory::Closed],
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Assigned => 'blue',
            self::RequesterReplied => 'amber',
            self::RequesterReopened => 'orange',
            self::AutoClose => 'gray',
            self::Merged => 'purple',
        };
    }
}
