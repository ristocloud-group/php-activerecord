<?php
require_once __DIR__ . "/../lib/cache/File.php";

use ActiveRecord\File;

class FileCacheTest extends SnakeCase_PHPUnit_Framework_TestCase
{
  private $cache, $cache_dir;

  public function set_up() {
    $this->cache_dir = sys_get_temp_dir() . "/phpar-file-cache-test";
    $this->cache = new File(["path" => $this->cache_dir]);
    $this->cache->flush();
  }

  public function test_flushing_clears_all_values()
  {
    $this->cache->write("foo", "bar");
    $this->cache->write("bar", "baz");

    $this->cache->flush();

    $this->assert_null($this->cache->read("foo"));
    $this->assert_null($this->cache->read("bar"));
  }

  public function test_reads_own_writes()
  {
    $this->cache->write("foo", "bar");

    $this->assert_equals("bar", $this->cache->read("foo"));
  }

  public function test_can_store_complex_objects()
  {
    $this->cache->write("foo", new Value("bar"));

    $this->assert_equals(new Value("bar"), $this->cache->read("foo"));
  }

  public function test_creates_cache_directory_if_necessary()
  {
    rmdir($this->cache_dir);
    $this->cache->write("foo", "bar");

    $this->assert_equals("bar", $this->cache->read("foo"));
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

  public function test_treats_legacy_raw_payload_as_miss()
  {
    if (!is_dir($this->cache_dir)) mkdir($this->cache_dir);
    // A file written by a previous version: a bare serialized value, not an envelope.
    file_put_contents($this->cache_dir . "/legacy", serialize("bare-value"));

    $this->assert_null($this->cache->read("legacy"));
  }

  public function test_reading_expired_entry_twice_does_not_warn()
  {
    $this->cache->write("foo", "bar", 1);
    sleep(2);
    $this->cache->read("foo");                 // lazy-GC deletes the file
    $this->assert_null($this->cache->read("foo")); // file already gone: must not warn
  }

  public function test_write_leaves_no_temp_files()
  {
    $this->cache->write("foo", "bar");

    $files = array_map('basename', glob($this->cache_dir . "/*"));
    $this->assert_equals(["foo"], $files);
  }

  public function test_can_store_falsy_values()
  {
    // The envelope records value + expiry separately, so unlike memcache/redis
    // (which read back falsy as a miss) the file backend round-trips falsy data.
    $this->cache->write("false_key", false);
    $this->cache->write("zero_key", 0);
    $this->cache->write("empty_key", "");

    $this->assert_same(false, $this->cache->read("false_key"));
    $this->assert_same(0, $this->cache->read("zero_key"));
    $this->assert_same("", $this->cache->read("empty_key"));
  }
}

class Value {
  public $raw;

  public function __construct($raw) {
    $this->raw = $raw;
  }
}
