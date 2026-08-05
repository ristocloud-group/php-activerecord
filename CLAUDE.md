# CLAUDE.md

Guidance for Claude Code in this repo. **These instructions override defaults.**
This file is deliberately lean: it holds the *non-obvious* policy, priorities,
and gotchas. Anything mechanical — exact commands, the CI matrix, per-class
internals, supported versions — **read fresh from the source named inline**
(`composer.json`, `.github/workflows/ci.yml`, `README.md`, `compose.yaml`, the
`lib/*.php` files); don't rely on a summary that can go stale.

## What this is

A fork of `jpfuentes2/php-activerecord` (via `zamzar/php-activerecord`) — a
legacy ActiveRecord-pattern ORM. It is a **library, not an application**: we
vendor it into our monolith and maintain it. Goals: **fix bugs** and **raise
test coverage to 90%**. (Runs on PHP 8.3–8.5 — see `composer.json`.)

## Adapter priorities (judgment — not derivable from the code)

- **MySQL is the primary production target: when a design choice or bug fix
  trades off between adapters, MySQL wins.** Put MySQL-specific behavior in
  `MysqlAdapter`.
- **MariaDB** reuses `MysqlAdapter` (same `mysql://` protocol). **Postgres** and
  **SQLite** are supported and tested — keep them working, don't break them.
- **Oracle (`oci`) was removed in v1.8.0** (incomplete, never used); an `oci://`
  URL now throws a clear `DatabaseException` from
  `Connection::load_adapter_class()`. Do not reintroduce it.
- Supported minimums (policy, *not* enforced by `composer.json`): **MySQL 8+,
  MariaDB 10.11+**. The exact tested versions live in `README.md` /
  `.github/workflows/ci.yml`.

## Working in the repo

Everything runs in Docker; the DB / memcached / redis containers must be up
(`docker compose up -d`). Run tools through the `tests` service:

```sh
docker compose exec tests composer run test      # full suite — the CI gate
docker compose exec tests composer run analyse   # PHPStan (level 8)
docker compose exec tests composer run cs-fix    # apply coding style
```

The `test` / `analyse` / `cs` / `cs-fix` scripts (and their exact flags) are
defined in `composer.json` — read them there. To run one test:
`… vendor/bin/phpunit --filter <Class|test_name>` or `… test/SomeTest.php`.
Gotchas that aren't obvious from the tooling:

- **Skipped tests fail the build** (`--fail-on-skipped`). A test that skips
  because a dependency (a DB, memcached) is unavailable is a *red* build, not a
  pass — design so nothing skips in the Docker environment.
- **The PHPStan baseline (`phpstan-baseline.neon`) is frozen: new code must not
  add suppressions to it.** Achieve green by fixing the code.
- CI (`.github/workflows/ci.yml`) runs the suite across a PHP × DB-version
  matrix. To test another PHP locally, rebuild with
  `docker compose build --build-arg PHP_VERSION=8.4`.

## Architecture (the map; read the files for specifics)

Entry point `ActiveRecord.php` (repo root) is a **manual `require` manifest** of
every `lib/*.php` — **there is no PSR-4 autoloader for the library itself**, so
a new file under `lib/` must be added to `ActiveRecord.php` by hand. Everything
lives in the `ActiveRecord\` namespace, roughly one class per file.

The core flow: a user's **`Model`** subclass — configured via `static` arrays
(`$has_many`, `$belongs_to`, `$validates_*`, `$before_save`/`$after_create`
callbacks, `$attr_accessible`, `$alias_attribute`, …), with dynamic finders and
attribute access via `__call`/`__callStatic`/`__get`/`__set` — delegates to its
**`Table`** (one per class, statically cached; turns finder options
`conditions`/`order`/`limit`/`include`/… into SQL). **Schema is introspected
from the live database at runtime and cached — models never declare columns.**
`Table` runs through **`Connection`** + a `lib/adapters/*Adapter.php` (a thin PDO
wrapper; adapters supply quoting, `LIMIT` syntax, and introspection queries).
Relationships and eager-loading live in `lib/Relationship.php` — **`has_many …
through` is a historical bug hotspot** (see `RELEASES.md` and open issues).
The remaining pieces are self-describing — read the file: `SQLBuilder`,
`Validations` (+`Errors`), `CallBack`, `Serialization`, `Cache`
(`lib/cache/*`), `Config`, `ConnectionManager`, `Inflector`, `Reflections`,
`Expressions`, `Column`, `DateTime`, `Utils`, `Singleton`.

## Conventions that matter when changing code

- **Backward compatibility is a hard gate — STOP and ask before breaking it.**
  This is a library vendored into other applications, so a change that could
  break existing consumers must never be made unilaterally: explicitly ask the
  maintainer whether to proceed, describing the break and who it affects, and
  wait for a yes. This covers (non-exhaustively) renaming/removing/changing the
  signature of any public method, static config array (`$has_many`,
  `$validates_*`, callbacks, …), option key, or property; changing a method's
  return type, thrown-exception type, or observable behavior/defaults; altering
  a connection-string or `Config` format; tightening validation or input
  handling; and raising the PHP floor or a dependency constraint. When unsure
  whether something is breaking, treat it as breaking and ask. (Contrast with
  the intentionally-scoped breaks already shipped — e.g. removing the OCI
  adapter, the file-cache `expire` default — which were explicit, documented
  decisions.)
- **snake_case public API.** Methods and options are snake_case (`find_by_pk`,
  `set_default_connection`, `save`, `is_dirty`) — this mirrors Rails and is the
  library's contract. Do not rename to camelCase. It is a *naming* rule only,
  fully compatible with the modern-PHP requirement below.
- **Modern PHP (≥ 8.3) in the code you write.** Use modern idioms: short arrays
  `[]` (never `array()`), type declarations (params, returns, typed properties
  and class constants), `??` / `?->`, `[$a, $b] = …` destructuring,
  enums/readonly/match where they fit. Applies to README/docs examples too.
- **Coding style: PER-CS 3.0 (4-space indent) + the non-risky PHP 8.3 migration
  set**, enforced by php-cs-fixer via `.php-cs-fixer.dist.php` and gated by
  `composer run cs`; run `composer run cs-fix` to apply. It's formatting only —
  it doesn't relax the rules above, and **it doesn't licence refactoring files
  you aren't otherwise changing (YAGNI)**; in a legacy hot path, prefer minimal,
  behavior-preserving edits.

## Tests

`phpunit.xml` bootstraps `test/helpers/config.php`, which registers connections
from `PHPAR_*` env vars (set in `compose.yaml`, mirrored in CI) and defaults the
connection to `mysql`. Conventions:

- Tests extend **`DatabaseTest`** (→ `SnakeCase_PHPUnit_Framework_TestCase`):
  write `public function test_something()`, `set_up()`/`tear_down()`, and
  snake_case assertions (`assert_equals`, `assert_has_keys`, …) — a `__call`
  camelizes them onto real PHPUnit methods.
- `DatabaseTest::set_up()` drops+recreates tables per protocol from
  `test/sql/<protocol>.sql` and reloads fixtures from `test/fixtures/<table>/`
  every test. Test **schema** → `test/sql/*.sql` (one per adapter); **row data**
  → `test/fixtures/`.
- Test models live in `test/models/Foo.php`, loaded by a bespoke autoloader
  (`test/helpers/model_autoloader.php`), *not* Composer. Adapter-specific tests
  (`MysqlAdapterTest`, `MariadbAdapterTest`, …) extend `AdapterTest`.
- When raising coverage, prefer exercising `Model`/`Table`/`Relationship`/
  `Validations` through model fixtures over unit-testing private helpers — that
  is the grain of the existing suite.
