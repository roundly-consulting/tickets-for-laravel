<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Approvals\ApprovalsServiceProvider;
use RoundlyConsulting\Attributes\AttributesServiceProvider;
use RoundlyConsulting\Comments\CommentsServiceProvider;
use RoundlyConsulting\Crypto\CryptoServiceProvider;
use RoundlyConsulting\Lifecycle\LifecycleServiceProvider;
use RoundlyConsulting\Likes\LikesServiceProvider;
use RoundlyConsulting\MediaLibrary\MediaLibraryServiceProvider;
use RoundlyConsulting\QueryBuilder\QueryBuilderServiceProvider;
use RoundlyConsulting\Reports\ReportsServiceProvider;
use RoundlyConsulting\Sluggable\SluggableServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\TicketsServiceProvider;
use RoundlyConsulting\Translatable\TranslatableServiceProvider;

abstract class TestCase extends PackageTestCase
{
    /**
     * Every provider tickets hard-requires (and what they require in turn), in registration
     * order. A host auto-discovers these; the suite lists them so its environment is real —
     * tickets' migrations need the toolkit's and sluggable's schema macros.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [
            CryptoServiceProvider::class,
            ApprovalsServiceProvider::class,
            LikesServiceProvider::class,
            MediaLibraryServiceProvider::class,
            ReportsServiceProvider::class,
            CommentsServiceProvider::class,
            LifecycleServiceProvider::class,
            AttributesServiceProvider::class,
            SluggableServiceProvider::class,
            TranslatableServiceProvider::class,
            QueryBuilderServiceProvider::class,
            TicketsServiceProvider::class,
        ];
    }

    /**
     * Every package's migrations are publish-only, so the suite runs them the way a host
     * does after publishing — named by provider class, never by file.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [
            MediaLibraryServiceProvider::class,
            LikesServiceProvider::class,
            ReportsServiceProvider::class,
            ApprovalsServiceProvider::class,
            CommentsServiceProvider::class,
            LifecycleServiceProvider::class,
            AttributesServiceProvider::class,
            TicketsServiceProvider::class,
            __DIR__.'/Fixtures/migrations',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [
            'media.disk' => 'local',
            'media.image_driver' => 'gd',
            'tickets.requesters' => ['user' => User::class],
            'tickets.assignables' => ['user' => User::class, 'team' => Team::class],
            'tickets.projects' => ['project' => Project::class],
        ];
    }
}
