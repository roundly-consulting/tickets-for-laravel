<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use BackedEnum;
use Carbon\CarbonInterval;
use ReflectionEnum;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\PackageToolkit\Support\ConfigValidator;
use RoundlyConsulting\Tickets\Contracts\Priority;
use RoundlyConsulting\Tickets\Contracts\TicketLifecycle as TicketLifecycleContract;
use RoundlyConsulting\Tickets\Enums\TicketPriority;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLifecycleException;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Lifecycle\TicketLifecycle;

/**
 * Validated readers for the `tickets.*` settings the toolkit's `Config` cannot express on
 * its own (class contracts, durations). A bad value throws naming the key.
 */
final class TicketsConfig
{
    /**
     * The ticket lifecycle definition class (`tickets.lifecycle`).
     *
     * @return class-string<LifecycleDefinition&TicketLifecycleContract>
     */
    public static function lifecycle(): string
    {
        $class = Config::using(InvalidTicketLifecycleException::class)->string('tickets.lifecycle', TicketLifecycle::class);

        if (! is_subclass_of($class, LifecycleDefinition::class) || ! is_subclass_of($class, TicketLifecycleContract::class)) {
            throw InvalidTicketLifecycleException::notADefinition($class);
        }

        return $class;
    }

    /**
     * The priority enum (`tickets.priority.enum`): a string-backed enum implementing
     * {@see Priority}.
     *
     * @return class-string<BackedEnum&Priority>
     */
    public static function priorityEnum(): string
    {
        $class = Config::using(InvalidTicketsConfigurationException::class)->string('tickets.priority.enum', TicketPriority::class);

        if (! enum_exists($class) || ! is_subclass_of($class, BackedEnum::class) || ! is_subclass_of($class, Priority::class)
            || (new ReflectionEnum($class))->getBackingType()?->getName() !== 'string') {
            throw InvalidTicketsConfigurationException::invalid('tickets.priority.enum', 'a string-backed enum implementing '.Priority::class);
        }

        return $class;
    }

    /**
     * The configured priority case for a raw value, or null when the value is not part of
     * the enum.
     */
    public static function priority(BackedEnum|string $value): (BackedEnum&Priority)|null
    {
        $enum = self::priorityEnum();

        if ($value instanceof BackedEnum && ! $value instanceof $enum) {
            return null;
        }

        return $enum::tryFrom($value instanceof BackedEnum ? $value->value : $value);
    }

    /**
     * The priority a ticket gets when neither the caller nor its category gives one
     * (`tickets.priority.default`).
     */
    public static function defaultPriority(): BackedEnum&Priority
    {
        $value = self::read()->string('tickets.priority.default', 'normal');

        return self::priority($value)
            ?? throw InvalidTicketsConfigurationException::invalid('tickets.priority.default', 'a value of '.self::priorityEnum());
    }

    /**
     * The reference prefix of tickets without a project (`tickets.reference.prefix`).
     */
    public static function referencePrefix(): string
    {
        $prefix = self::read()->string('tickets.reference.prefix', 'TCK');

        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw InvalidTicketsConfigurationException::invalid('tickets.reference.prefix', 'an upper-case letter followed by up to nine upper-case letters or digits');
        }

        return $prefix;
    }

    /**
     * The intake channels a ticket may come in through (`tickets.channels`).
     *
     * @return list<string>
     */
    public static function channels(): array
    {
        return self::read()->list(
            'tickets.channels',
            ['web', 'email', 'api', 'phone', 'chat'],
            static fn (string $channel): bool => preg_match('/^[a-z][a-z0-9_]{0,31}$/', $channel) === 1,
        );
    }

    /**
     * The channel of a ticket created without one (`tickets.default_channel`).
     */
    public static function defaultChannel(): string
    {
        $channels = self::channels();

        if ($channels === []) {
            throw InvalidTicketsConfigurationException::invalid('tickets.channels', 'a non-empty list of channels');
        }

        return self::read()->oneOf('tickets.default_channel', $channels, in_array('api', $channels, true) ? 'api' : $channels[0]);
    }

    /**
     * How deep the category tree may grow (`tickets.categories.max_depth`).
     */
    public static function maxCategoryDepth(): int
    {
        return self::read()->integer('tickets.categories.max_depth', 5, min: 1, max: 50);
    }

    /**
     * Whether the activity timeline is written (`tickets.activity.enabled`).
     */
    public static function activityEnabled(): bool
    {
        return self::read()->boolean('tickets.activity.enabled', true);
    }

    /**
     * Whether a call without an explicit actor is attributed to the authenticated user
     * (`tickets.actor.from_auth`), and through which guard (`tickets.actor.guard`, blank
     * for the default guard).
     */
    public static function actorFromAuth(): bool
    {
        return self::read()->boolean('tickets.actor.from_auth', true);
    }

    public static function actorGuard(): ?string
    {
        $guard = self::read()->string('tickets.actor.guard', '');

        return trim($guard) === '' ? null : $guard;
    }

    /**
     * The validation rules a custom field definition may use (`tickets.fields.allowed_rules`).
     *
     * @return list<string>
     */
    public static function allowedFieldRules(): array
    {
        return self::read()->list('tickets.fields.allowed_rules', [
            'min', 'max', 'between', 'regex', 'digits', 'digits_between', 'alpha', 'alpha_num', 'alpha_dash', 'starts_with', 'ends_with',
        ]);
    }

    /**
     * How long a resolved ticket waits before lifecycle's sweep closes it
     * (`tickets.auto_close.after`: an ISO-8601 duration, or `off` for never). Read when a
     * ticket enters Resolved, so a change applies to later resolutions only.
     */
    public static function autoCloseAfter(): ?CarbonInterval
    {
        $value = self::read()->string('tickets.auto_close.after', 'P7D');

        if (strtolower(trim($value)) === 'off') {
            return null;
        }

        return Durations::parse($value)
            ?? throw InvalidTicketsConfigurationException::invalid('tickets.auto_close.after', 'an ISO-8601 duration (P7D, PT12H) or off');
    }

    /**
     * The Gate ability that makes a user an agent (`tickets.authorization.abilities.agent`).
     * The host defines it; an undefined ability denies, so the package fails closed. A blank
     * value is the default.
     */
    public static function agentAbility(): string
    {
        return self::read()->string('tickets.authorization.abilities.agent', 'tickets.agent');
    }

    /**
     * Whether a ticket reply that `comments.require_approval` left pending is approved at once
     * (`tickets.comments.auto_approve`). A reply the host's blocklist held is never approved.
     */
    public static function autoApproveReplies(): bool
    {
        return self::read()->boolean('tickets.comments.auto_approve', true);
    }

    /**
     * Whether a requester's reply reopens a resolved ticket (`tickets.comments.reply_reopens`).
     */
    public static function replyReopens(): bool
    {
        return self::read()->boolean('tickets.comments.reply_reopens', true);
    }

    /**
     * Whether a non-agent watcher may view the ticket and reply as a participant
     * (`tickets.watchers.can_view`).
     */
    public static function watchersCanView(): bool
    {
        return self::read()->boolean('tickets.watchers.can_view', true);
    }

    /**
     * The Gate ability that lets an actor merge tickets (`tickets.authorization.abilities.merge`).
     */
    public static function mergeAbility(): string
    {
        return self::read()->string('tickets.authorization.abilities.merge', 'tickets.agent');
    }

    /**
     * Whether `$who` starts watching on their own (`tickets.watchers.auto.*`): the requester on
     * creation, the assignee on assignment, an agent who writes on the ticket, someone mentioned.
     */
    public static function autoWatch(string $who): bool
    {
        return match ($who) {
            'requester' => self::read()->boolean('tickets.watchers.auto.requester', true),
            'assignee' => self::read()->boolean('tickets.watchers.auto.assignee', true),
            'agent_repliers' => self::read()->boolean('tickets.watchers.auto.agent_repliers', true),
            'mentioned' => self::read()->boolean('tickets.watchers.auto.mentioned', false),
            default => throw InvalidTicketsConfigurationException::invalid('tickets.watchers.auto.'.$who, 'one of requester, assignee, agent_repliers, mentioned'),
        };
    }

    /**
     * Whether an unknown tag name is created when it is attached (`tickets.tags.free_form`).
     */
    public static function freeFormTags(): bool
    {
        return self::read()->boolean('tickets.tags.free_form', true);
    }

    /**
     * Whether tickets of different projects may be linked (`tickets.links.cross_project`).
     */
    public static function crossProjectLinks(): bool
    {
        return self::read()->boolean('tickets.links.cross_project', true);
    }

    /**
     * How far the cycle check of `blocks` / `parent_of` links walks (`tickets.links.max_depth`).
     */
    public static function maxLinkDepth(): int
    {
        return self::read()->integer('tickets.links.max_depth', 50, min: 1, max: 1000);
    }

    /**
     * Whether tickets of different projects may be merged (`tickets.merge.cross_project`).
     */
    public static function crossProjectMerge(): bool
    {
        return self::read()->boolean('tickets.merge.cross_project', false);
    }

    /**
     * Whether a merge moves the source's attachments to the target
     * (`tickets.merge.move_attachments`).
     */
    public static function mergeMovesAttachments(): bool
    {
        return self::read()->boolean('tickets.merge.move_attachments', true);
    }

    /**
     * The media bucket of ticket attachments (`tickets.attachments.bucket`).
     */
    public static function attachmentsBucket(): string
    {
        $bucket = self::read()->string('tickets.attachments.bucket', 'attachments');

        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $bucket) !== 1) {
            throw InvalidTicketsConfigurationException::invalid('tickets.attachments.bucket', 'a bucket name of lower-case letters, digits, dashes and underscores');
        }

        return $bucket;
    }

    /**
     * The disk ticket attachments are stored on (`tickets.attachments.disk`) — private by
     * default; media-library's own default is the web-served `public` disk.
     */
    public static function attachmentsDisk(): string
    {
        return self::read()->string('tickets.attachments.disk', 'local');
    }

    /**
     * The mime types a ticket attachment may have (`tickets.attachments.mimes`, [] = any).
     *
     * @return list<string>
     */
    public static function attachmentMimes(): array
    {
        return self::read()->list(
            'tickets.attachments.mimes',
            [],
            static fn (string $mime): bool => preg_match('#^[a-z0-9.+-]+/[a-z0-9.+*-]+$#i', $mime) === 1,
        );
    }

    /**
     * The largest ticket attachment, in kilobytes (`tickets.attachments.max_kb`).
     */
    public static function attachmentMaxKilobytes(): int
    {
        return self::read()->integer('tickets.attachments.max_kb', 10240, min: 1);
    }

    /** A reference prefix: an upper-case letter, then up to nine upper-case letters or digits. */
    public const string PREFIX_PATTERN = '/^[A-Z][A-Z0-9]{0,9}$/';

    private static function read(): ConfigValidator
    {
        return Config::using(InvalidTicketsConfigurationException::class);
    }
}
