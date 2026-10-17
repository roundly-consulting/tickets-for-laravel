<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures;

use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

/**
 * A host's `comments.mention_resolver`: `@Grace` is the user named Grace.
 */
final class MentionResolver
{
    public function __invoke(string $handle): ?User
    {
        return User::query()->where('name', $handle)->first();
    }
}
