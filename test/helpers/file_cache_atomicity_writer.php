<?php

/**
 * Concurrency fixture for FileCacheTest::test_writes_are_atomic_under_concurrent_reads.
 *
 * Rapidly overwrites a single key with two distinct large payloads through the
 * real File adapter, so a concurrent reader in the test process can verify it
 * never observes a torn/partial value (which the temp-file + rename write makes
 * impossible on a POSIX filesystem).
 *
 * Usage: php file_cache_atomicity_writer.php <cache_dir> <iterations>
 */

require __DIR__ . '/../../lib/cache/File.php';

$dir        = $argv[1] ?? sys_get_temp_dir();
$iterations = (int) ($argv[2] ?? 200);

$cache = new ActiveRecord\File(['path' => $dir]);

$a = str_repeat('A', 262144);
$b = str_repeat('B', 262144);

for ($i = 0; $i < $iterations; $i++) {
    $cache->write('atomic', $i % 2 === 0 ? $a : $b);
}
