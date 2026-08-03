# Design: `Model::upsert()`

**Date:** 2026-08-03
**Status:** Approved (pending implementation plan)
**Scope:** Add a bulk upsert operation to the ORM, mirroring Laravel Eloquent's `upsert`.

## 1. Goal

Provide a single, atomic, bulk "insert-or-update" operation on models, faithful to
Eloquent's `upsert` (see <https://laravel.com/docs/13.x/eloquent#upserts>). It writes
many rows in one statement, using the database's native conflict-resolution clause.

This is a **purely additive** change (a new static method); it does not alter any
existing public method, option, or behavior, so there is no backward-compatibility
concern for existing consumers.

## 2. Public API

```php
public static function upsert(array $values, array|string $unique_by, ?array $update = null): int
```

- **`$values`** — a list of row hashes (`column => value`). Every row **must have the
  same set of keys**; otherwise an `ActiveRecordException` is thrown.
- **`$unique_by`** — a string or array of column names that uniquely identify a record.
  **Required.** On MySQL/MariaDB the emitted SQL *ignores* it (the engine uses the
  table's actual PRIMARY/UNIQUE indexes, exactly as Eloquent documents); on
  Postgres/SQLite it becomes the `ON CONFLICT (...)` target. It is still required for
  API parity across adapters.
- **`$update`** — optional list of columns to overwrite when a matching row exists.
  When omitted, the default is **all columns present in the rows** (including the
  `unique_by` columns — faithful to Eloquent) **plus `updated_at`**, **excluding
  `created_at`**.
- **Return** — `int`, the value of `PDOStatement::rowCount()`.
  > Caveat: on MySQL/MariaDB, `ON DUPLICATE KEY UPDATE` counts **1 per inserted row
  > and 2 per updated row**, so the returned number is not a clean "rows touched"
  > count. This matches Eloquent's contract (it returns the driver's affected-row
  > count as-is).
- When `$values` is empty, the method returns `0` **without executing any query**.

The name `upsert` is a single word and therefore complies with the project's
snake_case public-API rule.

## 3. Semantics

- **Bulk, atomic, single statement.** No `Model` instances are created.
- **No validations, no callbacks** (`before_save`, `before_create`, `after_create`,
  `after_save`, …), **no dirty-tracking.** This mirrors Eloquent's query-builder-level
  upsert. Consumers who need the model lifecycle must use `save()` / `create()`.
- **Timestamps (Eloquent-faithful).** If the table has `created_at` / `updated_at`
  columns (detected from `Table`'s existing column metadata):
  - Each inserted row gets `created_at = now()` and `updated_at = now()` **only if the
    caller did not already provide them** (caller-supplied values are preserved).
  - The `ON CONFLICT` / `ON DUPLICATE KEY UPDATE` set includes `updated_at` but **not**
    `created_at` (a matched row keeps its original creation time).
  - `now()` uses the same format as `Model::set_timestamps()` (`date('Y-m-d H:i:s')`).

## 4. Data flow

`Model::upsert()` delegates to `Table::upsert()`:

1. Normalize `$unique_by` to an array; throw `ActiveRecordException` if empty.
2. Return `0` immediately if `$values` is empty.
3. Derive the column set from the first row; verify every other row has an identical
   key set, else throw `ActiveRecordException`.
4. Inject `created_at` / `updated_at` into each row where those columns exist and the
   caller did not supply them.
5. Compute the default `$update` list if it is `null`.
6. Run each row through the existing `Table::process_data()` (converts `DateTime` to
   the adapter's string form; reuses current logic).
7. Flatten bind values in **row-major** order (row 1's values, then row 2's, …).
8. Build the SQL via `SQLBuilder` (see §5).
9. `Connection::query($sql, $values)` → return `PDOStatement::rowCount()`.

## 5. SQL construction — where dialects diverge

Follows the existing pattern where `SQLBuilder` assembles the statement and delegates
dialect-specific fragments to `Connection`/adapter methods (as it already does for
`quote_name`, `limit`, `next_sequence_value`).

- **`SQLBuilder`** gains an `upsert(...)` setter and a `build_upsert()` that assembles
  the common prefix:
  `INSERT INTO t (c1, c2, ...) VALUES (?, ?, ...), (?, ?, ...), ...`
  followed by the adapter's conflict clause.
- **New hook** `Connection::upsert_conflict_clause(array $unique, array $update): string`:
  - **Base `Connection`** (standard SQL, used **unchanged** by both `PgsqlAdapter` and
    `SqliteAdapter`):
    `ON CONFLICT (u1, u2) DO UPDATE SET c = EXCLUDED.c, ...`
  - **`MysqlAdapter` override** (covers **both MySQL and MariaDB**, which share this
    adapter): `ON DUPLICATE KEY UPDATE c = VALUES(c), ...` — ignores `$unique`.

### Why the override lives only on `MysqlAdapter`

The four supported databases collapse into **two** SQL families, not four:

- **Postgres and SQLite** use the *identical* standard `ON CONFLICT ... DO UPDATE SET
  ... = EXCLUDED. ...` syntax → the base `Connection` implementation serves both, no
  override needed.
- **MySQL and MariaDB** use `ON DUPLICATE KEY UPDATE`, **and MariaDB has no adapter of
  its own** — per project convention it reuses `MysqlAdapter` (`mysql://` protocol;
  `MariadbAdapterTest extends MysqlAdapterTest`). A single override on `MysqlAdapter`
  therefore covers both.

So: 4 databases → 2 syntax families → 2 implementations (one base + one override).

### MySQL/MariaDB: `VALUES(col)`, not the alias syntax — and why

The modern MySQL alias form (`... VALUES (...) AS new ON DUPLICATE KEY UPDATE
col = new.col`, MySQL 8.0.19+) was considered and **rejected** because it is
**not portable to MariaDB**, which reuses the same adapter and thus the same emitted
SQL. This was verified empirically against the project's own containers:

| Server | Alias syntax (`AS new ... = new.v`) | Classic `VALUES(v)` |
| --- | --- | --- |
| MySQL 9.7.2 | works | works (deprecated since 8.0.20) |
| MariaDB 11.4.12 | **ERROR 1064** (syntax error) | works (canonical form) |

Because a single shared `MysqlAdapter` cannot emit two different strings without
runtime flavor detection, and the maintainer opted for the simpler single-path design,
the implementation uses the classic `ON DUPLICATE KEY UPDATE col = VALUES(col)` for
both engines. Trade-off accepted: `VALUES()` is deprecated (not removed) on
MySQL 8.0.20+ and merely emits a server-side deprecation warning (not a PHP warning);
it remains fully functional on MySQL 8/9 and MariaDB 10.11+/11.x.

## 6. Minimum supported database versions (policy)

As part of this work the project raises its documented minimum supported versions to
**MySQL 8+** and **MariaDB 10.11+**. Note this is a **policy / documentation** decision
(recorded in `README` and `CLAUDE.md`): `composer.json` carries no database-version
constraint (only a PHP constraint), so nothing enforces it at install time. The upsert
feature itself works on those versions and older; the bump reflects the maintainer's
supported-platform decision, not a hard technical requirement of the `VALUES()` path.

## 7. Column naming

Row keys are treated as **raw database column names** (as in Eloquent). `alias_attribute`
mappings are **not** applied in this version. This is a documented limitation of v1
(a bulk operation that never instantiates a model has no attribute layer to consult).

## 8. Error handling

- `$values` empty → return `0` (no-op, no query).
- Non-uniform row key sets → `ActiveRecordException`.
- Missing / empty `$unique_by` → `ActiveRecordException`.
- Database errors (e.g. no matching unique index for an `ON CONFLICT` target on
  Postgres/SQLite) → propagate as `DatabaseException` from the existing
  `Connection::query()` path. On MySQL/MariaDB `$unique_by` is ignored, so a missing
  index there simply means no conflict is detected (documented per-adapter).

## 9. Files touched

| File | Change |
| --- | --- |
| `lib/Model.php` | new `public static function upsert()` |
| `lib/Table.php` | new `upsert()` (validation, timestamp injection, `process_data`, bind flattening) |
| `lib/SQLBuilder.php` | new `upsert()` setter + `build_upsert()` |
| `lib/Connection.php` | new `upsert_conflict_clause()` (base = `ON CONFLICT ... EXCLUDED`) |
| `lib/adapters/MysqlAdapter.php` | override `upsert_conflict_clause()` (`ON DUPLICATE KEY UPDATE ... VALUES(col)`) |
| `README` / `CLAUDE.md` | document `upsert` + raised minimum DB versions |

No new file under `lib/` → no change to the `ActiveRecord.php` require manifest.
`PgsqlAdapter` and `SqliteAdapter` inherit the base clause unchanged.

## 10. Test surface

Existing fixtures suffice — no new table required:

- **`venues`** → `UNIQUE(name, address)` composite (multi-column `unique_by`), PK `Id`.
- **`authors`** → `created_at` / `updated_at` columns, PK `author_id`, and a `set_name`
  setter (uppercases) that lets us prove the model lifecycle is bypassed.

`AdapterTest` is the cross-adapter harness: any method added there runs on **all four**
backends (MySQL, MariaDB, Postgres, SQLite) via the concrete subclasses. Where the
update path is exercised, seed known rows (fixtures or explicit inserts in `set_up()`)
so conflicts are deterministic.

### A. Real execution, cross-adapter (in `AdapterTest`, runs ×4; asserts actual DB state)

1. Insert-only: all rows new → inserted; verify rows in DB.
2. Update on conflict: pre-existing row → only `$update` columns change; others untouched.
3. Mixed insert + update in one statement (atomicity).
4. Default `$update` (omitted) → all provided columns overwritten.
5. Composite `unique_by` on `venues` (`['name', 'address']`).
6. String `unique_by` → normalized to array (conflict on `authors.author_id`).
7. Auto timestamps on `authors`: insert sets both; update changes `updated_at` but
   preserves `created_at`.
8. Caller-provided timestamps are **not** overwritten.
9. `DateTime` value in a row → converted via `process_data` and stored correctly.
10. Single-row batch (degenerate case) works.
11. Lifecycle bypass: `Author::set_name` (uppercase) and validations are **not**
    applied → the stored value is raw.

### B. Behavior / errors (in `AdapterTest` → ×4, so enforcement holds on every backend)

12. Empty `$values` → returns `0` and executes no query.
13. Non-uniform row keys → `ActiveRecordException`.
14. Empty `$unique_by` (`''` / `[]`) → `ActiveRecordException`.
15. Return is `int`; assert consistent with the MySQL 1/2 quirk (assert `> 0`, or
    exact per-adapter).
16. Missing unique index on the target → propagates `DatabaseException` (Postgres/SQLite
    path; documented that MySQL ignores `unique_by`).

### C. Per-dialect SQL correctness (assert on `last_sql` / `last_query`)

17. `Mysql` + `MariadbAdapterTest`: SQL contains `ON DUPLICATE KEY UPDATE` and `VALUES(`.
18. `Pgsql` + `SqliteAdapterTest`: SQL contains `ON CONFLICT (` and `EXCLUDED.`.
19. Multi-row → N `(?, ?, ...)` groups and bind values flattened row-major
    (count = rows × columns).
20. Default update: the set contains `updated_at`, not `created_at`.

### D. `SQLBuilder::build_upsert()` unit tests (no DB, fast; help reach the 90% goal)

Verify the constructed string shape and bind-value ordering in isolation.

## 11. Out of scope (v1)

- **Automatic chunking** of very large `$values` arrays (risk of hitting the ~65k bind
  placeholder limit on MySQL). Remains the caller's responsibility; documented.
- `alias_attribute` translation of row keys.
- Firing model events / running validations.
