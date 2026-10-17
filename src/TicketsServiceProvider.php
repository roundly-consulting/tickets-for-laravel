<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets;

use Closure;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\Tickets\Exceptions\TicketsException;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

final class TicketsServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('tickets')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations()
            ->contributesToAbout(static fn (): array => [
                'Primary key type' => self::orInvalid(static fn (): string => KeyType::fromConfig('tickets.primary_key_type')->value),
                'Key type' => self::orInvalid(static fn (): string => KeyType::fromConfig('tickets.key_type')->value),
                'Lifecycle' => self::orInvalid(static fn (): string => class_basename(TicketsConfig::lifecycle())),
                'Priority enum' => self::orInvalid(static fn (): string => class_basename(TicketsConfig::priorityEnum())),
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(TicketsManager::class);
    }

    public function boot(): void
    {
        parent::boot();

        // The migrations key their morph and owner columns through the toolkit's
        // `morphKey` / `ownerKey` macros, so they must exist before `migrate` runs.
        // Idempotent — the toolkit guards each macro with `hasMacro()`.
        $this->registerBlueprintMacros();
    }

    /**
     * A strict read rendered for `about`, or `INVALID` when the setting is broken — so
     * `php artisan about` still renders on a misconfigured host while every real read throws.
     *
     * @param  Closure(): string  $read
     */
    private static function orInvalid(Closure $read): string
    {
        try {
            return $read();
        } catch (InvalidConfigurationException|TicketsException) {
            return 'INVALID';
        }
    }
}
