# Issue #26 — Cross-adapter coverage + SQLite eager-load fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the behavioral test suite runnable and CI-gated on all four
adapters (Part A), then fix the SQLite eager-load binding bug that this exposes
(Part B).

**Architecture:** Two sequenced PRs. PR A (Tasks A1–A2) replaces the dead
`--adapter` argv switch with a `PHPAR_CONNECTION` env var and runs the suite
per-adapter in CI for mysql/mariadb/pgsql. PR B (Tasks B1–B3) adds an
overridable binding seam to `Connection`, overrides it in `SqliteAdapter` to
bind params with correct PDO types (fixing `length()=int` on SQLite), then adds
the SQLite CI step. PR A lands first so PR B's fix is guarded.

**Tech Stack:** PHP 8.3+, PDO, PHPUnit 12.5, php-cs-fixer (PER-CS 3.0), PHPStan
level 8, Docker Compose, GitHub Actions.

## Global Constraints

- Branch off **`master`**: `spec/issue26-cross-adapter-coverage` already exists
  (holds the design doc); do all implementation on it. Two logical PRs, one
  branch is acceptable, or split at the A/B boundary — maintainer's call.
- **Backward compatibility is a hard gate.** MySQL is the primary target; its
  code path must stay byte-identical. No public API / option / signature change.
- **snake_case** public API; **modern PHP ≥ 8.3** idioms (short arrays, typed
  params/returns); PER-CS 3.0 formatting via `composer run cs-fix`.
- **Skipped tests fail the build** (`--fail-on-skipped`): every fixture must
  load on every adapter.
- **PHPStan baseline is frozen** — no new suppressions; achieve green by fixing
  code. `composer run analyse` (level 8) and `composer run cs` must stay green.
- Everything runs in Docker via the `tests` service. Env vars reach the
  container **only** via `docker compose exec -e VAR=val` — a host-shell prefix
  (`VAR=val docker compose exec …`) is NOT forwarded (verified).
- The `tests` container mounts `.:/code`, so on-disk edits are live (no rebuild).

---

## PHASE A — Real cross-adapter coverage (PR A)

### Task A1: Env-var connection selection + parity config + local-run docs

**Files:**
- Modify: `test/helpers/config.php:53-61`
- Modify: `compose.yaml` (`tests` service `environment:` block)
- Modify: `CLAUDE.md` ("Working in the repo" section)

**Interfaces:**
- Consumes: existing `Config::set_default_connection(string)`, connections
  `mysql|mariadb|pgsql|sqlite` already registered in `config.php:47-51`.
- Produces: the default test connection is now selected by env var
  `PHPAR_CONNECTION` (default `'mysql'`); the dead `--adapter`/`--slow-tests`
  argv loop is gone. No new symbols.

- [ ] **Step 1: Verify the current state (baseline)**

Run: `docker compose exec -e PHPAR_CONNECTION=pgsql tests php -r 'require "test/helpers/config.php"; echo ActiveRecord\Config::instance()->get_default_connection_string();'`
Expected: prints the **mysql** URL — proving the env var is currently ignored
(the dead argv switch never fires). This is the bug we are fixing.

- [ ] **Step 2: Replace the dead argv scan in `config.php`**

Replace lines 53-61 (the `set_default_connection('mysql')` call and the entire
`for` loop over `$GLOBALS['argv']`) with:

```php
    $cfg->set_default_connection(getenv('PHPAR_CONNECTION') ?: 'mysql');
```

Leave `$GLOBALS['slow_tests'] = false;` (line 37) untouched: `--slow-tests` was
equally unreachable under modern PHPUnit, so removing its branch changes nothing
observable, and reviving slow tests is out of scope for #26.

- [ ] **Step 3: Verify the env var now selects the connection**

Run: `docker compose exec -e PHPAR_CONNECTION=pgsql tests php -r 'require "test/helpers/config.php"; echo ActiveRecord\Config::instance()->get_default_connection_string();'`
Expected: now prints the **pgsql** URL. Re-run with no `-e` flag → prints the
**mysql** URL (default preserved).

- [ ] **Step 4: Add `PHPAR_SQLITE` to `compose.yaml` for parity**

In the `tests` service `environment:` list, add alongside the other `PHPAR_*`
entries:

```yaml
      - PHPAR_SQLITE=sqlite://test.db
```

- [ ] **Step 5: Document local cross-adapter runs in `CLAUDE.md`**

In the "Working in the repo" section, after the three example commands, add:

```markdown
- **Run the suite against another adapter** by selecting the default connection
  with `PHPAR_CONNECTION` (`mysql` is the default). The env var must be passed
  with `-e` so it crosses into the container — a host-shell prefix is NOT
  forwarded:
  ```sh
  docker compose exec -e PHPAR_CONNECTION=sqlite tests composer run test
  ```
```

- [ ] **Step 6: Run the full behavioral suite on MariaDB (surface drift)**

Run: `docker compose exec -e PHPAR_CONNECTION=mariadb tests composer run test`
Expected: PASS with no skips. If `--fail-on-skipped` trips or a fixture/schema
mismatch appears, fix the drift here — schema in `test/sql/mariadb.sql` (falls
back to mysql protocol), row data in `test/fixtures/`. Record any fix in the
commit message.

- [ ] **Step 7: Run the full behavioral suite on Postgres (surface drift)**

Run: `docker compose exec -e PHPAR_CONNECTION=pgsql tests composer run test`
Expected: PASS with no skips. Fix any Postgres-specific fixture/schema drift in
`test/sql/pgsql.sql` / `test/fixtures/`.

- [ ] **Step 8: Confirm MySQL still green + style/analysis**

Run: `docker compose exec tests composer run test`
Run: `docker compose exec tests composer run cs`
Run: `docker compose exec tests composer run analyse`
Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add test/helpers/config.php compose.yaml CLAUDE.md test/sql test/fixtures
git commit -m "feat: select test connection via PHPAR_CONNECTION env var

Replace the dead --adapter argv switch (rejected by PHPUnit 12.5) with a
PHPAR_CONNECTION env var so the behavioral suite can run against any adapter;
mysql stays the default. Add PHPAR_SQLITE to compose.yaml for parity and
document the -e invocation. Fix any mariadb/pgsql fixture drift surfaced by
--fail-on-skipped."
```

---

### Task A2: Per-adapter CI steps (mysql / mariadb / pgsql)

**Files:**
- Modify: `.github/workflows/ci.yml:100-101` (the single `Tests` step)

**Interfaces:**
- Consumes: `PHPAR_CONNECTION` selection from Task A1; job-level `env:` block
  already exports `PHPAR_MYSQL/MARIADB/PGSQL/SQLITE`.
- Produces: three named test steps. SQLite is intentionally **not** added yet
  (Task B3 adds it, once the bug is fixed — the suite would be red on SQLite).

- [ ] **Step 1: Replace the single `Tests` step with three named steps**

Replace lines 100-101:

```yaml
      - name: Tests
        run: composer run test -- --coverage-clover coverage.xml
```

with (CI has no Docker boundary, so an inline env prefix forwards correctly):

```yaml
      - name: Tests (mysql)
        run: PHPAR_CONNECTION=mysql composer run test -- --coverage-clover coverage.xml

      - name: Tests (mariadb)
        run: PHPAR_CONNECTION=mariadb composer run test

      - name: Tests (pgsql)
        run: PHPAR_CONNECTION=pgsql composer run test
```

Coverage-clover is generated on the mysql step only (single source for the
existing upload step at `ci.yml:103-108`, which is unchanged). Steps run
sequentially; `fail-fast: false` at the matrix level keeps other cells running.

- [ ] **Step 2: Validate the workflow YAML**

Run: `docker compose exec tests php -r 'var_dump(yaml_parse_file(".github/workflows/ci.yml") !== false);'`
if `yaml` ext is present; otherwise eyeball indentation (2-space, steps aligned
with the existing `Upload coverage` step).
Expected: parses / visually consistent.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: run behavioral suite per-adapter (mysql, mariadb, pgsql)

Split the single Tests step into named per-adapter steps driven by
PHPAR_CONNECTION so MariaDB and Postgres are actually exercised at the ORM
level, not just booted. SQLite is added in a follow-up once its eager-load
bug is fixed."
```

- [ ] **Step 4 (optional): Push and confirm CI is green**

```bash
git push -u origin spec/issue26-cross-adapter-coverage
```
Watch the run: `gh run watch` (or `gh run list --branch spec/issue26-cross-adapter-coverage`).
Expected: mysql/mariadb/pgsql steps green across the PHP × db-version matrix.

---

## PHASE B — Fix the SQLite eager-load bug (PR B)

### Task B1: Add an overridable bind seam to `Connection` (no behavior change)

**Files:**
- Modify: `lib/Connection.php:334` and add a new protected method nearby.

**Interfaces:**
- Consumes: nothing new.
- Produces: `protected function bind_values(\PDOStatement $sth, array $values):
  bool` on `Connection` — default returns `$sth->execute($values)`. `query()`
  calls `$this->bind_values($sth, $values)`. Subclasses may override to change
  parameter binding. Signature relied on by Task B2.

- [ ] **Step 1: Confirm the full suite is green on MySQL first (guard the refactor)**

Run: `docker compose exec tests composer run test`
Expected: PASS. This is the byte-identical-behavior baseline.

- [ ] **Step 2: Extract the bind seam**

In `lib/Connection.php`, change line 334 inside `query()` from:

```php
            if (!$sth->execute($values)) {
```
to:
```php
            if (!$this->bind_values($sth, $values)) {
```

Then add this protected method to the `Connection` class (e.g. immediately
after `query()`):

```php
    /**
     * Bind the positional values and execute the prepared statement.
     *
     * Default binding delegates to PDOStatement::execute(), which binds every
     * value as PDO::PARAM_STR. Adapters may override to bind by type.
     *
     * @param array<int, mixed> $values Positional bind values
     */
    protected function bind_values(\PDOStatement $sth, array $values): bool
    {
        return $sth->execute($values);
    }
```

- [ ] **Step 3: Run the full suite on MySQL (behavior unchanged)**

Run: `docker compose exec tests composer run test`
Run: `docker compose exec tests composer run analyse`
Expected: PASS — the refactor is pure extraction, no observable change.

- [ ] **Step 4: Commit**

```bash
git add lib/Connection.php
git commit -m "refactor: extract Connection::bind_values seam over execute()

Pure extraction — default behavior (execute(), all params as PARAM_STR) is
unchanged for every adapter. Lets an adapter override parameter binding."
```

---

### Task B2: Type-aware binding in `SqliteAdapter` (fixes the eager-load bug)

**Files:**
- Modify: `lib/adapters/SqliteAdapter.php` (add `bind_values` override)
- Test: `test/RelationshipTest.php:560`
  (`test_gh93_and_gh100_eager_loading_respects_association_options`, already
  present — used as the failing test)

**Interfaces:**
- Consumes: `Connection::bind_values(\PDOStatement, array): bool` from Task B1.
- Produces: overridden `bind_values` on `SqliteAdapter` binding `int` →
  `PDO::PARAM_INT`, `bool` → `PDO::PARAM_BOOL`, `null` → `PDO::PARAM_NULL`, else
  `PDO::PARAM_STR`.

- [ ] **Step 1: Run the existing test on SQLite to see it fail (RED)**

Run: `docker compose exec -e PHPAR_CONNECTION=sqlite tests vendor/bin/phpunit --filter test_gh93_and_gh100_eager_loading_respects_association_options`
Expected: FAIL — `Failed asserting that 0 matches expected 1`. (The
`assert_sql_has` assertion passes; the row-count assertion fails.) This is the
Part B bug: `length(title) = ?` bound as the string `'14'` never matches on
SQLite's storage-class comparison.

- [ ] **Step 2: Confirm it passes on MySQL and Postgres (scope the fix)**

Run: `docker compose exec -e PHPAR_CONNECTION=mysql tests vendor/bin/phpunit --filter test_gh93_and_gh100_eager_loading_respects_association_options`
Run: `docker compose exec -e PHPAR_CONNECTION=pgsql tests vendor/bin/phpunit --filter test_gh93_and_gh100_eager_loading_respects_association_options`
Expected: both PASS — proving the bug is SQLite-only and the fix must not touch
their path.

- [ ] **Step 3: Add the typed-binding override to `SqliteAdapter`**

Add to `SqliteAdapter` (e.g. after the constructor):

```php
    /**
     * Bind positional params by PHP type so numeric predicates work under
     * SQLite's dynamic typing. The default (PARAM_STR for everything) makes
     * `length(col) = 14` bind `'14'`; SQLite compares INTEGER vs TEXT across
     * storage classes and never matches. MySQL/Postgres coerce and are handled
     * by the base implementation — this override is SQLite-only.
     *
     * Floats have no dedicated PDO param type; PARAM_STR is correct (SQLite
     * applies REAL affinity to numeric text in arithmetic contexts).
     *
     * @param array<int, mixed> $values Positional bind values
     */
    protected function bind_values(\PDOStatement $sth, array $values): bool
    {
        foreach ($values as $i => $value) {
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                null === $value => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $sth->bindValue($i + 1, $value, $type);
        }

        return $sth->execute();
    }
```

Note: positional PDO params are 1-indexed, so bind at `$i + 1`.

- [ ] **Step 4: Run the failing test on SQLite (GREEN)**

Run: `docker compose exec -e PHPAR_CONNECTION=sqlite tests vendor/bin/phpunit --filter test_gh93_and_gh100_eager_loading_respects_association_options`
Expected: PASS.

- [ ] **Step 5: Prove no regression on MySQL and Postgres**

Run: `docker compose exec -e PHPAR_CONNECTION=mysql tests vendor/bin/phpunit --filter test_gh93_and_gh100_eager_loading_respects_association_options`
Run: `docker compose exec -e PHPAR_CONNECTION=pgsql tests vendor/bin/phpunit --filter test_gh93_and_gh100_eager_loading_respects_association_options`
Expected: both still PASS (their code path is untouched by an SQLite override).

- [ ] **Step 6: Run the full SQLite suite (catch wider binding effects)**

Run: `docker compose exec -e PHPAR_CONNECTION=sqlite tests composer run test`
Expected: PASS with no skips. Typed binding can shift other SQLite results
(e.g. a test that relied on stringified binding); investigate and fix any
fallout here — this is the first time the full suite runs on SQLite.

- [ ] **Step 7: Full suite on MySQL + analysis + style**

Run: `docker compose exec tests composer run test`
Run: `docker compose exec tests composer run analyse`
Run: `docker compose exec tests composer run cs`
Expected: all PASS.

- [ ] **Step 8: Commit**

```bash
git add lib/adapters/SqliteAdapter.php
git commit -m "fix: bind params by PHP type in SqliteAdapter (gh93/gh100 eager load)

SQLite compares across storage classes, so a numeric predicate like
length(title) = ? bound as PARAM_STR ('14') never matches. Bind int/bool/null
with their PDO param types; MySQL/Postgres are untouched. Fixes the eager-load
association-options test that only ever ran on MySQL before #26."
```

---

### Task B3: Add the SQLite CI step

**Files:**
- Modify: `.github/workflows/ci.yml` (per-adapter Tests steps from Task A2)

**Interfaces:**
- Consumes: the SQLite fix from Task B2; `PHPAR_SQLITE` already in the job `env:`.
- Produces: a `Tests (sqlite)` CI step — completes four-adapter coverage.

- [ ] **Step 1: Add the SQLite step after `Tests (pgsql)`**

```yaml
      - name: Tests (sqlite)
        run: PHPAR_CONNECTION=sqlite composer run test
```

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: run behavioral suite on SQLite

Now that the eager-load binding bug is fixed, add SQLite to the per-adapter
test steps — completing the mysql/mariadb/pgsql/sqlite behavioral coverage
that #26 called for."
```

- [ ] **Step 3: Push and confirm all four adapters are green in CI**

```bash
git push
gh run watch
```
Expected: mysql/mariadb/pgsql/sqlite steps green across the full matrix.

---

## Self-Review

**Spec coverage:**
- Part A env-var selection → Task A1 (steps 2-3). ✅
- Remove dead `--adapter` argv branch → Task A1 step 2. ✅
- MySQL remains default → Task A1 step 3. ✅
- CI runs suite on mysql/mariadb/pgsql → Task A2; sqlite → Task B3. ✅
- `PHPAR_SQLITE` parity in compose → Task A1 step 4. ✅
- `-e` local-invocation doc → Task A1 step 5. ✅
- Fixture/schema drift handling → Task A1 steps 6-7. ✅
- Part B root cause (PARAM_STR binding) → Task B1/B2 rationale. ✅
- SQLite test green, MySQL/PG unregressed → Task B2 steps 4-5. ✅
- BC hard gate (MySQL byte-identical) → Task B1 (default seam unchanged),
  Task B2 (SQLite-only override). ✅

**Placeholder scan:** No TBD/TODO; every code step has literal code. ✅

**Type consistency:** `bind_values(\PDOStatement $sth, array $values): bool`
defined identically in Task B1 (base) and overridden with the same signature in
Task B2. `PHPAR_CONNECTION` spelled consistently across A1/A2/B3. ✅

## Notes / risks
- Task B2 step 6 is the real unknown: the full SQLite suite has never run
  before. Budget time for adapter-specific fixture drift and for tests that
  implicitly depended on string binding. If a large fallout appears, split it
  into its own follow-up commit rather than bloating the fix commit.
- If `yaml` PHP extension is absent (Task A2 step 2), validate the workflow by
  pushing to a branch and reading `gh run` output instead.
