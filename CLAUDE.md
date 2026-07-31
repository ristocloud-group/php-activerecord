# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A fork (`zamzar/php-activerecord`, forked from `jpfuentes2/php-activerecord`) of a legacy ActiveRecord-pattern ORM. It is a **library**, not an application. We vendor it into our monolith and maintain it.

Project goals for this fork:
- Fix bugs.
- ~~Upgrade the codebase to run on PHP 8.5~~ — **done**: `composer.json` requires `^8.3`, and CI runs the full suite on 8.3, 8.4, and 8.5.
- Raise test coverage to **90%**.

Adapter support / priorities:
- **MySQL** — the primary production target. When a design choice or bug fix trades off between adapters, MySQL wins.
- **MariaDB** — supported and tested. It reuses `MysqlAdapter` (its connection string uses the `mysql://` protocol, same as MySQL); `MariadbAdapterTest` extends `MysqlAdapterTest` and runs the same battery.
- **Postgres** and **SQLite** — supported and tested (Postgres in CI, SQLite used heavily in tests). Keep them working; don't break them.
- **Oracle (`oci`)** — **removed in v1.8.0.** The adapter was incomplete and never used. A residual `oci://` connection string now throws a clear `DatabaseException` from `Connection::load_adapter_class()`. Do not reintroduce it.

## Commands

Everything runs inside Docker (the `tests` service has PHP + all PDO extensions + memcached). The MySQL/MariaDB/Postgres/memcached containers must be up because most tests hit a real database.

```sh
docker compose up -d                                    # boot mysql, mariadb, postgres, memcached, tests
docker compose exec tests composer run test             # full suite (this is the CI gate)
docker compose exec tests vendor/bin/phpunit --filter CacheTest        # one test class
docker compose exec tests vendor/bin/phpunit test/InflectorTest.php    # one file
docker compose exec tests vendor/bin/phpunit --filter test_name        # one method
docker compose exec tests composer run analyse                         # PHPStan (level 5) static analysis
docker compose exec tests composer run cs        # PHP-CS-Fixer dry-run (CI style gate)
docker compose exec tests composer run cs-fix    # apply the coding style
```

`composer run test` = `phpunit --fail-on-risky --fail-on-warning --fail-on-skipped --testdox` (see `composer.json` for the full flag set, including JUnit output). **Skipped tests fail the build** — a test that skips because a dependency (e.g. memcached, a DB) is unavailable is a red build, not a pass. Design work so nothing skips in the Docker environment.

Coverage isn't wired into `phpunit.xml` as an always-on report, but the `tests` image ships pcov and CI collects coverage on every matrix job. To measure it locally:
```sh
docker compose exec tests vendor/bin/phpunit --coverage-text --coverage-html storage/coverage
```

Testing against another PHP version — rebuild the image with the build arg, then run:
```sh
docker compose build --build-arg PHP_VERSION=8.4 && docker compose up -d
```
CI (`.github/workflows/ci.yml`, GitHub Actions) runs a matrix per push: PHP 8.3, 8.4, 8.5, each with MySQL, MariaDB, Postgres, and memcached as service containers (SQLite needs no service). The 8.3 job also runs `composer run analyse` (PHPStan, level 5, with a frozen baseline in `phpstan-baseline.neon` — new code must not add suppressions to it). `compose.yaml`'s `tests` service defaults its build arg `PHP_VERSION` to `8.3`.

## Architecture

Entry point is `ActiveRecord.php` at the repo root (Composer autoloads it via `autoload.files`). It is a manual `require` manifest of every `lib/*.php` file — **there is no PSR-4 class autoloader for the library itself**, so a new file under `lib/` must be added to `ActiveRecord.php` by hand. Everything lives in the `ActiveRecord\` namespace.

The object model, and how a `Model` subclass turns into SQL:

- **`Model`** (`lib/Model.php`, ~1300 lines — the core) is the base class users extend. Instances wrap a single row. It holds no schema knowledge itself; it delegates to its `Table`. Configuration is expressed as `static` arrays on the subclass: `$has_many`, `$belongs_to`, `$has_one`, `$has_many` `through`, `$validates_*`, `$before_save`/`$after_create`/... callbacks, `$attr_accessible`/`$attr_protected`, `$alias_attribute`, etc. Dynamic finders (`find_by_name_and_id(...)`) and attribute magic are implemented through `__call`/`__callStatic`/`__get`/`__set`.
- **`Table`** (`lib/Table.php`) — one instance per model class, cached statically (`Table::load($class)` / `Table::clear_cache()`). Owns the `Connection`, the primary key, the column metadata, the callback object, and relationship definitions. This is where finder options (`conditions`, `order`, `limit`, `include`, ...) get assembled into queries. Schema is **introspected from the live database at runtime and cached** — models never declare columns.
- **`Connection`** (`lib/Connection.php`) + `lib/adapters/*Adapter.php` — a thin PDO wrapper. `Connection::instance($url)` parses a `protocol://user:pass@host/db` URL (`parse_connection_url`) and instantiates `ucwords($protocol).'Adapter'` (`load_adapter_class`). Adapters (`MysqlAdapter`, `PgsqlAdapter`, `SqliteAdapter`) supply the DB-specific bits: quoting, `LIMIT` syntax, and schema-introspection queries (`columns()`, `tables()`). Put MySQL-specific behavior in `MysqlAdapter`.
- **`ConnectionManager`** (`lib/ConnectionManager.php`) — singleton registry of open connections keyed by name.
- **`Config`** (`lib/Config.php`) — singleton holding the named connection strings, the default connection, logger, and cache. Configured via `Config::initialize(fn($cfg) => ...)`.
- **`SQLBuilder`** (`lib/SQLBuilder.php`) — programmatic SELECT/INSERT/UPDATE/DELETE builder used by `Table`.
- **`Relationship`** (`lib/Relationship.php`) — `HasMany`/`HasOne`/`BelongsTo`/`HasAndBelongsToMany` classes implementing loading + eager-loading (`include`). `has_many ... through` chains live here and have historically been a bug hotspot (see RELEASES.md).
- **`Validations`** (`lib/Validations.php`) + `Errors` — Rails-style validation macros, run on save.
- **`CallBack`** (`lib/CallBack.php`) — resolves and fires lifecycle hooks around save/create/update/delete/find.
- **`Serialization`** (`lib/Serialization.php`) — `to_json`/`to_xml`/`to_array`.
- **`Cache`** (`lib/Cache.php`, `lib/cache/{Memcache,File}.php`) — optional caching of introspected schema. `File` is our fork's addition for hosts without memcached.
- Supporting singletons/utilities: `Reflections`, `Inflector` (pluralize/camelize — drives table-name and dynamic-finder conventions), `Expressions`, `Column`, `DateTime`, `Utils`, `Singleton`.

### Conventions that matter when changing code

- **Backward compatibility is a hard gate — STOP and ask before breaking it.** This is a library vendored into other applications, so a change that could break existing consumers must never be made unilaterally: explicitly ask the maintainer whether to proceed, describing the break and who it affects, and wait for a yes. This covers (non-exhaustively) renaming/removing/changing the signature of any public method, static config array (`$has_many`, `$validates_*`, callbacks, …), option key, or property; changing a method's return type, thrown-exception type, or observable behavior/defaults; altering a connection-string or `Config` format; tightening validation or input handling; and raising the PHP floor or a dependency constraint. When unsure whether something is breaking, treat it as breaking and ask. (Contrast with the intentionally-scoped breaks already shipped — e.g. removing the OCI adapter, the file-cache `expire` default — which were explicit, documented decisions.)
- **snake_case public API.** Methods and options are snake_case (`find_by_pk`, `set_default_connection`, `save`, `is_dirty`) — this mirrors Rails and is the library's contract. Do not rename to camelCase. snake_case is a *naming* rule, not a licence to write dated PHP: it is fully compatible with the modern-PHP requirement below.
- **Modern PHP (>= 8.3) in the code you write.** The library requires `^8.3`, so new code and any code you author must use modern PHP idioms: short array syntax `[]` (never `array()`), type declarations (parameters, return types, typed properties, typed class constants), null coalescing (`??`) / nullsafe (`?->`), `list()`-destructuring as `[$a, $b] = …`, first-class enums/readonly/match where they fit, etc. Applies equally to README/docs examples — show modern PHP, not PHP 5.x-era code.
- **Coding style is PER-CS 3.0 (4-space indentation) plus the non-risky PHP 8.3 migration set.** Enforced by `friendsofphp/php-cs-fixer` via `.php-cs-fixer.dist.php` (rulesets `@PER-CS3x0` + `@PHP8x3Migration`, `setRiskyAllowed(false)`), and checked by a blocking CI step (`composer run cs`) on the 8.3 job. Run `composer run cs-fix` to apply it. This is formatting/syntactic modernization only — it doesn't relax the snake_case public API rule above or the modern-PHP rule below, and it doesn't licence refactoring files you aren't otherwise changing (YAGNI); when editing a legacy hot path, prefer minimal, behavior-preserving edits.

## Tests

`phpunit.xml` bootstraps `test/helpers/config.php`, which registers connections from env vars (`PHPAR_MYSQL`, `PHPAR_MARIADB`, `PHPAR_PGSQL`, `PHPAR_SQLITE`, `PHPAR_MEMCACHED` — set in `compose.yaml`, and mirrored for CI in `.github/workflows/ci.yml`) and sets `mysql` as the default connection.

- **Test base class:** tests extend `DatabaseTest` (→ `SnakeCase_PHPUnit_Framework_TestCase`), which lets tests be written with `set_up()`/`tear_down()` and snake_case assertions (`assert_equals`, `assert_has_keys`, ...). The base's `__call` camelizes unknown snake_case calls onto real PHPUnit methods. So a new test method is `public function test_something()` and setup is `set_up()`, not `setUp()`.
- **DB state:** `DatabaseTest::set_up()` clears the `Table` cache, then `DatabaseLoader` drops+recreates tables **once per protocol** from `test/sql/<protocol>.sql` and reloads fixture rows from `test/fixtures/<table>/` on every test. Schema changes for tests go in `test/sql/*.sql` (one file per adapter); row data goes in `test/fixtures/`.
- **Test models** live in `test/models/` and are loaded by a bespoke autoloader (`test/helpers/model_autoloader.php`), *not* Composer — a new fixture model is a class in `test/models/Foo.php` matching its class name.
- **Adapter-specific tests** (`MysqlAdapterTest`, `MariadbAdapterTest`, `PgsqlAdapterTest`, `SqliteAdapterTest`) extend a shared `AdapterTest` and run the same battery against each backend by overriding the connection name.

When raising coverage, prefer exercising `Model`/`Table`/`Relationship`/`Validations` behavior through model fixtures over unit-testing private helpers; that is the grain of the existing suite.
