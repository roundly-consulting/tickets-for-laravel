<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Models;

use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;
use RoundlyConsulting\Tickets\Models\TicketField;

final class CustomField extends TicketField
{
    use CountsCreations;
}
