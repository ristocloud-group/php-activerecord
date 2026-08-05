# Optimize `Model::exists()` (avoid `COUNT(*)`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `Model::exists()` emit a short-circuiting `SELECT EXISTS(SELECT 1 …)` instead of delegating to `COUNT(*)`, improving latency/resource use while keeping its `boolean` contract.

**Architecture:** Follow the existing object model — `Model` parses args and builds finder options, `Table` turns options into SQL, the `Connection`/adapter supplies the DB-specific `EXISTS` wrapper. A new adapter hook `exists_sql()` centralizes the one per-backend difference (Postgres returns a boolean, normalized to `1/0` via `::int`).

**Tech Stack:** PHP 8.3+, PDO, PHPUnit 12, PHPStan (level 8), PHP-CS-Fixer (PER-CS 3.0). Everything runs in the project's Docker image.

## Global Constraints

- **PHP floor:** `^8.3`. Use modern idioms (typed params/returns, `[]` arrays, `??`). Public API stays **snake_case**. (verbatim from spec)
- **Backward compatibility (hard gate):** `exists()` MUST still return `boolean`; `count()` behavior MUST be unchanged; the extracted helper MUST be `private`. The only observable change allowed is the SQL text `exists()` emits.
- **PHPStan:** `composer run analyse` (level 8, over `lib` and `examples`) MUST pass. Do **not** add entries to `phpstan-baseline.neon`.
- **Style:** `composer run cs` MUST be clean (run `composer run cs-fix` to apply).
- **Test gate:** `composer run test` = `phpunit --fail-on-risky --fail-on-warning --fail-on-skipped --testdox`. Nothing may skip.
- **No trailing `;` in generated SQL** — the codebase never appends one (verified: adapter `limit()` etc.). `exists_sql()` must not either.
- **Verification harness (worktree is invisible to `docker compose exec`):** run each command in a one-off container that mounts this worktree over `/code` and the primary checkout's `vendor/` over `/code/vendor`. The DB/redis/memcached service containers must already be up (`docker compose up -d` in the primary checkout). Template:

  ```sh
  docker run --rm --network php-activerecord_default \
    -e PHPAR_MYSQL=mysql://phpar:secret@mysql/phpar_test \
    -e PHPAR_MARIADB=mysql://phpar:secret@mariadb/phpar_test \
    -e PHPAR_PGSQL=pgsql://phpar:secret@postgres/phpar_test \
    -e PHPAR_MEMCACHED=memcached -e PHPAR_REDIS=redis://redis:6379 \
    -v /Users/nicolasvaccari/dev/ristocloudgroup/php-activerecord-exists:/code \
    -v /Users/nicolasvaccari/dev/ristocloudgroup/php-activerecord/vendor:/code/vendor \
    -w /code php-activerecord-tests:latest \
    <command>
  ```
  Below, `RUN <command>` means "execute `<command>` via that template".

---

### Task 1: Adapter `exists_sql()` hook

Wrap an inner `SELECT` in an existence check yielding a single `1/0` scalar row. Default is correct for MySQL/MariaDB/SQLite; Postgres overrides to cast its boolean to int. Tested through the shared `AdapterTest` battery so it runs on **all four** backends (this is what actually verifies the Postgres path).

**Files:**
- Modify: `lib/Connection.php` (add method, near `query_and_fetch_one`, ~line 355)
- Modify: `lib/adapters/PgsqlAdapter.php` (add override, near `limit`, ~line 38)
- Test: `test/helpers/AdapterTest.php` (add one method to the shared battery)

**Interfaces:**
- Produces: `Connection::exists_sql(string $inner): string` — returns a complete query (`"SELECT EXISTS($inner)"`, `"…::int"` on Postgres). Consumed by Task 2.

- [ ] **Step 1: Write the failing test** in `test/helpers/AdapterTest.php`

```php
public function test_exists_sql_yields_integer_scalar()
{
    // at least one author exists -> 1
    $sql = $this->conn->exists_sql('SELECT 1 FROM authors');
    $this->assert_same(1, (int) $this->conn->query_and_fetch_one($sql));

    // no matching row -> 0. Also proves Postgres' boolean is normalized to an
    // integer here rather than coming back as the string 't'/'f'.
    $none = $this->conn->exists_sql(
        'SELECT 1 FROM authors WHERE ' . $this->conn->quote_name('author_id') . ' = -1'
    );
    $this->assert_same(0, (int) $this->conn->query_and_fetch_one($none));
}
```

- [ ] **Step 2: Run the test to verify it fails**

RUN `vendor/bin/phpunit test/MysqlAdapterTest.php --filter test_exists_sql_yields_integer_scalar`
Expected: FAIL — `Call to undefined method ActiveRecord\MysqlAdapter::exists_sql()`.

- [ ] **Step 3: Implement the default in `lib/Connection.php`**

Add near `query_and_fetch_one`:

```php
/**
 * Wrap an inner SELECT in an existence check the database can short-circuit
 * at the first matching row. Returns a query that yields a single scalar
 * 1/0 row (so it fits query_and_fetch_one()). Adapters whose EXISTS() does
 * not already yield an integer override this to normalize to 1/0.
 *
 * @param string $inner a complete inner SELECT, e.g. "SELECT 1 FROM t WHERE …"
 */
public function exists_sql(string $inner): string
{
    return "SELECT EXISTS($inner)";
}
```

- [ ] **Step 4: Implement the Postgres override in `lib/adapters/PgsqlAdapter.php`**

```php
public function exists_sql(string $inner): string
{
    // Postgres EXISTS() returns a boolean (t/f); cast so the scalar is 1/0.
    return "SELECT EXISTS($inner)::int";
}
```

- [ ] **Step 5: Run the test on every adapter to verify it passes**

RUN `vendor/bin/phpunit --filter test_exists_sql_yields_integer_scalar test/MysqlAdapterTest.php test/MariadbAdapterTest.php test/PgsqlAdapterTest.php test/SqliteAdapterTest.php`
Expected: PASS (4 classes). The Postgres pass confirms `::int` normalization.

- [ ] **Step 6: Commit**

```bash
git add lib/Connection.php lib/adapters/PgsqlAdapter.php test/helpers/AdapterTest.php
git commit -m "feat: add Connection::exists_sql() adapter hook (pgsql casts to int)"
```

---

### Task 2: `Table::exists()`

Turn a finder options array into an `EXISTS` query and run it, returning a bool. Reuses `options_to_sql()` (which already handles `select`/`conditions`) and the Task 1 hook.

**Files:**
- Modify: `lib/Table.php` (add method after `find`, ~line 294)
- Test: `test/ActiveRecordFindTest.php` (add a Table-level test)

**Interfaces:**
- Consumes: `Connection::exists_sql(string): string` (Task 1); `Table::options_to_sql(array): SQLBuilder`, `Table::connection(): Connection` (existing).
- Produces: `Table::exists(array<string,mixed> $options): bool`. Consumed by Task 3.

- [ ] **Step 1: Write the failing test** in `test/ActiveRecordFindTest.php`

```php
public function test_table_exists_returns_bool_via_exists_query()
{
    $table = Author::table();
    $this->assert_true($table->exists(['select' => '1', 'conditions' => ['author_id' => 1]]));
    $this->assert_false($table->exists(['select' => '1', 'conditions' => ['author_id' => -1]]));

    // it must NOT be a COUNT
    $this->assert_sql_has('EXISTS', $this->conn->last_query);
    $this->assert_sql_doesnt_has('COUNT', $this->conn->last_query);
}
```

- [ ] **Step 2: Run the test to verify it fails**

RUN `vendor/bin/phpunit test/ActiveRecordFindTest.php --filter test_table_exists_returns_bool_via_exists_query`
Expected: FAIL — `Call to undefined method ActiveRecord\Table::exists()`.

- [ ] **Step 3: Implement `Table::exists()`** in `lib/Table.php` (after `find()`)

```php
/**
 * Whether any row matches $options, without counting: emits an EXISTS query
 * that stops at the first matching row.
 *
 * @param array<string, mixed> $options
 */
public function exists($options): bool
{
    $conn = $this->connection();
    $sql = $this->options_to_sql($options);
    $values = $sql->get_where_values();

    return (bool) (int) $conn->query_and_fetch_one($conn->exists_sql($sql->to_s()), $values);
}
```

- [ ] **Step 4: Run the test to verify it passes**

RUN `vendor/bin/phpunit test/ActiveRecordFindTest.php --filter test_table_exists_returns_bool_via_exists_query`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add lib/Table.php test/ActiveRecordFindTest.php
git commit -m "feat: add Table::exists() using the EXISTS adapter hook"
```

---

### Task 3: Rewrite `Model::exists()` + extract shared args helper

Stop delegating `exists()` to `count()`. Extract the (unchanged) argument-parsing logic into a private helper both use, so they can't drift; rewrite `exists()` to build minimal options and call `Table::exists()`. `count()` keeps emitting `COUNT(*)`.

**Files:**
- Modify: `lib/Model.php` — `count()` (~lines 1484-1502), `exists()` (~lines 1516-1519); add private helper.
- Test: `test/ActiveRecordFindTest.php` (extend existing `test_exists`; add SQL-shape + edge tests)

**Interfaces:**
- Consumes: `Table::exists(array): bool` (Task 2); `Model::extract_and_validate_options(array &$): array`, `Model::pk_conditions($args): array`, `Model::table()`, `Model::connection()` (existing).
- Produces: unchanged public `Model::exists(): bool`, `Model::count(): int|string`; new `private static Model::finder_conditions_from_args(list<mixed> $args): array<string,mixed>`.

- [ ] **Step 1: Write the failing test** in `test/ActiveRecordFindTest.php`

```php
public function test_exists_emits_exists_not_count()
{
    Author::exists(1);
    $this->assert_sql_has('EXISTS', $this->conn->last_query);
    $this->assert_sql_doesnt_has('COUNT', $this->conn->last_query);
}

public function test_exists_no_args_checks_table_not_empty()
{
    $this->assert_true(Author::exists());
}

public function test_exists_true_when_many_rows_match()
{
    // several authors share a non-null parent_author_id; existence is still true
    $this->assert_true(Author::exists(['conditions' => 'parent_author_id IS NOT NULL']));
}
```

- [ ] **Step 2: Run the test to verify it fails**

RUN `vendor/bin/phpunit test/ActiveRecordFindTest.php --filter test_exists_emits_exists_not_count`
Expected: FAIL — `Failed asserting that a string contains "EXISTS"` (current `exists()` still emits `COUNT`).

- [ ] **Step 3: Add the private helper** in `lib/Model.php` (place just above `count()`)

```php
/**
 * Build a validated finder options array from the variadic count()/exists()
 * arguments: a bare primary-key value, a conditions hash, or a trailing
 * options hash. This is the parsing count() has always done, factored out so
 * count() and exists() cannot diverge.
 *
 * @param list<mixed> $args
 * @return array<string, mixed>
 */
private static function finder_conditions_from_args(array $args): array
{
    $options = static::extract_and_validate_options($args); // by-ref: pops trailing options hash

    if (!empty($args) && !is_null($args[0]) && !empty($args[0])) {
        if (is_hash($args[0])) {
            $options['conditions'] = $args[0];
        } else {
            $options['conditions'] = call_user_func_array([static::class, 'pk_conditions'], $args);
        }
    }

    return $options;
}
```

- [ ] **Step 4: Rewrite `count()`** in `lib/Model.php` to use the helper (behavior identical)

```php
public static function count(/* ... */)
{
    $options = self::finder_conditions_from_args(func_get_args());
    $options['select'] = 'COUNT(*)';

    $table = static::table();
    $sql = $table->options_to_sql($options);
    $values = $sql->get_where_values();
    return static::connection()->query_and_fetch_one($sql->to_s(), $values);
}
```

- [ ] **Step 5: Rewrite `exists()`** in `lib/Model.php`

```php
public static function exists(/* ... */)
{
    $options = self::finder_conditions_from_args(func_get_args());

    // Existence only needs the WHERE clause; select a constant and drop
    // ordering/paging/grouping so the database can stop at the first match.
    $exists_options = ['select' => '1'];
    if (isset($options['conditions'])) {
        $exists_options['conditions'] = $options['conditions'];
    }

    return static::table()->exists($exists_options);
}
```

- [ ] **Step 6: Run the new + existing exists/count tests to verify they pass**

RUN `vendor/bin/phpunit test/ActiveRecordFindTest.php --filter 'exists|count'`
Expected: PASS — includes the pre-existing `test_exists`, `test_count*`, and the three new tests.

- [ ] **Step 7: Commit**

```bash
git add lib/Model.php test/ActiveRecordFindTest.php
git commit -m "feat: Model::exists() uses EXISTS instead of COUNT(*)"
```

---

### Task 4: Full gates + empirical benchmark

Confirm the whole suite, static analysis, and style pass across the changes, and record the performance evidence the maintainer expects.

**Files:**
- Modify: `docs/superpowers/specs/2026-08-05-optimize-model-exists-design.md` (append a "Benchmark results" section)

**Interfaces:** none (validation only).

- [ ] **Step 1: Run the full suite (CI gate)**

RUN `composer run test`
Expected: `OK` with no risky/warning/skipped; test count increased by the new tests.

- [ ] **Step 2: Run PHPStan**

RUN `composer run analyse`
Expected: `[OK] No errors`. If a null-safety error appears on `Table::exists`, ensure it uses `$this->connection()` (non-null accessor), not `$this->conn`.

- [ ] **Step 3: Run the style check**

RUN `composer run cs`
Expected: `Found 0 of N files that can be fixed`. If not, RUN `composer run cs-fix` and re-run.

- [ ] **Step 4: Benchmark COUNT vs EXISTS on MySQL**

Write `test/scratch_exists_bench.php` (NOT committed) that: opens the mysql connection, creates a temp table `bench_rows(id INT PRIMARY KEY AUTO_INCREMENT, flag INT, INDEX(flag))`, inserts ~100k rows all with `flag = 1`, then:
- `EXPLAIN SELECT COUNT(*) FROM bench_rows WHERE flag = 1` vs `EXPLAIN SELECT EXISTS(SELECT 1 FROM bench_rows WHERE flag = 1)`
- times each query ~1000× and prints avg ms.

RUN `php test/scratch_exists_bench.php`
Expected: EXISTS shows EXPLAIN `rows` ≈ 1 and lower/equal time; COUNT scans all ~100k. Record both EXPLAINs and timings.

- [ ] **Step 5: Record results and clean up**

Append the EXPLAIN output + timings to the design doc under a new "## Benchmark results" heading. Delete the scratch script.

```bash
rm test/scratch_exists_bench.php
git add docs/superpowers/specs/2026-08-05-optimize-model-exists-design.md
git commit -m "docs(spec): record COUNT-vs-EXISTS benchmark results"
```

- [ ] **Step 6: Push and open the PR**

```bash
git push -u origin feat/optimize-model-exists
```
Open a PR to `master`. In the body: summarize the change, paste the benchmark, and flag the BC note (only the emitted SQL changes; `exists()` stays `bool`).

---

## Self-Review

**Spec coverage:**
- EXISTS query shape + Postgres `::int` → Task 1. ✓
- `Table::exists()` reusing `options_to_sql` → Task 2. ✓
- `Model::exists()` rewrite + shared `finder_conditions_from_args` helper, `count()` unchanged → Task 3. ✓
- No-args / pk / hash / conditions edge cases → Task 3 tests. ✓
- Minimal inner options (drop order/limit/group) → Task 3 Step 5. ✓
- Regression tests stay green → Task 3 Step 6 (`--filter 'exists|count'`). ✓
- Per-adapter coverage → Task 1 Step 5 (all four adapter test classes). ✓
- Empirical benchmark → Task 4. ✓
- BC gate → Global Constraints + Task 4 Step 6. ✓

**Placeholder scan:** no TBD/TODO; every code step has real code. ✓

**Type consistency:** `exists_sql(string): string` used identically in Tasks 1→2; `Table::exists(array): bool` defined Task 2, called Task 3; `finder_conditions_from_args(array): array` defined and called in Task 3. `query_and_fetch_one`'s by-ref `$values` is always passed a variable. ✓
