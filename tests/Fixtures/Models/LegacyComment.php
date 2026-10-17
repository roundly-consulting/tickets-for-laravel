<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Models;

use RoundlyConsulting\Comments\Models\Comment;

/**
 * A host comment model on a table that never got the comments 1.1 `meta` column.
 */
final class LegacyComment extends Comment
{
    protected $table = 'legacy_comments';
}
