<?php
use ActiveRecord\Cache;
use ActiveRecord\Config;

/**
 * End-to-end coverage of the schema-metadata cache through a real Model, for
 * the File and Redis backends. ActiveRecordCacheTest already exercises the
 * memcache backend, so this closes the Cache -> adapter -> Table integration
 * gap for the other two bundled adapters.
 */
class CacheSchemaIntegrationTest extends DatabaseTest
{
    private string $file_cache_dir;

    public function set_up($connection_name=null)
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
        return "get_meta_data-$table_name";
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
