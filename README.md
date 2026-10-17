<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/package-template-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/package-template-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/package-template-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/package-template-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/package-template-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/package-template-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=package-template-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# package-template-for-laravel

A GitHub **template repository** for scaffolding new `roundly-consulting/*-for-laravel`
packages. It is not itself a shippable package — it's a working, green skeleton that
already follows every workspace convention (native service provider on top of
`package-toolkit-for-laravel`, Pest 4 + Testbench via `testing-for-laravel`, Pint,
Larastan level 7, the three GitHub workflows, dependabot) so a new package starts from
day one compliant instead of retrofitted later.

## Requirements

- PHP ^8.4
- Laravel 12.x or 13.x

## Using this template

1. On GitHub, click **"Use this template" → "Create a new repository"** under
   `roundly-consulting`, named `<domain>-for-laravel` (e.g. `credits-for-laravel`). Keep
   it **private**.
2. Clone it into this workspace, next to the other packages:

   ```bash
   gh repo clone roundly-consulting/<domain>-for-laravel ~/Code/Packages/<domain>-for-laravel
   cd ~/Code/Packages/<domain>-for-laravel
   ```

3. Run the rename script with the StudlyCase domain name (no `-for-laravel` suffix), and
   optionally a second StudlyCase name for the public API:

   ```bash
   bash bin/rename-package.sh Credits
   bash bin/rename-package.sh Auth Authentication   # when the domain is a Laravel core name
   ```

   The **domain** names the package itself; the **API name** (defaults to the domain) names
   everything a host application refers to by name:

   | Derived from | What | `Credits` | `Auth Authentication` |
   |---|---|---|---|
   | domain | repo + composer name, GitHub URLs | `credits-for-laravel` | `auth-for-laravel` |
   | domain | namespace | `RoundlyConsulting\Credits` | `RoundlyConsulting\Auth` |
   | API name | facade + its global alias | `Facades\Credits`, `Credits` | `Facades\Authentication`, `Authentication` |
   | API name | manager, fake, service provider | `CreditsManager`, `CreditsFake`, `CreditsServiceProvider` | `AuthenticationManager`, `AuthenticationFake`, `AuthenticationServiceProvider` |
   | API name | example action + DTO | `ExampleCreditsAction`, `ExampleCreditsData` | `ExampleAuthenticationAction`, `ExampleAuthenticationData` |
   | API name | config file + handle, env prefix | `config/credits.php`, `CREDITS_` | `config/authentication.php`, `AUTHENTICATION_` |

   The script refuses — before touching anything — a name that would break the new package
   on its first run, and says what to pass instead:

   - an API name that is a **Laravel core facade or global alias** (`Auth`, `Cache`, `Http`,
     `Log`, `Mail`, `Queue`, `Storage`, `Str`, `URL`, … — compared case-insensitively): the
     package's facade and alias would shadow Laravel's;
   - an API name whose config handle is a **config file Laravel ships** (`database`,
     `services`, `logging`, `filesystems`, …): the package's config would be merged into the
     framework's;
   - a PHP reserved word as the API name (the facade class would not parse), `Package` (the
     provider would collide with the toolkit's `PackageServiceProvider`), and a domain that
     names the template or a Tier-0 dependency (`PackageTemplate`, `PackageToolkit`,
     `Testing`) or still carries the `ForLaravel` suffix.

   It rewrites every `PackageTemplate` / `packageTemplate` / `package-template` /
   `package_template` / `PACKAGE_TEMPLATE` occurrence per the table above and renames every
   file that carries the name in its filename (`src/CreditsServiceProvider.php`,
   `config/credits.php`, `src/CreditsManager.php`, `src/Facades/Credits.php`,
   `src/Testing/CreditsFake.php`, `src/Actions/ExampleCreditsAction.php`,
   `src/DataTransferObjects/ExampleCreditsData.php`). It re-sorts the `use` imports the rename
   moves, so the tree passes `pint --test` as-is, and it fails if the template's name survives
   in any file's contents or name, **in any casing**.

   As its last act it deletes the template's own scaffolding — `bin/rename-package.sh` and
   `tests/Feature/RenamePackageScriptTest.php` — and stages both as deletions. Neither may
   survive into the new package: the script's substitution patterns are the one place the
   rewrite cannot reach, so keeping it keeps template tokens, and the self-test tests a
   script that is no longer there. If you restore either one, delete it again before you
   commit.

4. Finish the manual steps the script prints: write a real `composer.json` description,
   rewrite this README for the real package in the **slim format** (the
   `laravel-package-developer` skill's *README Requirements*: title + short pitch,
   Installation, one Usage example — nothing else; the full docs live on our website) — keep
   the badges row at the top, the "Documentation" block and the "Support our work" section
   before "License" — and write the 1.0.0 draft into `CHANGELOG.md`'s
   `## Unreleased` ("Initial public release." + an `### Added` summary), then run the quality gate:

   ```bash
   composer install
   composer format && composer test && composer analyse && composer audit
   ```

   A scaffolded package is expected to be clean of template tokens, in any casing — this
   must print nothing:

   ```bash
   grep -rliE 'package[-_]?template' --exclude-dir=.git --exclude-dir=vendor .
   ```

5. The **Tier-0 pairing** is already in `composer.json`, from Packagist:
   `roundly-consulting/package-toolkit-for-laravel` in `require`,
   `roundly-consulting/testing-for-laravel` in `require-dev`, both plain carets. Add any
   *other* roundly package the new one needs the same way — a caret on its latest release, no
   `repositories`, no `@dev`, no `minimum-stability` (see the `laravel-package-developer`
   skill's **Cross-package dependencies** section). The one exception is a brand-new provider
   that is not on Packagist yet: wire it path + VCS `@dev` with the `PRIVATE_REPOS_TOKEN` secret,
   and take that wiring out when it publishes.
6. The package needs **no CI secret** — every dependency installs from Packagist. Manually
   dispatch the three workflows once and confirm they're green — they run on
   `workflow_dispatch` only, never on push (see the skill's **GitHub Workflows** section).
7. From here, treat it like any other package: run it through the normal
   `laravel-package-improver` → `laravel-package-implementer` → `laravel-package-publisher`
   lifecycle (`/package-pipeline <domain>-for-laravel`) once there's real functionality to
   plan.

## What's included

```
src/
├── Actions/ExamplePackageTemplateAction.php          # placeholder action — replace me
├── DataTransferObjects/ExamplePackageTemplateData.php # its input DTO — replace me
├── Facades/PackageTemplate.php        # final facade, @method static docblock, real fake()
├── Testing/PackageTemplateFake.php    # extends the manager, records calls, assert*()
├── PackageTemplateManager.php         # the facade root: thin, resolves actions via the container
├── Commands/ Events/ Exceptions/ Jobs/ Models/ Traits/
│   (empty — the standard topic folders, ready for real code)
└── PackageTemplateServiceProvider.php # extends PackageServiceProvider; binds the manager
config/package-template.php            # placeholder keys (bool, int, vocabulary), merged + published + read strictly
database/{factories,migrations}/       # empty — populate as the package grows
tests/
├── TestCase.php           # extends PackageTestCase (testing-for-laravel)
├── Pest.php
├── ArchTest.php           # the arch presets that apply to a model-less, migration-less package
├── ConfigContractTest.php # pins the config file against what src/ reads, and off Laravel's own handles
├── Feature/AboutTest.php  # pins the `php artisan about` section and its strict config reads
└── Feature/FacadeTest.php # pins the facade contract + tests facade, DI, action and fake
```

Two more files exist only for the template itself and are deleted by step 3, so they never
reach a real package: `bin/rename-package.sh` and its self-test
`tests/Feature/RenamePackageScriptTest.php`, which runs the script against a throwaway copy
of this tree.

The only example code is the placeholder public API — one action, its DTO, the manager
method that exposes it, the facade and the fake — so the required **Actions → Manager →
Facade** layering (the `laravel-package-developer` skill section *Public API: Actions →
Manager → Facade (REQUIRED)*) and its pins are in place before the first real line is
written. Rename `example()` to the package's first real verb and grow from there; everything
else is tooling and folder shape. Add `src/Models`, migrations, etc. as the real package needs
them, following the skill's conventions for each (Models use `$guarded = []` + `SoftDeletes` +
a factory; Actions take DTOs, never arrays; migrations never define `down()`; and so on).
Enable `ArchPresets::modelsGoThroughTheFacade()` in `tests/ArchTest.php` once the package has a
`Models`, `Concerns` or `Traits` namespace.

A package with no host-facing stateful behaviour — a pure trait, a per-request static
builder, package-author or dev-only tooling — is exempt: delete the manager, facade, fake,
example action + DTO, `tests/Feature/FacadeTest.php` and the `aliases` entry in
`composer.json`, and say why in one README line.

## Usage

> Placeholder — rewrite this section for the real API once `example()` is replaced. Keep the
> order: facade first, then *Without the facade*, then the fake.

```php
use RoundlyConsulting\PackageTemplate\DataTransferObjects\ExamplePackageTemplateData;
use RoundlyConsulting\PackageTemplate\Facades\PackageTemplate;

PackageTemplate::example(new ExamplePackageTemplateData('Ada')); // "Hello, Ada!"
```

The facade is also aliased globally as `PackageTemplate` (declared in `composer.json` under
`extra.laravel.aliases`, so package discovery registers it).

### Without the facade

The facade is sugar over `PackageTemplateManager`, a container singleton — inject it for the
same API. Or call the action directly; it is the behaviour both of them run.

```php
use RoundlyConsulting\PackageTemplate\Actions\ExamplePackageTemplateAction;
use RoundlyConsulting\PackageTemplate\DataTransferObjects\ExamplePackageTemplateData;
use RoundlyConsulting\PackageTemplate\PackageTemplateManager;

final class GreetController
{
    public function __construct(private PackageTemplateManager $packageTemplate) {}

    public function __invoke(): string
    {
        return $this->packageTemplate->example(new ExamplePackageTemplateData('Ada'));
    }
}

app(ExamplePackageTemplateAction::class)->execute(new ExamplePackageTemplateData('Ada'));
```

### Testing with the fake

`PackageTemplate::fake()` swaps the manager for a recording fake — behind the facade and in
the container, so injected managers are faked too. Nothing runs; every call is recorded.

```php
$fake = PackageTemplate::fake();

// ... code under test ...

$fake->assertExampleCalled();
$fake->assertExampleCalled(fn (ExamplePackageTemplateData $data): bool => $data->name === 'Ada');

// or, when the code under test must not call it at all:
$fake->assertNothingCalled();
```

## Configuration

> Placeholder — keep one row per shipped key as the real configuration replaces these.
> Every key is read strictly through the toolkit's `Config` readers: a key that is not set
> takes its default, and an invalid value throws `InvalidConfigurationException` naming the
> key — nothing falls back silently. **Blank means not set:** an absent key, `null` and a
> blank value (a host's `PACKAGE_TEMPLATE_TIMEOUT=`, empty or whitespace only) all take the
> default. Pass env values through raw (`env('X', 30)`, never `(int) env('X', 30)`), so the
> reader sees the typo.

Publish the config file with:

```bash
php artisan vendor:publish --tag=package-template-config
```

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `PACKAGE_TEMPLATE_ENABLED` | `true` | Placeholder flag, reported by `php artisan about`. Read with the toolkit's `Config::boolean()`, so `true`/`false`, `1`/`0`, `on`/`off` and `yes`/`no` all mean what they say, a blank value takes the default, and anything else throws `InvalidConfigurationException` naming the key. |
| `timeout` | `PACKAGE_TEMPLATE_TIMEOUT` | `30` | Placeholder integer (seconds, 1–300), reported by `php artisan about`. Read with `Config::integer()`: an int or an integer string (`'45'`) passes, a blank value takes the default; `'five'`, `'5.5'`, `'30s'`, `0` or `301` throws. |
| `mode` | `PACKAGE_TEMPLATE_MODE` | `sync` | Placeholder vocabulary (`sync` or `queue`), reported by `php artisan about`. Read with `Config::oneOf()` (use `Config::enum()` once the package has an enum for it): a blank value takes the default; a typo such as `queued` throws, listing the allowed values. |

## Testing

```bash
composer test
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/package-template-for-laravel](https://roundly-consulting.com/open-source/docs/package-template-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=package-template-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=package-template-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=package-template-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
