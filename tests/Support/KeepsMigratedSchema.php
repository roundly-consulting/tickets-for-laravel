<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RoundlyConsulting\Testing\PackageTestCase;
use Throwable;

/**
 * Migrate once per worker and schema, then reset only the data between tests.
 *
 * `PackageTestCase` migrates every source before each test and drops every table after it: ~35
 * tables from 9 sources, which was ~90% of the MySQL leg and ~60% of the SQLite one. Here a
 * worker migrates once per **signature** — the connection, the migration sources, the providers
 * and the whole config, i.e. everything the migrations can read — and between tests empties the
 * tables and rewinds the key counters.
 *
 * What stays exactly as before:
 *
 *  - no wrapping transaction: a test's `transactionDepth` is its own, and the forked racers see
 *    the parent's committed rows across real connections;
 *  - every test starts on empty tables with every counter at 1 — what a fresh migrate gives;
 *  - a test that changes the schema (drops a table, adds a column, runs or deletes a migration,
 *    leaves SQLite's foreign keys off) is caught by the catalogue check at teardown, and the next
 *    test migrates from scratch;
 *  - a different signature (the uuid/ulid key legs, the swapped models, the HTTP switch) starts
 *    from an empty database and migrates again.
 *
 * @phpstan-require-extends PackageTestCase
 */
trait KeepsMigratedSchema
{
    private static ?MigratedSchema $keptSchema = null;

    private static bool $discardsKeptSchemaOnShutdown = false;

    private bool $keepsSchema = false;

    protected function defineDatabaseMigrations(): void
    {
        /** @var Connection $connection */
        $connection = $this->databaseManager()->connection();

        if (! MigratedSchema::supports($connection)) {
            parent::defineDatabaseMigrations();

            return;
        }

        $signature = $this->schemaSignature($connection);

        if (self::$keptSchema?->signature === $signature) {
            self::$keptSchema->attach($connection);
        } else {
            if (self::$keptSchema !== null) {
                self::$keptSchema = null;
                MigratedSchema::discard();
            }

            // A previous run's leftovers (or another suite's) never meet a fresh migrate. A new
            // in-memory database is empty by definition.
            if ($connection->getDriverName() !== 'sqlite') {
                $connection->getSchemaBuilder()->dropAllTables();
            }

            parent::defineDatabaseMigrations();

            self::$keptSchema = MigratedSchema::capture($connection, $signature);

            if (! self::$discardsKeptSchemaOnShutdown) {
                self::$discardsKeptSchemaOnShutdown = true;
                MigratedSchema::discardOnShutdown();
            }
        }

        $this->keepsSchema = true;
    }

    protected function tearDownInteractsWithMigrations(): void
    {
        if (! $this->keepsSchema || self::$keptSchema === null) {
            parent::tearDownInteractsWithMigrations();

            return;
        }

        // Nothing to roll back — the schema stays, and no migration ships a down() anyway.
        $this->cachedTestMigratorProcessors = [];

        // Close the test's sessions first: an open transaction rolls back, its locks go.
        $manager = $this->databaseManager();

        foreach (array_keys($manager->getConnections()) as $name) {
            $manager->purge((string) $name);
        }

        try {
            if (! self::$keptSchema->reset()) {
                self::$keptSchema = null;
                MigratedSchema::discard();
            }
        } catch (Throwable $exception) {
            // Never hand the next test a half-reset schema: it migrates from scratch instead.
            self::$keptSchema = null;
            MigratedSchema::discard();

            throw $exception;
        }
    }

    private function schemaSignature(Connection $connection): string
    {
        return md5(serialize([
            $connection->getConfig(),
            array_map(static fn (string $source): string => self::migrationDirectoryFor($source), $this->migrationSources()),
            array_keys($this->app?->getLoadedProviders() ?? []),
            self::normalised($this->app?->make('config')->all() ?? []),
        ]));
    }

    private static function normalised(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::normalised(...), $value);
        }

        return is_object($value) ? 'object:'.$value::class : $value;
    }

    private function databaseManager(): DatabaseManager
    {
        /** @var DatabaseManager */
        return $this->app?->make('db');
    }
}
