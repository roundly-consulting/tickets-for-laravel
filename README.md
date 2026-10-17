<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/tickets-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=tickets-for-laravel">
    <img src="https://raw.githubusercontent.com/roundly-consulting/tickets-for-laravel/main/art/hero.png" alt="Tickets for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/tickets-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/tickets-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/tickets-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/tickets-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/tickets-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/tickets-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=tickets-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Tickets for Laravel

A headless helpdesk core for Laravel apps: categories, swappable statuses, public replies and
internal notes, assignment, priorities, due dates, custom fields, guest requesters and optional
projects — behind one facade, with an opt-in JSON API and mail notifications in English and Slovak.

## Installation

Requires PHP 8.4, Laravel 12 or 13, and a MySQL or PostgreSQL database.

```bash
composer require roundly-consulting/tickets-for-laravel
php artisan tickets:install
php artisan migrate
```

`tickets:install` publishes the config and the migrations of tickets and the packages it builds on,
and prints the key types to settle first: with UUID or ULID user keys, set them **before**
migrating. Overdue flags and auto-close run on the scheduler:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('tickets:sweep')->everyFiveMinutes();  // overdue and due-soon flags
Schedule::command('lifecycle:sweep')->everyMinute();     // closes resolved tickets after 7 days
```

## Usage

File, work and resolve a ticket through the facade (`$customer` and `$agent` are your users):

```php
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Tickets\Facades\Tickets;

// Who is an agent is your call — an undefined ability denies.
Gate::define('tickets.agent', fn ($user) => $user->is_support);

// A category, once: its defaults apply to every ticket filed under it.
$hardware = Tickets::categories()->make(['en' => 'Hardware', 'sk' => 'Hardvér'])
    ->defaults(priority: 'high', dueIn: 'P1D')
    ->create();

// The customer opens a ticket — numbered TCK-1, high priority, due in a day.
$ticket = Tickets::make()
    ->subject('Printer on floor 3 is jammed')
    ->description('Paper jam error E-102 since this morning.')
    ->requester($customer)
    ->category($hardware)
    ->create();

// An agent takes it, answers the customer and leaves a note only agents see.
Tickets::for($ticket)->by($agent)->claim()->start();
Tickets::for($ticket)->by($agent)->reply('A technician is on the way.', transition: 'wait');
Tickets::for($ticket)->by($agent)->note('Roller worn out, order a spare.');

// The customer's reply puts it back in the queue; the agent resolves it.
Tickets::for($ticket)->by($customer)->reply('Works again, thanks!');
Tickets::for('TCK-1')->by($agent)->resolve('Roller replaced.');

Tickets::for('TCK-1')->status();   // TicketStatus::Resolved
Tickets::for('TCK-1')->thread();   // the two public replies — never the note
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/tickets-for-laravel](https://roundly-consulting.com/open-source/docs/tickets-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=tickets-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=tickets-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=tickets-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
