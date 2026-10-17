<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Models;

use RoundlyConsulting\Comments\Models\Comment;

/**
 * A host's own comment model (`comments.model`) on the packaged table.
 */
final class HostComment extends Comment
{
    protected $table = 'comments';
}
