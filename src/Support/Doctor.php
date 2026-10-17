<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RoundlyConsulting\Comments\Support\CommentModel;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Tickets\Contracts\Assignee;
use RoundlyConsulting\Tickets\Contracts\GuestLinkGenerator;
use RoundlyConsulting\Tickets\Contracts\TicketProject;
use RoundlyConsulting\Tickets\DataTransferObjects\DoctorCheck;
use RoundlyConsulting\Tickets\DataTransferObjects\LifecycleIssue;
use RoundlyConsulting\Tickets\Enums\NotificationType;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Exceptions\TicketsException;
use RoundlyConsulting\Tickets\Exceptions\UnknownMorphAliasException;
use RoundlyConsulting\Tickets\Guests\SignedRouteLinkGenerator;

/**
 * The installation checks behind `tickets:doctor`, read-only, by section: key types, the
 * lifecycle contract, priorities, the Gate abilities, the alias allow-lists, the guest fields,
 * the conversation, attachments, the HTTP API, notifications and due dates.
 *
 * @internal
 */
final class Doctor
{
    /**
     * @return list<DoctorCheck>
     */
    public static function checks(): array
    {
        return [
            ...self::keys(),
            ...self::lifecycle(),
            ...self::priority(),
            ...self::authorization(),
            ...self::aliases(),
            ...self::guests(),
            ...self::conversation(),
            ...self::attachments(),
            ...self::api(),
            ...self::notifications(),
            ...self::due(),
        ];
    }

    /**
     * One key type for the whole installation (§4.2), and a user model keyed by it.
     *
     * @return list<DoctorCheck>
     */
    private static function keys(): array
    {
        $problems = KeyAlignment::problems();

        $checks = $problems === []
            ? [new DoctorCheck('keys', 'alignment', Messages::get('doctor.keys_ok', [
                'type' => KeyAlignment::settings()['tickets.key_type']->value ?? '',
            ]), 'ok')]
            : array_map(static fn (string $problem): DoctorCheck => new DoctorCheck('keys', 'alignment', $problem, 'error'), $problems);

        $class = self::userModel();
        $type = $class === null ? null : self::keyTypeOf(new $class);
        $expected = KeyAlignment::settings()['tickets.key_type'];

        if ($class !== null && $type !== null && $expected !== null && $type !== $expected->value) {
            $checks[] = new DoctorCheck('keys', 'user_model', Messages::get('doctor.user_model_mismatch', [
                'class' => $class, 'model' => $type, 'type' => $expected->value,
            ]), 'warning');
        }

        return $checks;
    }

    /**
     * The model class of the default guard's users, or null when there is none.
     *
     * @return class-string<Model>|null
     */
    private static function userModel(): ?string
    {
        $guard = config('auth.defaults.guard');
        $provider = is_string($guard) ? config('auth.guards.'.$guard.'.provider') : null;
        $class = is_string($provider) ? config('auth.providers.'.$provider.'.model') : null;

        return is_string($class) && is_subclass_of($class, Model::class) ? $class : null;
    }

    /**
     * The key type a model uses — bigint, uuid or ulid — or null when that cannot be told.
     */
    private static function keyTypeOf(Model $model): ?string
    {
        if (! $model->usesUniqueIds()) {
            return $model->getKeyType() === 'int' ? 'bigint' : null;
        }

        $id = $model->newUniqueId();

        return match (true) {
            is_string($id) && Str::isUlid($id) => 'ulid',
            is_string($id) && Str::isUuid($id) => 'uuid',
            default => null,
        };
    }

    /**
     * @return list<DoctorCheck>
     */
    private static function lifecycle(): array
    {
        $issues = LifecycleContract::issues();

        $checks = array_map(
            static fn (LifecycleIssue $issue): DoctorCheck => new DoctorCheck('lifecycle', $issue->code, $issue->message, $issue->error ? 'error' : 'warning'),
            $issues,
        );

        if (array_filter($issues, static fn (LifecycleIssue $issue): bool => $issue->error) !== []) {
            return $checks;
        }

        $checks[] = new DoctorCheck('lifecycle', 'contract', Messages::get('doctor.lifecycle_ok', [
            'class' => TicketsConfig::lifecycle(),
        ]), 'ok');

        $supported = LifecycleContract::supportedConveniences();

        $checks[] = new DoctorCheck('lifecycle', 'conveniences', Messages::get('doctor.conveniences', [
            'list' => $supported === [] ? '—' : implode(', ', $supported),
        ]), count($supported) === 8 ? 'ok' : 'warning');

        return $checks;
    }

    /**
     * The priority enum and default, and stored priorities the enum no longer has (they read as
     * no priority and sort last).
     *
     * @return list<DoctorCheck>
     */
    private static function priority(): array
    {
        try {
            $enum = TicketsConfig::priorityEnum();
            $default = TicketsConfig::defaultPriority();
        } catch (InvalidTicketsConfigurationException $exception) {
            return [new DoctorCheck('priority', 'enum', $exception->getMessage(), 'error')];
        }

        $checks = [new DoctorCheck('priority', 'enum', Messages::get('doctor.priority_ok', [
            'class' => $enum, 'default' => (string) $default->value,
        ]), 'ok')];

        $ticket = TicketModels::ticket();
        $model = new $ticket;

        if ($model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            $unknown = $ticket::withTrashed()
                ->whereNotIn('priority', array_map(static fn (mixed $case): string|int => $case->value, $enum::cases()))
                ->count();

            if ($unknown > 0) {
                $checks[] = new DoctorCheck('priority', 'stored', Messages::choice('doctor.priority_unknown', $unknown, ['class' => $enum]), 'warning');
            }
        }

        return $checks;
    }

    /**
     * The Gate abilities the package asks about. An undefined one denies — unless a
     * `Gate::before` (a permissions package) grants it, which `Gate::has()` cannot see, so it
     * is a warning.
     *
     * @return list<DoctorCheck>
     */
    private static function authorization(): array
    {
        try {
            $abilities = array_values(array_unique([
                TicketsConfig::agentAbility(),
                TicketsConfig::assignAbility(),
                TicketsConfig::mergeAbility(),
                TicketsConfig::manageAbility(),
            ]));
        } catch (InvalidTicketsConfigurationException $exception) {
            return [new DoctorCheck('authorization', 'abilities', $exception->getMessage(), 'error')];
        }

        $gate = Container::getInstance()->make(Gate::class);
        $undefined = array_values(array_filter($abilities, static fn (string $ability): bool => ! $gate->has($ability)));

        return [$undefined === []
            ? new DoctorCheck('authorization', 'abilities', Messages::get('doctor.abilities_ok', ['list' => implode(', ', $abilities)]), 'ok')
            : new DoctorCheck('authorization', 'abilities', Messages::get('doctor.abilities_undefined', ['list' => implode(', ', $undefined)]), 'warning')];
    }

    /**
     * The alias allow-lists API input names models through: model classes, assignables
     * implementing {@see Assignee}, projects {@see TicketProject}.
     *
     * @return list<DoctorCheck>
     */
    private static function aliases(): array
    {
        $contracts = [
            MorphAliases::REQUESTERS => null,
            MorphAliases::ASSIGNABLES => Assignee::class,
            MorphAliases::WATCHABLES => null,
            MorphAliases::PROJECTS => TicketProject::class,
        ];

        $checks = [];

        foreach ($contracts as $kind => $contract) {
            try {
                $map = MorphAliases::map($kind);
            } catch (InvalidTicketsConfigurationException|UnknownMorphAliasException $exception) {
                $checks[] = new DoctorCheck('aliases', $kind, $exception->getMessage(), 'error');

                continue;
            }

            foreach ($map as $alias => $class) {
                if ($contract !== null && ! is_subclass_of($class, $contract)) {
                    $checks[] = new DoctorCheck('aliases', $kind, Messages::get('doctor.alias_contract', [
                        'key' => 'tickets.'.$kind, 'alias' => $alias, 'class' => $class, 'contract' => $contract,
                    ]), 'error');
                }
            }
        }

        return $checks === [] ? [new DoctorCheck('aliases', 'maps', Messages::get('doctor.aliases_ok'), 'ok')] : $checks;
    }

    /**
     * @return list<DoctorCheck>
     */
    private static function guests(): array
    {
        try {
            $fields = array_keys(GuestFields::all());
        } catch (InvalidTicketsConfigurationException $exception) {
            return [new DoctorCheck('guests', 'fields', $exception->getMessage(), 'error')];
        }

        return [new DoctorCheck('guests', 'fields', Messages::get('doctor.guest_fields_ok', ['list' => implode(', ', $fields)]), 'ok')];
    }

    /**
     * The comments-for-laravel side of the conversation: the 1.1 `meta` column replies and notes
     * are written with, and `comments.authorization`, which checks the authenticated user —
     * not tickets' own policy — on every reply, note, approval and lock.
     *
     * @return list<DoctorCheck>
     */
    private static function conversation(): array
    {
        $comment = CommentModel::class();
        $model = new $comment;

        $checks = [$model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'meta')
            ? new DoctorCheck('conversation', 'meta', Messages::get('doctor.meta_ok'), 'ok')
            : new DoctorCheck('conversation', 'meta', Messages::get('doctor.meta_missing'), 'error')];

        if (Config::boolean('comments.authorization')) {
            $checks[] = new DoctorCheck('conversation', 'comments_authorization', Messages::get('doctor.comments_authorization'), 'warning');
        }

        return $checks;
    }

    /**
     * Ticket attachments are private: on the web-served `public` disk a private file is still
     * reachable under /storage, without the signed URL. And their download URLs are temporary
     * ones — the disk's own, or media-library's signed stream route: with that route off and a
     * disk that mints none, every attachment URL the API renders throws.
     *
     * @return list<DoctorCheck>
     */
    private static function attachments(): array
    {
        $disk = TicketsConfig::attachmentsDisk();

        $checks = [$disk === 'public'
            ? new DoctorCheck('attachments', 'disk', Messages::get('doctor.attachments_public', ['disk' => $disk]), 'warning')
            : new DoctorCheck('attachments', 'disk', Messages::get('doctor.attachments_ok', ['disk' => $disk]), 'ok')];

        if (Config::boolean('media.stream.enabled', true)) {
            $checks[] = new DoctorCheck('attachments', 'temporary_urls', Messages::get('doctor.temporary_urls_stream'), 'ok');

            return $checks;
        }

        // Ticket attachments, and the reply and note attachments comments keeps privately.
        $disks = array_values(array_unique([$disk, Config::string('comments.media.private_disk', 'local')]));
        $level = TicketsConfig::routesEnabled() ? 'error' : 'warning';
        $failing = array_values(array_filter($disks, static fn (string $name): bool => ! self::presigns($name)));

        $checks[] = $failing === []
            ? new DoctorCheck('attachments', 'temporary_urls', Messages::get('doctor.temporary_urls_native', ['list' => implode(', ', $disks)]), 'ok')
            : new DoctorCheck('attachments', 'temporary_urls', Messages::get('doctor.temporary_urls_missing', ['list' => implode(', ', $failing)]), $level);

        return $checks;
    }

    /**
     * Whether the disk mints temporary URLs of its own (S3 and friends, or a local disk that
     * serves signed URLs).
     */
    private static function presigns(string $disk): bool
    {
        try {
            $filesystem = Container::getInstance()->make(Filesystems::class)->disk($disk);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $filesystem instanceof FilesystemAdapter && $filesystem->providesTemporaryUrls();
    }

    /**
     * The HTTP API: whether it is served, behind authentication; whether the policy setting
     * names a usable policy (a broken one registers nothing); and whether the default guest-link
     * generator can mint a link at all — an error once guests are notified, whose mails carry
     * those links.
     *
     * @return list<DoctorCheck>
     */
    private static function api(): array
    {
        $checks = [];

        try {
            $checks[] = new DoctorCheck('api', 'policy', Messages::get('doctor.policy_ok', ['class' => TicketsConfig::policy()]), 'ok');
        } catch (InvalidTicketsConfigurationException $exception) {
            $checks[] = new DoctorCheck('api', 'policy', $exception->getMessage(), 'error');
        }

        $routes = TicketsConfig::routesEnabled();
        $guarded = array_filter(TicketsConfig::routeMiddleware(), static fn (string $middleware): bool => str_starts_with($middleware, 'auth')) !== [];

        $checks[] = match (true) {
            ! $routes => new DoctorCheck('api', 'routes', Messages::get('doctor.routes_off'), 'ok'),
            $guarded => new DoctorCheck('api', 'routes', Messages::get('doctor.routes_on'), 'ok'),
            default => new DoctorCheck('api', 'routes', Messages::get('doctor.routes_unguarded'), 'warning'),
        };

        if (self::guestLinks() instanceof SignedRouteLinkGenerator && (! $routes || ! TicketsConfig::guestsEnabled())) {
            $checks[] = self::notifiesGuests()
                ? new DoctorCheck('api', 'guest_links', Messages::get('doctor.guest_links_notified'), 'error')
                : new DoctorCheck('api', 'guest_links', Messages::get('doctor.guest_links_unavailable'), 'warning');
        }

        return $checks;
    }

    /**
     * Whether guests are notified — false too when a notification setting is broken (the
     * notifications section reports that).
     */
    private static function notifiesGuests(): bool
    {
        try {
            return TicketsConfig::notifiesGuests();
        } catch (TicketsException|InvalidConfigurationException) {
            return false;
        }
    }

    /**
     * The bound guest-link generator — whatever the host bound, by config or in the container.
     */
    private static function guestLinks(): GuestLinkGenerator
    {
        return Container::getInstance()->make(GuestLinkGenerator::class);
    }

    /**
     * Notifications: on or off, on which channels; every type a usable class (or off); the
     * button URL template.
     *
     * @return list<DoctorCheck>
     */
    private static function notifications(): array
    {
        try {
            $enabled = TicketsConfig::notificationsEnabled();
            $channels = TicketsConfig::notificationChannels();
        } catch (InvalidTicketsConfigurationException $exception) {
            return [new DoctorCheck('notifications', 'enabled', $exception->getMessage(), 'error')];
        }

        $checks = [$enabled
            ? new DoctorCheck('notifications', 'enabled', Messages::get('doctor.notifications_on', ['list' => implode(', ', $channels)]), 'ok')
            : new DoctorCheck('notifications', 'enabled', Messages::get('doctor.notifications_off'), 'ok')];

        $off = [];
        $broken = false;

        foreach (NotificationType::cases() as $type) {
            try {
                if (TicketsConfig::notification($type) === null) {
                    $off[] = $type->value;
                }
            } catch (InvalidTicketsConfigurationException $exception) {
                $checks[] = new DoctorCheck('notifications', 'types', $exception->getMessage(), 'error');
                $broken = true;
            }
        }

        if (! $broken) {
            $checks[] = new DoctorCheck('notifications', 'types', Messages::get('doctor.notification_types', [
                'list' => $off === [] ? '—' : implode(', ', $off),
            ]), 'ok');
        }

        try {
            TicketsConfig::notificationUrl('TCK-1');
        } catch (InvalidTicketsConfigurationException $exception) {
            $checks[] = new DoctorCheck('notifications', 'url', $exception->getMessage(), 'error');
        }

        return $checks;
    }

    /**
     * Due dates and auto-close: both durations valid, and the reminder of the two scheduled
     * sweeps that drive them.
     *
     * @return list<DoctorCheck>
     */
    private static function due(): array
    {
        $checks = [];

        foreach (['soon_window' => TicketsConfig::dueSoonWindow(...), 'auto_close' => TicketsConfig::autoCloseAfter(...)] as $code => $read) {
            try {
                $read();
            } catch (InvalidTicketsConfigurationException $exception) {
                $checks[] = new DoctorCheck('due', $code, $exception->getMessage(), 'error');
            }
        }

        $checks[] = new DoctorCheck('due', 'schedule', Messages::get('doctor.schedule'), 'ok');

        return $checks;
    }
}
