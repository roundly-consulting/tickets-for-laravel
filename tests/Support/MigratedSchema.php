<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Throwable;

/**
 * A schema one worker migrated once and keeps between tests: what it was built from (the
 * signature), what it looks like (the catalogue), and the session that empties it again.
 *
 * The session is the worker's own, outside the test's app: on a real engine a second connection
 * to the worker database, kept warm across tests; on in-memory SQLite the PDO that *is* the
 * database, handed to each test's connection.
 */
final class MigratedSchema
{
    private static ?Connection $session = null;

    private static ?string $sessionKey = null;

    /**
     * @param  list<string>  $tables  every table but `migrations`
     */
    private function __construct(
        public readonly string $signature,
        private readonly array $tables,
        private readonly string $catalogue,
    ) {}

    public static function supports(Connection $connection): bool
    {
        return match ($connection->getDriverName()) {
            'mysql', 'mariadb', 'pgsql' => true,
            'sqlite' => $connection->getConfig('database') === ':memory:',
            default => false,
        };
    }

    /**
     * Record the schema the migrations just built on the test's connection.
     */
    public static function capture(Connection $connection, string $signature): self
    {
        $session = self::sessionFor($connection);
        $schema = $session->getSchemaBuilder();
        $tables = [];

        // The connection's own schema only: on MySQL a bare listing spans every database on the
        // server — the other workers' and the probes' included.
        foreach ($schema->getTables($schema->getCurrentSchemaName()) as $table) {
            if ($table['name'] !== 'migrations') {
                $tables[] = (string) $table['name'];
            }
        }

        sort($tables);

        return new self($signature, $tables, self::catalogue($session));
    }

    /**
     * In-memory SQLite: point the test's connection at the kept database. A real engine needs
     * nothing — the test's connection opens the same worker database by name.
     */
    public function attach(Connection $connection): void
    {
        if ($connection->getDriverName() === 'sqlite' && self::$session !== null) {
            $pdo = self::$session->getPdo();
            $connection->setPdo($pdo)->setReadPdo($pdo);
        }
    }

    /**
     * Empty every table and rewind every key counter, so the next test starts where a fresh
     * migrate would leave it. False — nothing reset — when the test changed the schema itself
     * (a dropped table, an extra column, a migrations row, foreign keys switched off): the
     * caller then {@see self::discard()}s it and the next test migrates from scratch.
     */
    public function reset(): bool
    {
        $session = self::$session;

        if ($session === null) {
            return false;
        }

        // SQLite shares the PDO with the test: a transaction the test left open is still open.
        if ($session instanceof SQLiteConnection && $session->getPdo()->inTransaction()) {
            $session->getPdo()->rollBack();
        }

        try {
            if (self::catalogue($session) !== $this->catalogue) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        match ($session->getDriverName()) {
            'pgsql' => $this->resetPostgres($session),
            'sqlite' => $this->resetSqlite($session),
            default => $this->resetMysql($session),
        };

        return true;
    }

    /**
     * Throw the kept schema away: a real engine's tables are dropped, an in-memory database is
     * let go of. The next test migrates into an empty database.
     */
    public static function discard(): void
    {
        $session = self::$session;

        try {
            if ($session !== null && ! $session instanceof SQLiteConnection) {
                $session->getSchemaBuilder()->dropAllTables();
            }
        } finally {
            $session?->disconnect();
            self::$session = null;
            self::$sessionKey = null;
        }
    }

    /**
     * Drop a real engine's kept schema once the worker is done, so the next run (or another
     * package's suite on the same local database) starts from the empty database the
     * drop-per-test reset always left behind.
     */
    public static function discardOnShutdown(): void
    {
        $pid = getmypid();

        register_shutdown_function(static function () use ($pid): void {
            // A forked racer inherits the handler; only the worker that owns the schema drops it.
            if (getmypid() !== $pid) {
                return;
            }

            try {
                self::discard();
            } catch (Throwable) {
                // Best effort: the next run drops leftovers before it migrates anyway.
            }
        });
    }

    private static function sessionFor(Connection $connection): Connection
    {
        if ($connection->getDriverName() === 'sqlite') {
            // The PDO the migrations just ran on is the database; a new capture is a new one.
            self::$session = new SQLiteConnection($connection->getPdo(), ':memory:', $connection->getTablePrefix(), $connection->getConfig());
            self::$sessionKey = null;

            return self::$session;
        }

        $config = $connection->getConfig();
        $key = md5(serialize($config));

        if (self::$session === null || self::$sessionKey !== $key) {
            self::$session?->disconnect();
            self::$session = (new ConnectionFactory(new Container))->make($config, 'kept_schema');
            self::$sessionKey = $key;

            // InnoDB keeps an advanced AUTO_INCREMENT on an emptied table (a rolled-back insert,
            // a delete), and MySQL caches the information_schema counters unless asked not to.
            if (self::$session instanceof MySqlConnection && ! self::$session->isMaria()) {
                self::$session->statement('set session information_schema_stats_expiry = 0');
            }
        }

        return self::$session;
    }

    private function resetMysql(Connection $session): void
    {
        $dirty = $this->tablesWithRows($session);

        $advanced = array_values(array_intersect($this->tables, array_map(
            static fn (object $row): string => (string) $row->name,
            $session->select('select table_name as name from information_schema.tables where table_schema = database() and auto_increment > 1'),
        )));

        if ($dirty === [] && $advanced === []) {
            return;
        }

        $grammar = $session->getQueryGrammar();

        // DELETE plus a counter rewind costs half a TRUNCATE (which is DDL: drop and recreate).
        $session->statement('set foreign_key_checks = 0');

        try {
            foreach ($dirty as $table) {
                $session->delete('delete from '.$grammar->wrapTable($table));
            }

            foreach ($advanced as $table) {
                $session->statement('alter table '.$grammar->wrapTable($table).' auto_increment = 1');
            }
        } finally {
            $session->statement('set foreign_key_checks = 1');
        }
    }

    private function resetPostgres(Connection $session): void
    {
        $dirty = $this->tablesWithRows($session);

        if ($dirty !== []) {
            $grammar = $session->getQueryGrammar();

            $session->statement('truncate table '.implode(', ', array_map(
                static fn (string $table): string => $grammar->wrapTable($table),
                $dirty,
            )).' cascade');
        }

        // Every sequence a test drew from — one whose rows were rolled back included — back to 1.
        $session->select(
            "select setval(format('%I.%I', schemaname, sequencename)::regclass, 1, false) from pg_sequences "
            .'where schemaname = current_schema() and last_value is not null',
        );
    }

    private function resetSqlite(Connection $session): void
    {
        $dirty = $this->tablesWithRows($session);

        if ($dirty !== []) {
            $grammar = $session->getQueryGrammar();

            $session->statement('pragma foreign_keys = off');

            try {
                foreach ($dirty as $table) {
                    $session->delete('delete from '.$grammar->wrapTable($table));
                }
            } finally {
                $session->statement('pragma foreign_keys = on');
            }
        }

        // AUTOINCREMENT counters live in sqlite_sequence, created with the first such table.
        if ((int) $session->scalar("select count(*) from sqlite_master where name = 'sqlite_sequence'") > 0) {
            $session->delete('delete from sqlite_sequence');
        }
    }

    /**
     * @return list<string>
     */
    private function tablesWithRows(Connection $session): array
    {
        if ($this->tables === []) {
            return [];
        }

        $grammar = $session->getQueryGrammar();
        $from = in_array($session->getDriverName(), ['mysql', 'mariadb'], true) ? ' from dual' : '';

        // One round trip for every table; the names come from the catalogue, never from a test.
        $sql = implode(' union all ', array_map(
            static fn (string $table): string => 'select '.$grammar->quoteString($table).' as name'.$from
                .' where exists (select 1 from '.$grammar->wrapTable($table).')',
            $this->tables,
        ));

        return array_map(static fn (object $row): string => (string) $row->name, $session->select($sql));
    }

    /**
     * Everything a migration can change — columns, indexes, constraints — plus the migrations
     * table itself, as one string. Equal catalogues mean the next test gets the schema a fresh
     * migrate would build.
     */
    private static function catalogue(Connection $session): string
    {
        $rows = match ($session->getDriverName()) {
            'pgsql' => $session->select(<<<'SQL'
                select 'c|' || c.relname || '|' || a.attnum || '|' || a.attname || '|' || format_type(a.atttypid, a.atttypmod)
                    || '|' || a.attnotnull || '|' || coalesce(pg_get_expr(d.adbin, d.adrelid), '~') as entry
                from pg_attribute a
                join pg_class c on c.oid = a.attrelid
                join pg_namespace n on n.oid = c.relnamespace
                left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
                where n.nspname = current_schema() and c.relkind in ('r', 'p') and a.attnum > 0 and not a.attisdropped
                union all
                select 'i|' || pg_get_indexdef(i.indexrelid)
                from pg_index i
                join pg_class c on c.oid = i.indrelid
                join pg_namespace n on n.oid = c.relnamespace
                where n.nspname = current_schema()
                union all
                select 'k|' || con.conrelid::regclass::text || '|' || con.conname || '|' || pg_get_constraintdef(con.oid)
                from pg_constraint con
                join pg_namespace n on n.oid = con.connamespace
                where n.nspname = current_schema()
                SQL),
            // The DDL text of every table and index, and the foreign-key switch a test can leave off.
            'sqlite' => $session->select(<<<'SQL'
                select type || '|' || name || '|' || tbl_name || '|' || coalesce(sql, '~') as entry from sqlite_master
                union all
                select 'f|' || foreign_keys from pragma_foreign_keys
                SQL),
            default => $session->select(<<<'SQL'
                select concat_ws('|', 'c', table_name, ordinal_position, column_name, column_type, is_nullable,
                    coalesce(column_default, '~'), extra, coalesce(collation_name, '~')) as entry
                from information_schema.columns where table_schema = database()
                union all
                select concat_ws('|', 'i', table_name, index_name, seq_in_index, column_name, non_unique, coalesce(sub_part, '~'))
                from information_schema.statistics where table_schema = database()
                union all
                select concat_ws('|', 'k', table_name, constraint_name, column_name,
                    coalesce(referenced_table_name, '~'), coalesce(referenced_column_name, '~'))
                from information_schema.key_column_usage where table_schema = database()
                union all
                select concat_ws('|', 't', table_name, constraint_name, constraint_type)
                from information_schema.table_constraints where table_schema = database()
                SQL),
        };

        $entries = array_map(static fn (object $row): string => (string) $row->entry, $rows);

        foreach ($session->table('migrations')->orderBy('id')->get(['id', 'migration', 'batch']) as $migration) {
            $entries[] = 'm|'.$migration->id.'|'.$migration->migration.'|'.$migration->batch;
        }

        sort($entries);

        return md5(implode("\n", $entries));
    }
}
