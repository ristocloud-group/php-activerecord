<?php

require_once __DIR__ . "/../lib/cache/Redis.php";

use ActiveRecord\Cache;
use ActiveRecord\Redis;

class RedisCacheTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    private $url;
    private $cache;

    public function set_up()
    {
        $this->url = getenv('PHPAR_REDIS') ?: 'redis://localhost:6379';
        $this->cache = new Redis(parse_url($this->url));
        $this->cache->flush();
    }

    public function tear_down()
    {
        // Tolerant of connection errors: a leak here must never mask (or cause)
        // a failure in a sibling test.
        try {
            if ($this->cache) {
                $this->cache->flush();
            }
        } catch (\Throwable $e) {
        }

        try {
            $db3 = new Redis(parse_url($this->url . '/3'));
            $db3->flush();
        } catch (\Throwable $e) {
        }

        try {
            Cache::initialize(null);
        } catch (\Throwable $e) {
        }
    }

    public function test_reads_own_writes()
    {
        $this->cache->write("foo", "bar");
        $this->assert_equals("bar", $this->cache->read("foo"));
    }

    public function test_read_returns_null_on_miss()
    {
        $this->assert_null($this->cache->read("does-not-exist"));
    }

    public function test_can_store_complex_objects()
    {
        $value = ["a" => 1, "b" => [2, 3]];
        $this->cache->write("foo", $value);
        $this->assert_equals($value, $this->cache->read("foo"));
    }

    public function test_honors_expire()
    {
        $this->cache->write("foo", "bar", 1);
        sleep(2);
        $this->assert_null($this->cache->read("foo"));
    }

    public function test_zero_expire_never_expires()
    {
        $this->cache->write("foo", "bar", 0);
        $this->assert_equals("bar", $this->cache->read("foo"));
    }

    public function test_flush_with_namespace_only_clears_namespaced_keys()
    {
        $namespaced = new Redis(parse_url($this->url), ['namespace' => 'phpar_ns']);

        $this->cache->write("outside", "keep");
        $namespaced->write("phpar_ns::inside", "drop");

        $namespaced->flush();

        $this->assert_null($namespaced->read("phpar_ns::inside"));
        $this->assert_equals("keep", $this->cache->read("outside"));
    }

    public function test_parses_database_index_and_query_params_from_dsn()
    {
        // Append a database index and a passthrough Predis connection parameter.
        $client = new Redis(parse_url($this->url . '/3?read_write_timeout=2'));
        $client->write("dbcheck", "ok");
        $this->assert_equals("ok", $client->read("dbcheck"));
        $client->flush();
    }

    public function test_integrates_with_cache_facade()
    {
        Cache::initialize($this->url);

        $runs = 0;
        $first  = Cache::get("facade-key", function () use (&$runs) {
            $runs++;
            return "v";
        });
        $second = Cache::get("facade-key", function () use (&$runs) {
            $runs++;
            return "v";
        });

        $this->assert_equals("v", $first);
        $this->assert_equals("v", $second);
        $this->assert_equals(1, $runs); // second call is a cache hit

        Cache::flush();
        Cache::initialize(null);
    }

    public function test_normalizes_redis_scheme_to_tcp()
    {
        // A bare redis:// DSN (no scheme override) must reach Predis as 'tcp'.
        $params = $this->predis_parameters(new Redis(parse_url("redis://redis.example.com:6379/0")));

        $this->assert_equals('tcp', $params->scheme);
    }

    public function test_all_dsn_connection_options_pass_through_to_predis()
    {
        // Every option Predis accepts as a connection parameter is reachable from
        // the DSN: credentials/host/port/database from the URL structure, and any
        // other parameter from the query string (merged verbatim). Predis connects
        // lazily, so this never touches a server — we assert the mapping only.
        $query = http_build_query([
            'timeout'            => '3.5',
            'read_write_timeout' => '2',
            'persistent'         => '1',
            'tcp_nodelay'        => '0',
            'async_connect'      => '1',
            'alias'              => 'primary',
            'weight'             => '10',
            'scheme'             => 'tls', // query overrides the redis->tcp normalization
        ]);

        $params = $this->predis_parameters(new Redis(parse_url("redis://alice:s3cr3t@redis.example.com:6380/7?$query")));

        // From the URL structure.
        $this->assert_same('redis.example.com', $params->host);
        $this->assert_same(6380, $params->port);
        $this->assert_same(7, $params->database);       // path -> int database index
        $this->assert_same('alice', $params->username); // userinfo -> ACL username
        $this->assert_same('s3cr3t', $params->password);

        // From the query string (any Predis connection parameter).
        $this->assert_equals('tls', $params->scheme);   // overrides the default tcp
        $this->assert_equals('3.5', $params->timeout);
        $this->assert_equals('2', $params->read_write_timeout);
        $this->assert_equals('1', $params->persistent);
        $this->assert_equals('0', $params->tcp_nodelay);
        $this->assert_equals('1', $params->async_connect);
        $this->assert_equals('primary', $params->alias);
        $this->assert_equals('10', $params->weight);
    }

    private function predis_parameters(Redis $adapter)
    {
        // Reflection reads private properties without setAccessible() since PHP 8.1
        // (calling it is a deprecated no-op on 8.5).
        $client = (new ReflectionProperty(Redis::class, 'client'))->getValue($adapter);

        return $client->getConnection()->getParameters();
    }
}
