<?php

use ActiveRecord\Cache;

class CacheTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    private mixed $previous_adapter = null;
    private array $previous_options = [];
    private bool $saved_previous_state = false;

    public function set_up()
    {
        // Remember the cache state so tear_down() can restore it (#139). Only the
        // first set_up() before a tear_down() saves it: a repeated set_up() must not
        // replace it with the cache this test itself initialized.
        if (!$this->saved_previous_state) {
            $this->previous_adapter = Cache::$adapter;
            $this->previous_options = Cache::$options;
            $this->saved_previous_state = true;
        }

        if (!extension_loaded('memcached')) {
            $this->markTestSkipped('The memcache extension is not available');
            return;
        }

        $memcachedHost =  getenv('PHPAR_MEMCACHED') ?: 'localhost';
        Cache::initialize("memcache://$memcachedHost");
    }

    public function tear_down()
    {
        Cache::flush();
        Cache::$adapter = $this->previous_adapter;
        Cache::$options = $this->previous_options;
        $this->saved_previous_state = false;
    }

    private function cache_get()
    {
        return Cache::get("1337", function () {
            return "abcd";
        });
    }

    public function test_initialize()
    {
        $this->assert_not_null(Cache::$adapter);
    }

    public function test_initialize_with_null()
    {
        Cache::initialize(null);
        $this->assert_null(Cache::$adapter);
    }

    public function test_get_returns_the_value()
    {
        $this->assert_equals("abcd", $this->cache_get());
    }

    public function test_get_writes_to_the_cache()
    {
        $this->cache_get();
        $this->assert_equals("abcd", Cache::$adapter->read("1337"));
    }

    public function test_get_does_not_execute_closure_on_cache_hit()
    {
        $this->cache_get();
        Cache::get("1337", function () {
            throw new Exception("I better not execute!");
        });
        $this->assert_true(true);
    }

    public function test_cache_adapter_returns_false_on_cache_miss()
    {
        $this->assert_same(false, Cache::$adapter->read("some-key"));
    }

    public function test_get_works_without_caching_enabled()
    {
        Cache::$adapter = null;
        $this->assert_equals("abcd", $this->cache_get());
    }

    public function test_cache_expire()
    {
        Cache::$options['expire'] = 1;
        $this->cache_get();
        sleep(2);

        $this->assert_same(false, Cache::$adapter->read("1337"));
    }

    public function test_namespace_is_set_properly()
    {
        Cache::$options['namespace'] = 'myapp';
        $this->cache_get();
        $this->assert_same("abcd", Cache::$adapter->read("myapp::1337"));
    }

    public function test_initialize_without_scheme_throws_cache_exception()
    {
        try {
            Cache::initialize('cache-url-without-scheme');
            $this->fail('Expected ActiveRecord\CacheException to be thrown');
        } catch (ActiveRecord\CacheException $e) {
            $this->assert_true(strpos($e->getMessage(), 'must specify a scheme') !== false);
        }
    }

    public function test_every_shipped_cache_backend_has_a_case(): void
    {
        $shipped = array_map(
            static fn(string $file): string => basename($file, '.php'),
            glob(__DIR__ . '/../lib/cache/*.php')
        );
        $shipped = array_values(array_diff($shipped, ['CacheBackend']));
        $cases = array_column(ActiveRecord\CacheBackend::cases(), 'value');
        sort($shipped);
        sort($cases);

        $this->assert_equals($shipped, $cases);
    }

    public function test_an_unknown_cache_scheme_throws_a_cache_exception(): void
    {
        $this->expectException(ActiveRecord\CacheException::class);
        $this->expectExceptionMessage("Unsupported cache scheme 'foo': use file, memcache or redis");

        Cache::initialize('foo://localhost');
    }
}
