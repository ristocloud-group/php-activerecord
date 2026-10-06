<?php

require_once __DIR__ . '/CacheTest.php';

use ActiveRecord\Cache;

/**
 * Guards that CacheTest restores the cache state it changes (#139).
 * Self-contained: independent of suite order, filename order and --filter.
 */
class CacheTestIsolationTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    private mixed $saved_adapter = null;
    private array $saved_options = [];

    public function set_up()
    {
        $this->saved_adapter = Cache::$adapter;
        $this->saved_options = Cache::$options;
    }

    public function tear_down()
    {
        Cache::$adapter = $this->saved_adapter;
        Cache::$options = $this->saved_options;
    }

    public function test_cache_test_restores_the_previous_cache_state()
    {
        $sentinel = new class {
            public function flush(): void {}
        };
        $sentinel_options = ['expire' => 7, 'namespace' => 'sentinel'];
        Cache::$adapter = $sentinel;
        Cache::$options = $sentinel_options;

        $cache_test = new CacheTest('test_initialize');
        $cache_test->set_up();
        $this->assert_not_same($sentinel, Cache::$adapter, 'CacheTest::set_up() should initialize its own cache');
        $cache_test->tear_down();

        $this->assert_same($sentinel, Cache::$adapter);
        $this->assert_same($sentinel_options, Cache::$options);
    }

    public function test_cache_test_restores_a_disabled_cache()
    {
        Cache::$adapter = null;
        Cache::$options = [];

        $cache_test = new CacheTest('test_initialize');
        $cache_test->set_up();
        $cache_test->tear_down();

        $this->assert_null(Cache::$adapter);
        $this->assert_same([], Cache::$options);
    }

    public function test_cache_test_restores_the_state_when_the_cache_is_initialized_again_in_a_test()
    {
        Cache::$adapter = null;
        Cache::$options = [];

        $cache_test = new CacheTest('test_initialize');
        $cache_test->set_up();
        // a test body that initializes the cache again (another URL, or null)
        Cache::initialize('memcache://' . (getenv('PHPAR_MEMCACHED') ?: 'localhost'));
        Cache::initialize(null);
        Cache::initialize('memcache://' . (getenv('PHPAR_MEMCACHED') ?: 'localhost'));
        $cache_test->tear_down();

        $this->assert_null(Cache::$adapter);
        $this->assert_same([], Cache::$options);
    }

    public function test_cache_test_restores_the_original_state_when_set_up_runs_twice()
    {
        Cache::$adapter = null;
        Cache::$options = [];

        $cache_test = new CacheTest('test_initialize');
        $cache_test->set_up();
        $cache_test->set_up(); // initialized twice before tear_down()
        $this->assert_not_null(Cache::$adapter, 'CacheTest::set_up() should initialize its own cache');
        $cache_test->tear_down();

        $this->assert_null(Cache::$adapter);
        $this->assert_same([], Cache::$options);
    }
}
