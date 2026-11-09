# Query Iteration (chunk, lazy, cursor) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Eloquent's `chunk`, `chunk_by_id`, `chunk_by_id_desc`, `lazy`, `lazy_by_id`, `lazy_by_id_desc` and `cursor(stream:)` to the query builder, forwarded on models.

**Architecture:** Thin public methods on `Query` validate and delegate. `QueryPager` (internal) owns one offset loop and one keyset loop as page generators, building every page from a clone of the query through the builder's own methods. `Cursor` (internal) hydrates one row at a time. Streaming goes through a new `Connection::stream_query()` that marks the connection busy until the cursor is released.

**Tech Stack:** PHP 8.3–8.5, PDO (MySQL 8+/MariaDB 10.11+/Postgres/SQLite), PHPUnit through `SnakeCase_PHPUnit_Framework_TestCase`, PHPStan level 8, php-cs-fixer (PER-CS 3.0).

**Spec:** `docs/superpowers/specs/2026-11-09-query-iteration-design.md` (§ references below point there).

## Global Constraints

- Everything runs in Docker: `docker compose exec tests composer run test` (MySQL default); other adapters with `docker compose exec -e PHPAR_CONNECTION=mariadb tests composer run test` and `-e PHPAR_CONNECTION=sqlite`. One test: `docker compose exec tests vendor/bin/phpunit --filter <name>`.
- **No skipped tests** (`--fail-on-skipped`); design so nothing skips in Docker.
- **PHPStan level 8, `phpstan-baseline.neon` frozen:** no new entries, no new ignores. `composer run analyse` green.
- `composer run cs` green (run `cs-fix` before committing).
- Modern PHP ≥ 8.3 idioms; **snake_case public API**; tests use `test_*`, `set_up()`, `assert_*`.
- Backward compatibility is a hard gate (spec §6): additive only. `Connection::query()` keeps its signature.
- No SQL outside the engine (spec §3.1): pages are built with `Query`'s public methods on a clone; the cursor compiles with `QueryCompiler::compile_select()`.
- New classes ≥ 90% line coverage.
- Every gate must pass on PHP 8.3 **and** 8.5 (`docker compose build --build-arg PHP_VERSION=8.5`, then rebuild back).
- Disk: truncate `test/log/query.log` and purge the test MySQL binlogs after each task's full-suite run. Never delete named volumes.
- Never run suites in the background or concurrently.
- Work in an isolated worktree with its own compose stack (`cd <worktree> && docker compose up -d`). Keep helper scripts in `<worktree>/.superpowers/sdd/2026-11-09-query-iteration/bin/` (git-ignored).
- Branches: PR A `feat/query-iteration-paging`, cut from `spec/query-iteration` (it carries the spec and this plan). PR B `feat/query-iteration-cursor`, stacked on PR A. Local only until the maintainer says push.

## Review Focus

1. **A keyset key read from a model whose `$alias_attribute` maps the key** (`chunk_by_id(..., 'label')` where `label` → `title`): the default `$alias` must be the real column's attribute name, not the alias. Test in Task 2.
2. **A cursor held in a variable and abandoned with `break`**: the connection stays busy until `unset()`; after `unset()` every query works. Test in Task 5.
3. **A failing `execute()` in a stream** (bad SQL): `DatabaseException`, the connection is NOT left busy, and the MySQL buffered attribute is restored. Tests in Tasks 5 and 6.
4. **`include_new_rows: false` with a filtered query whose matching max is lower than the table max**: rows beyond the filtered max that are updated during the run to match are not processed. Bound = MAX over the filtered query. Test in Task 2.
5. **A `chunk` on a joined query** (`join` + implicit pk order): the implicit order must be qualified with the model table (ambiguous column otherwise). Test in Task 1.

---

## PR A — paging (`feat/query-iteration-paging`)

### Task 1: Offset paging — `chunk` and `lazy`

**Files:**
- Create: `lib/QueryPager.php`
- Modify: `lib/Query.php` (public `chunk`, `lazy`; private helpers; `FORWARDABLE`)
- Modify: `lib/Model.php` (`@method static` tags next to the other builder tags, ~line 80–174)
- Create: `test/QueryChunkTest.php`, `test/QueryLazyTest.php`
- Create: `test/models/ChunkShadowingAuthor.php`

**Interfaces:**
- Produces:
  - `final class QueryPager` (`@internal`, `@template TModel of Model`): `__construct(private readonly Query $query)`.
  - `QueryPager::offset_pages(int $count): \Generator<int, list<TModel>>`: yields non-empty pages, key = page index from 0.
  - `QueryPager::models(\Generator $pages): \Generator<int, TModel>`: flattens pages with sequential keys from 0. Do NOT use `yield from` over the pages, because it would repeat keys.
  - `Query::chunk(int $count, callable $callback): bool`.
  - `Query::lazy(int $chunk_size = 1000): \Generator<int, TModel>`.
  - Private on `Query`, reused by Tasks 2 and 4:
    - `refuse_chunk_size(string $method, int $count): void` throws `ActiveRecordException` with `sprintf('%s(): the chunk size must be at least 1, %d given', $method, $count)`;
    - `run_pages(\Generator $pages, callable $callback): bool` calls `$callback($page, $index + 1)` and returns `false` on a strict `false`, else `true`.
  - Model `@method` tags: `@method static bool chunk(int $count, callable $callback)` and `@method static \Generator<int, static> lazy(int $chunk_size = 1000)`, with a one-line description in the style of the neighbors.

- [ ] **Step 1: Write the failing tests** in `test/QueryChunkTest.php` (`class QueryChunkTest extends DatabaseTest`). Fixtures: authors 1–4, books 1 (author 1) and 2 (author 2), dated_counts (2026-01-01 10:00), (2026-01-02 10:00). Collect each page as the list of `author_id`s.
  - `test_chunk_pages_in_pk_order_without_order`: `Author::chunk(3, $fn)` gives pages `[[1,2,3],[4]]`, page numbers `[1,2]`, returns `true`.
  - `test_chunk_keeps_existing_order`: `Author::order_by_desc('author_id')->chunk(3, $fn)` gives `[[4,3,2],[1]]`.
  - `test_chunk_honors_offset_and_limit`: `Author::order_by('author_id')->offset(1)->limit(2)->chunk(1, $fn)` gives `[[2],[3]]`.
  - `test_chunk_limit_zero_reads_nothing`: `Author::limit(0)->chunk(2, $fn)`: callback never called, returns `true`.
  - `test_chunk_exact_multiple_has_no_empty_page`: `Author::chunk(2, $fn)`: the callback is called exactly 2 times.
  - `test_chunk_stops_on_false`: the callback returns `false` on page 1; it is called once and `chunk` returns `false`.
  - `test_chunk_no_rows`: `Author::where('author_id', '>', 99)->chunk(2, $fn)`: never called, returns `true`.
  - `test_chunk_composite_pk_implicit_order`: `DatedCount::chunk(1, $fn)` gives `day` values `['2026-01-01','2026-01-02']`, and `DatedCount::table()->last_sql` orders by `day` then `at`.
  - `test_chunk_join_qualifies_implicit_order` (Review Focus 5): `Author::join('books', 'books.author_id', '=', 'authors.author_id')->select('authors.*')->chunk(10, $fn)` runs without error and gives `[[1,2]]`.
  - `test_chunk_leaves_query_unchanged`: `$q = Author::where('author_id', '>', 1); $before = $q->to_sql(); $q->chunk(1, fn() => null);` then `$q->to_sql() === $before`.
  - `test_chunk_size_below_one_throws`: for `0` and `-1`, an `ActiveRecordException` whose message contains `chunk(): the chunk size must be at least 1`, and `Author::table()->last_sql` is unchanged (no SQL ran).
  - `test_chunk_eager_loads_per_page`: with the statement-capturing helper below, `Author::with('books')->chunk(2, fn() => null)` runs exactly 2 statements on `books`, not 4.
  - `test_chunk_readonly`: `Author::readonly()` is not forwarded, so use `Author::new_query()->readonly()->chunk(2, $fn)`: every model `is_readonly()`.
  - `test_chunk_forwarded_and_shadowed`: `ChunkShadowingAuthor::chunk(2, [], $fn)` returns its own `-1` marker; `ChunkShadowingAuthor::where('author_id', '>', 0)->chunk(2, $fn)` returns `true` with pages `[[1,2],[3,4]]`.

  Statement-capturing helper (private in the test class):
  - signature `statements(callable $run): list<string>`;
  - swap the private `ActiveRecord\Connection::$logger` of `Author::table()->connection()` through `ReflectionProperty` for an `AbstractLogger` that records `$message`, then run `$run`;
  - restore the original logger in `finally`.

  `test/models/ChunkShadowingAuthor.php`: `class ChunkShadowingAuthor extends Author`, `$table_name = 'authors'`, and the `RCModel` shape `public static function chunk(int $size, array $options, callable $each): int { return -1; }`.

  `test/QueryLazyTest.php`:
  - `test_lazy_yields_models_with_sequential_keys`: `iterator_to_array(Author::lazy(3))` has keys `[0,1,2,3]` and ids `[1,2,3,4]`.
  - `test_lazy_validates_at_call`: `Author::lazy(0)` throws at the call, before any iteration.
  - `test_lazy_runs_no_sql_before_iteration`: `last_sql` is unchanged after `$g = Author::where('author_id', 1)->lazy();`.
  - `test_lazy_stops_reading_when_consumer_stops`: `foreach (Author::lazy(1) as $a) { break; }` runs exactly 1 statement on `authors`.

- [ ] **Step 2: Run them to verify they fail**
  Run: `docker compose exec tests vendor/bin/phpunit test/QueryChunkTest.php test/QueryLazyTest.php`
  Expected: FAIL / ERROR with `Call to undefined method: chunk` (and `lazy`).

- [ ] **Step 3: Implement `QueryPager::offset_pages()` and `models()`, then `Query::chunk()` / `lazy()`**
  - `offset_pages` follows spec §4.2. Read `limit`, `offset` and `orders` from `$this->query->state()`.
  - Implicit order when `orders === []`: one `order_by($pk)` per `Table::$pk` column on the clone. The builder qualifies it when the query joins.
  - Page *k* is `$this->query->clone()->offset($start + $k * $count)->limit(min($count, $remaining))->get()`, with `$remaining = null` meaning unbounded.
  - Stop after yielding a page shorter than requested, when `$remaining` reaches 0, or on an empty page (not yielded).
  - `lazy()` is NOT a generator itself: it validates, then returns `(new QueryPager($this))->models(...)`.
  - Add `'chunk'` and `'lazy'` to `Query::FORWARDABLE`, and add the two `@method` tags.

- [ ] **Step 4: Run them to verify they pass**, plus `test/QueryForwardingTest.php` (FORWARDABLE ↔ `@method` check).
  Run: `docker compose exec tests vendor/bin/phpunit test/QueryChunkTest.php test/QueryLazyTest.php test/QueryForwardingTest.php`
  Expected: PASS.

- [ ] **Step 5: Full suite on mysql, mariadb, sqlite + `analyse` + `cs`**, all green.

- [ ] **Step 6: Commit**
  `git commit -m "feat: chunk() and lazy() on the query builder (offset paging)"`

### Task 2: Keyset paging — `chunk_by_id`, `chunk_by_id_desc`, `lazy_by_id`, `lazy_by_id_desc`

**Files:**
- Modify: `lib/QueryPager.php`, `lib/Query.php`, `lib/Model.php`
- Create: `test/QueryChunkByIdTest.php`, `test/models/KeysetAliasAuthor.php`
- Modify: `test/MysqlQueryBuilderTest.php` (inherited by `MariadbQueryBuilderTest`)

**Interfaces:**
- Consumes (from Task 1): `QueryPager::models()`, `Query::refuse_chunk_size()`, `Query::run_pages()`.
- Produces:
  - `QueryPager::keyset_pages(int $count, string $column, string $alias, bool $desc, bool $include_new_rows): \Generator<int, list<TModel>>`
  - `Query::chunk_by_id(int $count, callable $callback, ?string $column = null, ?string $alias = null, bool $include_new_rows = false): bool`
  - `Query::chunk_by_id_desc(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool`
  - `Query::lazy_by_id(int $chunk_size = 1000, ?string $column = null, ?string $alias = null, bool $include_new_rows = false): \Generator<int, TModel>`
  - `Query::lazy_by_id_desc(int $chunk_size = 1000, ?string $column = null, ?string $alias = null): \Generator<int, TModel>`
  - Private `Query::keyset_key(string $method, ?string $column, ?string $alias): array{0: string, 1: string}` returns `[column, alias]` after the checks below.

- [ ] **Step 1: Write the failing tests** in `test/QueryChunkByIdTest.php`.
  - `test_chunk_by_id_pages_by_key`: `Author::chunk_by_id(3, $fn)` gives `[[1,2,3],[4]]` and returns `true`.
  - `test_chunk_by_id_desc`: `Author::chunk_by_id_desc(3, $fn)` gives `[[4,3,2],[1]]`.
  - `test_chunk_by_id_survives_deletes_in_loop`: deleting every model of each page inside `chunk_by_id(1, …)` sees `[1,2,3,4]`, and `Author::count() === 0` afterwards.
  - `test_chunk_by_id_survives_updates_of_filtered_column`: `Author::where('parent_author_id', 2)->chunk_by_id(1, …)` sets `parent_author_id = 9` on each model and sees `[2,4]`.
  - `test_include_new_rows_false_skips_inserted`: on page 1, `Author::create(['name' => 'New'])` once; the ids seen are `[1,2,3,4]`.
  - `test_include_new_rows_true_processes_inserted`: same, with `include_new_rows: true`; the seen ids end with the new author's id (5 rows seen).
  - `test_bound_is_max_of_filtered_query` (Review Focus 4): `Author::where('parent_author_id', 3)->chunk_by_id(1, …)` matches author 1 only (bound = 1); during page 1 set `parent_author_id = 3` on author 2. Seen ids: `[1]` (author 2 matches now, but its key 2 > bound 1).
  - `test_insert_into_other_table_in_loop`: `Book::create(['author_id' => 1, 'name' => 'Inside'])` inside the callback works on every page.
  - `test_limit_caps_total`: `Author::limit(3)->chunk_by_id(2, $fn)` gives `[[1,2],[3]]`.
  - `test_order_on_key_is_replaced`: `Author::order_by('author_id')->chunk_by_id(2, $fn)` works.
  - `test_other_order_throws`: `Author::order_by('name')->chunk_by_id(2, $fn)` throws `ActiveRecordException` containing `chunk_by_id()` and `ordered by`, before any SQL.
  - `test_offset_throws`: `Author::offset(1)->chunk_by_id(2, $fn)` throws, before any SQL.
  - `test_composite_pk_needs_column`: `DatedCount::new_query()->chunk_by_id(1, $fn)` throws; with `'at'` it gives 2 pages.
  - `test_unknown_column_throws`: `chunk_by_id(1, $fn, 'nope')` throws, before any SQL.
  - `test_alias_select`: `Author::select('author_id as aid', 'name')->chunk_by_id(2, $fn, 'author_id', 'aid')` gives `[[1,2],[3,4]]`.
  - `test_missing_alias_throws_after_first_page`: `Author::select('name')->chunk_by_id(2, $fn)` throws `ActiveRecordException` containing `[author_id] column is not present in the query result`; the callback ran 0 times.
  - `test_aliased_key_column` (Review Focus 1): new test model `test/models/KeysetAliasAuthor.php` (`$table_name = 'authors'`, `$alias_attribute = ['ref' => 'author_id']`); `KeysetAliasAuthor::chunk_by_id(2, $fn, 'ref')` gives `[[1,2],[3,4]]` — the default `$alias` resolves to the real attribute `author_id`.
  - `test_big_int_max_key`: `BigId::chunk_by_id(1, $fn)` gives ids `[1, PHP_INT_MAX]` (every adapter).
  - `test_lazy_by_id_and_desc`: `iterator_to_array(Author::lazy_by_id(3))` gives ids `[1,2,3,4]` with keys `[0..3]`; `lazy_by_id_desc(3)` gives `[4,3,2,1]`. `lazy_by_id(0)` throws at the call.

  In `test/MysqlQueryBuilderTest.php`:
  - `test_chunk_by_id_exact_beyond_signed_bigint`: insert `big_ids` rows `'18446744073709551614'` and `'18446744073709551615'`; `BigId::chunk_by_id(1, $fn)` gives ids exactly `[1, PHP_INT_MAX, '18446744073709551614', '18446744073709551615']` (`assert_same`).

- [ ] **Step 2: Run them to verify they fail**
  Run: `docker compose exec tests vendor/bin/phpunit test/QueryChunkByIdTest.php`
  Expected: FAIL / ERROR, undefined methods.

- [ ] **Step 3: Implement `keyset_key()`**, rules in order, each an `ActiveRecordException` naming the method, thrown before any SQL:
  1. Default `$column` is the single `Table::$pk` column; a composite pk without `$column` throws.
  2. `$column` must be a column of the model: `QueryCompiler::model_column($column) !== null`, or a qualified `table.column` whose table is the model's table (or the `from()` alias) and whose column passes the same check. Anything else throws ("unknown column").
  3. `state()->offset !== null` throws.
  4. Every entry of `state()->orders` must be `type === 'column'` and resolve (via `model_column`) to the same `Column` as the key. Any other entry, raw or random included, throws.
  5. Default `$alias` is `model_column($column)->inflected_name`, the attribute key of `Model::attributes()`. This covers Review Focus 1.

- [ ] **Step 4: Implement `keyset_pages()`** per spec §4.3:
  - Bound when `!$desc && !$include_new_rows`: `$probe = $this->query->clone()`, then set `$probe->state()->limit = null`, `offset = null`, `orders = []`, then `$probe->max($column)`. `null` means return without yielding. This also covers spec §10.4.
  - Page: `clone()->reorder($column, $desc ? 'desc' : 'asc')->limit(min($count, $remaining))`. Add `where($column, $desc ? '<' : '>', $last)` after the first page, and `where($column, '<=', $bound)` when bound. Then `get()`.
  - `$last = $page[array_key_last($page)]->attributes()[$alias] ?? null`. Missing or null throws `sprintf('%s() was aborted because the [%s] column is not present in the query result', $method, $alias)`. Pass `$method` into the pager.
  - Stop rules as Task 1.

- [ ] **Step 5: Wire the four public methods.** `lazy_by_id*` are not generators themselves (validate, then return `models(...)`). Add the four names to `FORWARDABLE` and the `@method` tags.

- [ ] **Step 6: Run the new tests**, `QueryForwardingTest`, and `MysqlQueryBuilderTest` + `MariadbQueryBuilderTest`. Expected: PASS.

- [ ] **Step 7: Full suite on mysql, mariadb, sqlite + `analyse` + `cs`**, all green.

- [ ] **Step 8: Commit**
  `git commit -m "feat: chunk_by_id/lazy_by_id (+ _desc) keyset paging with include_new_rows"`

### Task 3: README for paging + PR A gate

**Files:**
- Modify: `README.md` (query builder section, after the read terminals; the forwarding notes are near line 375)

- [ ] **Step 1: Write the README subsection "Iterating over many rows"** per spec §7.3, paging parts only:
  - the six methods, with one example each in snake_case;
  - offset vs keyset: when to use which, and the warning that `chunk` skips rows if the loop deletes or updates them, so use `chunk_by_id`;
  - `include_new_rows`;
  - the deviations (spec §4.6, rows 1–4 and 7);
  - the shadowing note: a model's own static `chunk` wins; use `X::new_query()->chunk(...)`;
  - the soft-delete note: consumer finder overrides are bypassed.

  Examples use `[]` and named args.
- [ ] **Step 2: PR A gate**: full suite on mysql, mariadb, sqlite; `analyse`; `cs`; then the same on PHP 8.5 (`docker compose build --build-arg PHP_VERSION=8.5 && docker compose up -d`), then rebuild back to 8.3. All green, no skips. Coverage of `lib/QueryPager.php` ≥ 90%: `docker compose exec tests vendor/bin/phpunit --coverage-text --filter 'QueryChunk|QueryLazy'`.
- [ ] **Step 3: Commit**
  `git commit -m "docs: iterating over many rows with chunk/lazy (README)"`

---

## PR B — cursor (`feat/query-iteration-cursor`, stacked on PR A)

### Task 4: `Table::hydrate()` and the buffered `cursor()`

**Files:**
- Modify: `lib/Table.php` (`find_by_sql()` ~line 301)
- Create: `lib/Cursor.php`
- Modify: `lib/Query.php`, `lib/Model.php`
- Create: `test/QueryCursorTest.php`
- Modify: `test/SqliteQueryBuilderTest.php`

**Interfaces:**
- Produces:
  - `Table::hydrate(array $row, bool $readonly): Model` (`@internal`). This is the body of `find_by_sql()`'s loop: `new $this->class->name($row, false, true, false)`, then `readonly()` when asked. `find_by_sql()` calls it.
  - `final class Cursor` (`@internal`, `@template TModel of Model`):
    - `__construct(private readonly Table $table, private readonly SQLBuilder $sql, private readonly bool $readonly, private readonly bool $stream)`;
    - `models(): \Generator<int, TModel>` with sequential keys from 0.
  - `Query::cursor(): \Generator<int, TModel>`. It compiles at the call (`QueryCompiler::compile_select($this->state)`), so later changes to the query do not affect the cursor. It is not a generator itself.

- [ ] **Step 1: Write the failing tests** in `test/QueryCursorTest.php`:
  - `test_cursor_yields_same_models_as_get`: the ids and attributes of `iterator_to_array(Author::order_by('author_id')->cursor())` equal those of `->get()`, with keys `[0..3]`.
  - `test_cursor_runs_no_sql_before_iteration`: `last_sql` is unchanged after `$c = Author::where('author_id', 2)->cursor();`.
  - `test_cursor_with_eager_load_throws`: `Author::with('books')->cursor()` throws `ActiveRecordException` containing `cursor()` and `lazy()`, at the call.
  - `test_buffered_cursor_allows_other_queries`: inside the loop, `Author::find(1)` works.
  - `test_cursor_readonly`: `Author::new_query()->readonly()->cursor()`: every model `is_readonly()`.
  - `test_cursor_binds_dates`: `DatedCount::where('day', new ActiveRecord\DateTime('2026-01-02'))->cursor()` yields 1 model (binds prepared with `Table::process_data()`, as `find_by_sql()` does).
  - `test_cursor_forwarded`: `Author::cursor()` works from the model.

  In `test/SqliteQueryBuilderTest.php`:
  - `test_cursor_is_live_on_sqlite`: over `Author::order_by('author_id')->cursor()`, create author `'Ahead'` on the first iteration; the yielded names include `'Ahead'`. This pins the documented behavior.

- [ ] **Step 2: Run them to verify they fail.** Expected: ERROR, undefined method `cursor`.

- [ ] **Step 3: Extract `Table::hydrate()`.** Run `test/ActiveRecordFindTest.php` and `test/QueryReadTest.php`: unchanged, PASS.

- [ ] **Step 4: Implement `Cursor::models()`** (buffered path only; `$stream` is always `false` in this task):
  - `$values = $table->process_data($sql->select_bind_values()) ?? []`;
  - `$sth = $table->connection()->query($sql->to_s(), $values)`;
  - set `Table::$last_sql`;
  - `while ($row = $sth->fetch())`, yield `hydrate()`;
  - `finally { $sth?->closeCursor(); }`.
- [ ] **Step 5: Implement `Query::cursor()`**: refuse `with()` (`state()->includes !== []`, or a legacy `include`) with `sprintf('%s() cannot eager load relationships (with()); use lazy() instead', 'cursor')`. Then compile and return `(new Cursor(...))->models()`. Add to `FORWARDABLE` and the `@method` tags.
- [ ] **Step 6: Run the tests.** Expected: PASS. Then the full suite on mysql, mariadb, sqlite + `analyse` + `cs`.
- [ ] **Step 7: Commit**
  `git commit -m "feat: cursor() on the query builder, hydrating one row at a time"`

### Task 5: Streaming — busy connection, `ConnectionBusy`, `cursor(stream: true)`

**Files:**
- Create: `lib/exceptions/ConnectionBusy.php`
- Modify: `lib/Connection.php` (`query()` ~line 350, `transaction()`/`commit()`/`rollback()` ~lines 497–550)
- Modify: `lib/Cursor.php`, `lib/Query.php`, `lib/Model.php`
- Modify: `test/QueryCursorTest.php`, `test/PgsqlQueryBuilderTest.php`

**Interfaces:**
- Consumes: `Cursor`, `Query::cursor()` (Task 4).
- Produces:
  - `class ConnectionBusy extends ActiveRecordException {}` (same shape as `MultipleRecordsFound`).
  - `Connection::stream_query(string $sql, array $values = []): \PDOStatement` (`@internal`). It refuses when busy, prepares and binds as `query()` does, runs `bind_values()` inside `$this->unbuffered(...)`, and marks the connection busy only after a successful execute.
  - `Connection::release_stream(): void` (`@internal`), which clears busy.
  - `protected function unbuffered(Closure $execute): mixed` (`@internal` hook), default `return $execute();`.
  - Private `refuse_when_busy(string $method): void` throws `ConnectionBusy` with `sprintf('%s() cannot run while the connection streams a cursor (cursor(stream: true)): iterate the cursor to its end, or unset() it, first', $method)`. Called first in `query()`, `stream_query()`, `transaction()`, `commit()`, `rollback()`.
    *Changed in the final review: rollback() closes the stream (spec §4.5).*
  - `Query::cursor(bool $stream = false)`.
  - Share the prepare/bind code of `query()` and `stream_query()` through one private method. `query()` keeps its signature and logging.

- [ ] **Step 1: Write the failing tests**, appended to `test/QueryCursorTest.php`. Each runs on every adapter.
  - `test_stream_blocks_other_queries`: inside `foreach (Author::new_query()->cursor(stream: true) as $a)`, `Author::find(1)` throws `ConnectionBusy` containing `query()`. After the loop (run to its end), `Author::find(1)` works.
  - `test_stream_blocks_transactions`: inside the loop, `transaction()`, `commit()` and `rollback()` on `Author::table()->connection()` each throw `ConnectionBusy`.
    *Changed in the final review: rollback() closes the stream (spec §4.5).*
  - `test_second_stream_is_refused`: a nested `Author::new_query()->cursor(stream: true)` throws `ConnectionBusy` at its first iteration.
  - `test_break_from_direct_foreach_releases`: after `foreach (... cursor(stream: true) as $a) { break; }`, `Author::find(1)` works.
  - `test_held_cursor_keeps_busy_until_unset` (Review Focus 2): `$c = Author::new_query()->cursor(stream: true); foreach ($c as $a) { break; }` — `Author::find(1)` throws `ConnectionBusy`. After `unset($c)`, it works.
  - `test_exception_in_body_releases`: throw a `RuntimeException` inside the loop and catch it outside; then `Author::find(1)` works.
  - `test_failing_stream_execute_leaves_connection_free` (Review Focus 3): `Author::table()->connection()->stream_query('SELECT * FROM no_such_table')` throws `DatabaseException`; then `Author::find(1)` works.
  - `test_stream_inside_transaction`: open `transaction()`, fully iterate a stream, then `commit()` works. Inside a second loop, `commit()` throws `ConnectionBusy`; finish the loop, then `rollback()` works.
  - `test_other_connection_unaffected`: `$other = ActiveRecord\Connection::instance(ActiveRecord\Config::instance()->get_default_connection())` (a new object); inside the stream loop, `$other->query('SELECT 1')` works.
  - `test_stream_yields_same_models`: stream and buffered yield the same ids.

  In `test/PgsqlQueryBuilderTest.php`:
  - `test_stream_busy_rule_applies_on_pgsql`: a stream loop plus another query gives `ConnectionBusy`.

- [ ] **Step 2: Run them to verify they fail.** Expected: ERROR (unknown named parameter `stream`, and missing `ConnectionBusy` / `stream_query`).

- [ ] **Step 3: Implement** `ConnectionBusy` and the `Connection` members above, then the stream path of `Cursor::models()`:
  - `stream_query()` instead of `query()`;
  - `finally { closeCursor(); release_stream(); }`, where `release_stream()` runs only if `stream_query()` returned. This covers Review Focus 3.

  Then pass `$stream` from `Query::cursor()`.
- [ ] **Step 4: Run the tests.** Expected: PASS. This also verifies spec §10.1 on 8.3: the `break` and `unset()` cases pass only if the generator's `finally` runs on destruction.
- [ ] **Step 5: Full suite on mysql, mariadb, sqlite + `analyse` + `cs`.**
- [ ] **Step 6: Commit**
  `git commit -m "feat: cursor(stream: true) with a busy connection guard (ConnectionBusy)"`

### Task 6: MySQL/MariaDB unbuffered stream

**Files:**
- Modify: `lib/adapters/MysqlAdapter.php`
- Modify: `test/MysqlQueryBuilderTest.php` (inherited by `MariadbQueryBuilderTest`)

**Interfaces:**
- Consumes: `Connection::unbuffered()` hook (Task 5).
- Produces:
  - `MysqlAdapter::buffered_query_attribute(): int` (`@internal`, public static). It returns `\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY` when `defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')`, else `constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')`. Use `constant()` so PHP 8.5 never touches the deprecated name.
  - `MysqlAdapter::unbuffered(Closure $execute): mixed`: read the attribute, set it to `false`, run `$execute()`, and restore the previous value in `finally`.

- [ ] **Step 1: Write the failing tests** in `test/MysqlQueryBuilderTest.php`:
  - `test_stream_is_unbuffered`: inside a `cursor(stream: true)` loop over authors, a raw `$this->conn->connection->query('SELECT 1')` (bypassing the guard) throws `PDOException` whose message contains `2014`. The same raw query inside a buffered `cursor()` loop works.
  - `test_buffered_attribute_restored`: after a full stream loop, `$this->conn->connection->getAttribute(MysqlAdapter::buffered_query_attribute())` is truthy.
  - `test_buffered_attribute_restored_after_failing_execute`: `stream_query('SELECT * FROM no_such_table')` throws `DatabaseException`; the attribute is truthy; `Author::find(1)` works.
- [ ] **Step 2: Run them to verify they fail.** `test_stream_is_unbuffered` fails, because no 2014 is raised while still buffered.
- [ ] **Step 3: Implement** the two `MysqlAdapter` members.
- [ ] **Step 4: Run** `MysqlQueryBuilderTest` and `MariadbQueryBuilderTest`. Expected: PASS.
- [ ] **Step 5: Verify spec §10.3** (memory stays flat) with a script in `.superpowers/sdd/2026-11-09-query-iteration/bin/stream-memory.php`:
  - insert 100 000 rows into a scratch table on mysql;
  - compare `memory_get_peak_usage()` growth while iterating `cursor(stream: true)` vs `cursor()`;
  - run it on mysql and mariadb, then drop the table.

  Expected: stream growth < 5 MB; buffered growth > 15 MB. Record the numbers in the commit message body.
- [ ] **Step 6: Full suite on mysql, mariadb, sqlite + `analyse` + `cs`.**
- [ ] **Step 7: Commit**
  `git commit -m "feat: MySQL/MariaDB cursor streams unbuffered (buffering off only around execute)"`

### Task 7: README for cursor + PR B gate on PHP 8.3 and 8.5

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Extend "Iterating over many rows"** per spec §7.3:
  - `cursor()` buffered vs `cursor(stream: true)`;
  - memory notes;
  - the busy rule and `ConnectionBusy`;
  - the `break` + held-variable gotcha;
  - `net_write_timeout` (2006);
  - Postgres (no memory saving) and SQLite (live cursor);
  - `cursor()` refuses `with()`, so use `lazy()`.
- [ ] **Step 2: Verify spec §10.1 and §10.2 on PHP 8.5**: rebuild with `PHP_VERSION=8.5`, then run the full suite on mysql, mariadb and sqlite plus `analyse`. Expected: green, no skips, and no `Deprecated` line in the PHPUnit output (`grep -i deprecat` on it is empty). Rebuild back to 8.3.
- [ ] **Step 3: Coverage check:** `lib/QueryPager.php` and `lib/Cursor.php` ≥ 90%, `ConnectionBusy` covered.
- [ ] **Step 4: Draft the release-notes text** (spec §7.3: "Added", not breaking, plus the monolith notes) into `.superpowers/sdd/2026-11-09-query-iteration/release-notes.md`. It goes into the GitHub draft release only when the PRs merge.
- [ ] **Step 5: Commit**
  `git commit -m "docs: cursor() and streaming (README)"`

After Task 7: the final whole-branch review by two independent reviewers on different models, consolidated by the controller. Then hand off for push and PRs (the maintainer decides when).
