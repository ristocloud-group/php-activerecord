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
    if ($this->cache)
      $this->cache->flush();
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
    $value = array("a" => 1, "b" => array(2, 3));
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
    $namespaced = new Redis(parse_url($this->url), array('namespace' => 'phpar_ns'));

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
    $first  = Cache::get("facade-key", function() use (&$runs) { $runs++; return "v"; });
    $second = Cache::get("facade-key", function() use (&$runs) { $runs++; return "v"; });

    $this->assert_equals("v", $first);
    $this->assert_equals("v", $second);
    $this->assert_equals(1, $runs); // second call is a cache hit

    Cache::flush();
    Cache::initialize(null);
  }
}
