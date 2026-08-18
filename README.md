# PHP ActiveRecord #

[![CI](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml/badge.svg)](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml)
[![Coverage](https://raw.githubusercontent.com/ristocloud-group/php-activerecord/badges/coverage.svg)](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml)

> **This is a fork maintained by Ristocloud Group S.r.l.**
>
> It is based on [`zamzar/php-activerecord`](https://github.com/zamzar/php-activerecord) (itself a fork of the original [`jpfuentes2/php-activerecord`](https://github.com/jpfuentes2/php-activerecord)). We vendor it into our own applications and maintain it — fixing bugs and keeping it running on modern PHP and database versions. It is not affiliated with, nor endorsed by, the original authors.

Originally created by:

* [@kla](https://github.com/kla) - Kien La
* [@jpfuentes2](https://github.com/jpfuentes2) - Jacques Fuentes
* [and these contributors](https://github.com/kla/php-activerecord/contributors)

Upstream documentation: <http://www.phpactiverecord.org/>

## Introduction ##
A brief summarization of what ActiveRecord is:

> Active record is an approach to access data in a database. A database table or view is wrapped into a class,
> thus an object instance is tied to a single row in the table. After creation of an object, a new row is added to
> the table upon save. Any object loaded gets its information from the database; when an object is updated, the
> corresponding row in the table is also updated. The wrapper class implements accessor methods or properties for
> each column in the table or view.

More details can be found [here](http://en.wikipedia.org/wiki/Active_record_pattern).

This implementation is inspired and thus borrows heavily from Ruby on Rails' ActiveRecord.
We have tried to maintain their conventions while deviating mainly because of convenience or necessity.
Of course, there are some differences which will be obvious to the user if they are familiar with rails.

## Minimum Requirements ##

- PHP 8.3+ (tested on PHP 8.3, 8.4 and 8.5)
- PDO driver for your respective database

## Supported Databases ##

- **MySQL** — the primary production target. Supported minimum: MySQL 8+.
- **MariaDB** — supported minimum: MariaDB 10.11+.
- **PostgreSQL**
- **SQLite**

These are policy minimums; `composer.json` carries no database-version constraint. Continuous integration runs the full test suite across PHP 8.3, 8.4 and 8.5 against MySQL 8.4 & 9.7, MariaDB 10.11, 11.4, 11.8 & 12.3, PostgreSQL 15, 16, 17 & 18, and SQLite 3. The Oracle (`oci`) adapter was removed in v1.8.0.

## Features ##

- Finder methods
- Dynamic finder methods
- Writer methods
- Relationships
- Validations
- Callbacks
- Serializations (json/xml)
- Transactions
- Support for multiple adapters
- Miscellaneous options such as: aliased/protected/accessible attributes

## Installation ##

This fork is not published on Packagist. Install it with [Composer](https://getcomposer.org/) from its Git repository — add a VCS repository to your `composer.json` and require the package by its name (`ristocloud-group/php-activerecord`):

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ristocloud-group/php-activerecord" }
    ],
    "require": {
        "ristocloud-group/php-activerecord": "dev-master"
    }
}
```

Setup is very easy and straight-forward. There are essentially only two configuration points you must concern yourself with:

1. Configuring your database connections.
2. Setting the database connection to use for your environment.

Example:

```php
ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) {
    $cfg->set_connections([
        'development' => 'mysql://username:password@localhost/development_database_name',
        'test' => 'mysql://username:password@localhost/test_database_name',
        'production' => 'mysql://username:password@localhost/production_database_name',
    ]);
});
```

Alternatively (without a closure):

```php
$cfg = ActiveRecord\Config::instance();
$cfg->set_connections([
    'development' => 'mysql://username:password@localhost/development_database_name',
    'test' => 'mysql://username:password@localhost/test_database_name',
    'production' => 'mysql://username:password@localhost/production_database_name',
]);
```

MariaDB uses the same `mysql://` connection scheme (and the MySQL adapter) as MySQL.

### Connection URLs ###

Connections are configured with URLs of the form:

```
protocol://username:password@host[:port]/dbname
```

- **Protocols:** `mysql://` (MySQL and MariaDB), `pgsql://` (PostgreSQL) and `sqlite://`. The Oracle adapter was removed in v1.8.0 — an `oci://` URL throws a `DatabaseException`.
- **Port** is optional and defaults to the adapter's standard port (3306 for MySQL/MariaDB, 5432 for PostgreSQL).
- **Unix sockets:** pass the socket path in place of the host: `mysql://user:pass@unix(/var/run/mysqld/mysqld.sock)/dbname`.

The query string may carry two optional parameters, separated by `&` as usual:

- `charset=<charset>` — character set for the connection, applied with `SET NAMES` right after connecting. Supported by MySQL/MariaDB and PostgreSQL; SQLite does not support it and throws.
- `decode=true` — URL-decode the username and password before connecting. Use it when credentials contain characters that are reserved in URLs (`@`, `:`, `/`, …): percent-encode them and add `decode=true`.

```php
$cfg->set_connections([
    'development' => 'mysql://username:password@localhost/database_name?charset=utf8mb4',
    // password is "p@ss:word", percent-encoded because of decode=true
    'production' => 'mysql://username:p%40ss%3Aword@db.example.com:3307/database_name?charset=utf8mb4&decode=true',
]);
```

SQLite needs no credentials or database name — the URL is a path to an existing database file:

```
sqlite://file.db                            relative to the current working directory
sqlite://../relative/path/to/file.db
sqlite://unix(/absolute/path/to/file.db)    absolute path on Unix
sqlite://windows(c%3A/absolute/path/to/file.db)   absolute path on Windows (drive colon percent-encoded)
```

PHP ActiveRecord will default to use your development database. For testing or production, you simply set the default
connection according to your current environment ('test' or 'production'):

```php
ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) {
    $cfg->set_default_connection('production'); // 'development', 'test', or 'production'
});
```

Once you have configured these settings you are done. ActiveRecord takes care of the rest for you.
It does not require that you map your table schema to yaml/xml files. It will query the database for this information and
cache it so that it does not make multiple calls to the database for a single schema.

### Optional: caching the schema ###

php-activerecord introspects each table's schema (columns, types, primary key) from the database. Within a single request this is kept in memory, but PHP's shared-nothing model means it is re-introspected on every request. To persist it across requests, configure an external cache. Three backends are bundled:

**Memcached** — requires the `memcached` PHP extension:

```php
$cfg->set_cache('memcache://localhost:11211', ['expire' => 120, 'namespace' => 'my_app']);
```

**File** — a filesystem cache (added by this fork for hosts without memcached); no extension required:

```php
$cfg->set_cache('file:///var/tmp/php-activerecord-cache');
```

The file backend stores one serialized file per cache key inside the directory you pass (creating the directory if it does not exist), and reads it back with `unserialize()`.

The **file** backend also honors the `expire` option: each entry stores an expiry timestamp
and is treated as a miss once it lapses (deleted lazily on the next read). Writes are atomic
(temp file + `rename`). **Behavior change:** because the default `expire` is 30 seconds, file
entries that previously persisted forever now expire after 30s by default — pass
`['expire' => 0]` to keep entries until you `flush()` them. Files written by older
versions are treated as a miss and regenerated, so no manual purge is needed when upgrading.

**Redis** — requires the `predis/predis` Composer package (`composer require predis/predis`); no PHP extension needed:

```php
$cfg->set_cache('redis://localhost:6379/0', ['expire' => 120, 'namespace' => 'my_app']);
```

Connection parameters are taken from the DSN, including its query string, so any Predis
connection parameter is reachable — e.g. TLS and tuning:

```php
$cfg->set_cache('redis://user:secret@redis.example.com:6379/0?read_write_timeout=2', [
    'namespace' => 'my_app',
]);
```

The same `redis://` DSN targets **Redis 6/7/8 and Valkey 7/8/9** interchangeably; the adapter
is exercised against all six in CI. Values are serialized on write and unserialized on read.
`ActiveRecord\Cache::flush()` deletes only the keys under the configured `namespace`
(via `SCAN`/`DEL`); with no namespace it falls back to `FLUSHDB`, which clears the whole
selected Redis database — set a `namespace` when the Redis instance is shared. **Do not** pass
a Predis `prefix` client option for key isolation: Predis does not apply `prefix` to the plain
`SCAN` command that namespace-scoped `flush()` relies on, so keys end up stored under
`prefix + key` while `flush()` only matches `namespace::*`, silently deleting nothing — use the
`namespace` option instead, which `flush()` already understands.

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

## Examples ##

Rather than inline snippets, every major feature is shown as a **runnable,
self-contained example** under [`examples/`](examples/). Each creates its own
SQLite database and runs with no setup:

```sh
php examples/simple/simple.php
```

| Example | Demonstrates |
|---|---|
| [`simple/`](examples/simple/) | Basic CRUD (find/first/create/update/delete) and convention overrides (`$table_name`, `$primary_key`) |
| [`finders/`](examples/finders/) | Dynamic finders, the `conditions`/`order`/`limit`/`offset`/`group`/`having`/`select` options, `find_by_sql`, static scopes |
| [`validations/`](examples/validations/) | `$validates_*` macros, a custom `validate()`, the `Errors` object |
| [`relationships/`](examples/relationships/) | `belongs_to`, `has_many`, `has_one`, `has_many … through`, eager `include`, `create_*` builders |
| [`callbacks/`](examples/callbacks/) | Lifecycle hooks and halting a save |
| [`attributes/`](examples/attributes/) | Custom `get_*`/`set_*`, `$alias_attribute`, `$attr_accessible`, `$delegate`, dirty tracking |
| [`serialization/`](examples/serialization/) | `to_json` / `to_xml` / `to_array` with `only`/`except`/`methods`/`include` |
| [`upsert/`](examples/upsert/) | `Model::upsert()` — bulk insert-or-update with `unique_by`/`update` and managed timestamps |
| [`orders/`](examples/orders/) | A fuller app combining validations, a callback, and relationships |

See [`examples/README.md`](examples/README.md) for the full index.

## Contributing ##

Please refer to [CONTRIBUTING.md](CONTRIBUTING.md) for information on how to contribute to this fork.

## License ##

MIT — see [LICENSE](LICENSE).
