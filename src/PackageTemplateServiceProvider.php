<?php

declare(strict_types=1);

namespace RoundlyConsulting\PackageTemplate;

use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class PackageTemplateServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('package-template')
            ->hasConfigFile()
            // Read every setting through the toolkit's strict Config readers — boolean(),
            // integer(), oneOf() / enum(), requireString() — never a cast or a truthiness check:
            // env() leaves `off`/`no` as non-empty (truthy) strings, `(int) 'five'` is 0, and a
            // typo must throw naming the key instead of falling back to a default. A blank value
            // (a host's `KEY=`) is not set: the readers return the default, exactly as if absent.
            ->contributesToAbout(static fn (): array => [
                'Enabled' => Config::boolean('package-template.enabled', true) ? 'YES' : 'NO',
                'Timeout' => Config::integer('package-template.timeout', 30, min: 1, max: 300).'s',
                'Mode' => Config::oneOf('package-template.mode', ['sync', 'queue'], 'sync'),
            ]);

        // Grow this as the package grows:
        //   ->hasMigrations()
        //   ->hasCommands([SomeCommand::class])
        //   ->hasViews() / ->hasTranslations()
        //   ->hasRoutes('package-template.php')
        // See RoundlyConsulting\PackageToolkit\Package for the full fluent API.
        //
        // The PackageTemplate facade's global alias is declared in composer.json
        // (extra.laravel.aliases), not here — the skill's facade rule, and package discovery
        // registers it without booting any code.
    }

    public function register(): void
    {
        parent::register();

        // Container bindings (bind/singleton/scoped) go here — never in boot().
        $this->app->singleton(PackageTemplateManager::class);
    }
}
