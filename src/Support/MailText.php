<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Support\Str;

/**
 * User-written text (a subject, a message, a name) placed in a notification mail. Laravel
 * escapes HTML in a mail line, but renders the line as Markdown: `[pay here](https://…)` in a
 * reply would turn into a real link in an agent's inbox. Markdown's own punctuation is
 * backslash-escaped, so the text shows exactly as written.
 *
 * @internal
 */
final class MailText
{
    /** How much of a message a mail quotes. */
    public const int EXCERPT = 1000;

    public static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]()#!~|{}])/', '\\\\$1', $text);
    }

    /**
     * The start of a message, escaped.
     */
    public static function excerpt(string $text): string
    {
        return self::escape(Str::limit(trim($text), self::EXCERPT));
    }
}
