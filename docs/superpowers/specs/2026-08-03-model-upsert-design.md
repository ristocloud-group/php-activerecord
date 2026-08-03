# Design: `Model::upsert()`

**Date:** 2026-08-03
**Status:** Approved (pending implementation plan)
**Scope:** Add a bulk upsert operation to the ORM, mirroring Laravel Eloquent's `upsert`.

## 1. Goal

Provide an atomic, bulk "insert-or-update" operation on models, faithful to
Eloquent's `upsert` (see <https://laravel.com/docs/13.x/eloquent#upserts>). It writes
many rows using the database's native conflict-resolution clause — in a single statement
for typical batches, or a few chunked statements inside a transaction for very large
ones (§5.1).

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
- **`$update`** — columns to overwrite when a matching row exists. Three cases,
  mirroring Eloquent:
  - `null` (omitted, the default) → **all columns present in the rows** (including the
    `unique_by` columns — faithful to Eloquent) **plus `updated_at`**, **excluding
    `created_at`**.
  - `[]` (explicit empty array) → degrade to a **plain `INSERT`** with no conflict
    clause (i.e. "insert only; error on duplicates"), exactly as Eloquent does.
  - a non-empty list → update precisely those columns (`updated_at` is still appended
    when timestamps apply, per §3).
- **Return** — `int`, the **sum** of `PDOStatement::rowCount()` across all chunks (see
  §5.1; a non-chunked call is just one chunk).
  > Caveat: on MySQL/MariaDB, `ON DUPLICATE KEY UPDATE` counts **1 per inserted row
  > and 2 per updated row**, so the returned number is not a clean "rows touched"
  > count. This matches Eloquent's contract (it returns the driver's affected-row
  > count as-is).
- When `$values` is empty, the method returns `0` **without executing any query**.
- When `$unique_by` is empty (`''` / `[]`), an `ActiveRecordException` is thrown
  (Eloquent throws `InvalidArgumentException` here; we use the library's exception).

The name `upsert` is a single word and therefore complies with the project's
snake_case public-API rule.

## 3. Semantics

- **Bulk and atomic.** One statement for typical batches, or several chunked statements
  wrapped in one transaction for very large ones (§5.1). No `Model` instances are created.
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
5. If `$update === []`, take the **plain-insert branch**: no conflict clause is emitted
   (a multi-row `INSERT`), still chunked and still returning the summed row count.
   Otherwise compute the default `$update` list when it is `null`.
6. Run each row through the existing `Table::process_data()` (converts `DateTime` to
   the adapter's string form; reuses current logic).
7. Determine the chunk size from the adapter's placeholder limit (see §5.1) and split
   the rows into chunks.
8. For each chunk: build the SQL via `SQLBuilder` (see §5), flatten that chunk's bind
   values in **row-major** order (row 1's values, then row 2's, …), execute via
   `Connection::query()`, and accumulate `rowCount()`. The whole loop is wrapped in a
   transaction when it produces more than one chunk (see §5.1).
9. Return the accumulated count.

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

### 5.1 Automatic chunking (an enhancement beyond Eloquent)

**Eloquent does not chunk upsert.** Verified against the source
(`illuminate/database`, `Query/Builder.php::upsert` and `Eloquent/Builder.php::upsert`):
it issues a single `affectingStatement` with all rows and no transaction, so it fails
outright if the row count exceeds the driver's bind-parameter limit. We deliberately go
one step further for robustness. The public API is unchanged; chunking is entirely
internal to `Table::upsert()`, so it can be tuned or removed without touching consumers.

**The limit.** A single prepared statement caps the number of `?` placeholders. Since an
upsert binds exactly `rows × columns` placeholders (the `$update` list adds only column
*names*, not bindings), the max rows per statement is `floor(limit / columns)`.

| Adapter | Bind-parameter limit | Notes |
| --- | --- | --- |
| MySQL / MariaDB | 65,535 | 16-bit protocol limit |
| Postgres | 65,535 | 16-bit protocol limit |
| SQLite | 999 (conservative default) | `SQLITE_MAX_VARIABLE_NUMBER` is 999 before SQLite 3.32.0 and 32,766 after; not readily queryable via PDO, so we use the safe 999 |

**Mechanism.**
- Each adapter exposes its limit (a static value / method, following the existing
  `DEFAULT_PORT` pattern): 65,535 for MySQL/Postgres, 999 for SQLite.
- `Table::upsert()` computes `chunk_size = floor(limit / column_count)` and
  `array_chunk()`s the rows. If `column_count > limit` (unrealistic) → throw
  `ActiveRecordException` (a single row cannot fit).
- **Atomicity.** When the operation splits into more than one chunk, the loop is wrapped
  in `Connection::transaction()` / `commit()`, with `rollback()` on any error, so the
  whole upsert stays all-or-nothing (preserving the guarantee a single statement gives
  for free). A single-chunk call is not wrapped (the one statement is already atomic).
  If the connection is **already** inside a transaction (`Connection::inTransaction()`),
  `upsert()` joins it and does not open/commit its own — the library has no savepoints,
  so it must not create a nested boundary.
- **Return value** is the sum of each chunk's `rowCount()`.

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
| `lib/Table.php` | new `upsert()` (validation, timestamp injection, `process_data`, chunking loop + transaction wrapping, bind flattening) |
| `lib/SQLBuilder.php` | new `upsert()` setter + `build_upsert()` |
| `lib/Connection.php` | new `upsert_conflict_clause()` (base = `ON CONFLICT ... EXCLUDED`) |
| `lib/adapters/MysqlAdapter.php` | override `upsert_conflict_clause()` (`ON DUPLICATE KEY UPDATE ... VALUES(col)`); bind-parameter limit `65535` |
| `lib/adapters/PgsqlAdapter.php` | bind-parameter limit `65535` |
| `lib/adapters/SqliteAdapter.php` | bind-parameter limit `999` (conservative) |
| `README` / `CLAUDE.md` | document `upsert` + raised minimum DB versions |

`Connection` may host a default bind-parameter limit that `SqliteAdapter` overrides,
mirroring how `upsert_conflict_clause()` is structured.

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
16b. `update: []` → emits a plain `INSERT` (no conflict clause); a subsequent call with a
    colliding key raises `DatabaseException` (proves no conflict handling was applied).

### C. Per-dialect SQL correctness (assert on `last_sql` / `last_query`)

17. `Mysql` + `MariadbAdapterTest`: SQL contains `ON DUPLICATE KEY UPDATE` and `VALUES(`.
18. `Pgsql` + `SqliteAdapterTest`: SQL contains `ON CONFLICT (` and `EXCLUDED.`.
19. Multi-row → N `(?, ?, ...)` groups and bind values flattened row-major
    (count = rows × columns).
20. Default update: the set contains `updated_at`, not `created_at`.

### D. `SQLBuilder::build_upsert()` unit tests (no DB, fast; help reach the 90% goal)

Verify the constructed string shape and bind-value ordering in isolation.

### E. Chunking (in `AdapterTest` → ×4 where it touches the DB)

To keep these deterministic with small datasets, the adapter's bind-parameter limit is
an **overridable static value**; tests lower it to force multiple chunks from a handful
of rows (rather than inserting tens of thousands). Each test restores the original limit
in `tear_down()`.

**Chunk math / boundaries**

21. Single chunk (batch fits the limit) → **no** `BEGIN`/`COMMIT` wrapping, one
    statement (asserts we don't wrap unnecessarily).
22. Exact boundary: `rows == chunk_size` → exactly **one** chunk (guards the floor
    division against an off-by-one that would emit an empty second chunk).
23. `rows == chunk_size + 1` → exactly **two** chunks, the second holding the single
    remainder row.
24. Multi-chunk batch writes **all** rows and returns the **summed** `rowCount()`.
25. Statement count: the number of executed upsert statements equals
    `ceil(rows / chunk_size)` (asserted via a query counter / the logger).

**Data integrity across chunks**

26. Rows spread across chunks keep the correct `column => value` mapping — use distinct
    values per row and verify every row persisted intact (guards against cross-chunk
    bind-value bleed / mis-flattening).
27. Mixed insert + update spanning multiple chunks: pre-existing rows are updated and new
    rows inserted across the chunk boundary; summed count is correct.
28. Timestamps applied uniformly: `created_at` / `updated_at` are set on **every**
    inserted row regardless of which chunk it lands in.

**Atomicity / transaction**

29. Atomic rollback: a failure in a **later** chunk (e.g. an invalid row) rolls back the
    rows written by earlier chunks — nothing new persists **and** previously-existing
    rows retain their original values (not just "inserts removed").
30. The connection is usable after a rolled-back chunked upsert (not left stuck in an
    aborted transaction — a following query succeeds).
31. Joins a caller-opened transaction (negative): `upsert()` does not commit on its own;
    a subsequent caller `rollback()` also undoes the upsert (no nested boundary created).
32. Joins a caller-opened transaction (positive): a subsequent caller `commit()`
    persists the chunked upsert.

**Plain-insert path also chunks**

33. `update: []` on a large batch (spanning chunks) inserts all rows, wrapped in a
    transaction — the chunking loop is shared with the conflict path.

**Per-adapter limit + guards**

34. `column_count > limit` (limit forced absurdly low) → `ActiveRecordException`.
35. Adapter exposes the expected limit (MySQL/MariaDB & Postgres = 65535, SQLite = 999)
    and `chunk_size == floor(limit / column_count)` — fast unit assertion, no DB.

## 11. Out of scope (v1)

- `alias_attribute` translation of row keys.
- Firing model events / running validations.
- Savepoint-based partial rollback within a chunked upsert (the library has no savepoint
  support; the whole operation is one all-or-nothing transaction — see §5.1).
