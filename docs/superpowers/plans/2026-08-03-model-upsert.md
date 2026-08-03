# Model::upsert() Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a bulk, Eloquent-faithful `Model::upsert()` that inserts-or-updates many rows in one atomic operation across MySQL/MariaDB, Postgres and SQLite.

**Architecture:** `Model::upsert()` delegates to `Table::upsert()`, which validates input, injects timestamps, resolves the update column list, converts values via the existing `process_data()`, chunks the rows to stay under each adapter's bind-parameter limit, and executes each chunk through `SQLBuilder`. The per-dialect conflict clause lives on `Connection` (base = standard `ON CONFLICT … EXCLUDED`, used by Postgres/SQLite) with a single `MysqlAdapter` override (`ON DUPLICATE KEY UPDATE … VALUES(col)`, covering MariaDB too since it reuses `MysqlAdapter`). Multi-chunk runs are wrapped in a transaction.

**Tech Stack:** PHP 8.3+, PDO, PHPUnit (snake_case `DatabaseTest` harness), the library's manual `require` autoload manifest (`ActiveRecord.php`).

**Spec:** `docs/superpowers/specs/2026-08-03-model-upsert-design.md` — read it before starting.

## Global Constraints

- **PHP floor:** `^8.3`. Use modern PHP idioms (short arrays `[]`, type declarations, `??`, `match`, union types like `array|string`). Never `array()`.
- **Public API is snake_case:** the method is `upsert`, its option is `$unique_by` (not `uniqueBy`).
- **Coding style:** PER-CS 3.0 (4-space indent) + `@PHP8x3Migration`. Run `docker compose exec tests composer run cs-fix` before each commit; the CI gate is `composer run cs`.
- **Backward compatibility:** this is purely additive — do not change any existing signature, return type, or behavior.
- **No new PHPStan baseline suppressions:** new code must pass `composer run analyse` (level 5) without adding to `phpstan-baseline.neon`.
- **No skipped tests:** in the Docker environment every test must run and pass (`composer run test` uses `--fail-on-skipped`).
- **New `lib/` files must be added to `ActiveRecord.php`** by hand. (This plan adds none — everything extends existing files.)
- **Adapter priority:** MySQL is the primary target. MariaDB reuses `MysqlAdapter`.
- **MySQL/MariaDB dialect:** use `ON DUPLICATE KEY UPDATE col = VALUES(col)` (portable across MySQL 8+ and MariaDB 10.11+; the `AS new` alias syntax is **not** supported by MariaDB — verified).
- **All commands run in Docker:** prefix with `docker compose exec tests`.

---

## File Structure

**Modified (library):**
- `lib/Connection.php` — new `upsert_conflict_clause()`; new `public static $MAX_BIND_PARAMS = 65535`.
- `lib/adapters/MysqlAdapter.php` — override `upsert_conflict_clause()`; redeclare `$MAX_BIND_PARAMS = 65535`.
- `lib/adapters/PgsqlAdapter.php` — redeclare `$MAX_BIND_PARAMS = 65535`.
- `lib/adapters/SqliteAdapter.php` — redeclare `$MAX_BIND_PARAMS = 999`.
- `lib/SQLBuilder.php` — new `upsert()` setter + `build_upsert()`.
- `lib/Table.php` — new `upsert()`.
- `lib/Model.php` — new `public static function upsert()`.

**Modified (tests / infra):**
- `test/SQLBuilderTest.php` — `build_upsert()` unit tests.
- `phpunit.xml` — exclude the new abstract `test/helpers/UpsertTest.php`.

**Created (tests):**
- `test/helpers/UpsertTest.php` — abstract base holding all cross-adapter upsert tests (extends `DatabaseTest`).
- `test/MysqlUpsertTest.php`, `test/MariadbUpsertTest.php`, `test/PgsqlUpsertTest.php`, `test/SqliteUpsertTest.php` — thin subclasses selecting the connection (mirrors the `AdapterTest` pattern).

**Created (docs / examples — Task 7):**
- `examples/upsert/upsert.sql`, `examples/upsert/models/Flight.php`, `examples/upsert/upsert.php`.
- `README.md` (new `### Upsert`), `CLAUDE.md` (min-version note).

---

## Task 1: Per-adapter bind-parameter limit

Foundation for chunking (Task 5). Each concrete adapter declares its **own** static so tests can lower it in isolation without contaminating sibling adapters.

**Files:**
- Modify: `lib/Connection.php` (add static near the top of the class, by the other properties)
- Modify: `lib/adapters/MysqlAdapter.php`, `lib/adapters/PgsqlAdapter.php`, `lib/adapters/SqliteAdapter.php`
- Test: `test/MysqlAdapterTest.php`, `test/PgsqlAdapterTest.php`, `test/SqliteAdapterTest.php`

**Interfaces:**
- Produces: `Connection::$MAX_BIND_PARAMS` (int static, default 65535); `SqliteAdapter::$MAX_BIND_PARAMS = 999`. Read at runtime as `$this->conn::$MAX_BIND_PARAMS`.

- [ ] **Step 1: Write the failing test (SQLite = 999)**

Add to `test/SqliteAdapterTest.php`:

```php
public function test_max_bind_params_is_conservative()
{
    $this->assert_equals(999, $this->conn::$MAX_BIND_PARAMS);
}
```

Add to `test/MysqlAdapterTest.php`:

```php
public function test_max_bind_params_default()
{
    $this->assert_equals(65535, $this->conn::$MAX_BIND_PARAMS);
}
```

Add to `test/PgsqlAdapterTest.php`:

```php
public function test_max_bind_params_default()
{
    $this->assert_equals(65535, $this->conn::$MAX_BIND_PARAMS);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_max_bind_params`
Expected: FAIL — undefined static property `$MAX_BIND_PARAMS`.

- [ ] **Step 3: Add the statics**

In `lib/Connection.php`, among the class's other declared properties, add:

```php
    /**
     * Maximum number of bind parameters allowed in a single prepared statement.
     * Used by Table::upsert() to chunk large batches. Each concrete adapter
     * declares its own so tests can override one adapter without affecting others.
     */
    public static $MAX_BIND_PARAMS = 65535;
```

In `lib/adapters/MysqlAdapter.php` (next to `$DEFAULT_PORT`):

```php
    public static $MAX_BIND_PARAMS = 65535;
```

In `lib/adapters/PgsqlAdapter.php` (next to `$DEFAULT_PORT`):

```php
    public static $MAX_BIND_PARAMS = 65535;
```

In `lib/adapters/SqliteAdapter.php` (next to the other statics):

```php
    // SQLITE_MAX_VARIABLE_NUMBER is 999 before SQLite 3.32.0 and 32766 after,
    // and is not readily queryable via PDO — use the safe lower bound.
    public static $MAX_BIND_PARAMS = 999;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_max_bind_params`
Expected: PASS (all three adapter subclasses).

- [ ] **Step 5: Style + commit**

```bash
docker compose exec tests composer run cs-fix
git add lib/Connection.php lib/adapters/ test/MysqlAdapterTest.php test/PgsqlAdapterTest.php test/SqliteAdapterTest.php
git commit -m "feat(upsert): add per-adapter bind-parameter limit"
```

---

## Task 2: Per-dialect conflict clause

**Files:**
- Modify: `lib/Connection.php` (base method)
- Modify: `lib/adapters/MysqlAdapter.php` (override)
- Test: `test/PgsqlAdapterTest.php` (or SQLite — a `Connection` subclass using the base), `test/MysqlAdapterTest.php`

**Interfaces:**
- Produces: `Connection::upsert_conflict_clause(array $unique, array $update): string`
  - Base (Postgres/SQLite): `ON CONFLICT (<quoted unique cols>) DO UPDATE SET <q> = EXCLUDED.<q>, …`
  - `MysqlAdapter` override: `ON DUPLICATE KEY UPDATE <q> = VALUES(<q>), …` (ignores `$unique`)

- [ ] **Step 1: Write the failing tests**

Add to `test/PgsqlAdapterTest.php`:

```php
public function test_upsert_conflict_clause_uses_on_conflict_excluded()
{
    $clause = $this->conn->upsert_conflict_clause(['name', 'address'], ['city', 'phone']);

    $name  = $this->conn->quote_name('name');
    $addr  = $this->conn->quote_name('address');
    $city  = $this->conn->quote_name('city');
    $phone = $this->conn->quote_name('phone');

    $this->assert_equals(
        "ON CONFLICT ($name, $addr) DO UPDATE SET $city = EXCLUDED.$city, $phone = EXCLUDED.$phone",
        $clause
    );
}
```

Add to `test/MysqlAdapterTest.php`:

```php
public function test_upsert_conflict_clause_uses_on_duplicate_key_update()
{
    // MySQL/MariaDB ignore the unique columns and use the table's indexes.
    $clause = $this->conn->upsert_conflict_clause(['ignored'], ['city', 'phone']);

    $city  = $this->conn->quote_name('city');
    $phone = $this->conn->quote_name('phone');

    $this->assert_equals(
        "ON DUPLICATE KEY UPDATE $city = VALUES($city), $phone = VALUES($phone)",
        $clause
    );
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_upsert_conflict_clause`
Expected: FAIL — `Call to undefined method upsert_conflict_clause()`.

- [ ] **Step 3: Implement the base method**

In `lib/Connection.php`, near `quote_name()`:

```php
    /**
     * Builds the standard-SQL conflict clause for an upsert (used by Postgres and
     * SQLite). MysqlAdapter overrides this with ON DUPLICATE KEY UPDATE.
     *
     * @param string[] $unique Column names forming the conflict target
     * @param string[] $update Column names to overwrite on conflict
     * @return string
     */
    public function upsert_conflict_clause(array $unique, array $update): string
    {
        $target = implode(', ', array_map([$this, 'quote_name'], $unique));

        $sets = [];
        foreach ($update as $column) {
            $q = $this->quote_name($column);
            $sets[] = "$q = EXCLUDED.$q";
        }

        return "ON CONFLICT ($target) DO UPDATE SET " . implode(', ', $sets);
    }
```

- [ ] **Step 4: Implement the MySQL override**

In `lib/adapters/MysqlAdapter.php`:

```php
    /**
     * MySQL/MariaDB ignore the conflict target and rely on the table's PRIMARY/
     * UNIQUE indexes. VALUES(col) is the portable form: the AS-alias syntax added
     * in MySQL 8.0.19 is not supported by MariaDB, which reuses this adapter.
     *
     * @param string[] $unique Ignored on MySQL/MariaDB
     * @param string[] $update Column names to overwrite on conflict
     * @return string
     */
    public function upsert_conflict_clause(array $unique, array $update): string
    {
        $sets = [];
        foreach ($update as $column) {
            $q = $this->quote_name($column);
            $sets[] = "$q = VALUES($q)";
        }

        return 'ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
    }
```

- [ ] **Step 5: Run to verify they pass**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_upsert_conflict_clause`
Expected: PASS.

- [ ] **Step 6: Style + commit**

```bash
docker compose exec tests composer run cs-fix
git add lib/Connection.php lib/adapters/MysqlAdapter.php test/PgsqlAdapterTest.php test/MysqlAdapterTest.php
git commit -m "feat(upsert): add per-dialect conflict clause"
```

---

## Task 3: `SQLBuilder::build_upsert()`

Assembles a multi-row `INSERT` and appends the conflict clause (or omits it when `$update` is empty → plain insert).

**Files:**
- Modify: `lib/SQLBuilder.php`
- Test: `test/SQLBuilderTest.php`

**Interfaces:**
- Consumes: `Connection::upsert_conflict_clause()` (Task 2).
- Produces: `SQLBuilder::upsert(array $columns, int $row_count, array $unique_by, array $update): SQLBuilder` and private `build_upsert(): string`. The builder emits only the SQL string; bind values are supplied by the caller (`Table`), matching how `insert()` is used today.

- [ ] **Step 1: Write the failing tests**

Add to `test/SQLBuilderTest.php`:

```php
public function test_build_upsert_multi_row_with_conflict_clause()
{
    $sql = new SQLBuilder($this->conn, 'venues');
    $sql->upsert(['name', 'address', 'city'], 2, ['name', 'address'], ['city']);

    $name = $this->conn->quote_name('name');
    $addr = $this->conn->quote_name('address');
    $city = $this->conn->quote_name('city');

    $expected = "INSERT INTO venues ($name, $addr, $city) VALUES (?, ?, ?), (?, ?, ?) "
        . $this->conn->upsert_conflict_clause(['name', 'address'], ['city']);

    $this->assert_equals($expected, (string) $sql);
}

public function test_build_upsert_empty_update_omits_conflict_clause()
{
    $sql = new SQLBuilder($this->conn, 'venues');
    $sql->upsert(['name', 'address'], 1, ['name', 'address'], []);

    $name = $this->conn->quote_name('name');
    $addr = $this->conn->quote_name('address');

    $this->assert_equals("INSERT INTO venues ($name, $addr) VALUES (?, ?)", (string) $sql);
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_build_upsert`
Expected: FAIL — `Call to undefined method upsert()`.

- [ ] **Step 3: Implement the setter and builder**

In `lib/SQLBuilder.php`, add properties near the other insert/update state:

```php
    // for upsert
    private $upsert_columns;
    private $upsert_row_count;
    private $upsert_unique_by;
    private $upsert_update;
```

Add the setter (near `insert()`):

```php
    public function upsert(array $columns, int $row_count, array $unique_by, array $update)
    {
        $this->operation = 'UPSERT';
        $this->upsert_columns = $columns;
        $this->upsert_row_count = $row_count;
        $this->upsert_unique_by = $unique_by;
        $this->upsert_update = $update;

        return $this;
    }
```

Add the builder (near `build_insert()`):

```php
    private function build_upsert()
    {
        $keys = implode(', ', array_map([$this->connection, 'quote_name'], $this->upsert_columns));

        $one_row = '(' . implode(', ', array_fill(0, count($this->upsert_columns), '?')) . ')';
        $rows = implode(', ', array_fill(0, $this->upsert_row_count, $one_row));

        $sql = "INSERT INTO $this->table ($keys) VALUES $rows";

        if (!empty($this->upsert_update)) {
            $sql .= ' ' . $this->connection->upsert_conflict_clause($this->upsert_unique_by, $this->upsert_update);
        }

        return $sql;
    }
```

`to_s()` already dispatches to `build_` + `strtolower($this->operation)`, so `build_upsert` is picked up automatically.

- [ ] **Step 4: Run to verify they pass**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_build_upsert`
Expected: PASS.

- [ ] **Step 5: Style + commit**

```bash
docker compose exec tests composer run cs-fix
git add lib/SQLBuilder.php test/SQLBuilderTest.php
git commit -m "feat(upsert): build multi-row upsert SQL in SQLBuilder"
```

---

## Task 4: `Table::upsert()` + `Model::upsert()` (single-statement core) and the cross-adapter test harness

Delivers the working feature (no chunking yet — one statement per call) across all four adapters, plus the test scaffolding reused by later tasks.

**Files:**
- Modify: `lib/Table.php` (new `upsert()`)
- Modify: `lib/Model.php` (new static `upsert()`)
- Modify: `phpunit.xml` (exclude the abstract base)
- Create: `test/helpers/UpsertTest.php`, `test/MysqlUpsertTest.php`, `test/MariadbUpsertTest.php`, `test/PgsqlUpsertTest.php`, `test/SqliteUpsertTest.php`

**Interfaces:**
- Consumes: `SQLBuilder::upsert()` (Task 3), `Connection::$MAX_BIND_PARAMS` (Task 1, used minimally here — full chunking in Task 5), `Table::process_data()`, `Table::$columns` (keyed by raw column name; values are `Column` with `->name`), `Table::get_fully_qualified_table_name()`, `Connection::query()`.
- Produces:
  - `Table::upsert(array $values, array|string $unique_by, ?array $update = null): int`
  - `Model::upsert(array $values, array|string $unique_by, ?array $update = null): int`

- [ ] **Step 1: Create the abstract test base and per-adapter subclasses**

Create `test/helpers/UpsertTest.php`:

```php
<?php

abstract class UpsertTest extends DatabaseTest
{
    // Cross-adapter upsert tests. Concrete subclasses select the connection.
    // Uses the venues fixture (UNIQUE(name,address), PK Id) and authors
    // fixture (created_at/updated_at, PK author_id).
}
```

Create `test/MysqlUpsertTest.php`:

```php
<?php

class MysqlUpsertTest extends UpsertTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('mysql');
    }
}
```

Create `test/MariadbUpsertTest.php`:

```php
<?php

class MariadbUpsertTest extends UpsertTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('mariadb');
    }
}
```

Create `test/PgsqlUpsertTest.php`:

```php
<?php

class PgsqlUpsertTest extends UpsertTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('pgsql');
    }
}
```

Create `test/SqliteUpsertTest.php`:

```php
<?php

class SqliteUpsertTest extends UpsertTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('sqlite');
    }
}
```

- [ ] **Step 2: Exclude the abstract base from PHPUnit discovery**

In `phpunit.xml`, next to the existing excludes, add:

```xml
            <exclude>./test/helpers/UpsertTest.php</exclude>
```

- [ ] **Step 3: Write the first failing tests (insert + update path)**

Add to `test/helpers/UpsertTest.php`:

```php
public function test_upsert_inserts_new_rows()
{
    $before = count(Venue::all());

    Venue::upsert([
        ['name' => 'Fresh Hall',   'address' => '1 New St', 'city' => 'Austin'],
        ['name' => 'Second Stage', 'address' => '2 New St', 'city' => 'Dallas'],
    ], ['name', 'address']);

    $this->assert_equals($before + 2, count(Venue::all()));
    $this->assert_equals('Austin', Venue::find_by_name('Fresh Hall')->city);
}

public function test_upsert_updates_matching_row_only_for_listed_columns()
{
    // Fixture row 1: name "Blender Theater at Gramercy", address "127 East 23rd Street".
    $original = Venue::find(1);

    Venue::upsert([
        ['name' => 'Blender Theater at Gramercy', 'address' => '127 East 23rd Street', 'city' => 'Gotham'],
    ], ['name', 'address'], ['city']);

    $reloaded = Venue::find(1);
    $this->assert_equals('Gotham', $reloaded->city);       // updated
    $this->assert_equals($original->state, $reloaded->state); // untouched
    $this->assert_equals(6, count(Venue::all()));           // no new row
}
```

- [ ] **Step 4: Run to verify they fail**

Run: `docker compose exec tests vendor/bin/phpunit --filter Upsert`
Expected: FAIL — `Call to undefined method Venue::upsert()`.

- [ ] **Step 5: Implement `Table::upsert()`**

In `lib/Table.php` (near `insert()`):

```php
    public function upsert(array $values, $unique_by, ?array $update = null): int
    {
        $unique_by = is_array($unique_by) ? $unique_by : [$unique_by];

        if (empty($unique_by) || in_array('', $unique_by, true)) {
            throw new ActiveRecordException('upsert requires a non-empty $unique_by.');
        }

        if (empty($values)) {
            return 0;
        }

        // Every row must share the same set of keys.
        $first_keys = array_keys(reset($values));
        $sorted = $first_keys;
        sort($sorted);
        foreach ($values as $row) {
            $keys = array_keys($row);
            sort($keys);
            if ($keys !== $sorted) {
                throw new ActiveRecordException('upsert requires every row to have the same set of keys.');
            }
        }

        // Auto-manage timestamps where the columns exist and the caller omitted them.
        $now = date('Y-m-d H:i:s');
        $has_created = isset($this->columns['created_at']);
        $has_updated = isset($this->columns['updated_at']);
        foreach ($values as &$row) {
            if ($has_created && !array_key_exists('created_at', $row)) {
                $row['created_at'] = $now;
            }
            if ($has_updated && !array_key_exists('updated_at', $row)) {
                $row['updated_at'] = $now;
            }
        }
        unset($row);

        // Canonical column order (after timestamp injection, all rows share these).
        $columns = array_keys(reset($values));

        // Resolve the update column list.
        if (is_null($update)) {
            // All inserted columns (Eloquent-faithful), minus created_at.
            $update = array_values(array_filter($columns, fn ($c) => $c !== 'created_at'));
        }
        if ($update !== [] && $has_updated && !in_array('updated_at', $update, true)) {
            $update[] = 'updated_at';
        }

        // Convert values (DateTime -> string) per row.
        foreach ($values as &$row) {
            $row = $this->process_data($row);
        }
        unset($row);

        $sql = new SQLBuilder($this->conn, $this->get_fully_qualified_table_name());
        $sql->upsert($columns, count($values), $unique_by, $update);

        $bind = [];
        foreach ($values as $row) {
            foreach ($columns as $column) {
                $bind[] = $row[$column];
            }
        }

        $sth = $this->conn->query(($this->last_sql = $sql->to_s()), $bind);

        return $sth->rowCount();
    }
```

- [ ] **Step 6: Implement `Model::upsert()`**

In `lib/Model.php` (near `create()`):

```php
    /**
     * Bulk insert-or-update. Faithful to Laravel Eloquent's upsert: a single
     * atomic operation that bypasses validations, callbacks and dirty-tracking.
     *
     * @param array<array<string,mixed>> $values Rows to insert/update (identical keys)
     * @param array<string>|string $unique_by Column(s) identifying a record
     * @param array<string>|null $update Columns to overwrite on conflict; null = all
     *   inserted columns; [] = plain insert (error on duplicates)
     * @return int Affected row count (summed across chunks)
     */
    public static function upsert(array $values, array|string $unique_by, ?array $update = null): int
    {
        return static::table()->upsert($values, $unique_by, $update);
    }
```

- [ ] **Step 7: Run to verify the two tests pass on all adapters**

Run: `docker compose exec tests vendor/bin/phpunit --filter Upsert`
Expected: PASS across Mysql/Mariadb/Pgsql/Sqlite subclasses.

- [ ] **Step 8: Add the remaining single-statement behavior tests (groups A, B, C)**

Add to `test/helpers/UpsertTest.php`:

```php
public function test_upsert_mixed_insert_and_update()
{
    Venue::upsert([
        ['name' => 'Blender Theater at Gramercy', 'address' => '127 East 23rd Street', 'city' => 'Edited'],
        ['name' => 'Brand New Venue',             'address' => '99 New St',            'city' => 'Reno'],
    ], ['name', 'address'], ['city']);

    $this->assert_equals('Edited', Venue::find(1)->city);
    $this->assert_equals('Reno', Venue::find_by_name('Brand New Venue')->city);
    $this->assert_equals(7, count(Venue::all()));
}

public function test_upsert_default_update_overwrites_all_provided_columns()
{
    Venue::upsert([
        ['name' => 'Blender Theater at Gramercy', 'address' => '127 East 23rd Street', 'city' => 'C2', 'state' => 'XX'],
    ], ['name', 'address']); // no $update -> all columns

    $v = Venue::find(1);
    $this->assert_equals('C2', $v->city);
    $this->assert_equals('XX', $v->state);
}

public function test_upsert_composite_unique_by()
{
    // Same name, different address -> a NEW row (composite key), not an update.
    Venue::upsert([
        ['name' => 'Blender Theater at Gramercy', 'address' => 'DIFFERENT ADDRESS', 'city' => 'Nowhere'],
    ], ['name', 'address'], ['city']);

    $this->assert_equals(7, count(Venue::all()));
}

public function test_upsert_string_unique_by_is_normalized()
{
    // Conflict on the primary key via a string arg.
    Author::upsert([
        ['author_id' => 1, 'name' => 'renamed-via-upsert'],
    ], 'author_id', ['name']);

    $this->assert_equals('renamed-via-upsert', Author::find(1)->name);
}

public function test_upsert_sets_timestamps_on_insert()
{
    Author::upsert([
        ['author_id' => 999, 'name' => 'Timestamped'],
    ], 'author_id');

    $a = Author::find(999);
    $this->assert_not_null($a->created_at);
    $this->assert_not_null($a->updated_at);
}

public function test_upsert_bumps_updated_at_but_preserves_created_at_on_update()
{
    Author::upsert([['author_id' => 500, 'name' => 'First']], 'author_id');
    $created = Author::find(500)->created_at->format('Y-m-d H:i:s');

    Author::upsert([['author_id' => 500, 'name' => 'Second']], 'author_id', ['name']);
    $a = Author::find(500);

    $this->assert_equals($created, $a->created_at->format('Y-m-d H:i:s')); // preserved
    $this->assert_not_null($a->updated_at);                                // bumped
    $this->assert_equals('Second', $a->name);
}

public function test_upsert_does_not_overwrite_caller_supplied_timestamps()
{
    Author::upsert([
        ['author_id' => 777, 'name' => 'X', 'created_at' => '2001-01-01 00:00:00'],
    ], 'author_id');

    $this->assert_equals('2001-01-01', Author::find(777)->created_at->format('Y-m-d'));
}

public function test_upsert_converts_datetime_values()
{
    // A DateTime is converted to the adapter's string form by process_data().
    // updated_at is used (not some_Date) because Postgres quotes "some_Date"
    // case-sensitively, whereas updated_at is lowercase on every adapter.
    Author::upsert([
        ['author_id' => 888, 'name' => 'D', 'updated_at' => new DateTime('2010-05-06 07:08:09')],
    ], 'author_id', ['updated_at']);

    $this->assert_equals('2010-05-06 07:08:09', Author::find(888)->updated_at->format('Y-m-d H:i:s'));
}

public function test_upsert_single_row_batch()
{
    Venue::upsert([['name' => 'Solo', 'address' => 'Solo St', 'city' => 'One']], ['name', 'address']);
    $this->assert_equals('One', Venue::find_by_name('Solo')->city);
}

public function test_upsert_bypasses_setters_and_validations()
{
    // Author::set_name() upper-cases via the model layer; upsert must store raw text.
    Author::upsert([['author_id' => 321, 'name' => 'lowercase']], 'author_id');
    $stored = $this->conn->query_and_fetch_one(
        'SELECT name FROM authors WHERE author_id = 321'
    );
    $this->assert_equals('lowercase', $stored);
}

public function test_upsert_empty_values_returns_zero()
{
    $this->assert_equals(0, Venue::upsert([], ['name', 'address']));
}

public function test_upsert_empty_unique_by_throws()
{
    $this->expectException(ActiveRecord\ActiveRecordException::class);
    Venue::upsert([['name' => 'A', 'address' => 'B']], []);
}

public function test_upsert_non_uniform_row_keys_throws()
{
    $this->expectException(ActiveRecord\ActiveRecordException::class);
    Venue::upsert([
        ['name' => 'A', 'address' => 'B', 'city' => 'C'],
        ['name' => 'A', 'address' => 'B'], // missing city
    ], ['name', 'address']);
}

public function test_upsert_returns_positive_affected_count()
{
    $n = Venue::upsert([['name' => 'Counted', 'address' => 'C St', 'city' => 'X']], ['name', 'address']);
    $this->assert_true($n > 0);
}

public function test_upsert_emits_correct_dialect_sql()
{
    Venue::upsert([['name' => 'S', 'address' => 'A', 'city' => 'C']], ['name', 'address'], ['city']);
    $sql = $this->conn->last_query;

    if ($this->conn instanceof ActiveRecord\MysqlAdapter) {
        $this->assert_true(str_contains($sql, 'ON DUPLICATE KEY UPDATE'));
        $this->assert_true(str_contains($sql, 'VALUES('));
    } else {
        $this->assert_true(str_contains($sql, 'ON CONFLICT ('));
        $this->assert_true(str_contains($sql, 'EXCLUDED.'));
    }
}
```

> Note: `test_upsert_missing_unique_index_propagates_db_error` (spec item 16) is Postgres/SQLite-specific (MySQL ignores `unique_by`). Add it in the `Pgsql`/`Sqlite` subclasses if desired; it is optional coverage and omitted here to avoid an adapter-conditional `expectException`.

- [ ] **Step 9: Run the full upsert suite**

Run: `docker compose exec tests vendor/bin/phpunit --filter Upsert`
Expected: PASS on all four adapters.

- [ ] **Step 10: Static analysis + style + commit**

```bash
docker compose exec tests composer run analyse
docker compose exec tests composer run cs-fix
git add lib/Table.php lib/Model.php phpunit.xml test/helpers/UpsertTest.php test/MysqlUpsertTest.php test/MariadbUpsertTest.php test/PgsqlUpsertTest.php test/SqliteUpsertTest.php
git commit -m "feat(upsert): add Model::upsert() single-statement core across adapters"
```

---

## Task 5: Automatic chunking + transaction wrapping

Split large batches under `$MAX_BIND_PARAMS`; wrap multi-chunk runs in a transaction that joins any caller-open transaction.

**Files:**
- Modify: `lib/Table.php` (`upsert()`)
- Test: `test/helpers/UpsertTest.php`

**Interfaces:**
- Consumes: `Connection::$MAX_BIND_PARAMS`, `Connection::transaction()`, `commit()`, `rollback()`, `inTransaction()`.
- Produces: no signature change to `Table::upsert()`; behavior now chunks and returns the summed `rowCount()`.

- [ ] **Step 1: Write the failing chunking tests**

Add to `test/helpers/UpsertTest.php`:

```php
public function test_upsert_chunks_large_batch_and_sums_affected()
{
    $cls = get_class($this->conn);
    $prev = $cls::$MAX_BIND_PARAMS;
    $cls::$MAX_BIND_PARAMS = 6; // 3 columns -> chunk_size 2

    try {
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = ['name' => "Chunked $i", 'address' => "Addr $i", 'city' => "City $i"];
        }
        $affected = Venue::upsert($rows, ['name', 'address']);

        $this->assert_equals(5, count(Venue::find('all', ['conditions' => "name LIKE 'Chunked %'"])));
        $this->assert_true($affected >= 5);
    } finally {
        $cls::$MAX_BIND_PARAMS = $prev;
    }
}

public function test_upsert_rolls_back_all_chunks_on_failure()
{
    // authors rows carry author_id,name,created_at,updated_at = 4 columns after
    // timestamp injection, so a limit of 8 gives chunk_size 2 -> 2 chunks for 3 rows.
    $cls = get_class($this->conn);
    $prev = $cls::$MAX_BIND_PARAMS;
    $cls::$MAX_BIND_PARAMS = 8;

    try {
        $original = Author::find(1)->name; // fixture 'Tito'

        // Chunk 1 updates author 1 and inserts author 950; chunk 2 fails because
        // authors.name is NOT NULL on every adapter.
        $threw = false;
        try {
            Author::upsert([
                ['author_id' => 1,   'name' => 'CHANGED'],
                ['author_id' => 950, 'name' => 'Ok'],
                ['author_id' => 951, 'name' => null], // NOT NULL violation -> error
            ], 'author_id', ['name']);
        } catch (ActiveRecord\DatabaseException $e) {
            $threw = true;
        }

        $this->assert_true($threw);
        $this->assert_equals($original, Author::find(1)->name); // chunk 1 rolled back
        $this->assert_null(Author::find(950));                  // chunk 1 rolled back
    } finally {
        $cls::$MAX_BIND_PARAMS = $prev;
    }
}

public function test_upsert_joins_caller_transaction_and_rolls_back()
{
    $cls = get_class($this->conn);
    $prev = $cls::$MAX_BIND_PARAMS;
    $cls::$MAX_BIND_PARAMS = 6;

    try {
        $this->conn->transaction();
        Venue::upsert([
            ['name' => 'Txn A', 'address' => 'TA', 'city' => 'x'],
            ['name' => 'Txn B', 'address' => 'TB', 'city' => 'y'],
            ['name' => 'Txn C', 'address' => 'TC', 'city' => 'z'],
        ], ['name', 'address']);
        $this->conn->rollback();

        $this->assert_null(Venue::find_by_name('Txn A'));
    } finally {
        $cls::$MAX_BIND_PARAMS = $prev;
    }
}

public function test_upsert_throws_when_columns_exceed_limit()
{
    $cls = get_class($this->conn);
    $prev = $cls::$MAX_BIND_PARAMS;
    $cls::$MAX_BIND_PARAMS = 2; // fewer than the 3 columns

    try {
        $this->expectException(ActiveRecord\ActiveRecordException::class);
        Venue::upsert([['name' => 'A', 'address' => 'B', 'city' => 'C']], ['name', 'address']);
    } finally {
        $cls::$MAX_BIND_PARAMS = $prev;
    }
}
```

- [ ] **Step 2: Run to verify the new tests fail**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_upsert_chunks`
Expected: FAIL — the column-limit guard and rollback behavior don't exist yet (single-statement core would try one huge statement / not guard columns).

- [ ] **Step 3: Add chunking + transaction to `Table::upsert()`**

Replace the tail of `Table::upsert()` (from `$sql = new SQLBuilder(...)` onward, added in Task 4) with:

```php
        $max = $this->conn::$MAX_BIND_PARAMS;
        $column_count = count($columns);

        if ($column_count > $max) {
            throw new ActiveRecordException(
                "upsert: a row has more columns ($column_count) than the adapter's bind-parameter limit ($max)."
            );
        }

        $chunk_size = intdiv($max, $column_count);
        $chunks = array_chunk($values, $chunk_size);

        $use_transaction = count($chunks) > 1 && !$this->conn->inTransaction();
        if ($use_transaction) {
            $this->conn->transaction();
        }

        $affected = 0;

        try {
            foreach ($chunks as $chunk) {
                $sql = new SQLBuilder($this->conn, $this->get_fully_qualified_table_name());
                $sql->upsert($columns, count($chunk), $unique_by, $update);

                $bind = [];
                foreach ($chunk as $row) {
                    foreach ($columns as $column) {
                        $bind[] = $row[$column];
                    }
                }

                $sth = $this->conn->query(($this->last_sql = $sql->to_s()), $bind);
                $affected += $sth->rowCount();
            }

            if ($use_transaction) {
                $this->conn->commit();
            }
        } catch (\Exception $e) {
            if ($use_transaction) {
                $this->conn->rollback();
            }
            throw $e;
        }

        return $affected;
```

- [ ] **Step 4: Run to verify all chunking tests pass**

Run: `docker compose exec tests vendor/bin/phpunit --filter Upsert`
Expected: PASS on all four adapters.

- [ ] **Step 5: Static analysis + style + commit**

```bash
docker compose exec tests composer run analyse
docker compose exec tests composer run cs-fix
git add lib/Table.php test/helpers/UpsertTest.php
git commit -m "feat(upsert): chunk large batches inside a transaction"
```

---

## Task 6: `update: []` plain-insert path

`build_upsert()` already omits the conflict clause for an empty update (Task 3). Confirm the end-to-end behavior and lock it with tests.

**Files:**
- Test: `test/helpers/UpsertTest.php`
- (Verify only — no lib change expected, since `$update === []` flows through `Table::upsert()` unchanged and `build_upsert()` skips the clause.)

**Interfaces:**
- Consumes: existing `Table::upsert()` and `SQLBuilder::build_upsert()` behavior for `$update === []`.

- [ ] **Step 1: Write the failing tests**

Add to `test/helpers/UpsertTest.php`:

```php
public function test_upsert_empty_update_does_plain_insert()
{
    Venue::upsert([['name' => 'PlainInsert', 'address' => 'PI St', 'city' => 'PI']], ['name', 'address'], []);
    $this->assert_equals('PI', Venue::find_by_name('PlainInsert')->city);

    $sql = $this->conn->last_query;
    $this->assert_true(!str_contains($sql, 'ON CONFLICT'));
    $this->assert_true(!str_contains($sql, 'ON DUPLICATE'));
}

public function test_upsert_empty_update_errors_on_duplicate()
{
    $this->expectException(ActiveRecord\DatabaseException::class);
    // Row 1 already exists on the UNIQUE(name,address) index.
    Venue::upsert([
        ['name' => 'Blender Theater at Gramercy', 'address' => '127 East 23rd Street', 'city' => 'Dupe'],
    ], ['name', 'address'], []);
}
```

- [ ] **Step 2: Run to verify (expect one may already pass, one may fail)**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_upsert_empty_update`
Expected: If both pass immediately, the path is already correct — proceed to commit. If `errors_on_duplicate` fails, investigate whether `Table::upsert()` accidentally appends `updated_at` to an empty `$update` (it must not — the guard is `$update !== []`).

- [ ] **Step 3: Fix only if a test failed**

Confirm in `Table::upsert()` that the updated_at append is guarded by `$update !== []` (it is, per Task 4). No change expected. If a fix is needed, make the minimal change and re-run.

- [ ] **Step 4: Run to verify they pass**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_upsert_empty_update`
Expected: PASS on all four adapters.

- [ ] **Step 5: Commit**

```bash
docker compose exec tests composer run cs-fix
git add test/helpers/UpsertTest.php lib/Table.php
git commit -m "test(upsert): cover update:[] plain-insert path"
```

---

## Task 7: Examples + README + CLAUDE.md

**Files:**
- Create: `examples/upsert/upsert.sql`, `examples/upsert/models/Flight.php`, `examples/upsert/upsert.php`
- Modify: `README.md`, `CLAUDE.md`

**Interfaces:**
- Consumes: `Model::upsert()` (Task 4).

- [ ] **Step 1: Create the example schema**

Create `examples/upsert/upsert.sql`:

```sql
-- written for mysql, not tested with any other db
--
-- The (departure, destination) UNIQUE index is the conflict target for upsert.
-- On MySQL/MariaDB the engine uses it automatically; on Postgres/SQLite it is
-- named via the `unique_by` argument.

drop table if exists flights;
create table flights(
  id int not null primary key auto_increment,
  departure varchar(50) not null,
  destination varchar(50) not null,
  price int,
  created_at datetime,
  updated_at datetime,
  unique key uk_route (departure, destination)
);
```

- [ ] **Step 2: Create the example model**

Create `examples/upsert/models/Flight.php`:

```php
<?php

// Table name is inferred as "flights". The (departure, destination) UNIQUE
// index in upsert.sql is the conflict target used by Model::upsert().
class Flight extends ActiveRecord\Model
{
}
```

- [ ] **Step 3: Create the runnable example**

Create `examples/upsert/upsert.php`:

```php
<?php

require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Flight.php';

ActiveRecord\Config::initialize(function ($cfg) {
    $cfg->set_connections(['development' => 'mysql://test:test@127.0.0.1/upsert_test']);
});

// 1. Bulk insert-or-update. On conflict (departure, destination) only `price`
//    is overwritten. Returns the affected-row count.
$affected = Flight::upsert([
    ['departure' => 'Oakland', 'destination' => 'San Diego', 'price' => 99],
    ['departure' => 'Chicago', 'destination' => 'New York', 'price' => 150],
], unique_by: ['departure', 'destination'], update: ['price']);
echo "upsert #1 affected: $affected\n";

// Run again with a new price: the existing rows are updated, not duplicated.
Flight::upsert([
    ['departure' => 'Oakland', 'destination' => 'San Diego', 'price' => 79],
], unique_by: ['departure', 'destination'], update: ['price']);
echo "Oakland->San Diego price is now: " . Flight::find_by_departure('Oakland')->price . "\n";

// 2. Omit `update` -> every provided column is overwritten on conflict.
Flight::upsert([
    ['departure' => 'Chicago', 'destination' => 'New York', 'price' => 175],
], unique_by: ['departure', 'destination']);

// 3. A single-column `unique_by` may be passed as a string.
Flight::upsert([['id' => 1, 'departure' => 'Oakland', 'destination' => 'San Diego', 'price' => 60]], 'id', ['price']);

// 4. Timestamps are managed automatically when the columns exist: created_at is
//    set once on insert; updated_at is refreshed on every update.
$f = Flight::find_by_departure('Oakland');
echo "created_at={$f->created_at->format('c')} updated_at={$f->updated_at->format('c')}\n";

// 5. Passing update: [] performs a plain INSERT (it errors on duplicate keys).
Flight::upsert([['departure' => 'Boston', 'destination' => 'Miami', 'price' => 120]], ['departure', 'destination'], []);

// Note: very large arrays are chunked automatically to stay under the database's
// bind-parameter limit — the whole operation runs inside a single transaction.
// The MySQL/MariaDB affected-row count reports 1 per insert and 2 per update.
```

- [ ] **Step 4: Add the README section**

In `README.md`, after the `### Delete` subsection (before `## Contributing`), add:

````markdown
### Upsert ###
`Model::upsert()` inserts or updates many rows in one atomic, bulk operation
(modeled on Laravel Eloquent). It bypasses validations, callbacks and
dirty-tracking. The second argument names the column(s) that identify a record;
the optional third argument lists the columns to overwrite on conflict (all
inserted columns when omitted). `created_at`/`updated_at` are managed
automatically when those columns exist.

```php
Flight::upsert([
    ['departure' => 'Oakland', 'destination' => 'San Diego', 'price' => 99],
    ['departure' => 'Chicago', 'destination' => 'New York', 'price' => 150],
], unique_by: ['departure', 'destination'], update: ['price']);
# MySQL/MariaDB: INSERT ... VALUES (...),(...) ON DUPLICATE KEY UPDATE `price` = VALUES(`price`)
# Postgres/SQLite: INSERT ... VALUES (...),(...) ON CONFLICT (departure, destination) DO UPDATE SET price = EXCLUDED.price
```

On MySQL/MariaDB the `unique_by` columns are ignored and the table's PRIMARY/UNIQUE
indexes are used. Large batches are chunked automatically and run inside a
transaction. A full runnable example lives in [`examples/upsert/`](examples/upsert/).
````

- [ ] **Step 5: Update minimum-version notes**

In `README.md`, in the `## Supported Databases ##` section, update the MySQL/MariaDB lines to state the supported minimums (MySQL 8+, MariaDB 10.11+). In `CLAUDE.md`, under the adapter-support notes, add a one-line note that the supported minimums are MySQL 8+ and MariaDB 10.11+ (a policy statement; `composer.json` carries no DB-version constraint).

- [ ] **Step 6: Verify the docs render and the full suite still passes**

Run: `docker compose exec tests composer run test`
Expected: PASS, nothing skipped.

- [ ] **Step 7: Commit**

```bash
git add examples/upsert/ README.md CLAUDE.md
git commit -m "docs(upsert): add examples/upsert, README section and min-version notes"
```

---

## Final verification

- [ ] **Full gate:** `docker compose exec tests composer run test`
- [ ] **Static analysis:** `docker compose exec tests composer run analyse`
- [ ] **Style:** `docker compose exec tests composer run cs`
- [ ] Confirm coverage of `Model::upsert`/`Table::upsert`/`SQLBuilder::build_upsert` with `docker compose exec tests vendor/bin/phpunit --filter Upsert --coverage-text`.

---

## Self-Review Notes (author)

- **Spec coverage:** API §2 → Tasks 4/5/6; semantics/timestamps §3 → Task 4; SQL/dialect §4–5 → Tasks 2/3; chunking §5.1 → Task 5; min-versions §6 → Task 7; naming §7 (raw column names) → inherent (no alias translation); errors §8 → Task 4 (throws) + Task 5 (guard) + Task 6 (duplicate error); files §9 → all tasks; tests §10 groups A/B/C → Task 4, D → Task 3, E → Task 5, item 16b/33 → Task 6; examples §11 → Task 7.
- **Type consistency:** `upsert(array, array|string, ?array): int` is identical on `Model` and `Table`; `SQLBuilder::upsert(array $columns, int $row_count, array $unique_by, array $update)`; `upsert_conflict_clause(array $unique, array $update): string`; `$MAX_BIND_PARAMS` int static — all consistent across tasks.
- **Deviation from spec wording:** the spec said cross-adapter tests go "in AdapterTest"; the plan uses a dedicated `UpsertTest` base + four subclasses (same ×4 mechanism, better separation). Functionally equivalent.
