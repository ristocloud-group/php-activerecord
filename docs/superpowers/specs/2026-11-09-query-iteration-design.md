# Query iteration — chunk, lazy and cursor on the query builder

**Date:** 2026-11-09
**Status:** Approved (2026-11-09); implemented in the two stacked PRs.
**Author:** Claude (paired with maintainer)
**Program:** sub-project 1 of 6 of the "Eloquent fluent query builder" program (see the builder core spec,
`2026-10-21-query-builder-core-design.md` §1.2). It is built **second**, after the builder core (merged 2026-11-02,
#178–#183). Its starting decisions are that spec's Appendix A; this document supersedes the Appendix where they differ
(§1.3).
**Branches:** cut from `origin/master` 3316cbf.

---

## 1. Context

### 1.1 Where this started

The original request (2026-10-21) was Eloquent's *Chunking Results*, *Streaming Results Lazily* and *Cursors*, with a
chunking that tolerates updates and inserts during the run. The builder core shipped first; this sub-project adds the
iteration methods to it.

On 2026-11-09 the maintainer first asked for soft delete (sub-project 3, Scopes), then chose to do iteration first.
It was verified the same day that no iteration code, branch, spec or PR exists.

### 1.2 Real-world motivation (the monolith)

- The monolith base model (`RCModel`) has a static `chunk(int $size, array $options, callable $each): int` that pages by
  `OFFSET` with no `ORDER BY`. Paging by offset skips rows when the loop deletes or updates the rows it reads (a job that
  clears failed login attempts did exactly that). Today it has one call site (`myhospital/delivery.php:77`); its sibling
  `chunkRows` has none.
- That call site loads three relations per row (N+1), which a per-chunk `with()` removes.

### 1.3 Decisions

From the builder core spec, Appendix A (still valid):

- Eloquent's names in snake_case: `chunk`, `chunk_by_id`, `chunk_by_id_desc`, `lazy`, `lazy_by_id`, `lazy_by_id_desc`,
  `cursor`. `chunk` / `lazy` page by offset, the `_by_id` variants by keyset (§4.3).
- `include_new_rows`, default `false`, on the ascending keyset variants (§4.3).
- `cursor()` is buffered by default, with an opt-in `stream` flag; while a stream is open, any other query on its
  connection throws a clear ActiveRecord exception instead of PDO's error 2014.
- Arrays and generators, no Collection.

Taken on 2026-11-09 (**supersede Appendix A** where they differ):

1. **Entry point: the builder only.** The seven methods are `Query` methods, reached on a model through the existing
   `__callStatic` forwarding (`Query::FORWARDABLE`): `Order::chunk(100, $fn)`, `Order::where(...)->chunk_by_id(...)`.
   No real statics on `Model`, no legacy options array.
2. **No BC break on `chunk`.** Appendix A's approved break is not needed: a static method a subclass declares is called
   by PHP before `__callStatic`, so `RCModel::chunk(int, array, callable)` keeps working, unchanged, on every `RCModel`
   subclass. The library's version stays reachable there as `X::new_query()->chunk(...)` or `X::where(...)->chunk(...)`.
3. **Approach B:** thin public methods on `Query` that delegate to new internal classes (§3).
4. The Eloquent semantics of §4, with the deviations of §4.6.

### 1.4 Goals

1. Examples from the Laravel docs port by changing the method case, except for the deviations of §4.6.
2. `chunk_by_id` processes every matching row when the loop deletes or updates the rows it reads.
3. Memory: `chunk`/`lazy` hold one page of models (a model with a non-null date attribute is freed at PHP's next cycle
   collection, as it references itself through its `DateTime`); `cursor()` holds one model plus the driver's buffer;
   `cursor(stream: true)` on MySQL/MariaDB holds one row.
4. One engine: every page and every cursor read is compiled by `QueryCompiler` and runs through `Table` / `Connection`,
   as `get()` does. No SQL is written outside the engine.
5. No behavior change for existing code.

### 1.5 Non-goals

- `each`, `each_by_id`, `chunk_map` (YAGNI; additive later).
- Soft delete and global scopes (sub-project 3). Until then the iteration methods, like every builder method, do not go
  through consumer finder overrides: on `RCModel` soft-delete models they return soft-deleted rows unless the query says
  `where_null('deleted_at')` (§7.3).
- Lazy, unbuffered reads on Postgres (PDO pgsql always buffers the whole result; §4.5).
- Keyset on a non-unique column or a column whose values change during the run (documented as unsupported, not
  detected).

---

## 2. Facts the design relies on

Verified on 2026-10-21 (builder core brainstorming; PHP 8.3, MySQL 9.7, MariaDB 11.4):

- An unbuffered MySQL result plus any other query on the same connection gives error 2014.
- Passing `MYSQL_ATTR_USE_BUFFERED_QUERY => false` to `prepare()` is ignored; setting the connection attribute only
  around `execute()` works.
- An unbuffered result read more slowly than `net_write_timeout` (60 s by default) gives 2006 "server has gone away".
- A buffered result is a snapshot on MySQL and Postgres; an SQLite cursor is live (it sees rows inserted ahead of it).
- Hydrating every row costs about 5× the raw buffered rows (100k rows: 22.9 MB vs 117.6 MB).
- A keyset `id > ?` is exact up to 2^64−1 with string binds on MySQL and MariaDB.

Verified on 2026-11-09 in the code:

- `Column::cast()` keeps an INTEGER value beyond `PHP_INT_MAX` as its exact digit string (#44); the MySQL test schema
  has a `BIGINT UNSIGNED` pk table (`big_ids`).
- `Query::max()` returns an INTEGER column's maximum as an int, or an exact string past `PHP_INT_MAX`.
- Every SELECT and write goes through `Connection::query()`; transactions through `Connection::transaction()`,
  `commit()`, `rollback()` (savepoints through `query()`).
- `lib/` uses no `PDO::MYSQL_ATTR_*` constant today.

To verify in the plan: §10.

---

## 3. Architecture

### 3.1 Principle

The iteration methods add **loops**, not SQL. A page is a clone of the query with a window (`offset`/`limit`) or a
keyset condition (`where`/`order_by`/`limit`) added through the builder's own public methods, then read with `get()`.
So every page inherits what the builder already guarantees: column mapping and qualification with joins, aliases, the
user's WHERE parenthesized when combined (a top-level `OR` cannot leak), dates bound in the format of their column,
eager loading through `with()`.

### 3.2 Components

| Unit | Kind | Responsibility |
|---|---|---|
| `Query` (changed) | public | The seven methods: validate the arguments (§4.6, §5), delegate. Added to `Query::FORWARDABLE`; `@method` tags on `Model`. |
| `QueryPager` (new, `lib/QueryPager.php`) | `@internal` | Two page generators: `offset_pages()` and `keyset_pages()` (§4.2, §4.3). `chunk*` consume them and call the callback; `lazy*` `yield from` them. One loop per paging kind. |
| `Cursor` (new, `lib/Cursor.php`) | `@internal` | Compiles the query's SELECT with `QueryCompiler`, executes it (buffered or streamed), hydrates and yields one model per row; releases the statement and the connection lock in `finally` (§4.4, §4.5). |
| `Table::hydrate()` (new, extracted) | `@internal` | `hydrate(array $row, bool $readonly): Model` — the per-row hydration now inline in `find_by_sql()`, extracted so `find_by_sql()` and `Cursor` share it. Behavior-preserving. |
| `Connection::stream_query()` (new) | `@internal` | Prepares, binds and executes `$sql` through the adapter's unbuffered hook, then marks the connection busy. A new method rather than a parameter on `query()`, because an added parameter breaks a consumer adapter that overrides `query()`. |
| `Connection::unbuffered(Closure)` (new hook) | `@internal` | Runs the `execute()` closure. Default: as is. `MysqlAdapter` overrides it: buffered queries off, execute, restore the previous value in `finally` (§4.5). |
| Busy guard in `Connection` | `@internal` | While a stream is open, `query()`, `transaction()`, `commit()` and `rollback()` throw `ConnectionBusy` (§6). |
| `ConnectionBusy` (new, `lib/exceptions/ConnectionBusy.php`) | public | `extends ActiveRecordException`, as `MultipleRecordsFound` does. |

New files need only their file in the right PSR-4 base dir (`LibraryLoadingTest` guards it).

### 3.3 Flow

```
Order::where('status', 'open')->chunk_by_id(500, $fn)
  └─ Model::__callStatic → Query::where → Query::chunk_by_id
       ├─ validate (size, orders, offset, key column)           — before any SQL
       └─ QueryPager::keyset_pages
            ├─ bound = clone()->max(key)                        — unless include_new_rows
            └─ loop: clone()->where(key > last)->where(key <= bound)->reorder()->order_by(key)->limit(n)->get()
                       └─ QueryCompiler → Table::find_by_sql → Connection::query  (+ eager loads per page)

Order::where(...)->cursor(stream: true)
  └─ Query::cursor → validate → Cursor (generator; nothing runs until the first iteration)
       └─ first iteration: QueryCompiler → Connection::stream_query → adapter unbuffered(execute) → busy
            loop: fetch → Table::hydrate → yield
            finally: closeCursor, release busy
```

---

## 4. Public API and semantics

### 4.1 Signatures

```php
chunk(int $count, callable $callback): bool
chunk_by_id(int $count, callable $callback, ?string $column = null, ?string $alias = null, bool $include_new_rows = false): bool
chunk_by_id_desc(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
lazy(int $chunk_size = 1000): \Generator<int, TModel>
lazy_by_id(int $chunk_size = 1000, ?string $column = null, ?string $alias = null, bool $include_new_rows = false): \Generator<int, TModel>
lazy_by_id_desc(int $chunk_size = 1000, ?string $column = null, ?string $alias = null): \Generator<int, TModel>
cursor(bool $stream = false): \Generator<int, TModel>
```

Common rules:

- **Callback:** `$callback(list<TModel> $models, int $page)`, `$page` from 1. Returning `false` (strictly) stops the
  run and the method returns `false`; otherwise it returns `true`, including when no row matches (the callback is not
  called).
- **Generators** yield the models with sequential int keys from 0, so `iterator_to_array()` works.
- **Validation runs at the call**, not at the first iteration: `lazy*` and `cursor` are plain methods that validate,
  then return an inner generator. No SQL runs until the first iteration (as Eloquent's `LazyCollection`).
- The query the method is called on is **not modified**; every page is read from a clone.
- `with()` eager loads per page (`chunk*`, `lazy*`); `readonly()` is honored by every method.
- A model with its own static method of the same name (e.g. `RCModel::chunk`) shadows the forwarded one (§1.3 item 2);
  in an instance method `static::chunk(...)` is not forwarded (builder core rule) — write `static::new_query()->chunk(...)`.

### 4.2 Offset paging — `chunk`, `lazy`

- If the query has no `ORDER BY`, an ascending order on the primary key is added (every column of a composite key,
  qualified with the model table). An existing order is kept.
- An existing `offset` is the start; an existing `limit` caps the total rows. `limit(0)` reads nothing.
- Page *k* (from 0): `offset = start + k·count`, `limit = min(count, remaining)`.
- Stops when a page returns fewer rows than it asked for (no extra query), when the limit is used up, or when the
  callback returns `false`.
- **Not safe when the loop changes the rows it reads** (deletes, or updates of a filtered column): documented, with
  `chunk_by_id` as the answer — as Laravel's docs do.

### 4.3 Keyset paging — `chunk_by_id`, `chunk_by_id_desc`, `lazy_by_id`, `lazy_by_id_desc`

The position is the last key value seen, not a row count:

```sql
SELECT ... WHERE (<user's where>) AND id > ? [AND id <= ?] ORDER BY id LIMIT ?
```

- `$column` defaults to the single primary key. It is a column reference as the builder reads it (model column or
  alias; qualified with the model table when the query joins).
- `$alias` defaults to the unqualified `$column`: the attribute the last key value is read from on the last model of a
  page (for a key selected under another name).
- An existing order on `$column` itself is replaced; any other order throws (§4.6).
- An existing `limit` caps the total rows; an `offset` throws (§4.6).
- The first page has no `>` condition. Each next page: `where($column, '>', $last)` (`'<'` for `_desc`), the order on
  `$column` (desc for `_desc`), `limit(min($count, remaining))`.
- `$last` is the alias attribute's value, exact: an INTEGER beyond `PHP_INT_MAX` stays its digit string (§2) and is
  bound as such.
- **`include_new_rows: false`** (default; ascending variants only): before the first page,
  `$bound = clone()->max($column)` over the same filtered query (no order, no limit). `null` means no matching row:
  return without calling the callback. Every page adds `where($column, '<=', $bound)`. Rows inserted during the run
  (beyond the bound) are not processed. **`true`**: no bound, as Eloquent's `chunkById` — rows inserted during the run
  are processed, and a run that keeps inserting may never end (documented).
- The `_desc` variants need no bound: they start from the highest key at their first page, and rows inserted later
  with higher keys are behind them.
- Stops as offset paging does (§4.2).
- The key must be unique and must not change during the run (documented, not detected).

### 4.4 `cursor()` — buffered (default)

- Compiles the query's SELECT, executes it with `Connection::query()`, then fetches and hydrates **one row at a time**
  (`Table::hydrate()`), yielding each model. Memory: the driver's raw buffer plus one model (§2: ~5× less than `get()`).
- Other queries on the same connection work inside the loop.
- Snapshot on MySQL/MariaDB/Postgres; **live on SQLite** (it can see rows the loop inserts ahead of it) — documented.
- `finally`: `closeCursor()`.

### 4.5 `cursor(stream: true)`

- Executes through `Connection::stream_query()`: on MySQL/MariaDB (`MysqlAdapter`), buffered queries are turned off
  only around `execute()`, then restored, so the rows come from the server one at a time. The constant is
  `Pdo\Mysql::ATTR_USE_BUFFERED_QUERY` where it exists (PHP ≥ 8.4), else `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` (PHP 8.3);
  no deprecation may be raised on PHP 8.5 (§10).
- **The connection is busy** from the `execute()` until the generator finishes or is destroyed, **on every adapter**
  (not only MySQL), so code tried on SQLite behaves as it will on MySQL. While busy, any other query, transaction
  start, commit or rollback on that connection throws `ConnectionBusy` — including the schema introspection of a model
  not loaded yet and a lazy relationship read inside the loop. Other connections are unaffected. A second stream on the
  same connection is "another query".
- Released in the generator's `finally`, which PHP runs when the loop ends, on `break` (a `foreach` over the call
  result destroys the generator), on an exception, and when a generator held in a variable is destroyed (`unset()`, end
  of scope). **Gotcha (documented):** a cursor assigned to a variable and left with `break` keeps the connection busy
  until the variable is unset or goes out of scope.
- Postgres: PDO pgsql buffers the whole result anyway, so `stream` saves no memory there, but the busy rule applies.
  SQLite: steps natively; the busy rule applies.
- A stream inside an open transaction is allowed; commit/rollback while it is open throw `ConnectionBusy`.
- A stream read more slowly than MySQL's `net_write_timeout` fails with 2006 (documented).

### 4.6 Deviations from Eloquent (all throw before any SQL unless stated)

| # | Case | Eloquent | Here |
|---|---|---|---|
| 1 | `$count` / `$chunk_size` < 1 | `lazy*` throw; the `chunk*` guard is not uniform | every method throws |
| 2 | keyset with an `ORDER BY` on another column | keeps it, silently skips rows | throws |
| 3 | keyset with an `offset` | not guarded | throws |
| 4 | keyset on a composite primary key without `$column` | n/a | throws |
| 5 | `cursor()` with `with()` | eager loads silently ignored | throws (use `lazy()`) |
| 6 | stream lock | n/a | on every adapter (§4.5) |
| 7 | `include_new_rows` | always includes new rows | opt-in, default `false` (§4.3) |

---

## 5. Errors

| Condition | Exception | When |
|---|---|---|
| §4.6 rows 1–5; an unknown `$column` | `ActiveRecordException`, message names the method | at the call, before any SQL |
| keyset: the alias attribute is missing or `null` on a page's last model | `ActiveRecordException` (as Eloquent's `RuntimeException`: "…the [alias] column is not present in the query result") | after that page (a `select_raw` cannot be checked earlier) |
| any query / transaction / commit / rollback on a busy connection | `ConnectionBusy` | at that call |
| a database error while executing or fetching (e.g. 2006) | `DatabaseException` | at the failing step; lock released |
| an exception from the callback or the loop body | propagates unchanged | lock and statement released in `finally` |

`ConnectionBusy`'s message says the connection is streaming a cursor (`cursor(stream: true)`) and to finish or `unset()`
it before running another query.

---

## 6. Backward compatibility

- Additive only: seven new forwarded names, one new exception class, `@internal` methods on `Table` and `Connection`.
- **A consumer model's own static with one of the seven names** shadows the forwarded method; nothing breaks
  (`RCModel::chunk` keeps its behavior). Pinned by a test (§7.2).
- A forwarded name was an "undefined method" `ActiveRecordException` before; now it works. Not a break.
- `find_by_sql()`'s behavior is unchanged by the `hydrate()` extraction (existing tests guard it).
- `Connection::query()` keeps its signature. A consumer adapter that overrides `query()` is not reached by
  `stream_query()`; documented in the method's docblock.
- No dependency or PHP floor change.

---

## 7. Testing, documentation, release

### 7.1 Gates

The full suite on MySQL (default), MariaDB and SQLite through `PHPAR_CONNECTION`, on PHP 8.3 and 8.5 — **no skips**;
PHPStan level 8 with **no new baseline entry**; `composer run cs`. New classes ≥ 90% line coverage.

### 7.2 Tests

New `test/QueryChunkTest.php`, `test/QueryLazyTest.php`, `test/QueryCursorTest.php` (extend `DatabaseTest`, run on
every adapter), plus adapter-specific cases in the existing `*QueryBuilderTest` / `MysqlAdapterTest` files.

- **Keyset correctness:** deleting every row of each page inside `chunk_by_id` processes all rows; updating a filtered
  column inside the loop processes all rows; `_desc` order; `$column` / `$alias`; `limit` as a total cap.
- **`include_new_rows`:** `false` — rows inserted during the run (same table) are not processed; `true` — they are;
  inserts into another table inside the loop work.
- **Offset paging:** implicit pk order (single and composite), existing order kept, `offset` + `limit`, `limit(0)`.
- **Common:** page numbers; stop on `false` and the return value; no rows → callback never called, `true`; the
  original query is unchanged afterwards; `readonly()`; `with()` runs one eager-load query per page (counted with a
  query-counting logger), not one per row; generators' sequential keys; validation errors at the call, before the
  first iteration; every §4.6 throw.
- **Cursor:** yields the same models as `get()`; `with()` throws; buffered — another query inside the loop works.
- **Stream:** another query, `transaction()`, `commit()`, `rollback()` and a second stream throw `ConnectionBusy`; the
  lock is released after a full loop, after `break`, after `unset()` of a held cursor, after an exception in the body;
  another connection is unaffected; inside a transaction.
- **MySQL/MariaDB:** the buffered-query attribute is off during `execute()` and restored after (both on success and on
  a failing execute); keyset exact on `big_ids` up to 2^64−1.
- **SQLite:** the live cursor behavior, pinned as documented.
- **Forwarding / BC:** `Model::chunk(...)` etc. forward to the builder; a test model with its own static
  `chunk(int, array, callable)` (the `RCModel` shape) keeps it, with no fatal; `Query::FORWARDABLE` matches the
  `@method` tags (existing test).

### 7.3 Documentation and release

- README, in the query builder section: the seven methods; offset vs keyset (when to use which, the Laravel warning);
  `include_new_rows`; cursor buffered vs stream, the busy rule, the `break` + variable gotcha, `net_write_timeout`,
  Postgres and SQLite notes; the shadowing note for models with their own `chunk`.
- Draft GitHub release (on merge, per the changelog policy): "Added: chunk / chunk_by_id(_desc) / lazy /
  lazy_by_id(_desc) / cursor on the query builder" — not breaking. Monolith note: `RCModel::chunk` keeps shadowing the
  builder's on `RCModel` subclasses; the builder's iteration bypasses `RCModel`'s soft delete until Scopes ship, so use
  `where_null('deleted_at')` on soft-delete models; `delivery.php` can move to
  `X::where(...)->with([...])->chunk_by_id(...)` to drop its N+1.

---

## 8. Sequencing and delivery

Two stacked PRs, each green on its own:

1. **PR A — paging:** `QueryPager`, `chunk`, `chunk_by_id`, `chunk_by_id_desc`, `lazy`, `lazy_by_id`,
   `lazy_by_id_desc`, their forwarding, tests, README part.
2. **PR B — cursor:** `Table::hydrate()` extraction, `Cursor`, `Connection::stream_query()` / `unbuffered()` / busy
   guard, `MysqlAdapter` hook, `ConnectionBusy`, `cursor`, tests, README part.

---

## 9. Out of scope (recorded)

`each`, `each_by_id`, `chunk_map`; unbuffered reads on Postgres; detecting a non-unique or changing keyset column;
soft delete / scopes (sub-project 3).

---

## 10. Verifications to do in the plan

1. PHP 8.3 and 8.5 run a suspended generator's `finally` on destruction, for the `break` and `unset()` cases of §4.5.
2. The MySQL buffered-query constant: `Pdo\Mysql::ATTR_USE_BUFFERED_QUERY` on PHP ≥ 8.4 and the `PDO::` constant on
   8.3, with no deprecation on PHP 8.5 (rebuild with `PHP_VERSION=8.5`).
3. Toggling the attribute around `execute()` still streams on the CI's MySQL and MariaDB versions (memory of a 100k-row
   stream stays flat).
4. `Query::max()` on a query with a `limit`: the bound is computed with the limit removed (§4.3); check that the
   builder's aggregate ignores or wraps a limit as expected.
5. The query-counting approach for the N+1 test (the `Connection` logger or `Table::$last_sql`).
