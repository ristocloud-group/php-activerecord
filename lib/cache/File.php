<?php
namespace ActiveRecord;

/**
 * A simple, file-based implementation of Cache.
 *
 * Stores each cached value as a serialized envelope
 * (['value' => ..., 'expires_at' => int|null]) in the directory passed to its
 * constructor, so the backend can honor the cache's `expire` option. Writes are
 * atomic (temp file + rename) and expiry is enforced lazily on read.
 *
 * @package ActiveRecord
 */
class File
{
  private string $cache_dir;

  /**
   * Creates a File instance.
   *
   * Takes an $options array w/ the following parameters:
   *
   * <ul>
   * <li><b>path:</b> directory to which cache files will be written</li>
   * </ul>
   * @param array $options
   */
  public function __construct($options)
  {
    $this->cache_dir = $options["path"];
  }

  public function flush()
  {
    array_map("unlink", glob($this->get_cache_path_for_key("*")));
  }

  public function read($key)
  {
    $cache_path = $this->get_cache_path_for_key($key);
    if (!is_file($cache_path))
      return null;

    $raw = @file_get_contents($cache_path);
    if ($raw === false)
      return null;

    $envelope = @unserialize($raw);

    // A bare value written by an older version, a corrupt file, or a torn read
    // is not a valid envelope: treat it as a miss so the value is regenerated.
    if (!is_array($envelope) || !array_key_exists('value', $envelope) || !array_key_exists('expires_at', $envelope))
      return null;

    if ($envelope['expires_at'] !== null && time() >= $envelope['expires_at'])
    {
      @unlink($cache_path);
      return null;
    }

    return $envelope['value'];
  }

  public function write($key, $value, $expire=0)
  {
    if (!is_dir($this->cache_dir))
      @mkdir($this->cache_dir, 0777, true);

    $envelope = [
      'value'      => $value,
      'expires_at' => $expire > 0 ? time() + $expire : null,
    ];

    $cache_path = $this->get_cache_path_for_key($key);

    // Write atomically: a concurrent reader sees the whole old or whole new file.
    $tmp_path = tempnam($this->cache_dir, 'phpar');
    if ($tmp_path === false)
      return;

    file_put_contents($tmp_path, serialize($envelope));
    rename($tmp_path, $cache_path);
  }

  private function get_cache_path_for_key($key) {
    return $this->cache_dir . "/" .  $key;
  }
}
