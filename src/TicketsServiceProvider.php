<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Comments\Events\CommentMentioned;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\Tickets\Assignment\CategoryDefaultStrategy;
use RoundlyConsulting\Tickets\Authorization\UnrestrictedAgentScope;
use RoundlyConsulting\Tickets\Commands\DoctorCommand;
use RoundlyConsulting\Tickets\Contracts\AgentScope;
use RoundlyConsulting\Tickets\Contracts\AssignmentStrategy;
use RoundlyConsulting\Tickets\Contracts\ConversationLock;
use RoundlyConsulting\Tickets\Contracts\DueDateResolver;
use RoundlyConsulting\Tickets\Contracts\GuestLinkGenerator;
use RoundlyConsulting\Tickets\Conversation\CommentsConversationLock;
use RoundlyConsulting\Tickets\Due\CategoryDueDateResolver;
use RoundlyConsulting\Tickets\Exceptions\TicketsException;
use RoundlyConsulting\Tickets\Fields\FieldSetResolver;
use RoundlyConsulting\Tickets\Guests\SignedRouteLinkGenerator;
use RoundlyConsulting\Tickets\Listeners\OnCommentMentioned;
use RoundlyConsulting\Tickets\Listeners\OnTicketTransitioned;
use RoundlyConsulting\Tickets\Listeners\OnTicketTransitioning;
use RoundlyConsulting\Tickets\Support\TicketModels;
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
            ->hasRoutes('tickets.php', 'tickets.routes.enabled')
            ->hasCommands([DoctorCommand::class])
            ->contributesToAbout(static fn (): array => [
                'Primary key type' => self::orInvalid(static fn (): string => KeyType::fromConfig('tickets.primary_key_type')->value),
                'Key type' => self::orInvalid(static fn (): string => KeyType::fromConfig('tickets.key_type')->value),
                'Lifecycle' => self::orInvalid(static fn (): string => class_basename(TicketsConfig::lifecycle())),
                'Priority enum' => self::orInvalid(static fn (): string => class_basename(TicketsConfig::priorityEnum())),
                'Reference prefix' => self::orInvalid(static fn (): string => TicketsConfig::referencePrefix()),
                'Activity' => self::orInvalid(static fn (): string => TicketsConfig::activityEnabled() ? 'ON' : 'OFF'),
                'Routes' => self::orInvalid(static fn (): string => TicketsConfig::routesEnabled() ? 'ON' : 'OFF'),
                'Guest access' => self::orInvalid(static fn (): string => TicketsConfig::guestsEnabled() && TicketsConfig::routesEnabled() ? 'ON' : 'OFF'),
                'Auto-close' => self::orInvalid(static fn (): string => TicketsConfig::autoCloseAfter()?->spec() ?? 'OFF'),
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(TicketsManager::class);

        // Per request or job (Octane-safe): the field sets it memoises go stale between them.
        $this->app->scoped(FieldSetResolver::class);

        $this->bindFromConfig(AssignmentStrategy::class, 'tickets.assignment.strategy', CategoryDefaultStrategy::class);
        $this->bindFromConfig(DueDateResolver::class, 'tickets.due.resolver', CategoryDueDateResolver::class);
        // A class default, never null: bindFromConfig binds the default for a blank value.
        $this->bindFromConfig(AgentScope::class, 'tickets.authorization.agent_scope', UnrestrictedAgentScope::class);
        $this->bindFromConfig(GuestLinkGenerator::class, 'tickets.guests.link_generator', SignedRouteLinkGenerator::class);
        $this->app->bind(ConversationLock::class, CommentsConversationLock::class);
    }

    public function boot(): void
    {
        parent::boot();

        // The migrations key their morph and owner columns through the toolkit's
        // `morphKey` / `ownerKey` macros, so they must exist before `migrate` runs.
        // Idempotent — the toolkit guards each macro with `hasMacro()`.
        $this->registerBlueprintMacros();

        // Lifecycle → tickets: each listener filters on the configured ticket model and the
        // `status` lifecycle, so other lifecycle subjects of the host pass straight through.
        $events = $this->app->make(Dispatcher::class);
        $events->listen(LifecycleTransitioning::class, [OnTicketTransitioning::class, 'handle']);
        $events->listen(LifecycleTransitioned::class, [OnTicketTransitioned::class, 'handle']);

        // Comments → tickets: a mention in a ticket comment becomes TicketMentioned, only for
        // someone who may read that comment (an internal note reaches agents only).
        $events->listen(CommentMentioned::class, [OnCommentMentioned::class, 'handle']);

        $this->registerPolicy();
        $this->defineRateLimiters();
    }

    /**
     * The ticket policy, for the configured ticket model. A broken setting registers nothing
     * rather than taking every artisan command down at boot — Laravel's own policy discovery
     * still finds the shipped policy for the shipped model — and `tickets:doctor` names it.
     */
    private function registerPolicy(): void
    {
        try {
            if (TicketsConfig::registerPolicy()) {
                $this->app->make(Gate::class)->policy(TicketModels::ticket(), TicketsConfig::policy());
            }
        } catch (InvalidConfigurationException|TicketsException) {
            return;
        }
    }

    /**
     * `tickets-api`: per minute per signed-in user (else per IP). `tickets-guest`: per minute per
     * IP and ticket. Defined only with the routes on — the route middleware names them.
     */
    private function defineRateLimiters(): void
    {
        if (! TicketsConfig::routesEnabled()) {
            return;
        }

        $limiter = $this->app->make(RateLimiter::class);

        $limiter->for('tickets-api', static function (Request $request): Limit {
            $user = $request->user();
            $key = $user instanceof Model ? $user->getMorphClass().':'.(string) $user->getKey() : (string) $request->ip();

            return Limit::perMinute(TicketsConfig::apiRateLimit())->by('tickets-api|'.$key);
        });

        $limiter->for('tickets-guest', static function (Request $request): Limit {
            $ticket = $request->route('ticket');

            return Limit::perMinute(TicketsConfig::guestRateLimit())->by('tickets-guest|'.$request->ip().'|'.(is_string($ticket) ? $ticket : ''));
        });
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
