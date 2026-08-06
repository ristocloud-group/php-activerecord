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

## Revised scope (2026-08-06, after Task A1 — maintainer ruling)

Task A1 empirically surfaced that **Postgres is genuinely broken at the ORM
level today** (11 failures + 4 structural skips), NOT fixture/schema drift. The
plan's premise that PR A could turn on `Tests (pgsql)` green was false. Per
maintainer ruling, the scope is revised:

- **PR A CI covers `mysql` + `mariadb` only** (both green). `pgsql` is deferred.
  Task A2 no longer adds a `Tests (pgsql)` step.
- **SQLite** stays in scope via PR B (Tasks B1–B3), CI step added in B3.
- **Two low-risk Postgres-surfaced fixes are folded in** as new tasks:
  - **Task C2** — `Expressions::build_sql_from_hash()` emits `IS ?` (bound
    NULL); fix to literal `IS NULL`. Real lib bug, MySQL-safe (matches what
    PDO_MYSQL emulated-prepares already send on the wire). Fixes 4 PG failures.
  - **Task C3** — `test_update_all_with_set_as_string/_hash` assert a
    MySQL-only "rows changed" count; make adapter-agnostic. Test-only.
- **Deferred to a follow-up issue** (Task F opens it): Cat 1 (case-sensitive
  quoted-identifier round-trip in Table/SQLBuilder), Cat 4 (`Model::find()`
  PK-vs-string ambiguity), Cat 5 (structural skips: no `UPDATE/DELETE … ORDER
  BY LIMIT` in Postgres — a test-architecture decision that gates pgsql CI
  regardless of 1–4). Full analysis in the A1 report and the follow-up issue.

Task order: A1 (done) → C2 → C3 → A2 → B1 → B2 → B3 → F.

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

### Task A2: Per-adapter CI steps (mysql / mariadb) — pgsql deferred

**Files:**
- Modify: `.github/workflows/ci.yml:100-101` (the single `Tests` step)

**Interfaces:**
- Consumes: `PHPAR_CONNECTION` selection from Task A1; job-level `env:` block
  already exports `PHPAR_MYSQL/MARIADB/PGSQL/SQLITE`.
- Produces: two named test steps (`mysql`, `mariadb`). **`pgsql` is NOT added**
  (Postgres is red today — deferred to the follow-up issue per the revised
  scope). **SQLite is NOT added yet** (Task B3 adds it once the bug is fixed).

- [ ] **Step 1: Replace the single `Tests` step with two named steps**

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
```

Coverage-clover is generated on the mysql step only (single source for the
existing upload step at `ci.yml:103-108`, which is unchanged). Steps run
sequentially; `fail-fast: false` at the matrix level keeps other cells running.
Do NOT add `Tests (pgsql)` — Postgres has pre-existing ORM failures (see the
revised-scope section and Task F); adding it would make CI red.

- [ ] **Step 2: Validate the workflow YAML**

Run: `docker compose exec tests php -r 'var_dump(yaml_parse_file(".github/workflows/ci.yml") !== false);'`
if `yaml` ext is present; otherwise eyeball indentation (2-space, steps aligned
with the existing `Upload coverage` step).
Expected: parses / visually consistent.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: run behavioral suite per-adapter (mysql, mariadb)

Split the single Tests step into named per-adapter steps driven by
PHPAR_CONNECTION so MariaDB is actually exercised at the ORM level, not just
booted. Postgres is deferred (pre-existing ORM failures, tracked separately);
SQLite is added once its eager-load bug is fixed."
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

- [ ] **Step 1: Add the SQLite step after `Tests (mariadb)`**

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
Expected: mysql/mariadb/sqlite steps green across the full matrix (pgsql not in
CI — deferred per revised scope).

---

## PHASE C — Postgres-surfaced low-risk fixes (folded into #26)

These two fixes were surfaced by Task A1's Postgres run. They are correctness
improvements that are MySQL-safe; they do NOT make pgsql CI green on their own
(Cat 1/4/5 remain — see Task F), so no pgsql CI step is added.

### Task C2: Fix `Expressions::build_sql_from_hash()` null handling (`IS ?` → `IS NULL`)

**Files:**
- Modify: `lib/Expressions.php:183-203` (`build_sql_from_hash`)
- Test: `test/ExpressionsTest.php` (add a unit assertion) — verified end-to-end
  by existing `test_find_hash_using_alias_with_null`,
  `ModelCallbackTest::test_destroy`,
  `test_belongs_to_combined_hash_mixes_in_equality_and_is_null`,
  `test_belongs_to_combined_hash_works_lazily_too`.

**Interfaces:**
- Consumes: nothing new.
- Produces: hash conditions with a `null` value now render `"$name IS NULL"` as
  a literal predicate with **no** bind marker and **no** corresponding entry in
  the returned values list (positional alignment preserved).

**Root cause:** the current code emits `"$name IS ?"` and returns
`array_values($hash)` — i.e. it binds the `null` as a positional param. MySQL's
default emulated prepares substitute `?`→`NULL` client-side, so the wire SQL is
already `IS NULL`; Postgres uses real prepares and rejects `IS $1`
(`syntax error`). Emitting the literal makes both adapters send identical SQL.

- [ ] **Step 1: Write the failing unit test (RED)**

Add to `test/ExpressionsTest.php`:

```php
public function test_build_sql_from_hash_renders_null_as_literal_is_null()
{
    $expressions = new ActiveRecord\Expressions(null, ['id' => null]);
    $this->assert_equals('id IS NULL', $expressions->to_s());
    // null must NOT be a bound value — it is inlined as a literal predicate
    $this->assert_equals([], $expressions->values());
}
```

If `Expressions` has no public `values()` accessor, assert only the `to_s()`
string and drop the second assertion.

- [ ] **Step 2: Run it to confirm it fails**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_build_sql_from_hash_renders_null_as_literal_is_null`
Expected: FAIL — current output is `id IS ?` with a bound `[null]`.

- [ ] **Step 3: Fix `build_sql_from_hash`**

Replace the method body's SQL-building loop and return so null values emit a
literal and are excluded from the bound values:

```php
    private function build_sql_from_hash(array &$hash, string $glue): array
    {
        $sql = $g = "";
        $values = [];

        foreach ($hash as $name => $value) {
            if ($this->connection) {
                $name = $this->connection->quote_name((string) $name);
            }

            if (is_array($value)) {
                $sql .= "$g$name IN(?)";
                $values[] = $value;
            } elseif (is_null($value)) {
                $sql .= "$g$name IS NULL";
            } else {
                $sql .= "$g$name=?";
                $values[] = $value;
            }

            $g = $glue;
        }

        return [$sql, $values];
    }
```

(The only changes: build `$values` explicitly instead of `array_values($hash)`,
emit `IS NULL` literal for null, and skip pushing null to `$values`.)

- [ ] **Step 4: Run the unit test (GREEN)**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_build_sql_from_hash_renders_null_as_literal_is_null`
Expected: PASS.

- [ ] **Step 5: Full suite on MySQL + MariaDB + analysis/style**

Run: `docker compose exec tests composer run test`
Run: `docker compose exec -e PHPAR_CONNECTION=mariadb tests composer run test`
Run: `docker compose exec tests composer run analyse`
Run: `docker compose exec tests composer run cs`
Expected: all PASS. **Watch for any test that asserted the old `IS ?` string
form** (e.g. `assert_sql_has`) — if one exists, its expectation was the buggy
form; update it to `IS NULL` and note it in the report.

- [ ] **Step 6: Confirm the 4 Postgres failures are fixed (partial)**

Run: `docker compose exec -e PHPAR_CONNECTION=pgsql tests vendor/bin/phpunit --filter 'test_find_hash_using_alias_with_null|test_destroy|test_belongs_to_combined_hash_mixes_in_equality_and_is_null|test_belongs_to_combined_hash_works_lazily_too'`
Expected: these 4 now PASS on Postgres (overall pgsql suite still red on Cat
1/4/5 — that is expected and out of scope).

- [ ] **Step 7: Commit**

```bash
git add lib/Expressions.php test/ExpressionsTest.php
git commit -m "fix: emit literal IS NULL for null hash conditions in Expressions

build_sql_from_hash bound the null as a positional param (IS ?), which only
worked because PDO_MYSQL emulated prepares rewrite it to IS NULL on the wire;
Postgres real-prepares reject IS \$1. Emit the literal IS NULL (matching the
string-conditions path already in SQLBuilder) so all adapters send identical
SQL. Surfaced by the cross-adapter suite in #26."
```

---

### Task C3: Make `update_all` affected-count tests adapter-agnostic

**Files:**
- Modify: `test/ActiveRecordWriteTest.php`
  (`test_update_all_with_set_as_string`, `test_update_all_with_set_as_hash`)

**Interfaces:**
- Consumes: `authors` fixture — rows have `parent_author_id` ∈ {3,2,1,2}; value
  `4` is a valid `author_id` (row 4) used by no row as a parent.
- Produces: both tests update to a value that genuinely changes **all four**
  rows, so MySQL "rows changed" and Postgres "rows matched" both report `4`.

**Root cause:** MySQL's PDO reports rows *changed* for UPDATE (excludes
already-equal rows); Postgres reports rows *matched* (SQL standard). The tests
set `parent_author_id = 2` (2 of 4 rows already had 2) and asserted `2`, a
MySQL-only value. Updating to `4` (no row currently has it) makes all four rows
change, so both drivers agree on `4`.

- [ ] **Step 1: Update both tests**

Replace the two test bodies with:

```php
    public function test_update_all_with_set_as_string()
    {
        // parent_author_id 4 (a valid author_id) is unused as a parent, so all
        // four rows genuinely change — MySQL "changed" and Postgres "matched"
        // row counts then agree (avoids driver-specific rowCount semantics).
        $num_affected = Author::update_all(['set' => 'parent_author_id = 4']);
        $this->assert_equals(4, $num_affected);
        $this->assert_equals(4, Author::count_by_parent_author_id(4));
    }

    public function test_update_all_with_set_as_hash()
    {
        $num_affected = Author::update_all(['set' => ['parent_author_id' => 4]]);
        $this->assert_equals(4, $num_affected);
        $this->assert_equals(4, Author::count_by_parent_author_id(4));
    }
```

- [ ] **Step 2: Run on MySQL (GREEN)**

Run: `docker compose exec tests vendor/bin/phpunit --filter 'test_update_all_with_set_as_string|test_update_all_with_set_as_hash'`
Expected: PASS (both rows now assert 4).

- [ ] **Step 3: Run on MariaDB and Postgres**

Run: `docker compose exec -e PHPAR_CONNECTION=mariadb tests vendor/bin/phpunit --filter 'test_update_all_with_set_as_string|test_update_all_with_set_as_hash'`
Run: `docker compose exec -e PHPAR_CONNECTION=pgsql tests vendor/bin/phpunit --filter 'test_update_all_with_set_as_string|test_update_all_with_set_as_hash'`
Expected: PASS on both (this is the fix's whole point).

- [ ] **Step 4: Full MySQL suite + style**

Run: `docker compose exec tests composer run test`
Run: `docker compose exec tests composer run cs`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add test/ActiveRecordWriteTest.php
git commit -m "test: make update_all affected-count tests adapter-agnostic

The tests asserted 2 affected rows — MySQL's rows-changed count (2 of 4 rows
already held the target value). Postgres reports rows-matched (4). Update to a
value no row currently holds so all four rows change and both drivers agree,
without depending on driver-specific rowCount semantics. Surfaced by #26."
```

---

## PHASE F — Follow-up tracking

### Task F: Open the Postgres follow-up issue

**Files:** none (GitHub issue via `gh`).

**Interfaces:**
- Consumes: the A1 report (`.superpowers/sdd/.../task-A1-report.md`) categories.
- Produces: a tracking issue for the deferred Postgres work.

- [ ] **Step 1: Open the issue**

```bash
gh issue create --title "Postgres ORM-level failures surfaced by #26 cross-adapter coverage" --body "$(cat <<'EOF'
Enabling the behavioral suite on Postgres (#26) surfaced pre-existing
ORM-level failures. #26 fixed the low-risk ones (Expressions null handling;
adapter-agnostic update_all count tests) and deferred the rest here. Postgres
is NOT yet in CI; it will be added once these are resolved.

## Blockers to a green pgsql CI run

**Cat 1 — case-sensitive quoted-identifier round-trip (4 tests).**
`Table` lowercases introspected column names for PHP attributes, then
`Table`/`SQLBuilder` reuse the lowercased name as a *quoted* SQL identifier.
Harmless on MySQL (case-insensitive identifiers); on Postgres a column declared
`"some_Date"` no longer matches the generated `"some_date"`. Needs an
attribute-name → real-column-name mapping. Tests: `test_flag_dirty`,
`test_set_date_flags_dirty`, `test_set_date_flags_dirty_with_php_datetime`,
`test_datefield_gets_converted_to_ar_datetime`.

**Cat 4 — `Model::find()` PK-vs-conditions-string ambiguity (1 test).**
`Author::first('name = 123123123')` is routed to `find_by_pk` and bound as an
int PK. MySQL coerces the bad string to 0 (→ RecordNotFound, as the test
expects); PDO_PGSQL throws `22P02 invalid input syntax for integer`. Root cause
is the legacy single-string-arg ambiguity in `find()`/`find_by_pk()`. BC-
sensitive. Test: `test_find_nothing_with_sql_in_string`.

**Cat 5 — structural skips: no `UPDATE/DELETE … ORDER BY LIMIT` in Postgres (4 tests).**
`PgsqlAdapter` correctly inherits `accepts_limit_and_order_for_update_and_delete()
= false`, so these tests `mark_test_skipped`. With `--fail-on-skipped`, any
pgsql CI run is red regardless of Cat 1/4. **This gates pgsql CI independently.**
Decide one of: (a) exclude these tests on Postgres without counting a skip
(adapter-specific test class / data provider filter); (b) emulate via a `ctid`
subquery in `PgsqlAdapter` (a real behavior addition); (c) relax
`--fail-on-skipped` for the pgsql run (weakens the guard). Tests:
`test_delete_all_with_limit_and_order`, `test_update_all_with_limit_and_order`
(`ActiveRecordWriteTest`), `test_update_with_limit_and_order`,
`test_delete_with_limit_and_order` (`SQLBuilderTest`).

## Acceptance
- [ ] Cat 5 test-architecture decision made and implemented.
- [ ] Cat 1 fixed (mixed-case columns work on Postgres).
- [ ] Cat 4 resolved or explicitly documented as known behavior.
- [ ] `Tests (pgsql)` added to CI and green across the matrix.

Full analysis: see #26 branch `spec/issue26-cross-adapter-coverage`, Task A1 report.
EOF
)"
```

- [ ] **Step 2: Record the issue number in the ledger and (optionally) reference it in a comment on #26.**

---

## Self-Review

**Spec coverage:**
- Part A env-var selection → Task A1 (steps 2-3). ✅
- Remove dead `--adapter` argv branch → Task A1 step 2. ✅
- MySQL remains default → Task A1 step 3. ✅
- CI runs suite on mysql/mariadb → Task A2; sqlite → Task B3; pgsql deferred to
  Task F (revised scope after A1). ✅
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
