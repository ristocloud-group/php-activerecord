# Issue #26 — Real cross-adapter test coverage + SQLite eager-load fix

**Date:** 2026-08-06
**Status:** Approved design — pending implementation.
**Author:** Claude (paired with maintainer)
**Issue:** #26
**Branches:** two PRs, both cut from **`master`** (current workspace branch
`spec/typed-relationship-options` is unrelated — do not build on it).

## Context

Issue #26 documents two linked problems:

- **Part A — dead `--adapter` switch / coverage gap.** `test/helpers/config.php`
  hardcodes the default connection to `mysql` and tries to override it by
  scanning PHPUnit's `argv` for `--adapter <name>`. PHPUnit 12.5 rejects
  `--adapter` as an unknown option and exits before the suite runs, so the
  override can never fire. Every `DatabaseTest`-based test (the entire
  *behavioral* surface: `Model`, `Table`, `Relationship`, eager loading,
  `Validations`, `Serialization`, finders, `SQLBuilder`) therefore runs on
  **MySQL only**. CI boots mariadb/postgres services and sets `PHPAR_*` vars but
  never points the behavioral suite at them, so "supported and tested" for
  Postgres/SQLite is currently unproven.

- **Part B — a real SQLite eager-load bug hidden by Part A.**
  `RelationshipTest::test_gh93_and_gh100_eager_loading_respects_association_options`
  fails on SQLite (`0 != 1`) while passing on MySQL/Postgres. It fails on
  `master` today; it has simply never run in CI.

The two parts are causally linked: Part B is the evidence for Part A. Fixing A
is what makes B-class bugs visible; fixing B is the concrete follow-up.

### Root cause of Part B (confirmed by reading the code path)

`Connection::query()` (`lib/Connection.php:334`) binds parameters via
`$sth->execute($values)`, which binds **every** value as `PDO::PARAM_STR`. The
failing model declares `'conditions' => ['length(title) = ?', 14]`; the int `14`
reaches the driver as the string `'14'`. On SQLite, `length(title)` yields an
INTEGER storage class and `'14'` is TEXT; a bare expression carries no column
affinity, so `INTEGER = TEXT` compares across storage classes and is **never
equal** → 0 rows. MySQL and Postgres coerce the operands and match → 1 row. This
is a general SQLite binding defect (any int bound against a non-affinity
expression), not specific to eager loading.

### Empirical verification already done (local Docker)

- All four connections open and introspect locally from the `tests` container
  (mysql 19, mariadb 19, pgsql 19, sqlite 21 tables). Cross-adapter local runs
  are viable.
- **Env var must cross the container boundary with `-e`.** Proven:
  `PHPAR_CONNECTION=sqlite docker compose exec tests …` → `getenv()` returns
  `false` (host-shell prefix is NOT forwarded); `docker compose exec -e
  PHPAR_CONNECTION=sqlite tests …` → `getenv()` returns `"sqlite"`. The issue's
  own repro command used the non-forwarding form.
- `compose.yaml` does **not** define `PHPAR_SQLITE`; config.php falls back to
  `sqlite://test.db`.

## Key decisions

### Decision 1 — CI structure: loop adapters inside existing cells (not a new matrix axis)

The `db.name` matrix (baseline/min/lts/rolling) already pins specific
mysql/mariadb/postgres image versions per cell. We run the behavioral suite once
per adapter **within** each existing cell, as separate named steps
(`Tests (mysql)`, `Tests (mariadb)`, `Tests (pgsql)`, `Tests (sqlite)`), each
with `PHPAR_CONNECTION=<x>`. This keeps job count at 12 (no explosion), gives
per-version coverage for the server DBs, and runs SQLite once per PHP-version
(SQLite has no version axis — no redundancy). Rejected: a `connection` matrix
axis (3×4×4 = 48 jobs, 4× redundant for SQLite).

### Decision 2 — Part B fix altitude: SQLite-scoped typed binding (not shared `query()`)

MySQL is the primary target and BC is a hard gate, so the shared hot path stays
byte-identical. We introduce a `protected` binding seam in `Connection` whose
default preserves today's behavior exactly, and override it **only** in
`SqliteAdapter` to bind int/float/bool/null with the correct `PDO::PARAM_*`.
MySQL and Postgres paths are unchanged. Rejected: typed binding in shared
`query()` (touches every adapter → BC gate, unjustified risk for a SQLite bug).

### Sequencing (matters)

CI cannot be green on SQLite while Part B is broken, and `--fail-on-skipped`
forbids skipping the failing test. Therefore:

- **PR A** ships the env-var selection mechanism + CI steps for
  **mysql/mariadb/pgsql** (all green today). It does **not** add the SQLite CI
  step.
- **PR B** fixes the SQLite binding and **then** adds the `Tests (sqlite)` CI
  step — test, fix, and guard land together (self-guarding).

Combined, the two PRs satisfy the issue's acceptance criteria. PR A must land
first so PR B's SQLite fix is guarded by an already-cross-adapter CI.

## PR A — make cross-adapter coverage real

### A1. `test/helpers/config.php`
Replace the dead `argv` scan with an env-var default connection:

```php
$cfg->set_default_connection(getenv('PHPAR_CONNECTION') ?: 'mysql');
```

Remove the `for` loop over `$GLOBALS['argv']` entirely (both the `--adapter` and
the already-dead `--slow-tests` branches). Keep `$GLOBALS['slow_tests'] = false`
as-is: `--slow-tests` was equally unreachable under modern PHPUnit, so dropping
its branch changes nothing observable, and reviving slow tests is out of scope
for #26. MySQL stays the default, so existing invocations are unchanged.

### A2. `.github/workflows/ci.yml`
Split the single `Tests` step into named per-adapter steps, each exporting
`PHPAR_CONNECTION` inline (CI has no Docker boundary, so an inline prefix
forwards correctly):

- `Tests (mysql)` — carries `--coverage-clover coverage.xml` (single source for
  the existing coverage upload; other adapters run without coverage to keep it
  simple).
- `Tests (mariadb)`, `Tests (pgsql)` — `PHPAR_CONNECTION=mariadb|pgsql composer
  run test`.
- **No `Tests (sqlite)` yet** (added in PR B).

Static-analysis / coding-style steps are unchanged.

### A3. `compose.yaml`
Add `PHPAR_SQLITE=sqlite://test.db` to the `tests` service `environment:` for
parity with CI and explicitness (currently relies on config.php's fallback).

### A4. Fixture / schema drift
`--fail-on-skipped` means every fixture must load on every adapter. Running the
behavioral suite on mariadb/pgsql will surface any adapter-specific
fixture/schema drift; fix such drift **in this PR** (that is the point of the
coverage work). Test schema lives in `test/sql/<adapter>.sql`, row data in
`test/fixtures/`.

### A5. Docs
Add a short note to `CLAUDE.md` (and/or `README.md`) documenting local
cross-adapter invocation with the **`-e` flag** (host-shell prefixes are not
forwarded into the container):

```sh
docker compose exec -e PHPAR_CONNECTION=sqlite tests composer run test
```

## PR B — fix the SQLite eager-load bug + activate it in CI

### B1. `lib/Connection.php`
Extract the bind/execute into an overridable `protected` seam, default behavior
identical:

```php
protected function bind_values(\PDOStatement $sth, array $values): bool
{
    return $sth->execute($values);
}
```

`query()` calls `$this->bind_values($sth, $values)` instead of
`$sth->execute($values)`. No behavior change for any adapter.

### B2. `lib/adapters/SqliteAdapter.php`
Override `bind_values` to bind by position with a type derived from the PHP
value (`int` → `PDO::PARAM_INT`, `bool` → `PDO::PARAM_BOOL`, `null` →
`PDO::PARAM_NULL`, everything else → `PDO::PARAM_STR`), then `execute()`. This
fixes `length(title) = 14` and every int-against-expression condition on SQLite.
Float has no dedicated PDO type — bind as `PARAM_STR` (SQLite parses numeric
text with REAL affinity in arithmetic contexts); document the choice inline.

### B3. Test (TDD)
`test_gh93_and_gh100_eager_loading_respects_association_options` must pass on
SQLite while staying green on MySQL and Postgres. Verify empirically against all
three adapters in Docker before claiming done:

```sh
for a in mysql pgsql sqlite; do
  docker compose exec -e PHPAR_CONNECTION=$a tests \
    vendor/bin/phpunit --filter test_gh93_and_gh100_eager_loading_respects_association_options
done
```

### B4. `.github/workflows/ci.yml`
Add the `Tests (sqlite)` step (`PHPAR_CONNECTION=sqlite composer run test`),
completing the four-adapter behavioral coverage.

## Testing / verification

- Local, per adapter: `docker compose exec -e PHPAR_CONNECTION=<adapter> tests
  composer run test` (full suite is the gate). PR A: mysql/mariadb/pgsql green.
  PR B: all four green, with explicit MySQL/Postgres re-run to prove the typed
  binding does not change their results.
- `composer run analyse` (PHPStan level 8) and `composer run cs` stay green; no
  new baseline entries.

## Acceptance criteria (from the issue)

**Part A**
- [ ] Default test connection selectable without a dead flag (env var); MySQL
      remains the default.
- [ ] CI runs the full `DatabaseTest` suite against MySQL, MariaDB, Postgres,
      and SQLite (SQLite lands with PR B per the sequencing above).
- [ ] The dead `--adapter` argv branch is removed from `config.php`.

**Part B**
- [ ] `test_gh93_and_gh100_eager_loading_respects_association_options` passes on
      SQLite and stays green on MySQL/Postgres.
- [ ] Root cause identified (SQLite binds params as TEXT via `execute($values)`;
      fixed by adapter-scoped typed binding).

## Out of scope
- Reviving `--slow-tests` as an env var.
- Typed binding in the shared `Connection::query()` path (would be a BC change
  across all adapters).
