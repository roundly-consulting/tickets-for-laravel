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
use RoundlyConsulting\Tickets\Enums\NotificationType;
use RoundlyConsulting\Tickets\Enums\TicketPriority;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLifecycleException;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Lifecycle\TicketLifecycle;
use RoundlyConsulting\Tickets\Notifications\TicketNotification;
use RoundlyConsulting\Tickets\Policies\TicketPolicy;

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

    /**
     * The Gate ability that lets an agent assign a ticket to someone else
     * (`tickets.authorization.abilities.assign`).
     */
    public static function assignAbility(): string
    {
        return self::read()->string('tickets.authorization.abilities.assign', 'tickets.assign');
    }

    /**
     * The Gate ability that deletes and restores tickets and runs the categories, fields and
     * tags (`tickets.authorization.abilities.manage`).
     */
    public static function manageAbility(): string
    {
        return self::read()->string('tickets.authorization.abilities.manage', 'tickets.manage');
    }

    /**
     * Whether the package registers its policy for the ticket model
     * (`tickets.authorization.register_policy`).
     */
    public static function registerPolicy(): bool
    {
        return self::read()->boolean('tickets.authorization.register_policy', true);
    }

    /**
     * The ticket policy (`tickets.authorization.policy`): {@see TicketPolicy} or a subclass —
     * the API asks it for abilities only the package's policy defines.
     *
     * @return class-string<TicketPolicy>
     */
    public static function policy(): string
    {
        $class = self::read()->string('tickets.authorization.policy', TicketPolicy::class);

        if (! is_a($class, TicketPolicy::class, true)) {
            throw InvalidTicketsConfigurationException::invalid('tickets.authorization.policy', 'a class extending '.TicketPolicy::class);
        }

        return $class;
    }

    /**
     * The transitions a requester may run on their own ticket
     * (`tickets.authorization.requester_transitions`) — `[]` (or a blank string) allows none;
     * null is the default.
     *
     * @return list<string>
     */
    public static function requesterTransitions(): array
    {
        $configured = config('tickets.authorization.requester_transitions') ?? ['close', 'reopen'];

        // An empty list is meaningful here (requesters run no transition), so it is read by hand
        // rather than through the toolkit's list reader, which treats [] as "use the default".
        if (is_string($configured)) {
            $configured = trim($configured) === '' ? [] : explode(',', $configured);
        }

        if (! is_array($configured)) {
            throw InvalidTicketsConfigurationException::invalid('tickets.authorization.requester_transitions', 'a list of transition names');
        }

        $names = [];

        foreach ($configured as $name) {
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', trim($name)) !== 1) {
                throw InvalidTicketsConfigurationException::invalid('tickets.authorization.requester_transitions', 'a list of transition names');
            }

            $names[] = trim($name);
        }

        return array_values(array_unique($names));
    }

    /**
     * Whether a watcher who is not an agent may run the requester transitions too
     * (`tickets.authorization.participants_can_transition`).
     */
    public static function participantsCanTransition(): bool
    {
        return self::read()->boolean('tickets.authorization.participants_can_transition', false);
    }

    /**
     * Whether anyone signed in may file a ticket for themselves
     * (`tickets.authorization.self_service`); off = agents only.
     */
    public static function selfService(): bool
    {
        return self::read()->boolean('tickets.authorization.self_service', true);
    }

    /**
     * Whether a requester may edit the subject and description of their unresolved ticket
     * (`tickets.authorization.requester_can_edit`).
     */
    public static function requesterCanEdit(): bool
    {
        return self::read()->boolean('tickets.authorization.requester_can_edit', true);
    }

    /**
     * Whether the guest endpoints are served (`tickets.guests.enabled`, with the routes on).
     */
    public static function guestsEnabled(): bool
    {
        return self::read()->boolean('tickets.guests.enabled', true);
    }

    /**
     * How long a guest link stays valid (`tickets.guests.link_ttl`, an ISO-8601 duration).
     */
    public static function guestLinkTtl(): CarbonInterval
    {
        return Durations::parse(self::read()->string('tickets.guests.link_ttl', 'P30D'))
            ?? throw InvalidTicketsConfigurationException::invalid('tickets.guests.link_ttl', 'an ISO-8601 duration such as P30D');
    }

    /**
     * Whether closing a ticket revokes every guest link to it (`tickets.guests.revoke_on_close`).
     */
    public static function revokeGuestsOnClose(): bool
    {
        return self::read()->boolean('tickets.guests.revoke_on_close', false);
    }

    /**
     * Whether the HTTP API is served (`tickets.routes.enabled`) — read exactly as the route
     * switch reads it.
     */
    public static function routesEnabled(): bool
    {
        return self::read()->boolean('tickets.routes.enabled', true);
    }

    /**
     * The URI prefix of every endpoint (`tickets.routes.prefix`; `/` for none).
     */
    public static function routePrefix(): string
    {
        $prefix = trim(self::read()->string('tickets.routes.prefix', 'api'), '/');

        if (preg_match('#^[A-Za-z0-9_.\-/]*$#', $prefix) !== 1) {
            throw InvalidTicketsConfigurationException::invalid('tickets.routes.prefix', 'a URI prefix of letters, digits, dashes, dots and slashes');
        }

        return $prefix;
    }

    /**
     * The middleware of the agent and requester endpoints (`tickets.routes.middleware`).
     *
     * @return list<string>
     */
    public static function routeMiddleware(): array
    {
        return self::read()->list('tickets.routes.middleware', ['api', 'auth', 'throttle:tickets-api']);
    }

    /**
     * The middleware of the guest endpoints (`tickets.routes.guest.middleware`); the signed
     * link check is always added after it.
     *
     * @return list<string>
     */
    public static function guestRouteMiddleware(): array
    {
        return self::read()->list('tickets.routes.guest.middleware', ['api', 'throttle:tickets-guest']);
    }

    /**
     * The largest page a ticket list returns (`tickets.routes.max_per_page`).
     */
    public static function maxPerPage(): int
    {
        return self::read()->integer('tickets.routes.max_per_page', 100, min: 1, max: 1000);
    }

    /**
     * Requests a minute per user on the `tickets-api` limiter (`tickets.routes.rate_limit`).
     */
    public static function apiRateLimit(): int
    {
        return self::read()->integer('tickets.routes.rate_limit', 120, min: 1);
    }

    /**
     * Requests a minute per IP and ticket on the `tickets-guest` limiter
     * (`tickets.routes.guest.rate_limit`).
     */
    public static function guestRateLimit(): int
    {
        return self::read()->integer('tickets.routes.guest.rate_limit', 30, min: 1);
    }

    /**
     * The most tickets one bulk call takes (`tickets.routes.bulk.max`).
     */
    public static function bulkMax(): int
    {
        return self::read()->integer('tickets.routes.bulk.max', 100, min: 1, max: 1000);
    }

    /**
     * How close to its due date an unresolved ticket is flagged as due soon by `tickets:sweep`
     * (`tickets.due.soon_window`: an ISO-8601 duration, or `off` for never).
     */
    public static function dueSoonWindow(): ?CarbonInterval
    {
        $value = self::read()->string('tickets.due.soon_window', 'PT4H');

        if (strtolower(trim($value)) === 'off') {
            return null;
        }

        return Durations::parse($value)
            ?? throw InvalidTicketsConfigurationException::invalid('tickets.due.soon_window', 'an ISO-8601 duration (PT4H, P1D) or off');
    }

    /**
     * Whether the package sends notifications at all (`tickets.notifications.enabled`).
     */
    public static function notificationsEnabled(): bool
    {
        return self::read()->boolean('tickets.notifications.enabled', true);
    }

    /**
     * The channels every notification goes out on (`tickets.notifications.channels`).
     *
     * @return list<string>
     */
    public static function notificationChannels(): array
    {
        return self::read()->list(
            'tickets.notifications.channels',
            ['mail'],
            static fn (string $channel): bool => trim($channel) !== '',
        );
    }

    /**
     * The queue notifications are pushed to (`tickets.notifications.queue`), or null for the
     * connection's default queue.
     */
    public static function notificationQueue(): ?string
    {
        $queue = trim(self::read()->string('tickets.notifications.queue', ''));

        return $queue === '' ? null : $queue;
    }

    /**
     * Whether the one who acted is notified of their own change too
     * (`tickets.notifications.notify_actor`).
     */
    public static function notifyActor(): bool
    {
        return self::read()->boolean('tickets.notifications.notify_actor', false);
    }

    /**
     * Whether guest requesters and guest watchers are notified (`tickets.notifications.guests`).
     */
    public static function notifyGuests(): bool
    {
        return self::read()->boolean('tickets.notifications.guests', true);
    }

    /**
     * Whether any notification may go to a guest: notifications on, guests notified, and a type
     * that reaches the requester side switched on.
     */
    public static function notifiesGuests(): bool
    {
        if (! self::notificationsEnabled() || ! self::notifyGuests()) {
            return false;
        }

        foreach (NotificationType::cases() as $type) {
            if ($type->reachesRequesters() && self::notification($type) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The link a signed-in recipient's mail opens for the ticket (`tickets.notifications.url`,
     * `{reference}` filled in), or null when none is configured.
     */
    public static function notificationUrl(string $reference): ?string
    {
        $template = trim(self::read()->string('tickets.notifications.url', ''));

        if ($template === '') {
            return null;
        }

        $url = str_replace('{reference}', rawurlencode($reference), $template);

        if (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $url) !== 1) {
            throw InvalidTicketsConfigurationException::invalid('tickets.notifications.url', 'an http(s) URL, with {reference} where the ticket reference goes');
        }

        return $url;
    }

    /**
     * The class sent for a notification type (`tickets.notifications.types.*`): the packaged
     * class or one extending it; null when the type is switched off (`false`). Blank is the
     * packaged class.
     *
     * @return class-string<TicketNotification>|null
     */
    public static function notification(NotificationType $type): ?string
    {
        // Read key by key: each type is a leaf of its own.
        $configured = match ($type) {
            NotificationType::Created => config('tickets.notifications.types.created'),
            NotificationType::Assigned => config('tickets.notifications.types.assigned'),
            NotificationType::Replied => config('tickets.notifications.types.replied'),
            NotificationType::RequesterReplied => config('tickets.notifications.types.requester_replied'),
            NotificationType::NoteAdded => config('tickets.notifications.types.note_added'),
            NotificationType::Mentioned => config('tickets.notifications.types.mentioned'),
            NotificationType::StatusChanged => config('tickets.notifications.types.status_changed'),
            NotificationType::Overdue => config('tickets.notifications.types.overdue'),
            NotificationType::DueSoon => config('tickets.notifications.types.due_soon'),
            NotificationType::Merged => config('tickets.notifications.types.merged'),
        };

        $key = 'tickets.notifications.types.'.$type->value;

        if ($configured === null || (is_string($configured) && trim($configured) === '')) {
            return $type->defaultClass();
        }

        if ($configured === false || (is_string($configured) && in_array(strtolower(trim($configured)), ['false', 'off', '0', 'no'], true))) {
            return null;
        }

        $base = $type->defaultClass();

        if (! is_string($configured) || ! class_exists($configured) || ! is_a($configured, $base, true)) {
            throw InvalidTicketsConfigurationException::invalid($key, 'false or a class extending '.$base);
        }

        return $configured;
    }

    /** A reference prefix: an upper-case letter, then up to nine upper-case letters or digits. */
    public const string PREFIX_PATTERN = '/^[A-Z][A-Z0-9]{0,9}$/';

    private static function read(): ConfigValidator
    {
        return Config::using(InvalidTicketsConfigurationException::class);
    }
}
