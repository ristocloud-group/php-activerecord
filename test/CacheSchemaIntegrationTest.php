<?php

use ActiveRecord\Cache;
use ActiveRecord\Config;
use ActiveRecord\Connection;
use ActiveRecord\ConnectionManager;

/**
 * Same table name as Author, on a separate SQLite database with a different
 * schema — two models whose schema-cache entries must never collide (#45).
 */
class OtherDatabaseAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $connection = 'schema_cache_other';
}

/**
 * End-to-end coverage of the schema-metadata cache through a real Model, for
 * the File and Redis backends. ActiveRecordCacheTest already exercises the
 * memcache backend, so this closes the Cache -> adapter -> Table integration
 * gap for the other two bundled adapters.
 */
class CacheSchemaIntegrationTest extends DatabaseTest
{
    private string $file_cache_dir;

    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name);
        $this->file_cache_dir = sys_get_temp_dir() . "/phpar-schema-cache-int";
    }

    public function tear_down()
    {
        Cache::flush();
        Cache::initialize(null);
    }

    private function meta_data_cache_key(): string
    {
        $table_name = Author::table()->get_fully_qualified_table_name(!($this->conn instanceof ActiveRecord\PgsqlAdapter));
        return 'get_meta_data-' . Author::connection()->cache_identity() . "-$table_name";
    }

    /**
     * Registers a throwaway SQLite database as an extra connection, runs $test
     * against it, then restores the connection config.
     */
    private function with_other_database(string $schema, Closure $test): void
    {
        $config = Config::instance();
        $connections = $config->get_connections();
        $path = tempnam(sys_get_temp_dir(), 'phpar-schema-cache-other-');

        try {
            $config->set_connections([...$connections, 'schema_cache_other' => "sqlite://unix($path)"]);
            ConnectionManager::get_connection('schema_cache_other')->query($schema);
            $test();
        } finally {
            ConnectionManager::drop_connection('schema_cache_other');
            ActiveRecord\Table::clear_cache(OtherDatabaseAuthor::class);
            $config->set_connections($connections);
            unlink($path);
        }
    }

    public function test_schema_cache_is_isolated_per_connection()
    {
        Config::instance()->set_cache("file://" . $this->file_cache_dir);
        Cache::flush();

        $this->with_other_database('CREATE TABLE authors (author_id INTEGER PRIMARY KEY, other_only TEXT)', function () {
            // The default connection introspects `authors` first and fills the cache ...
            $this->assert_true(array_key_exists('name', Author::table()->columns));

            // ... which must not leak into a same-named table on another database.
            $columns = array_keys(OtherDatabaseAuthor::table()->columns);
            $this->assert_equals(['author_id', 'other_only'], $columns);
        });
    }

    public function test_cache_identity_is_stable_for_the_same_database()
    {
        $this->assert_equals(
            Connection::instance(Config::instance()->get_default_connection_string())->cache_identity(),
            $this->conn->cache_identity(),
        );
    }

    public function test_cache_identity_differs_per_database()
    {
        $this->with_other_database('CREATE TABLE t (id INTEGER PRIMARY KEY)', function () {
            $this->assert_not_equals(
                $this->conn->cache_identity(),
                ConnectionManager::get_connection('schema_cache_other')->cache_identity(),
            );
        });
    }

    private function assert_backend_caches_schema_metadata(): void
    {
        // Loading a model introspects the schema and writes it to the cache.
        Author::first();

        $value = Cache::$adapter->read($this->meta_data_cache_key());
        $this->assert_true(is_array($value));
        $this->assert_true(count($value) > 0);
    }

    public function test_file_backend_caches_schema_metadata()
    {
        Config::instance()->set_cache("file://" . $this->file_cache_dir);
        Cache::flush();

        $this->assert_backend_caches_schema_metadata();
    }

    public function test_redis_backend_caches_schema_metadata()
    {
        $redis = getenv('PHPAR_REDIS') ?: 'redis://localhost:6379';
        Config::instance()->set_cache($redis);
        Cache::flush();

        $this->assert_backend_caches_schema_metadata();
    }

    public function test_file_backend_reuses_cached_metadata_without_reintrospecting()
    {
        Config::instance()->set_cache("file://" . $this->file_cache_dir);
        Cache::flush();

        // First load populates the cache.
        Author::first();
        $key = $this->meta_data_cache_key();
        $cached = Cache::$adapter->read($key);

        // Drop the in-memory Table cache: the next load must come from the
        // external file cache, not a fresh introspection.
        ActiveRecord\Table::clear_cache();
        Author::first();

        $this->assert_equals($cached, Cache::$adapter->read($key));
    }
}
