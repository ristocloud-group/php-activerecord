# File cache `expire` + Redis cache adapter — Design

Date: 2026-07-30
Branch base: `master`
Status: approved (pending spec review)

## Context

php-activerecord ships an optional cache layer used to persist the runtime-introspected
table schema across PHP requests. Today two backends are bundled:

- **Memcached** (`lib/cache/Memcache.php`) — requires the `memcached` PHP extension. Its
  `write($key, $value, $expire)` forwards the TTL to `Memcached::set()`, so it honors `expire`.
- **File** (`lib/cache/File.php`) — this fork's addition for hosts without memcached.

The cache contract lives in `lib/Cache.php`:

```php
public static function get($key, $closure) {
    $key = static::get_namespace() . $key;
    if (!static::$adapter) return $closure();
    if (!($value = static::$adapter->read($key)))
        static::$adapter->write($key, ($value = $closure()), static::$options['expire']);
    return $value;
}
```

`read()` returns a falsy value on miss; `write()` receives `expire` as its third argument.
The default `expire` is `30` seconds (`Cache.php:53`), merged with `namespace => ''`.
`Cache::initialize()` parses the URL with `parse_url()`, camelizes the scheme to a class name,
`require_once`s `lib/cache/<Scheme>.php`, and instantiates `ActiveRecord\<Scheme>($parsedUrl)`.
Because the require is dynamic, **a new adapter needs no change to `ActiveRecord.php`**.

### Problems this design addresses

1. **File adapter ignores `expire`.** `File::write($key, $value)` declares only two
   parameters, silently dropping the TTL that `Cache::get()` passes. File entries never
   expire; the README (line 139) documents this as a known limitation.
2. **No Redis backend.** Users on hosts with Redis (but no memcached extension) have no
   first-class option. We want a Predis-backed adapter that exposes the full driver surface.

## Goals

- Make the **File** adapter honor `expire`, with semantics uniform across all adapters.
- Add a **Redis** adapter via `predis/predis` reachable as `redis://…`, exposing all Predis
  connection parameters through the DSN and client options through the options array.
- Guarantee the Redis adapter works against **Redis 6.x, 7.x, 8.x** and **Valkey 7.x, 8.x,
  9.x**, verified by a dedicated CI matrix axis.
- Do everything **TDD**: failing test first, then implementation.
- Ensure **no test is skipped** in the Docker/CI environment (skips are a red build).
- Enrich the README with per-adapter documentation (uses, pros & cons, comparison table).

## Non-goals

- No change to the public cache API (`set_cache($url, $options)`, `Cache::get/flush`).
- No new cache use cases beyond schema caching; we keep serving the existing contract.
- No reintroduction of removed adapters.

## Uniform `expire` semantics (all adapters)

Agreed contract, applied consistently to File and Redis (and matching Memcached's behavior):

- `expire > 0` → the entry expires after that many seconds (TTL).
- `expire = 0` or `null` → **no expiration**; the entry persists until `flush()`.

This mirrors Memcached, where `0` means "never expire".

### Behavior change to document

The default `expire` is `30`. Once the File adapter honors `expire`, existing file-cache
users will see schema metadata expire every 30s where it previously persisted forever. This
is an intentional behavior change. It will be called out in the README and `RELEASES.md`;
users who want the old behavior pass `array('expire' => 0)`.

## Component 1 — File adapter honors `expire`

File: `lib/cache/File.php`

- **`write($key, $value, $expire = 0)`** — serialize an *envelope*, not the bare value:

  ```php
  ['value' => $value, 'expires_at' => $expire > 0 ? time() + $expire : null]
  ```

  The default argument keeps existing 2-arg call sites working. The write is **atomic**:
  serialize to a unique temp file in the cache directory, then `rename()` it onto the final
  path. `rename()` is atomic on the same filesystem, so a concurrent reader always sees
  either the whole old file or the whole new file — never a truncated blob.

- **`read($key)`** — read and unserialize the envelope:
  - If the file does not exist → return `null` (miss).
  - If the payload is not a well-formed envelope (a raw value written by a previous version,
    a corrupt file, or a partial read) → treat as a miss (return `null`) so the value is
    regenerated. This makes the upgrade safe without a manual `flush()` and absorbs any
    residual torn-read window.
  - If `expires_at !== null && time() >= expires_at` → **delete the file** (lazy GC) and
    return `null`.
  - Otherwise return `value`.

- **`flush()`** — unchanged (`unlink` of the cached files via glob).

- `read()` returns `null` on miss (consistent with the existing File behavior and
  `FileCacheTest`'s `assert_null`).

### Concurrency (PHP shared-nothing, one cache dir shared by many processes)

The cache is lock-free by design; every race below degrades to a redundant regeneration or a
lost entry, **never to a corrupt value or a wrong query result**, given the mitigations here.

- **Torn reads** — eliminated by the atomic temp-file + `rename()` write; the malformed =
  miss rule covers any residual case.
- **Lazy-GC `unlink` races** — two readers may both find an entry expired and both try to
  delete it, or a reader may delete a file another process just refreshed. The deletion is
  therefore **guarded** (`@unlink` / existence-tolerant) so a vanished file never raises a
  PHP warning. This matters because the CI gate runs with `--fail-on-warning` and PHPUnit
  promotes PHP warnings to test failures — an unguarded `unlink`/`mkdir` on a lost race would
  turn a benign cache miss into a red build. The `mkdir` in `write()` is guarded the same way
  (recursive + tolerant of a concurrent create).
- **Cache stampede** — when an entry expires, N concurrent requests all miss and all run the
  closure at once. This is inherent to `Cache::get`'s lock-free contract and affects every
  adapter equally. It is a **new consequence for the file cache**, which previously never
  expired: with the default `expire=30` it can now stampede every 30s. Given schema caching's
  low key cardinality the impact is small; introducing locking is out of scope. Documented in
  the README as a reason to raise `expire` (or set `0`) for very hot deployments.
- **Clock assumption** — `expires_at` uses `time()` on the writing/reading host. TTLs are
  reliable on a local filesystem; on shared storage (NFS) across clock-skewed hosts expiry
  may fire early or late. Documented; the local-filesystem case is the supported one.

## Component 2 — Redis adapter (Predis)

New file: `lib/cache/Redis.php`, class `ActiveRecord\Redis`. Auto-wired by
`Cache::initialize()` for the `redis` scheme; no edit to `ActiveRecord.php`.

### Configuration surface

Chosen approach: **full DSN + query string** for connection parameters; **options array**
for Predis client options.

- **Connection parameters from the DSN**, e.g.
  `redis://user:pass@host:6379/0?read_write_timeout=2&persistent=1&scheme=tls`
  The constructor receives the `parse_url()` array and maps it to a Predis parameters array:
  - `scheme` → `scheme` (default `tcp`; may be overridden to `tls`/`unix` via query string)
  - `host` → `host`
  - `port` → `port` (default `6379`)
  - `user` → `username` (Redis 6 ACL) when present
  - `pass` → `password` when present
  - `path` → `database` index (leading-slash stripped; default `0`)
  - **`query`** → parsed with `parse_str()` and merged into the parameters array, so **any
    Predis connection parameter** is reachable from the DSN without code changes.

- **Client options from the options array** — a dedicated sub-key of the `set_cache`
  options array (e.g. `array('redis' => array('prefix' => 'myapp:'))`) is passed as the
  second argument to `new \Predis\Client($params, $clientOptions)`. This covers `prefix`,
  `cluster`, `replication`, `serializer`, etc.

The `namespace` option (shared with the other adapters) continues to prefix the key at the
`Cache` layer; a Predis `prefix` client option can be used additionally/instead.

### Storage & (de)serialization

Redis stores strings, so the adapter serializes like the File adapter:

- **`write($key, $value, $expire)`**:
  - `$expire > 0` → `SET key serialize($value) EX $expire`
  - otherwise → `SET key serialize($value)` (no TTL — native Redis persistence)
- **`read($key)`** — `GET key`; `null` on miss, else `unserialize()` of the payload.
  (No manual expiry check — Redis enforces TTL natively.)

### `flush()`

**Prefix/namespace-scoped** flush to be safe on shared Redis databases:

- If a key prefix/namespace is configured (Predis `prefix` client option and/or the
  `Cache` `namespace`), delete only matching keys via `SCAN` + `DEL` (cursor loop, no
  blocking `KEYS`).
- If no prefix/namespace is configured, fall back to `FLUSHDB`.

The README documents that a scoped flush requires a configured prefix/namespace, and that
the fallback clears the whole selected Redis DB.

### Error handling

- A failed connection surfaces as a `CacheException` (consistent with `Memcache`'s
  constructor), wrapping any Predis connection exception where practical.

### Server compatibility (Redis 6/7/8, Valkey 7/8/9)

The adapter must run unchanged against **Redis 6.x, 7.x, 8.x** and **Valkey 7.x, 8.x, 9.x**
(Valkey is a wire-compatible Redis fork). This is achievable because the adapter uses only
the stable, universally-supported command set:

- `GET` / `SET` with the `EX` TTL option, `DEL`, and cursor-based `SCAN` — present and
  behaviorally identical across all six server families.
- `FLUSHDB` only as the no-prefix fallback.
- ACL `username` (Redis 6+ / Valkey) is sent only when the DSN provides a user, so a
  password-only or auth-less server keeps working.

`predis/predis ^2.0` speaks RESP2/RESP3 and connects to all of the above. No server-version
detection or feature-gating is needed in the adapter; compatibility is proven by CI (below)
rather than by conditional code.

### Concurrency

Redis needs none of the File adapter's hardening: TTL is enforced server-side and expiry is
atomic (`SET … EX` is a single command), so there is no lazy-GC race and no torn read. The
only non-atomic operation is the `SCAN`+`DEL` flush, which is inherently best-effort and
matches the semantics callers already expect from `flush()`. A cache stampede at expiry is
still possible (same lock-free `Cache::get` contract as every adapter).

## Component 3 — Dependency & test infrastructure

- **`composer.json`**: add `predis/predis` (`^2.0`) to `require-dev`, and a `suggest` entry
  advising runtime users who want the Redis backend to install it. (Not a hard `require`, so
  installs that don't use Redis stay lightweight — same treatment as the memcached ext.)
- **`compose.yaml`**: add a `redis` service (`redis:7-alpine` or `redis:8`) with a TCP
  healthcheck, add `PHPAR_REDIS=redis://redis:6379` to the `tests` service environment, and
  add it to the `tests` service `depends_on` (`condition: service_healthy`).
- **`.github/workflows/ci.yml`** — two changes:
  - **Main matrix (PHP × DBs):** add a single default `redis` service container to every job
    and mirror `PHPAR_REDIS` (e.g. `redis://127.0.0.1:6379`), matching how memcached is
    wired, so the full-suite gate (`composer run test`) always exercises the Redis adapter.
  - **Dedicated Redis/Valkey compatibility job:** a separate job with a matrix over the six
    server images, each started as a service container and targeted via `PHPAR_REDIS`, that
    runs only the Redis cache tests (`vendor/bin/phpunit --filter RedisCacheTest`) on a
    single pinned PHP version (8.3). This proves cross-server compatibility without
    multiplying the whole 3-PHP × 4-DB matrix by six. Matrix images:
    - `redis:6`, `redis:7`, `redis:8`
    - `valkey/valkey:7`, `valkey/valkey:8`, `valkey/valkey:9`

    The job runs under the same `--fail-on-*` flags, so a skip is still a red build.
- **`test/helpers/config.php`**: no connection registration needed (cache is not an AR
  connection), but the Redis host/DSN is read from `PHPAR_REDIS` in the cache tests.

## Component 4 — Tests (TDD)

Written test-first for each behavior.

### `test/FileCacheTest.php` (extend existing)

- `test_honors_expire` — `write("k","v",1)`, `sleep(2)`, assert `read("k")` is a miss.
- `test_zero_expire_never_expires` — `write("k","v",0)`, assert still readable.
- `test_treats_legacy_raw_payload_as_miss` — write a bare `serialize($value)` file directly,
  assert `read()` returns a miss (upgrade-safety); doubles as the torn-read case.
- `test_reading_expired_entry_does_not_warn` — write with a past/immediate expiry, then read
  twice so the second read hits an already-unlinked file; assert no PHP warning surfaces
  (guards the lazy-GC `unlink` race under `--fail-on-warning`).
- `test_write_is_atomic_no_partial_file` — assert the final cache file only ever contains a
  fully serialized envelope (no temp artifacts left behind after `write()`).
- Existing tests updated for the new `write()` signature (default arg keeps them valid).

### `test/RedisCacheTest.php` (new)

Mirrors `CacheTest`/`FileCacheTest` against a real Redis (`PHPAR_REDIS`). No skips in Docker/CI.

- init / not-null adapter, `read` miss returns falsy, reads-own-writes, complex objects
  (serialize round-trip), `test_honors_expire`, `test_zero_expire_never_expires`,
  `namespace`/`prefix` key prefixing, DSN-with-query-string parsing (e.g. database index and
  a passthrough parameter applied), scoped `flush()` clears only prefixed keys and leaves
  unrelated keys intact.

### Cache-layer integration (optional)

A variant of `ActiveRecordCacheTest` exercising Redis as the schema-cache backend
(`set_cache('redis://…')`, `Author::first()`, assert `get_meta_data-…` cached), if it adds
coverage beyond the adapter unit tests.

## Component 5 — Documentation

- **README** "Optional: caching the schema" section:
  - Add a **Redis** subsection with a DSN example (query string + client options), noting
    that the adapter is verified against Redis 6/7/8 and Valkey 7/8/9 and that the same
    `redis://` DSN targets either server.
  - Replace the note (line 139) that the file cache does not honor `expire`; state the new
    behavior and the `expire => 0` opt-out.
  - Add a **comparison table** of the three adapters: requirement (extension/package), TTL
    support, persistence model, prefix/namespace, flush semantics, **concurrency robustness**
    (Redis atomic server-side TTL vs File's lock-free lazy GC), pros & cons, when to use.
  - Note the cache is lock-free: on a very hot deployment raise `expire` (or set `0`) to
    avoid a stampede at each expiry, and prefer a local filesystem for the File backend.
- **`RELEASES.md`** (`v2.0.0 (TBD)`): note the File adapter now honors `expire` (with the
  behavior-change caveat) and the new Redis/Predis adapter.

## Testing & acceptance

- `docker compose exec tests composer run test` is green with no skipped/risky/warning tests.
- New File and Redis behaviors covered by tests that fail before implementation.
- The dedicated CI job runs the Redis cache tests green against all six server images
  (Redis 6/7/8, Valkey 7/8/9).
- `composer run analyse` (PHPStan level 5) passes without new baseline entries.
- README renders the new section and comparison table; RELEASES updated.

## Risks & mitigations

- **File behavior change (30s TTL).** Mitigated by envelope upgrade-safety (legacy files
  treated as miss), documentation, and the `expire => 0` opt-out.
- **Predis absent at runtime for Redis users.** Mitigated by the `suggest` entry and a clear
  `CacheException` if the class is missing.
- **`flush()` on shared Redis.** Mitigated by prefix/namespace-scoped deletion; `FLUSHDB`
  only as documented fallback when no prefix is configured.
- **Predis version / `SCAN` semantics.** Pin `predis/predis ^2.0`; use cursor-based `SCAN`.
- **Server-version drift across Redis 6/7/8 & Valkey 7/8/9.** Mitigated by restricting the
  adapter to the universally-supported command set (no version gating) and proving it in the
  dedicated CI compatibility job rather than trusting a single server version.
- **README doc example targeting.** Note in the README that the same `redis://` DSN works
  against Redis and Valkey interchangeably.
```
