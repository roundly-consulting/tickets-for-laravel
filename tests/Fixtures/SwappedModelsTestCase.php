<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures;

use RoundlyConsulting\Tickets\Tests\Fixtures\Models\CustomCategory;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\CustomField;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\CustomGuest;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\CustomTag;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\CustomTicket;
use RoundlyConsulting\Tickets\Tests\TestCase;

/**
 * Host subclasses named in `tickets.models.*` before the providers boot — the only window in
 * which a swap is real.
 */
abstract class SwappedModelsTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $this->swapModel('tickets.models.ticket', CustomTicket::class);
        $this->swapModel('tickets.models.category', CustomCategory::class);
        $this->swapModel('tickets.models.guest', CustomGuest::class);
        $this->swapModel('tickets.models.field', CustomField::class);
        $this->swapModel('tickets.models.tag', CustomTag::class);

        parent::defineEnvironment($app);
    }
}
