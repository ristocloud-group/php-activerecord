# File cache `expire` + Redis cache adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the File cache adapter honor the `expire` option and add a Predis-backed Redis cache adapter, both TDD, with README/RELEASES documentation.

**Architecture:** The cache layer (`lib/Cache.php`) is a lock-free facade that routes a `scheme://…` URL to an adapter class in `lib/cache/<Scheme>.php`, calling `read($key)` (falsy = miss) and `write($key,$value,$expire)`. The File adapter starts persisting an expiry timestamp in a serialized envelope and writes atomically; a new Redis adapter uses Predis with server-side TTL. No PSR-4 autoloader exists — the File/Redis classes are pulled in by `Cache::initialize()`'s dynamic `require_once`, so `ActiveRecord.php` is not touched.

**Tech Stack:** PHP 8.3–8.5, PHPUnit 12, `predis/predis` ^2.0 (pure PHP, no extension), Redis/Valkey, Docker Compose, GitHub Actions, PHPStan level 5.

## Global Constraints

- PHP floor `^8.3`; suite must stay green on 8.3, 8.4, 8.5 (copied from `composer.json` / CI matrix).
- `composer run test` runs `phpunit --fail-on-risky --fail-on-warning --fail-on-skipped --fail-on-deprecation`. A skipped/risky/warning/deprecation test is a **red build** — every new test must assert something and must not emit PHP warnings.
- Public API is **snake_case** and must not change: `set_cache($url, $options)`, `Cache::get`, `Cache::flush`, adapter methods `read`/`write`/`flush`.
- `expire` semantics, uniform across all adapters: `expire > 0` → TTL in seconds; `expire = 0` (or absent) → never expires.
- New code must be **PHPStan level 5 clean without adding entries to `phpstan-baseline.neon`** (`phpstan.neon` analyses `lib`, so `lib/cache/Redis.php` is analysed).
- Match the legacy style of the file being edited (`array()` literals allowed, tabs/spaces as in the surrounding file). `lib/cache/File.php` uses 2-space indent; `lib/cache/Memcache.php` uses tabs — follow each file's own convention.
- MySQL is the priority adapter for the ORM, but this work is cache-only and adapter-agnostic.

---

### Task 1: File adapter honors `expire` (atomic write + lazy GC)

**Files:**
- Modify: `lib/cache/File.php` (currently: `read` at line 35, `write($key,$value)` at line 41 with **no** `$expire` param)
- Test: `test/FileCacheTest.php` (extend the existing class)

**Interfaces:**
- Consumes: nothing (leaf change).
- Produces: `ActiveRecord\File::write($key, $value, $expire = 0)` and `ActiveRecord\File::read($key)` returning the stored value or `null` on miss/expiry. Envelope on disk is `serialize(['value' => mixed, 'expires_at' => int|null])`.

- [ ] **Step 1: Write the failing tests**

Append these methods inside the existing `FileCacheTest` class in `test/FileCacheTest.php` (before the trailing `Value` class):

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec tests vendor/bin/phpunit --filter FileCacheTest`
Expected: FAIL — `test_honors_expire` fails (current `write` ignores the 3rd arg, so the value never expires) and/or `write()` errors on the extra argument depending on PHP; `test_treats_legacy_raw_payload_as_miss` fails because today `read` returns the raw unserialized value.

- [ ] **Step 3: Rewrite `lib/cache/File.php`**

Replace the whole file with (keep the file's 2-space indentation):

```php
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
  private $cache_dir;

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

    $envelope = array(
      'value'      => $value,
      'expires_at' => $expire > 0 ? time() + $expire : null,
    );

    $cache_path = $this->get_cache_path_for_key($key);

    // Write atomically: a concurrent reader sees the whole old or whole new file.
    $tmp_path = tempnam($this->cache_dir, 'phpar');
    file_put_contents($tmp_path, serialize($envelope));
    rename($tmp_path, $cache_path);
  }

  private function get_cache_path_for_key($key) {
    return $this->cache_dir . "/" .  $key;
  }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec tests vendor/bin/phpunit --filter FileCacheTest`
Expected: PASS — all existing and new `FileCacheTest` methods green.

Note: `test_write_leaves_no_temp_files` relies on `tempnam` being renamed away; if it ever fails, confirm the temp path is inside `$this->cache_dir` (it must be, so `rename` stays on one filesystem and is atomic).

- [ ] **Step 5: Commit**

```bash
git add lib/cache/File.php test/FileCacheTest.php
git commit -m "Makes the file cache honor expire with atomic writes

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Add Predis dependency and local Redis service

**Files:**
- Modify: `composer.json` (add `predis/predis` to `require-dev`, add a `suggest` block)
- Modify: `compose.yaml` (add a `redis` service; add `PHPAR_REDIS` + `depends_on` to the `tests` service)

**Interfaces:**
- Consumes: nothing.
- Produces: inside the `tests` container, `Predis\Client` is autoloadable and a Redis server is reachable at `PHPAR_REDIS=redis://redis:6379`.

- [ ] **Step 1: Add Predis to `composer.json`**

Edit the `require-dev` block and add a `suggest` block after it:

```json
    "require-dev": {
        "monolog/monolog": "^3.0",
        "phpunit/phpunit": "^12.0",
        "phpstan/phpstan": "^2.0",
        "squizlabs/php_codesniffer": "^3.10",
        "predis/predis": "^2.0"
    },
    "suggest": {
        "predis/predis": "Enables the redis:// schema cache adapter (compatible with Redis 6/7/8 and Valkey 7/8/9)"
    },
```

(The `suggest` block goes between `require-dev` and `autoload` — mind the commas.)

- [ ] **Step 2: Add the `redis` service to `compose.yaml`**

In the `tests` service `environment:` list add:

```yaml
      - PHPAR_REDIS=redis://redis:6379
```

In the `tests` service `depends_on:` map add:

```yaml
      redis:
        condition: service_healthy
```

At the end of the `services:` map add a new service:

```yaml
  redis:
    image: redis:8
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      retries: 10
      timeout: 5s
```

- [ ] **Step 3: Rebuild the image and boot the stack**

The `Dockerfile` runs `composer install` at build time, so a `composer.json` change requires a rebuild.

Run:
```bash
docker compose build tests && docker compose up -d
```
Expected: build succeeds; `redis` container reports healthy.

- [ ] **Step 4: Verify Predis is installed and Redis is reachable**

Run:
```bash
docker compose exec tests php -r "exit(class_exists('Predis\\Client') ? 0 : 1);" && echo "predis ok"
docker compose exec tests php -r "\$c=new Predis\Client(getenv('PHPAR_REDIS')); \$c->set('k','v'); echo \$c->get('k');"
```
Expected: prints `predis ok` then `v`.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock compose.yaml
git commit -m "Adds predis dependency and a redis service for tests

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

(If `composer.lock` is not tracked or not present, omit it from the `git add`.)

---

### Task 3: Redis cache adapter (Predis) + options passthrough

**Files:**
- Create: `lib/cache/Redis.php` (class `ActiveRecord\Redis`)
- Modify: `lib/Cache.php` (line 48: pass the options array to the adapter constructor)
- Test: `test/RedisCacheTest.php` (new)

**Interfaces:**
- Consumes: `Predis\Client` (from Task 2); `ActiveRecord\CacheException` (existing, thrown by `Memcache` too).
- Produces:
  - `ActiveRecord\Redis::__construct(array $url, array $options = array())` — `$url` is the `parse_url()` result of a `redis://…` string; `$options` is the `set_cache()` options array. Predis client options come from `$options['redis']` (array); the SCAN-flush prefix comes from `$options['namespace']`.
  - `ActiveRecord\Redis::read($key)` → stored value or `null` on miss.
  - `ActiveRecord\Redis::write($key, $value, $expire = 0)` → `SET … EX` when `$expire > 0`, else `SET` with no TTL.
  - `ActiveRecord\Redis::flush()` → SCAN+DEL of `"{namespace}::*"` when a namespace is set, else `FLUSHDB`.
  - `Cache::initialize()` now calls `new $class($url, $options)` (extra arg is harmless for `File`/`Memcache`, which declare one parameter).

- [ ] **Step 1: Write the failing tests**

Create `test/RedisCacheTest.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec tests vendor/bin/phpunit --filter RedisCacheTest`
Expected: FAIL — `lib/cache/Redis.php` does not define `ActiveRecord\Redis` yet (fatal / class-not-found).

- [ ] **Step 3: Create `lib/cache/Redis.php`**

```php
<?php
namespace ActiveRecord;

use Predis\Client;

/**
 * A Redis-backed implementation of Cache, using the Predis client.
 *
 * Reachable via a redis:// connection string, e.g.
 *   redis://user:pass@host:6379/0?read_write_timeout=2
 *
 * Connection parameters come from the URL and its query string (so any Predis
 * connection parameter is reachable from the DSN); Predis client options
 * (prefix, cluster, ...) come from the 'redis' sub-key of the cache options
 * array. Values are (de)serialized because Redis stores strings.
 *
 * Compatible with Redis 6/7/8 and Valkey 7/8/9: only GET, SET (with EX), DEL,
 * SCAN and FLUSHDB are used, with no server-version feature gating.
 *
 * @package ActiveRecord
 */
class Redis
{
	const DEFAULT_PORT = 6379;

	private $client;
	private $namespace;

	/**
	 * @param array $url     result of parse_url() on the redis:// string
	 * @param array $options the options array passed to set_cache()
	 */
	public function __construct($url, $options=array())
	{
		if (!class_exists('Predis\\Client'))
			throw new CacheException("The predis/predis package is required to use the redis cache adapter");

		$parameters = array(
			'scheme' => isset($url['scheme']) ? $url['scheme'] : 'tcp',
			'host'   => isset($url['host']) ? $url['host'] : 'localhost',
			'port'   => isset($url['port']) ? $url['port'] : self::DEFAULT_PORT,
		);

		// A redis:// URL yields scheme 'redis'; Predis expects tcp/tls/unix.
		if ($parameters['scheme'] === 'redis')
			$parameters['scheme'] = 'tcp';

		if (isset($url['user']) && strlen($url['user']))
			$parameters['username'] = $url['user'];
		if (isset($url['pass']) && strlen($url['pass']))
			$parameters['password'] = $url['pass'];
		if (isset($url['path']) && strlen(ltrim($url['path'], '/')))
			$parameters['database'] = (int) ltrim($url['path'], '/');

		// Any Predis connection parameter can be supplied via the DSN query string.
		if (isset($url['query']))
		{
			parse_str($url['query'], $query);
			$parameters = array_merge($parameters, $query);
		}

		$client_options = (isset($options['redis']) && is_array($options['redis'])) ? $options['redis'] : array();
		$this->namespace = isset($options['namespace']) ? (string) $options['namespace'] : '';

		$this->client = new Client($parameters, $client_options);
	}

	public function flush()
	{
		if ($this->namespace !== '')
		{
			$pattern = $this->namespace . '::*';
			$cursor = 0;
			do
			{
				list($cursor, $keys) = $this->client->scan($cursor, array('MATCH' => $pattern, 'COUNT' => 100));
				if (!empty($keys))
					$this->client->del($keys);
			}
			while ((int) $cursor !== 0);
		}
		else
		{
			$this->client->flushdb();
		}
	}

	public function read($key)
	{
		$value = $this->client->get($key);
		if ($value === null)
			return null;

		$result = @unserialize($value);
		return $result === false ? null : $result;
	}

	public function write($key, $value, $expire=0)
	{
		$payload = serialize($value);
		if ($expire > 0)
			$this->client->set($key, $payload, 'EX', $expire);
		else
			$this->client->set($key, $payload);
	}
}
```

- [ ] **Step 4: Pass the options array to the adapter in `lib/Cache.php`**

In `lib/Cache.php`, change line 48 from:

```php
			static::$adapter = new $class($url);
```

to:

```php
			static::$adapter = new $class($url, $options);
```

(`$options` is the raw argument to `initialize()`, evaluated before the default merge on line 53. `File` and `Memcache` declare a single constructor parameter and silently ignore the extra argument.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker compose exec tests vendor/bin/phpunit --filter RedisCacheTest`
Expected: PASS — all `RedisCacheTest` methods green.

- [ ] **Step 6: Run PHPStan to verify no new suppressions are needed**

Run: `docker compose exec tests composer run analyse`
Expected: PASS at level 5 with no new errors. `lib/cache/Redis.php` uses Predis' documented command methods (`get`/`set`/`del`/`scan`/`flushdb`), which Predis' `Client` exposes via `@method` annotations, so they resolve. If PHPStan reports undefined methods on `Predis\Client`, do **not** edit the baseline — stop and report; the fallback is to route those five commands through `Predis\Client::executeRaw()` (a concrete method) while keeping the same behavior.

- [ ] **Step 7: Run the full cache suite to confirm no regressions**

Run: `docker compose exec tests vendor/bin/phpunit --filter Cache`
Expected: PASS — `CacheTest`, `FileCacheTest`, `RedisCacheTest`, `ActiveRecordCacheTest` all green, none skipped.

- [ ] **Step 8: Commit**

```bash
git add lib/cache/Redis.php lib/Cache.php test/RedisCacheTest.php
git commit -m "Adds a Predis-backed redis cache adapter

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: CI — default Redis service + Redis/Valkey compatibility matrix

**Files:**
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `RedisCacheTest` (Task 3), the `PHPAR_REDIS` env convention.
- Produces: the `test` job exercises Redis on every PHP version; a new `redis-compat` job runs `RedisCacheTest` against six server images.

- [ ] **Step 1: Add a default `redis` service to the main `test` job**

In `.github/workflows/ci.yml`, inside `jobs.test.services`, after the `memcached` service add:

```yaml
      redis:
        image: redis:8
        ports: ['6379:6379']
        options: >-
          --health-cmd="redis-cli ping"
          --health-interval=5s --health-timeout=5s --health-retries=10
```

In `jobs.test.env`, after `PHPAR_MEMCACHED` add:

```yaml
      PHPAR_REDIS: redis://127.0.0.1:6379
```

- [ ] **Step 2: Add the `redis-compat` job**

At the end of the `jobs:` map (after the `test` job) add a new job. The service image is parameterized by the matrix; the readiness wait runs on the runner (which has `bash` and `/dev/tcp`), so it works for both the `redis` and `valkey/valkey` images regardless of which CLI they ship:

```yaml
  redis-compat:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        redis-image:
          - redis:6
          - redis:7
          - redis:8
          - valkey/valkey:7
          - valkey/valkey:8
          - valkey/valkey:9

    services:
      redis:
        image: ${{ matrix.redis-image }}
        ports: ['6379:6379']

    env:
      PHPAR_REDIS: redis://127.0.0.1:6379

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Install dependencies
        run: composer update --no-interaction --prefer-dist

      - name: Wait for Redis
        run: |
          for i in $(seq 1 30); do
            (echo > /dev/tcp/127.0.0.1/6379) >/dev/null 2>&1 && exit 0
            sleep 1
          done
          echo "Redis did not become ready" >&2
          exit 1

      - name: Redis adapter tests
        run: vendor/bin/phpunit --filter RedisCacheTest --fail-on-risky --fail-on-warning --fail-on-skipped --fail-on-deprecation
```

- [ ] **Step 3: Validate the workflow YAML locally**

Run: `docker compose exec tests php -r "var_dump(is_array(yaml_parse_file('.github/workflows/ci.yml')));"` if the `yaml` extension is present; otherwise validate structure with:
`ruby -ryaml -e "YAML.load_file('.github/workflows/ci.yml'); puts 'ok'"` on the host, or simply re-read the file and eyeball indentation.
Expected: parses without error (`true` / `ok`).

- [ ] **Step 4: Verify the image tags exist (external check)**

Before relying on the matrix, confirm the six tags are published (image availability is an external fact that can drift):
```bash
for t in redis:6 redis:7 redis:8 valkey/valkey:7 valkey/valkey:8 valkey/valkey:9; do
  docker manifest inspect "$t" >/dev/null 2>&1 && echo "$t OK" || echo "$t MISSING"
done
```
Expected: all six print `OK`. If any prints `MISSING`, replace it with the nearest published minor tag (e.g. `valkey/valkey:9.0`) and note the substitution in the commit message. Do not silently drop a version the spec requires.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "Runs the redis adapter across Redis 6/7/8 and Valkey 7/8/9 in CI

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: Documentation (README + RELEASES)

**Files:**
- Modify: `README.md` (the "Optional: caching the schema" section, currently around lines 123–141)
- Modify: `RELEASES.md` (the `v2.0.0 (TBD)` bullet list at the top)

**Interfaces:**
- Consumes: the finished File and Redis behaviors.
- Produces: user-facing docs — a Redis subsection, a three-adapter comparison table, and corrected File-cache notes.

- [ ] **Step 1: Rewrite the README cache section**

In `README.md`, replace the paragraph that currently reads *"The file backend stores one serialized file per cache key … it does not honor the `expire` option …"* (line ~139) and add a Redis subsection + comparison table. Use this block (place it so the Memcached and File examples stay, and the corrected notes + table follow):

````markdown
**Redis** — requires the `predis/predis` Composer package (`composer require predis/predis`); no PHP extension needed:

```php
$cfg->set_cache('redis://localhost:6379/0', array('expire' => 120, 'namespace' => 'my_app'));
```

Connection parameters are taken from the DSN, including its query string, so any Predis
connection parameter is reachable — e.g. TLS and tuning:

```php
$cfg->set_cache('redis://user:secret@redis.example.com:6379/0?read_write_timeout=2', array(
    'namespace' => 'my_app',
    'redis'     => array('prefix' => 'ar:'), // Predis client options
));
```

The same `redis://` DSN targets **Redis 6/7/8 and Valkey 7/8/9** interchangeably; the adapter
is exercised against all six in CI. Values are serialized on write and unserialized on read.
`ActiveRecord\Cache::flush()` deletes only the keys under the configured `namespace`
(via `SCAN`/`DEL`); with no namespace it falls back to `FLUSHDB`, which clears the whole
selected Redis database — set a `namespace` when the Redis instance is shared.

The **file** backend now honors the `expire` option: each entry stores an expiry timestamp
and is treated as a miss once it lapses (deleted lazily on the next read). Writes are atomic
(temp file + `rename`). **Behavior change:** because the default `expire` is 30 seconds, file
entries that previously persisted forever now expire after 30s by default — pass
`array('expire' => 0)` to keep entries until you `flush()` them. Files written by older
versions are treated as a miss and regenerated, so no manual purge is needed when upgrading.

`ActiveRecord\Cache::flush()` invalidates the cache for any backend (for the file cache it
deletes the cached files) — for example after running a schema migration. All backends accept
a `namespace` option that prefixes every cache key, useful when several applications share one
cache store.

The cache is lock-free: at each expiry, concurrent requests all recompute the cached value
once (a brief stampede). For very hot deployments raise `expire` (or set it to `0`). Prefer a
local filesystem for the file backend — TTLs rely on the host clock, so shared storage (NFS)
across clock-skewed hosts can expire entries early or late.

| Backend | Requirement | TTL (`expire`) | Persistence | Namespace / flush | Concurrency | Best for |
|---|---|---|---|---|---|---|
| **Memcached** | `memcached` PHP extension | Yes (server-side) | In-memory, evictable | `namespace` prefix; `flush()` clears the whole server | Atomic server-side TTL | Existing memcached infra |
| **File** | none | Yes (since this fork) | On disk until expiry/flush | `namespace` prefix; `flush()` deletes files | Lock-free; atomic writes, lazy GC, local-FS assumption | Single host, no extra services |
| **Redis / Valkey** | `predis/predis` package | Yes (server-side) | In-memory (optionally persisted by the server) | `namespace`-scoped `SCAN`/`DEL`, else `FLUSHDB` | Atomic server-side TTL | Shared/networked cache, HA |
````

- [ ] **Step 2: Verify the old "does not honor expire" wording is gone**

Run: `grep -n "does \*\*not\*\* honor\|does not honor" README.md`
Expected: no matches (the stale sentence has been removed).

- [ ] **Step 3: Update `RELEASES.md`**

Under the `## v2.0.0 (TBD)` heading in `RELEASES.md`, add two bullets to the existing list:

```markdown
* Adds a Redis cache adapter (via `predis/predis`) for the schema cache, verified against Redis 6/7/8 and Valkey 7/8/9
* The file cache now honors the `expire` option (writes are atomic; entries expire lazily). **Behavior change:** with the default `expire` of 30s, file entries no longer persist indefinitely — pass `expire => 0` for the previous behavior
```

- [ ] **Step 4: Commit**

```bash
git add README.md RELEASES.md
git commit -m "Documents the redis adapter and the file cache expire behavior

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 6: Full-suite gate

**Files:** none (verification only).

**Interfaces:** consumes everything above.

- [ ] **Step 1: Run the full CI gate**

Run: `docker compose exec tests composer run test`
Expected: PASS — entire suite green with **zero** skipped/risky/warning/deprecation tests (including `RedisCacheTest`, since the `redis` service is up from Task 2).

- [ ] **Step 2: Run static analysis**

Run: `docker compose exec tests composer run analyse`
Expected: PASS at level 5, no new baseline entries.

- [ ] **Step 3: Confirm nothing was skipped**

Run: `docker compose exec tests vendor/bin/phpunit --filter Cache --testdox`
Expected: every cache test listed as passed; no "skipped" lines.

---

## Self-Review

**Spec coverage:**
- File adapter honors `expire` (envelope, `0`/`null` = never, atomic write, lazy GC, legacy=miss) → Task 1. ✅
- Uniform `expire` semantics → Task 1 (`$expire > 0`) + Task 3 (`SET … EX` vs `SET`). ✅
- Redis adapter via Predis, DSN + query-string params, client options from options array, serialize/unserialize, native TTL, `SCAN`+`DEL` flush with `FLUSHDB` fallback → Task 3. ✅
- `Cache::initialize` options passthrough → Task 3 Step 4. ✅
- Redis 6/7/8 + Valkey 7/8/9 compatibility, in CI matrix → Task 4 (`redis-compat` job) + command-set note in `Redis.php`. ✅
- Dependency placement (`require-dev` + `suggest`), compose + CI service, no skips → Tasks 2 & 4. ✅
- Concurrency hardening (atomic write, guarded `unlink`/`mkdir`, torn-read=miss, `--fail-on-warning` safety) → Task 1 code + `test_reading_expired_entry_twice_does_not_warn`. ✅
- Documentation: Redis subsection, comparison table w/ concurrency column, corrected File notes, stampede/local-FS notes, RELEASES → Task 5. ✅
- Full-suite + PHPStan gate → Task 6. ✅

**Placeholder scan:** No TBD/TODO/"handle edge cases"; every code step contains full code. Task 4 Step 4 flags external image-tag verification with an explicit substitution rule (not a placeholder). ✅

**Type consistency:** `read($key)`, `write($key,$value,$expire=0)`, `flush()` are consistent across `File` and `Redis`; `Redis::__construct($url, $options=array())` matches the `new $class($url, $options)` call added to `Cache.php`; `$options['redis']` (client options) and `$options['namespace']` (SCAN prefix) are the only options keys the adapter reads, matching the tests and README. ✅
