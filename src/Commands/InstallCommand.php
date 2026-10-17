<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Tickets\Concerns\IsTicketAssignee;
use RoundlyConsulting\Tickets\Contracts\Assignee;
use RoundlyConsulting\Tickets\Support\Doctor;
use RoundlyConsulting\Tickets\Support\KeyAlignment;
use RoundlyConsulting\Tickets\Support\Messages;

/**
 * Guided installation: publishes the config, the tickets migrations and the migrations of every
 * package tickets builds on (lifecycle, comments and its likes / reports / approvals, media,
 * attributes), shows the key types to settle before migrating, and prints the remaining steps —
 * the user model as an assignee, the abilities, the two scheduler lines, the doctor (and, while
 * guests would be mailed without a link, the three ways to settle that — the doctor's one error
 * on a default install). Each package's migrations keep their own order; no package's tables
 * reference another's, so the order between packages is free.
 * Running it again is harmless: published files are kept unless `--force`, and a republished
 * migration lands on the file it landed on before.
 *
 * The API's route file (`tickets-routes`) is not published: a published copy replaces the
 * package's routes for good, so it is a deliberate step of its own.
 */
final class InstallCommand extends Command
{
    /**
     * The publish tags: the config, then the migrations of the providers tickets requires, then
     * its own.
     *
     * @var list<string>
     */
    public const array TAGS = [
        'tickets-config',
        'media-migrations',
        'likes-migrations',
        'reports-migrations',
        'approvals-migrations',
        'comments-migrations',
        'lifecycle-migrations',
        'attributes-migrations',
        'tickets-migrations',
    ];

    /**
     * What each key-type setting keys.
     *
     * @var array<string, string>
     */
    private const array KEY_LABELS = [
        'tickets.primary_key_type' => 'install.key_primary',
        'tickets.key_type' => 'install.key_outbound',
        'lifecycle.key_type' => 'install.key_lifecycle',
        'lifecycle.actor_key_type' => 'install.key_lifecycle_actor',
        'comments.key_type' => 'install.key_comments',
        'attributes.key_type' => 'install.key_attributes',
    ];

    protected $signature = 'tickets:install {--force : Overwrite published files}';

    protected $description = 'Install tickets: publish the config and migrations, print the next steps';

    public function handle(): int
    {
        $force = $this->option('force') === true;

        foreach (self::TAGS as $tag) {
            $this->callSilently('vendor:publish', ['--tag' => $tag, '--force' => $force]);
            $this->components->task(Messages::get('install.published', ['tag' => $tag]));
        }

        $this->newLine();

        foreach (KeyAlignment::settings() as $key => $type) {
            $this->components->twoColumnDetail($key.' — '.Messages::get(self::KEY_LABELS[$key]), $type === null ? 'INVALID' : $type->value);
        }

        $this->components->warn(Messages::get('install.key_types'));

        $this->newLine();
        $this->components->info(Messages::get('install.next'));
        $this->line('  1. php artisan migrate');
        $this->line('  2. '.Messages::get('install.assignee'));
        $this->line('     use '.Assignee::class.';');
        $this->line('     use '.IsTicketAssignee::class.';');
        $this->line('     class User extends Authenticatable implements Assignee { use IsTicketAssignee; }');
        $this->line('  3. '.Messages::get('install.abilities'));
        $this->line("     Gate::define('tickets.agent', fn (\$user) => \$user->is_support);");
        $this->line('     '.Messages::get('install.agent_flag'));
        $this->line('  4. '.Messages::get('install.schedule'));
        $this->line("     Schedule::command('tickets:sweep')->everyFiveMinutes();");
        $this->line("     Schedule::command('lifecycle:sweep')->everyMinute();");
        $this->line('  5. php artisan tickets:doctor');

        if (Doctor::guestMailWithoutLinks()) {
            $this->line('     '.Messages::get('install.guests'));
        }

        $this->newLine();
        $this->line('  '.Messages::get('install.routes'));

        return self::SUCCESS;
    }
}
