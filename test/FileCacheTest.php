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

  public function test_interrupted_write_leaves_committed_value_intact()
  {
    // The commit point is the rename, not the temp write. A crash after the
    // temp file is written but before the rename leaves a stray phpar* temp;
    // it must not shadow or corrupt the already-committed key.
    $this->cache->write("k", "committed");

    $orphan = tempnam($this->cache_dir, 'phpar');
    file_put_contents($orphan, serialize(['value' => 'uncommitted', 'expires_at' => null]));

    $this->assert_equals("committed", $this->cache->read("k"));

    // And flush() reclaims the orphaned temp along with the committed files.
    $this->cache->flush();
    $this->assert_equals([], glob($this->cache_dir . "/*") ?: []);
  }

  public function test_writes_are_atomic_under_concurrent_reads()
  {
    // A background process overwrites one key with two distinct large payloads
    // while we read in a tight loop. With the temp-file + rename write, every
    // read is a *complete* payload (or the initial miss) — never a torn value.
    $a = str_repeat('A', 262144);
    $b = str_repeat('B', 262144);
    $writer = __DIR__ . '/helpers/file_cache_atomicity_writer.php';

    $proc = proc_open(
      [PHP_BINARY, $writer, $this->cache_dir, '800'],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes
    );
    $this->assert_true(is_resource($proc), 'failed to start the writer process');

    $saw_value = false;
    $stderr = '';

    try
    {
      stream_set_blocking($pipes[2], false);
      $deadline = microtime(true) + 20;

      while (true)
      {
        $status = proc_get_status($proc);
        $value = $this->cache->read('atomic');

        if ($value === null)
        {
          // A miss is only legitimate before the first commit. Once a value has
          // been committed the file always exists and is complete, so a null
          // here would mean a torn (non-atomic) write truncated it.
          $this->assert_false($saw_value, 'a committed value vanished mid-write — write is not atomic');
        }
        else
        {
          $saw_value = true;
          $this->assert_true($value === $a || $value === $b, 'a concurrent read observed a torn value — write is not atomic');
        }

        $stderr .= stream_get_contents($pipes[2]);

        if (!$status['running'] || microtime(true) > $deadline)
          break;
      }
    }
    finally
    {
      foreach ($pipes as $pipe)
        if (is_resource($pipe)) fclose($pipe);
      proc_terminate($proc);
      proc_close($proc);
    }

    $this->assert_equals('', $stderr, "writer process errored: $stderr");
    $this->assert_true($saw_value, 'reader never observed a committed value');

    // The writer committed hundreds of times: the key now holds a whole payload.
    $final = $this->cache->read('atomic');
    $this->assert_true($final === $a || $final === $b, 'final committed value is not intact');
  }
}

class Value {
  public $raw;

  public function __construct($raw) {
    $this->raw = $raw;
  }
}
