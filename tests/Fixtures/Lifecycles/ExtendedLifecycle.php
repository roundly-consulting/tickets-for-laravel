<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles;

use RoundlyConsulting\Tickets\Lifecycle\TicketLifecycle;

/**
 * A host's own lifecycle built on the shipped one, unchanged — it carries the same resolved →
 * closed pair (`close` and `dismiss`), but the host owns it now.
 */
final class ExtendedLifecycle extends TicketLifecycle {}
