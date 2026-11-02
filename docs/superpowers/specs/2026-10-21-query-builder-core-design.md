# Query builder core — one engine for every statement, Eloquent-style fluent API

**Date:** 2026-10-21
**Status:** Design approved section by section in chat (sections 1–6, 2026-10-21) — awaiting review of this written spec.
**Author:** Claude (paired with maintainer)
**Program:** sub-project 2 of 6 of the "Eloquent fluent query builder" program (see §1.2). It is built **first**.
**Related issues:** #145 (lands first, in its own PR), #146, #175, #92, #44, #35, #64, #34, #37, #47, #138, #149.
**Branches:** this document lives on `spec/query-builder-core`. The implementation branches are cut from `master` after the #145 fix merges.

---

## 1. Context

### 1.1 Where this started

The request was to implement Eloquent's *Chunking Results*, *Chunking Using Lazy Collections* and *Cursors*,
with a chunking that tolerates updates and inserts during the iteration. While designing it, the maintainer
widened the scope to **Eloquent's complete fluent query builder**. Its iteration methods are now one
sub-project of that program.

### 1.2 The program (agreed decomposition and order)

| # | Sub-project | Covers | Order |
|---|---|---|---|
| 2 | **Builder core** (this spec) | the single engine, the fluent API, read and write terminals | **1st** |
| 1 | Iteration | `chunk`, `chunk_by_id(_desc)`, `lazy`, `lazy_by_id(_desc)`, `cursor` (+ `stream`) as builder methods | 2nd |
| 3 | Scopes | local scopes (`scope_active` → `Order::active()`), global scopes, soft delete | 3rd |
| 4 | Relationship querying | `where_has`, `doesnt_have`, `where_relation`, `with_count`, `with_exists`, `with_sum`…, eager-load constraints, `$order->lines()->where(...)` | 4th |
| 5 | Writes extras and pagination | increment/decrement, `*_or_create`, `insert_or_ignore`, `update_or_insert`, `insert_get_id`, `paginate`, `simple_paginate`, `cursor_paginate` | 5th |
| 6 | Advanced SQL | subqueries (`select_sub`, `from_sub`, `join_sub`, `where_exists`, `where_in` with a closure), unions, locks, MySQL index hints, date/JSON/fulltext wheres | 6th |

Each sub-project has its own spec → plan → implementation cycle. The following are **out of the program**:

- dynamic wheres (`where_name('x')`), which in snake_case collide with `where_null` and `where_in`;
- morph relations (the library has none; its `poly_relationship` only means "returns many");
- vector search and lateral joins;
- macros;
- `dump` / `dd`, which depend on Symfony VarDumper in Laravel.

The decisions already taken for sub-project 1 are recorded in Appendix A.

### 1.3 Goals

1. Developers who know Eloquent write queries with **Eloquent's API and semantics in snake_case**:
   `Order::where('status', 'pending')->where_in('type', [1, 2])->order_by_desc('id')->limit(10)->get()`.
   Examples from the Laravel docs port by changing the method case, except for the deviations listed in §5.
2. **Maintainer's hard requirement:** there is *one* point of validation, SQL generation and
   database-data preparation, shared by the legacy options API and the builder, for every statement
   (SELECT, UPDATE, DELETE, INSERT, UPSERT).
3. No behavior change for code that works today, except the changes listed and approved in §6.2.

### 1.4 Non-goals

- Changing the semantics of the existing static methods (`find`, `first`, `last`, `all`, `count`, `exists`,
  `create`, `query`, `upsert`, `update_all`, `delete_all`…). They stay legacy, and a future major could align
  them (out of scope). One exception, decided by the maintainer on 2026-11-02: `count` runs the builder's `count()`
  (§5.3).
- Collections: terminals return arrays, as `find('all')` does. The library takes no dependency on Laravel.
- Soft delete in the builder: it comes from scopes (sub-project 3). Until then the builder bypasses the
  finder overrides of consumer subclasses (see §8.3).

---

## 2. Evidence

Verified on 2026-10-21 in the local Docker stack: PHP 8.3.35, MySQL 9.7, MariaDB 11.4, Postgres 18, SQLite 3.46.
The probe scripts are not committed.

### 2.1 Six places interpret user input today

Six methods each build their own `SQLBuilder` from user input:
- `Table::options_to_sql()`, used by `find`, `count`, `exists` and `and_pk_conditions`;
- `Model::delete_all()`;
- `Model::update_all()`;
- `Table::update()`;
- `Table::delete()`;
- `Table::insert()` / `upsert()`.

**They already diverge.** A conditions hash keyed by an alias (`Venue`: `'marquee' => 'name'`) works in
`find()`, but fails with `Unknown column 'marquee'` in `count()`, `exists()`, `update_all()` and `delete_all()`
on MySQL and SQLite. Only `find()` passes `mapped_names`. Twenty-two monolith models declare `$alias_attribute`.

### 2.2 Facts the design relies on

- **UPDATE with the same column assigned twice:** Postgres fails with `42601 multiple assignments to same
  column`. MySQL and SQLite accept it, and the last assignment wins. So a raw `SET` string must never get an
  engine-added column (§5.6).
- **Aggregates as the drivers return them** (library PDO options, `STRINGIFY_FETCHES=false`):

  | Aggregate | MySQL 9.7 | Postgres 18 | SQLite 3.46 |
  |---|---|---|---|
  | `SUM(INT)` | `'3'` (string) | `3` | `3` |
  | `AVG(INT)` | `'1.5000'` | `'1.5000000000000000'` | `1.5` (float) |
  | `AVG(BIGINT)` near 2^63 | `'4611686018427387850.0000'` | `'4611686018427387850'` | `4.611686018427388E+18` (inexact) |
  | `SUM` beyond 2^64 | `'18446744073709551615'` | `'18446744073709551615'` | error `integer overflow` |
  | `SUM(DECIMAL(20,2))` | `'1234567890123.46'` | `'1234567890123.46'` | float (DECIMAL is REAL storage) |
  | `SUM(DOUBLE)` | `0.30000000000000004` | `'0.30000000000000004'` (string) | `0.30000000000000004` |

  MySQL and Postgres always compute exactly, and only the **types** differ. SQLite is inexact for AVG, overflows
  on SUM, and stores DECIMAL as REAL.
- **Laravel 13.38** (read from `laravel/framework` source):
  - An unknown operator is silently taken as the value with `=` (`Query\Builder::where`).
  - `count()` wraps the query in a subquery only with `having` or `union`. So with `group_by` alone it returns
    the first group's count, and with an offset it returns 0.
  - `sum()` returns the driver's value as it is, or 0 when it is falsy, with no cast: the cast is in
    `numericAggregate()`, which `sum()` does not call (corrected by the final review, 2026-10-30, from the 13.x
    source; this line said that `sum()` casts with `(int)` or `(float)`).
- **`Column`** maps `float`, `double`, `numeric` and `decimal` all to `Column::DECIMAL` and keeps no scale.
  Exact vs floating point is told apart by `Column::$raw_type` (§5.4).
- **The monolith** (read-only check):
  - It never calls `options_to_sql`, `SQLBuilder` or `create_joins` directly.
  - It calls `update_all` 157 times outside tests, and only one of those calls sets `updated_at`.
  - `Table::insert()` is never called directly.
  - `upsert` is never called: it was born in this fork, and the monolith is still on `zamzar/php-activerecord v1.7.1`.

---

## 3. Architecture

### 3.1 Principle: one owner per responsibility, used by both APIs

| Responsibility | Single owner |
|---|---|
| Interpreting a query: conditions (string, positional, hash), aliases and real column names (#92), joins and the default select, order/limit/offset/group/having/from | `Query` (`from_options()` → state → `to_sql_builder()`) |
| Validating primary-key values (DATE pk, non-integer values for an INTEGER pk on Postgres #92) | moved from `Model`'s private helpers into the engine |
| Validating builder operators and arguments | `Query` |
| Generating SQL in the adapter's dialect | `SQLBuilder` + adapter hooks |
| Quoting identifiers | `Connection::quote_name()` / `split_name_parts()` |
| Placeholders and `IN(?)` lists | `Expressions` |
| Data sent to the database (DateTime by column type, bool → int, SQLite typed binds) | `Table::process_data()` + `Connection::bind_values()` |
| Exact decimal arithmetic | one internal helper (§3.2), shared with `Validations` |
| Timestamps ("now", which columns, the suspension) | one internal timestamp policy (§5.6) |
| Execution, hydration, eager loading | `Connection::query()` + `Table::find_by_sql()` |

### 3.2 Components

- **`lib/Query.php` (new) — `ActiveRecord\Query`.** The builder and the single engine.
  - One instance is one query on one model class (`@template TModel of Model`).
  - It is **mutable**, like Eloquent: methods change the state and return `$this`. Use `clone()` to fork.
  - It keeps a structured state and is the only code that turns that state into `SQLBuilder` calls
    (`to_sql_builder()`, `@internal`).
  - Its terminals execute every statement.
  - `Query::from_options(Table, array)` (`@internal`) records a legacy options array as "legacy entries". These
    replay the exact `SQLBuilder` calls `options_to_sql()` makes today, in the same order: joins before
    conditions, so hash keys get qualified (#35); `table.*` as the default select with joins; hashes through the
    name mapping (#92).
- **`lib/QueryState.php`, `lib/QueryCompiler.php` (new, `@internal`).** The query's structured state, and the code
  that turns it into `SQLBuilder` calls: `QueryCompiler::column()` is the single column-reference rule (§5.1).
- **`lib/Conditions.php`, `lib/ConditionCompiler.php` (new, `@internal`).** The where rules: `Conditions` validates
  and normalizes the arguments into condition shapes, and `ConditionCompiler` renders them. Every condition goes
  through them: `where*`, `JoinClause`'s `where*`, `having*`. Building shapes elsewhere would skip a rule, e.g. the
  Postgres `::text` cast of the LIKE operators.
- **`lib/IncludeTree.php`, `lib/RawSqlWriter.php` (new, `@internal`).** Pure helpers of `Query`: the include-tree
  rules of `with` / `without` / `with_only`, and the literal writer of `to_raw_sql()`.
- **`lib/JoinClause.php` (new).** The closure form of joins (`on`, `or_on`, `where`…), with bound values.
- **`lib/ExactDecimal.php` (new, `@internal`).** The exact-decimal helper, pure PHP, as decided in #175 (no
  bcmath/gmp, no extension requirement).
  - `parse()`, `integer_digits()` and `compare()` are #175's former `Validations` helpers, moved unchanged.
    `Validations` calls them with unchanged behavior; #175's tests guard that.
  - `to_int_or_string()` returns an `int` when the exact value fits `[PHP_INT_MIN, PHP_INT_MAX]`, decided by digit
    comparison, else the normalized digits.
  - `divide()` is an exact long division rounded half-up (away from zero) at a scale, with no negative zero.
  - Callers classify floats first: a float argument is coerced by PHP before the method runs.
  - `MAX_SCALE` is 16387 (Postgres's maximum numeric scale 16383, plus avg's 4); a larger scale throws.
- **`lib/AggregateCaster.php` (new, `@internal`).** It classifies the aggregated column (exact integer, exact
  decimal, floating, expression) and casts the driver's value by §5.4. `Connection::exact_decimals()` (`@internal`,
  default true, `SqliteAdapter` false) says whether the adapter stores DECIMAL exactly.
- **The timestamp policy (internal).** The single source of "now", the per-class suspension stack behind
  `Model::without_timestamps()`, and the rule of §5.6. `Model::set_timestamps()`, `upsert` and the engine all use it.
- **`lib/Exceptions.php`.** Gains `MultipleRecordsFound extends ActiveRecordException`.
- **`Table`.** `options_to_sql()`, `find()`, `insert()`, `insert_returning_pk()`, `update()`, `delete()` and
  `upsert()` keep their signatures and return values, but delegate to `Query`.
- **`Model`.**
  - `count()`, `exists()`, `update_all()`, `delete_all()` and the pk finders delegate to `Query`.
  - `__callStatic` forwards builder methods (§4.1).
  - New real statics: `new_query()` and `without_timestamps()`.
  - `@method static` tags for the forwarded methods.
- **`SQLBuilder`.** Gets additions only:
  - binds outside WHERE (select, join, having, order);
  - multi-row INSERT VALUES, generalized from `upsert`;
  - an accessor that returns all the binds of a statement in clause order;
  - letting the engine decide which hash keys get table-qualified (§5.1).

  The legacy path calls none of the additions in a way that changes its SQL.
- **`Relationship`.** `construct_inner_join_sql()` is generalized to a join type (INNER/LEFT) for
  `joins()` / `left_joins()`.
- **`ActiveRecord.php`.** Manual `require`s for the new files: there is no autoloader for `lib/`.

### 3.3 Flow

```
Legacy API (options / model)                   Builder API
find, first, last, all, count, exists ─┐       Order::where(...)->get() / ->count()
update_all / delete_all ───────────────┤       Order::where(...)->update([...]) / ->delete()
save / delete / upsert ────────────────┤       Order::new_query()->insert([...]) / ->upsert(...)
                                       ▼                    ▼
                            Query (state) ─▶ to_sql_builder() ─▶ SQLBuilder ─▶ adapter
                                       │
              data: Table::process_data / real_column_keys (one call site per statement kind)
              execution: Connection::query + Table::$last_sql; hydration: Table::find_by_sql
```

### 3.4 Legacy entries → engine

| Legacy entry | Goes through |
|---|---|
| `find` / `first` / `last` / `all` / by pk, `count`, `exists` | `Query::from_options(...)` → read terminal |
| `update_all($options)` | `Query::from_options(...)` (conditions/order/limit only) → `update($set)` |
| `delete_all($options)` | `Query::from_options(...)` (conditions/order/limit only) → `delete()` |
| `save()` of a new record → `Table::insert()` / `insert_returning_pk()` | `Query` → `insert()`, including RETURNING (#47) and sequences |
| `save()` of an existing record → `Table::update($data, $pk)` | `Query` → where on the pk → `update($data)` |
| `Model::delete()` → `Table::delete($pk)` | `Query` → where on the pk → `delete()` |
| `upsert()` → `Table::upsert()` | `Query` → `upsert(...)` |

---

## 4. Public API

### 4.1 Entry points on `Model`

- **`Order::new_query(): Query<Order>`.** A new real static. `Model::query()` already exists as the raw-SQL
  helper and keeps that meaning.
- **`Order::without_timestamps(callable $fn): mixed`.** A new real static. It suspends the timestamp rule (§5.6)
  for that class during `$fn` and returns `$fn`'s result. Suspensions nest, and the previous state is restored
  in a `finally`.
- **`Order::method(...)` via `__callStatic`.** It is `Order::new_query()->method(...)`, resolved in this order:
  1. dynamic finders (`find_by_*`, `find_all_by_*`, `count_by_*`, `find_or_create_by_*`), as today;
  2. `update` and `delete` throw an `ActiveRecordException` that points to `where(...)->update(...)` /
     `new_query()->delete()`, because forwarded statically they would write the whole table;
  3. a method on `Query::FORWARDABLE`, the explicit list of forwardable public methods, is forwarded;
  4. anything else throws `ActiveRecordException("Call to undefined method: …")`, today's message.

  The real statics that already exist never reach `__callStatic`, so they keep the legacy semantics. The
  forwarded methods are not real methods of `Model`, so they cannot clash with the methods of consumer
  subclasses.

### 4.2 `Query` methods

All names are snake_case, with the `or_` variants Eloquent has.

| Group | Methods |
|---|---|
| Selection | `select(...$columns)` (`'col as alias'` allowed), `add_select`, `select_raw($sql, $bindings = [])`, `distinct()`, `from($table, $as = null)`, `from_raw($sql, $bindings = [])` |
| Where | `where` / `or_where` / `where_not` / `or_where_not` in the forms (col, value), (col, op, value), hash, list `[[col, op, val], …]`, nested closure; `where_column`; `where_raw`; `where_in` / `where_not_in` (arrays); `where_null` / `where_not_null`; `where_between` / `where_not_between` / `where_between_columns`; `where_like` / `where_not_like`; `where_all` / `where_any` / `where_none`; `where_key` / `where_key_not` |
| Joins | `join`, `left_join`, `right_join`, `cross_join` (also with a closure on `JoinClause`); **`joins(...)`** (INNER) and **`left_joins(...)`** (LEFT) by relationship name. `joins` also accepts hand-written `JOIN …` fragments, as the legacy option does |
| Order and groups | `order_by($col, $dir = 'asc')`, `order_by_desc`, `order_by_raw`, `latest($col = 'created_at')`, `oldest`, `reorder`, `in_random_order()`, `group_by`, `group_by_raw`, `having` / `or_having`, `having_raw` / `or_having_raw`, `having_null` / `having_not_null`, `having_between` |
| Window | `limit` / `take`, `offset` / `skip`, `for_page($page, $per_page = 15)` |
| Model | `with(...)` (the `include`, also in dot notation `author.books`), `without(...)`, `with_only(...)`, `readonly($value = true)` |
| Utilities | `when($value, $callback, $default = null)`, `unless(...)`, `tap($callback)`, `clone()` |
| Reads | `get`, `first`, `first_or_fail`, `first_or`, `sole`, `find`, `find_or_fail`, `find_many`, `find_or`, `value`, `sole_value`, `pluck($col, $key = null)`, `exists`, `doesnt_exist`, `count($col = '*')`, `min`, `max`, `sum`, `avg` / `average` |
| Writes | `insert`, `update`, `delete`, `upsert` |
| Debug | `to_sql()`, `to_raw_sql()` (bindings inlined, for debugging only), `get_bindings()` |

`from_options()` and `to_sql_builder()` are `@internal` and are not on `FORWARDABLE`.

---

Details settled while building the utilities (Task 16):

- **`readonly($value = true)`** is a `Query` method, but it is **not forwardable**: `Model` has an instance
  `readonly()`, so a static `Author::readonly()` is PHP's own Error. Use `Author::new_query()->readonly()`.
- **`with(...)`** takes names, dot paths (`'author.books'`) and arrays. They merge into the include tree exactly as
  the `include` option takes it.
  - An undeclared relation is refused at the call (`RelationshipException`).
  - `without()` removes by path, so the parent stays as `name => []`, and does not look names up (Eloquent parity).
  - `with_only()` replaces the tree.
- **`when()` / `unless()`.**
  - A Closure value is evaluated first.
  - The callback receives `($query, $value)`. A Query on the same model that it returns is handed back; any other
    return is ignored. Eloquent returns any non-null result.
  - `tap()` calls back and returns the query.
- **`clone()`** is a deep copy: where groups, `JoinClause`s and includes. Mutating the clone never touches the
  original.
- **`to_raw_sql()`** is a debug aid, never for execution.
  - It replaces the markers in order with the binds.
  - Each value becomes the literal the adapter binds. Everything is quoted text through `Connection::escape()`, as
    `execute()` sends it, except a value the adapter binds as an integer, which is written bare: on SQLite, ints and
    bools turned into ints. `null` becomes `NULL`, and DateTimes are formatted as the builder binds them.
  - So the raw text runs by hand as the bound statement does on every adapter. Postgres refuses a bare `1` against a
    boolean or a bare `5` against a text column. SQLite compares a quoted `'1'` with an integer expression
    differently from the bound integer.
  - A marker/bind count mismatch throws.
- **`with()` and `readonly()` count as builder clauses.** Writes refuse them (§5.5), and so does a where group.

## 5. Semantics

### 5.1 Column references

These rules apply to every method that takes a column: wheres, select, order_by, group_by, having, pluck, value,
aggregates and join conditions.

1. An **unqualified name that is a column, attribute or alias of the model** is mapped to its real column
   (aliases, #92) and quoted with `quote_name()`.
2. **When the query has joins, such a name is also qualified with the model's table**, or with the `from(..., $as)`
   alias. So `where('id', 5)` becomes `` `orders`.`id` = ? `` and no longer fails with "ambiguous column".
   - Names that are not the model's (joined tables' columns, expression aliases) stay unqualified.
   - With `from_raw` there is no auto-qualification.
   - *Deviation from Eloquent, which never qualifies.*
   - *Legacy effect:* hash keys with joins that are model columns render exactly as today. Keys that are not
     model columns are no longer wrongly qualified with the model's table; such calls fail today.
3. `table.column`, `*` and `table.*` are quoted part by part (strict quoting, #64). Expressions only pass through
   the `*_raw` methods and the legacy raw strings, verbatim.

### 5.2 Conditions

| Rule | Example | vs Eloquent |
|---|---|---|
| Operators are whitelisted: Laravel's list plus the adapter-specific ones (Laravel 13's grammars: MySQL/MariaDB `sounds like`; Postgres's list without the operators that contain `?`, which collide with PDO's positional markers, and without those that are not a boolean in `col OP ?`: `between`, `||`, `-`, `#`, `#-`). **An unknown operator throws `ActiveRecordException`** at the call | `where('a', 'like', 'x%')` | Laravel takes it as the value with `=` |
| `null` with `=` or `<=>` → `IS NULL`; with `!=`/`<>` → `IS NOT NULL`; with any other operator → exception | `where('a', null)` | same |
| The hash form follows **the legacy hash rules** (`Expressions`): array → `IN`, empty array → `1=0`, `null` → `IS NULL`, `null` inside a list → `(… IN(?) OR … IS NULL)` | `where(['a' => [1, null]])` | Laravel binds the null, which never matches |
| A hash's entries are ANDed, also inside `or_where(hash)`, which renders `OR (a = ? AND b = ?)`, as the legacy hash rule does | `or_where(['a' => 1, 'b' => 2])` | Eloquent's `orWhere(array)` ORs the entries |
| `where_all` / `where_any` / `where_none` (and `or_`) with an empty column list throw `ActiveRecordException` at the call; `where_key` / `where_key_not` on a composite pk throw too, and a nested array in `where_in` / `where_between` / `where_key` is refused | `where_any([], 'x')` | Laravel's `whereAny([])` adds nothing and matches every row |
| Laravel's list form is accepted | `where([['a', '>', 1], ['b', 2]])` | same |
| `where_in` / `where_not_in` with an empty array → `1=0` / `1=1`; a `null` in the list is handled as in the hash | `where_in('id', [])` | same, except the null |
| A closure is a parenthesized group, and an empty group is omitted. The closure gets a new `Query` on the same model; setting anything but wheres on it throws | `where(fn ($q) => $q->where(...)->or_where(...))` | Eloquent ignores the rest |
| The compiled WHERE is **always parenthesized** when the engine combines it with its own predicates (pk, later scopes and the keyset predicate) | — | — |
| Values are bound through the single data-preparation owner: bool → int, and a `DateTime` in the **format of its column's type** (DATE vs DATETIME), because the builder knows which column each value belongs to | `where('day', new DateTime(...))` | — |

The last row also fixes DATE comparisons on SQLite (`'2026-01-02'` vs `'2026-01-02 00:00:00'`). Legacy positional
binds stay byte-identical (always datetime format), because they don't say which column they belong to. The
release notes list this explicitly (§8.3).

More builder rules:

| Method | Rule |
|---|---|
| `where_like($col, $pattern, $case_sensitive = false)` / `where_not_like` | The pattern uses the `%`/`_` wildcards. **Case-insensitive** (default): `LIKE` on MySQL/MariaDB (as the collation decides), `ILIKE` on Postgres, `LIKE` on SQLite (ASCII case folding only). **Case-sensitive:** `LIKE` on Postgres (on Postgres the column of every LIKE operator is cast `::text`, as Laravel does, so a non-text column works); `GLOB` on SQLite, with the pattern translated (`%` → `*`, `_` → `?`, GLOB metacharacters bracketed); on MySQL/MariaDB **`col LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_bin`**: the explicit collation converts the column to utf8mb4 and compares binary. Measured right, with no warnings, on latin1, utf8mb3 and utf8mb4 columns with ASCII and non-ASCII patterns, on MySQL 8.4/9.7 and MariaDB 10.11–12.3 (Task 12). Laravel's `LIKE BINARY` emits warning 1287 on MySQL 9.7; the first design, `CAST(? AS BINARY)`, missed non-ASCII patterns on latin1 columns and let `_` match one byte of a multi-byte letter. It assumes the connection's character set is the encoding of the data sent (utf8mb4 recommended). Residual: on MySQL (not MariaDB), a connection made latin1 by the URL's `charset=latin1` (applied with `SET NAMES` under PDO's emulated prepares) misreads non-ASCII patterns. `\` escapes `%`/`_` on MySQL, MariaDB and Postgres, and is literal on SQLite |
| `where_all($cols, $op, $value)` / `where_any` / `where_none` | A parenthesized group of the same comparison on each column, joined with AND / OR / NOT (… OR …), as in Laravel |
| `in_random_order()` | `RAND()` on MySQL/MariaDB, `RANDOM()` on Postgres/SQLite, through an adapter hook |
| `limit` / `offset` | Integers ≥ 0: a negative value throws. The parameters are typed `int`, so PHP's own coercion applies to other scalars (`'5'` → 5; a fractional float raises PHP's deprecation). `limit(0)` is `LIMIT 0` and finds nothing (#34). The legacy `limit`/`offset` options keep their current handling (`SQLBuilder::limit()` / `offset()`) |
| `order_by` | `asc`/`desc` (case-insensitive), anything else throws. The legacy `order` option stays a raw string |

Details settled by the final review (2026-10-30):

- **SQL written by hand is followed by a newline when it holds `--` or `#`.** `select_raw`, `from_raw`, a `JOIN`
  fragment of `joins()`, `where_raw`, `having_raw`, `group_by_raw`, `order_by_raw` and the `or_` forms send their SQL
  as written, but SQL that holds `--` or `#` and does not end with a newline gets one (`QueryCompiler::raw_fragment()`).
  A line comment at its end (`--`, or `#` on MySQL and MariaDB) then stops there, and cannot swallow the clauses
  that follow: the ORDER BY and LIMIT of a limited write, which would otherwise write every row it matches, or the
  parenthesis of a group. SQL without those markers renders byte-identically, and a `#` that is no comment (the
  Postgres operator, a literal) only gains a harmless newline. This supersedes the Task 19 ruling (document only):
  the fix has no false positive. The legacy `conditions` option is unchanged by it: its SQL is sent as given, so a
  trailing comment still cuts off what follows it there. A `;` was first left to the documentation; it is refused
  since (below).
- **A statement terminator in SQL written by hand is refused** (the maintainer, 2026-10-30, after the final
  re-review). A `;` outside the strings, the quoted identifiers and the comments of a fragment ends the statement on
  MySQL and MariaDB (PDO's emulated prepares send it with what follows, which runs as another statement whose error is
  never read) and on SQLite (the prepare drops what follows): the clauses after it were lost, and a limited write wrote
  every row it matched, a read read every row. Postgres refuses the statement. One rule in the engine, read as the
  database of the connection reads SQL (review of the residual patch: a reading of every database's quoting at once let
  through a `;` that one of them reads outside, and a limited delete wrote every row it matched):
  `RawSqlWriter::holds_statement_terminator()` reads the SQL in one pass, only when it holds a `;`, with the
  sub-patterns of `to_raw_sql()`'s scanner where they agree, and `QueryCompiler::refuse_statement_terminator()` throws
  an `ActiveRecordException` that names where the SQL was given, the model and the SQL, and says why.
  - MySQL and MariaDB, in the default sql_mode: strings in single and double quotes with `''` and `\` escapes,
    backtick identifiers, `#` comments, `--` comments only before a space or a control character (`0--1` is `0 - -1`),
    block comments that do not nest, and the text of an executable comment (`/*!`, `/*M!`) read as the SQL they run.
    No dollar quote: `$` is a character of identifiers and of variables (`@a$$`).
  - Postgres: standard strings with no backslash escape, `E''` strings with them and with `''`, double-quoted
    identifiers, dollar quotes (`$$...$$`, `$tag$...$tag$`) opened by a `$` that follows no identifier character (`a$$`
    is an identifier, `$1` a parameter), `--` comments and nested block comments. `#` is an operator, a backtick no
    quote.
  - SQLite: strings, and double-quoted, backtick and bracket identifiers, with no backslash escape; `--` comments and
    block comments that do not nest. No dollar quote: `#`, `$`, `@` and `:` start a parameter, read first as one token
    as SQLite's tokenizer reads it, its name (`::` in it) and a Tcl-style suffix from `(` to the first `)` or
    whitespace, in which a quote, a bracket or a `;` is the parameter's (`$a(')`, `$a(;)`).
  - A connection of no adapter of the library is read the three ways, and refused when any finds a `;` outside.
  - The builder refuses it at the call (§5.7), reads and writes alike, in `select_raw`, `from_raw`, a `JOIN` fragment
    of `joins`, `where_raw`, `having_raw`, `group_by_raw`, `order_by_raw` and the `or_` forms.
  - The legacy `conditions` option refuses it before any statement, a string or the SQL of a positional list, in
    `find` (every form, a find by pk with conditions included), `count`, `exists`, `update_all` and `delete_all`
    (`QueryCompiler::apply_conditions()`): a breaking change (§8.3). A `;` with nothing after it, which every database
    ran, is refused too. A relationship's declared conditions reach the same point, ANDed with the key in
    parentheses, which every database refused before (a `DatabaseException`); they now throw the
    `ActiveRecordException` before the load's statement.
  - Not read: the other legacy options (`select`, `joins`, `from`, `group`, `having`, `order`), the `$where` of
    `Table::update()` / `Table::delete()` (an argument of the engine, `Query::execute_update()` / `execute_delete()`,
    which no options array reaches, so the public `Table::find()`, `exists()` and `options_to_sql()` cannot turn the
    refusal off; `save()` and `Model::delete()` give a pk hash) and `find_by_sql()`. A hash binds its values and names
    columns.
  - A `;` in a string, a quoted identifier, a comment or a bound value is no terminator, and SQL without a `;` is
    never scanned. Golden unchanged: no corpus case holds one.
  - Measured on the four (a limited delete of `limit(1)` over four authors), every `;` that a reading of all the
    databases at once let through is refused on the database that reads it outside: `author_id > 0--1;` on MySQL and
    MariaDB (it deleted three authors), `name != 'C:\' ; '`, `author_id IS NOT #a;` and `author_id IS NOT $$a;b$$` on
    SQLite (four each), `author_id > 0 OR @a$$ IS NULL; @b$$` on MySQL, MariaDB and SQLite (four), and a
    `/*! ; */` on MySQL and MariaDB. Each is accepted where its database reads it inside: `0--1;` and `$$a;b$$` on
    Postgres, `'C:\' ; '` and `# ;` on MySQL and MariaDB. After the review of that round, SQLite's Tcl-style parameters
    too: `author_id IS NOT $a(') ; '` and `author_id IS NOT @a([) ; ]` (a reading that opened a string or a bracket in
    the parentheses let them through, and the limited delete, builder or legacy, deleted the four authors) are refused,
    and `author_id IS NOT $a(;)` is accepted: its LIMIT applies (one author deleted).
  - Residual, measured: the MySQL and MariaDB reading is that of the default sql_mode. A session with
    `NO_BACKSLASH_ESCAPES` reads `'C:\'` as a whole string, and `name != 'C:\' ; '`, accepted, deleted the four
    authors there; with `ANSI_QUOTES` a double-quoted identifier ending in a backslash is an unknown column. The library
    sets no sql_mode. Residual, by reading (second final review, 2026-11-02): the reading also assumes a connection
    character set in which a `\` byte is always a backslash (utf8mb4, utf8, latin1, ...). In GBK, Big5 or SJIS a `0x5C`
    byte can be the second byte of a character, so `'\xbf\x5c' ; '` reads as one string to the check and as a string, a
    terminator and an open quote to the server: a fragment built for it can hide a `;`. Conservative, the other way
    (measured on SQLite 3.46): a `;` in a block comment left open, which SQLite runs to the end of the SQL, is refused
    as a terminator.
- **A block comment left open in SQL written by hand is refused** (the maintainer, 2026-11-02, after the second final
  review). A `/*` outside the strings, the quoted identifiers and the comments of a fragment that nothing closes runs,
  on SQLite, to the end of the statement: `where_raw('author_id > 0 /* note')->order_by('author_id')->limit(1)->delete()`
  deleted the four authors, an update updated them, a read read them, and so did the legacy
  `delete_all(['conditions' => 'author_id > 0 /* note', 'order' => 'author_id', 'limit' => 1])`. MySQL, MariaDB (1064)
  and Postgres (42601, "unterminated /* comment") refuse the statement.
  - One rule with the `;`: `RawSqlWriter::leaves_block_comment_open()` reads the SQL in the pass of
    `holds_statement_terminator()`, with the same readings (each gains the opener of a comment that no closed comment
    of its own matches), only when the SQL holds `/*`, and `QueryCompiler::refuse_statement_terminator()` throws an
    `ActiveRecordException` that names the open comment, after the `;` check (a `;` in an open comment is refused as a
    terminator, as before).
  - The comment rules are each database's: MySQL, MariaDB and SQLite do not nest block comments (`/* /* */` is
    closed), Postgres nests them (`/* /* */` is open, `/* /* */ */` closed), and MySQL's executable comments (`/*!`,
    `/*M!`), whose text is SQL, are open until their `*/`. A `/*` in a string, a quoted identifier, a line comment, a
    dollar quote (Postgres), the Tcl-style suffix of a parameter (SQLite: `$a(/*)`) or a bound value is none.
  - The scope is the `;`'s: the builder's raw methods and `JOIN` fragments at the call, and the legacy `conditions`
    option before any statement, a breaking change (§8.3), in the same entries; nothing else of the legacy API.

### 5.3 Read terminals

| Terminal | Semantics | vs Eloquent / legacy |
|---|---|---|
| `get()` | `list<TModel>`; hydration, eager loading and `readonly` through `Table::find_by_sql()` | Eloquent returns a Collection |
| `first()` | LIMIT 1, no implicit order → `?TModel` | same as both |
| `first_or_fail()` / `first_or($cb)` | `RecordNotFound` / the callback's result | Eloquent: `ModelNotFoundException` |
| `sole()` | LIMIT 2: no row → `RecordNotFound`, two rows → **`MultipleRecordsFound`** | same, different names |
| `find($id)` | single-column pk only (composite pk → `ActiveRecordException`). A scalar → `?TModel`; an array → `list<TModel>`, missing ones ignored. It **shares the legacy pk-value validation**: a DATE pk is formatted as a date, and a non-integer value for an INTEGER pk on Postgres never reaches the database (#92) | legacy `Model::find` throws `RecordNotFound` |
| `find_or_fail($id)` / `find_many($ids)` / `find_or($id, $cb)` | `RecordNotFound` with the legacy message if any is missing / a list / the callback | — |
| `value($col)` / `sole_value($col)` | the column of the first record, **cast like a model attribute** (`Column::cast`, exact BIGINT as in #44, and today's DECIMAL → float of #146) | same |
| `pluck($col, $key = null)` | the values, or a key ⇒ value map, cast as `value()` does, without hydrating models | same |
| `exists()` / `doesnt_exist()` | the adapter's `exists_sql()`; order, limit and offset are dropped, as the legacy `exists` does; the select becomes `1` without GROUP BY/HAVING (see the note below) | Eloquent keeps an aggregate select, which can make `exists()` true with no row |
| `count($col = '*')` | **the number of rows of the result**. With `group_by`, `having`, `distinct`, `limit` or `offset`, the query is wrapped: `SELECT COUNT(*) FROM (…) AS t`. Returns `int` (COUNT never exceeds `PHP_INT_MAX`) | Laravel counts the first group, and returns 0 with an offset. Legacy `Model::count()` runs this count (see "One count" below) |
| `min` / `max` / `sum` / `avg` | see §5.4 | Laravel returns the driver's value (`sum()` of none is 0), with no cast |

Details settled while building the terminals (Task 15):

- **Smaller limits are kept.** `first()`, `sole()`, `value()` and a one-id `find()` keep a smaller limit the query already has: `limit(0)->first()` is `null`. A one-id `find()` sends `LIMIT 1`.
- **Missing ids.** `find_or_fail()` and `find_or()` compare the distinct ids with the distinct rows found. `find_or($ids, $cb)` with a list calls the callback when any id is missing, as `find_or_fail()` throws. Eloquent's `findOr` never calls it for a list.
- **`exists()`** drops order, limit and offset. It selects `1` unless the query has a GROUP BY or a HAVING, which
  may name select aliases; an empty select gets `1` in every case.
  - Without that, an aggregate select such as `COUNT(*)` would make it true with no matching row. Eloquent has that
    trap, so this is a deliberate deviation.
  - With a GROUP BY, an aggregate produces no row when nothing matches.
- **A wrapped `count()`** selects `1` in the subquery only when the select cannot matter. That requires no counted
  column, no DISTINCT, no HAVING, no `group_by_raw()` entry and no `order_by_raw()` entry in a kept window order
  (positional references such as `GROUP BY 1` name the select). It also requires a select that is empty or holds
  only plain column references without an alias. An empty select gets `1` even beside a HAVING, because `*` beside
  a GROUP BY is refused by Postgres and MySQL's ONLY_FULL_GROUP_BY. Otherwise
  it keeps the caller's select, because aliases named by GROUP BY or ORDER BY, and aggregates that collapse the rows,
  need it.
  - **A position in raw SQL is refused where the terminal makes its own select** (the maintainer, 2026-11-02, after
    the second final review). The exclusion above held only for a select of the caller's own: with none, a wrapped
    `count()` selected `1`, an aggregate of a column the column alone, and `exists()` `1`, raw groups included, so a
    position named the constant or that column. On the four databases `group_by_raw('1')->count()` counted 1 group
    where `get()` reads 4 rows, `group_by_raw('1')->sum('parent_author_id')` summed the distinct values (6, not 8),
    `order_by_raw('1')->limit(2)->sum(...)` picked the rows of the smallest values,
    `group_by_raw('1')->having_raw('COUNT(*) > 1')->exists()` was true where `get()` finds no row, and
    `order_by_raw('2')->limit(2)->count()` was refused by the database. A first fix kept the empty select there (`*`,
    as `get()`); it made MySQL and Postgres refuse the common `group_by_raw('parent_author_id')->count()` (`*` beside a
    GROUP BY that is not the key), which `SELECT 1` counts on the four, and it could not fix `exists()` on MariaDB
    11.4, which reads `SELECT *` inside `EXISTS()` as `SELECT 1` before it resolves `GROUP BY 1` (measured). So the
    select choice stays as it was, and `QueryCompiler::refuse_position_in_own_select()` refuses, at the call of
    `count()`, `min()`, `max()`, `sum()`, `avg()` and `exists()`, a `group_by_raw()` entry, or an `order_by_raw()`
    entry that a window keeps, that holds a position, when the statement makes its select itself (no select of the
    query's own; not `SELECT DISTINCT *` of a count of distinct rows, whose columns are `get()`'s; not an aggregate in
    place, which has no GROUP BY and leaves its order out). The `ActiveRecordException` says why, and to give the
    query a select of its own or to name the column. With a select of the query's own positions are accepted.
  - A position is a top-level item of the SQL that is an integer, alone or followed by the suffixes of an order in
    any combination (`ASC`, `DESC`, `NULLS FIRST`, `NULLS LAST`, `COLLATE <name>`, `USING <operator>`, `WITH ROLLUP`):
    `'1'`, `'name, 2'`, `'1 DESC'`, `'1 -- c'`, `'1 COLLATE NOCASE DESC'`, `'1 USING >'`. An integer in parentheses or
    after a unary `+` is one too: `(1)` is a position on the four databases and `+1` on MySQL, MariaDB and SQLite
    (measured). An integer in an expression (`CASE WHEN a THEN 1 ELSE 0 END`, `LEFT(name, 1)`), a string or a comment
    is none.
  - The SQL is read as the database of the connection reads its strings and comments, with the readings of the
    statement terminator (`RawSqlWriter::without_literals_and_comments()`): a backslash escapes a quote on MySQL and
    MariaDB only, `--` opens a comment there only before a space, and `#` opens one there only. Re-review of the fix,
    measured: a first reading of every database's strings at once missed the `1` of `'C:\', 1, 'x'` on SQLite (the
    count gave 1 where `get()` reads 4 rows) and refused `2--1` (`2 - -1`) on MySQL; and it knew no `COLLATE` or
    `USING` after a position, which SQLite (`1 COLLATE NOCASE`) and Postgres (`1 USING >`) read as the position, so a
    window's `sum()` gave 5 where `get()`'s rows give 3. Each is read right now; a database that does not read a
    suffix refuses the statement anyway.
  - Residual: a position bound to a `?` marker (`group_by_raw('?', [1])`, which MySQL's emulated prepares inline) is
    not read.
  - The replacement avoids MySQL/MariaDB's derived-table error 1060 for two same-named columns, e.g. `books.*` with
    `authors.name`.
  - Residual: when the select is kept, same-named columns can still hit 1060 on MySQL/MariaDB.
  - Residual, as in Eloquent: a `count()` that is not wrapped (no group, having, distinct, limit or offset) counts the
    source rows, even when the select is a lone aggregate whose `get()` returns one row.
  - Residual, SQLite only: SQLite accepts a select alias in WHERE; such a query's `exists()` fails there, because its
    select becomes `1`. The other databases refuse the alias in `get()` already.
- **One count: `Model::count()` runs the builder's `count()`** (the maintainer, 2026-11-02: "Non mi piace che ci siano
  due modalità di count. Preferirei avere solo la nuova modalità count del builder", confirmed as a BREAKING change of
  the legacy API, with the select honoured like the builder's). `Model::count(...)`, and so `count_by_*` and a
  consumer's override that calls `parent::count()`, builds its query from the legacy options as before
  (`finder_conditions_from_args()`, `Query::from_options()`) and returns `Query::count()` of it; the legacy execution
  (`Query::legacy_count()`, which selected `COUNT(*)` in the options and read the first column of the first row) is
  removed. There is one count rule: `QueryCompiler::compile_aggregate()` reads the clauses of legacy options as those
  of the builder written by hand (the `select` as `select_raw()`'s, the `group`, `having` and `order` as
  `group_by_raw()`'s, `having_raw()`'s and `order_by_raw()`'s, the window of `limit` and `offset` as it renders), and
  sets the select and drops the order of the options themselves, which are compiled as they always are.
  - Observable changes, all approved: the result is always an `int` (MySQL and MariaDB could return the driver's
    string; on PHP 8.1+ with the bundled drivers it was an int already); with `group` / `having` it counts the rows of
    the result (the groups), not the first group's count; with `limit` / `offset` it counts the rows of the window, not
    the total (or 0 with an offset); a `select` is honoured as the builder honours one of its own: kept in the
    subquery of a grouped or windowed count, left out (with the order) by a count in place, so
    `count(['select' => 'DISTINCT x'])` still counts every row, as `select_raw('DISTINCT x')->count()` does, and
    `count(['select' => 'DISTINCT x', 'limit' => 100])` the distinct values; the statement runs on the Table's
    connection, as `find()` and `exists()` run theirs, no longer through `static::connection()` (§6.3), and is left in
    `Table::$last_sql`, which the legacy count left alone. With no `select`, a `group` or a windowed `order` that names a
    column by its position is refused before any statement, as the builder refuses it; it failed at the database
    before (MySQL's "Can't group on 'COUNT(*)'").
  - Unchanged: the arguments and the shapes of the conditions, the alias resolution, the refusal of a `;` or an open
    block comment in the `conditions`, `joins`, `from`, and the exceptions for bad options. The golden entry
    `count_group` was re-recorded (the four adapters): `SELECT COUNT(*) FROM (SELECT 1 FROM authors GROUP BY
    parent_author_id) AS t`; the other `count` entries send the same SQL as before.
- **`count($column)`** counts the column's non-null values. After `distinct()` with no select of the query's own, it
  counts the column's distinct non-null values: the subquery selects `DISTINCT col`, Eloquent's
  `COUNT(DISTINCT col)` (final review, 2026-10-30; it selected `DISTINCT *`, which a primary key keeps apart, and
  counted every row). `count()` of the rows after `distinct()` counts distinct rows, as before.
- **Qualified names.** `value()` / `pluck()` given a qualified name (`'authors.name'`) return the driver's value uncast, because casting needs a model column.
- **`pluck($col, $key)` keys** are the driver's values. A `null` key becomes `''`, because PHP 8.5 deprecates null array keys.

### 5.4 Aggregates and exact numerics

The engine knows the aggregated column when the argument is a model column (§5.1). It classifies the column with
`Column::$type`, and also `Column::$raw_type`, because `Column::DECIMAL` covers both exact and floating types:

- **exact integer:** `Column::INTEGER`;
- **exact decimal:** `Column::DECIMAL` with a `decimal` / `numeric` / `dec` raw type;
- **floating:** `Column::DECIMAL` with a `float` / `double` / `real` / `double precision` raw type. The plan
  verifies the exact `raw_type` strings each adapter reports.

| | exact integer | exact decimal | floating | expression or non-model column |
|---|---|---|---|---|
| `min` / `max` / `sum` | `int` when the exact value fits `[PHP_INT_MIN, PHP_INT_MAX]` (decided with `ExactDecimal`), else the exact digits as a string | the exact string as the database returns it (scale kept) | `float` | the driver's value, unchanged |
| `sum` of no rows | `0` | `'0'` | `0.0` | the driver's value |
| `min` / `max` of no rows | `null` | `null` | `null` | the driver's value |
| `avg` | `SELECT SUM(col), COUNT(col)`, then the **exact long division** of `ExactDecimal` at scale **SUM's scale + 4, half-up**. The scale is MySQL's AVG scale rule (`'1.5000'`, `'1.6667'`). The rounding is half-up on every adapter. MySQL/MariaDB's own AVG truncates instead when its result scale is 9, 18 or 27, measured on MySQL 9.7 and MariaDB 11.4 (Task 17); the engine rounds consistently, so on those scales it can differ from MySQL's AVG in the last digit; no rows → `null` | the same division (SUM keeps the column's scale on MySQL and Postgres) | the database's `AVG`, as `float` | the driver's value |

The results are therefore identical on MySQL, MariaDB and Postgres, and on SQLite for integer columns. The
documented SQLite exceptions are: DECIMAL columns are REAL storage, so the floating path applies; and a SUM
beyond 2^63 fails with SQLite's `integer overflow` (`DatabaseException`). The computation is pure PHP and
deterministic, with no extension (#175's decision).

Details settled while building the aggregates (Task 18):

- **The aggregates share `count()`'s shape rules (§5.3).** A plain query is aggregated in place, with its order
  dropped. A grouped, distinct or windowed query (limit/offset) aggregates the rows of its result:
  `SELECT SUM(t.col) FROM (…) AS t`. Eloquent ignores the LIMIT and aggregates every row; this is a deviation, listed
  in the README.
- **A distinct aggregate of a column runs over its distinct values** (final review, 2026-10-30). With `distinct()`,
  no select of the query's own and an aggregated column, the subquery selects `DISTINCT col` instead of `*`: `sum` and
  `avg` are Eloquent's `SUM(DISTINCT col)` and `AVG(DISTINCT col)` (`avg` exact at the SUM's scale plus 4, as always),
  `count($col)` its `COUNT(DISTINCT col)`, and `min` / `max` are the same either way. The limit and the offset stay on
  the subquery, where they pick values. With a select of its own, the aggregate runs over the distinct rows the select
  gives, where Eloquent aggregates the column's distinct values: a deviation, in the README.
- **A distinct aggregate refuses a window ordered by another column** (final re-review, 2026-10-30). The subquery of
  such an aggregate is `SELECT DISTINCT col … ORDER BY … LIMIT n`, and a window (a limit, or an offset alone) keeps
  the query's order there: an order by another column is refused by MySQL (3065) and Postgres (42P10), and MariaDB
  and SQLite pick the values arbitrarily. `count($col)`, `min`, `max`, `sum` and `avg` refuse it at the call
  (`QueryCompiler::refuse_distinct_window_ordered_by_another_column()`), naming the column and the order and saying to
  order by the column or to give the query a select of its own. The aggregated column ordered by any of its names
  (an alias, its attribute name, qualified with the model's table or the `from()` alias) picks the values, as before;
  without a window the order is dropped; a select of the query's own and `count()` of the rows are not concerned.
  SQL of `order_by_raw()` is not read, and goes to the database as it is, as does the random order (Postgres
  refuses it there).
- **A non-model column over no row** returns the driver's `NULL` from `sum()`, so `sum()`'s type is
  `int|string|float|null`.
- **SQLite** stores DECIMAL as REAL (NUMERIC affinity), so its DECIMAL columns classify as floating: `sum`/`avg` are
  floats, and an empty `sum` is `0.0`. A BIGINT sum beyond `PHP_INT_MAX` raises SQLite's integer overflow
  (`DatabaseException`).
- **The raw type decides the class.** Postgres `real` and SQLite `REAL` are `Column::STRING` with raw type `real`.
  Postgres `float8` arrives as a string on PHP 8.3 and as a float on PHP 8.5; both read as floats. Postgres's `NaN` and
  `Infinity` read as PHP's `NAN` and `INF` on floating columns. A Postgres `numeric` NaN or ±Infinity stays the
  string `'NaN'` / `'Infinity'` / `'-Infinity'` in `sum`, `min`, `max` and `avg`.
- **A `BOOLEAN` column aggregates as an integer.** On MySQL/MariaDB (`tinyint(1)`) and SQLite, `sum` counts the
  trues, `avg` is exact, and `min`/`max` are `0`/`1`. Postgres refuses `SUM`/`AVG`/`MIN`/`MAX` of boolean, which
  surfaces as `DatabaseException`.
- **An ungrouped column under GROUP BY is refused.** `sum`/`min`/`max`/`avg` on a grouped query with no select of
  its own and only structured group columns refuse an aggregated column that is not grouped. MariaDB's default
  mode and SQLite would pick an arbitrary row per group. With a select of its own or a raw group it is allowed and
  documented. Groups that cover the model's whole primary key (every column of a composite one) are not refused: each
  group is one row, every column depends on the groups, and MySQL and Postgres accept the statement.
- **Postgres `bytea`.** A `min`/`max` over it arrives as a stream, which is read into a string. `MIN`/`MAX` of bytea
  exist from PostgreSQL 18; earlier versions refuse them with a `DatabaseException`.
- **An expression aliased with a model column's name** is classified by that column's kind. On SQLite, where
  DECIMAL is floating, an exact integer under such a name reads as a float. It is aggregated where the query's select
  is kept, in a query aggregated as a subquery (grouped, distinct or windowed); a query aggregated in place drops its
  select, as Eloquent's aggregates do, and aggregates the column itself.

### 5.5 Writes

**Rules common to every write of the engine:**
- Names map to real columns (aliases, #92).
- Data goes through the single preparation owner: DateTime by column type, bool → int.
- `Table::$last_sql` is updated, and execution goes through `Connection::query()`.
- The timestamp rule of §5.6 applies.
- A write that carries clauses it cannot use (joins, `group_by`, `having`, `offset`, `distinct`, `select`,
  `with`) **throws an `ActiveRecordException` instead of ignoring them**. The legacy wrappers hand the engine
  only `conditions`, `order` and `limit`, as they do today, so the other keys stay ignored there and the SQL is
  unchanged.

| Terminal | Semantics |
|---|---|
| `update(array $values): int` | UPDATE with the query's where, order and limit. On Postgres the limit picks the rows by pk in a subquery (#92). `updated_at` follows §5.6. Empty values throw `ActiveRecordException('Updating requires a hash or string.')`, as `update_all` does today. Returns the affected rows (`rowCount`; on MySQL the changed rows, as today) |
| `delete(): int` | DELETE with the same rules, returns `rowCount`. Without a where condition it deletes every row its limit allows: `new_query()->delete()`, or a chain of `order_by()` / `limit()` alone (SQLite refuses an `ORDER BY` without a `LIMIT` on a write). Static forwarding refuses `Model::delete()` / `update()`, and a write whose where calls all came out empty, or whose `when()` / `unless()` skipped its callback, is refused (see below) |
| `insert(array $values): bool` | One row (hash) or several (list). Several rows become one multi-VALUES INSERT, split on the adapter's bind limit, inside a transaction when there is more than one chunk (the `upsert` logic, reused). Every row must have the same keys, else an exception. `created_at` / `updated_at` follow §5.6 |
| `upsert($values, $unique_by, $update = null): int` | Today's semantics, with the timestamps of §5.6 |

| Legacy entry | Behavior after |
|---|---|
| `update_all` with a hash `set` | the engine's `update`, which now also sets `updated_at` (**breaking**, approved, §6.2) |
| `update_all` with a string `set` | a raw `SET`, no timestamps, identical SQL |
| `update_all` with an empty or missing `set` | the same exception as today, without the PHP warning that precedes it today |
| `delete_all` | the engine's `delete`, identical SQL |
| `save()`, `Model::delete()`, `create()` | identical SQL. `Table`'s return values are unchanged, including the pk read back with RETURNING (#47) |
| model guards (readonly, null or changed pk #41, destroyed record) | stay in `Model`: they validate the model's state, not the query's input |

The argument forms of `update_all` / `delete_all` (bare hash, lists, scalars) are fixed by #145 **before** this
work (§9). The engine preserves whatever #145 ships.

Details settled while building the write terminals (Task 19):

- **Which clauses a write takes.** `update()` / `delete()` take the builder's where (every form), `order_by` and
  `limit`. Joins, groups, havings, offset, distinct, select, `from` / `from_raw`, includes and `readonly` are refused at
  the call with `ActiveRecordException("<method>() cannot use <clause>")`. `insert()` and `upsert()` refuse every
  clause.
- **An empty where is refused.** A write whose where calls all rendered no condition (`where([])`, an empty closure
  group) is refused: a dynamic filter that came out empty must not write every row. A write with no where call at all
  writes every row its limit allows (`new_query()->delete()`, an `order_by()` or a `limit()` alone), and reads are
  unchanged. A `when()` or `unless()` that ran neither its callback nor a default marks the query as an empty where
  call does (final review, 2026-10-30): the optional filter came out empty, so a write with no other where condition
  is refused, with a message that names the skipped `when()` / `unless()` and the whole-table form
  `new_query()->delete()` / `->update(...)`; reads are unchanged, and a clone keeps the mark. `insert()` and `upsert()`,
  which take no clause, refuse it too, as they refuse `where([])`, and name it: "insert() cannot use a when() or
  unless() that skipped its callback" (final re-review, 2026-10-30), its own mark (`QueryState::$skipped_when`) beside
  that of an empty where call.
- **Values.**
  - Their keys map aliases and #92 attribute names to real columns. The legacy `update_all` set and `upsert` keep
    today's #92-only mapping.
  - Values are scalars, `null`, DateTimes or `Stringable` (bound as text). Arrays, enums and other objects are
    refused.
  - `insert()` validates its rows (at least one, identical keys, at least one column) and stamps them with one
    `now()` per call.
- **The returned count.** `update()`'s count on MySQL/MariaDB is the rows changed, and with `updated_at` set to now
  that is normally every matched row; a row already stamped within the same second, its other values unchanged, is
  not counted (final review, 2026-10-30).
- **Limited writes.** Postgres limits a write through the pk subquery (#92). MySQL, MariaDB and SQLite (3.46, compiled
  with `UPDATE/DELETE … LIMIT`) limit natively. SQLite refuses an order without a limit on a write, as the legacy path
  does. An adapter with neither LIMIT on UPDATE and DELETE nor a `limited_rows_condition()` refuses a limited write,
  the legacy ones included, with an `ActiveRecordException` that names the adapter, rather than send it without its
  limit and write every row it matches (`SQLBuilder`; final review, 2026-10-30). No adapter of the library is one, and
  an order without a limit is still left out there: it cannot widen a write.

### 5.6 Timestamps: one rule, in the engine

| Statement | `created_at` | `updated_at` |
|---|---|---|
| `save()` of a new record (its `set_timestamps()`, see the notes below) | now, over a value given | now, over a value given |
| `save()` of a record read | — | now, over a value given |
| INSERT (`->insert()`, `Table::insert()`) | missing → now | missing → now |
| UPDATE (`->update()`, `update_all` with a hash `set`) | — | missing → now |
| UPSERT | the INSERT part: missing → now | missing → now; it is also updated on conflict when the update list is not empty (today's behavior). A suspended class gets no `updated_at` in the conflict clause, because assigning it there would overwrite the existing row's value with NULL |
| raw SQL (`update_all` with a string `set`) | untouched | untouched |

- A timestamp column is a column whose attribute name is `created_at` / `updated_at`. This is the single rule;
  today `upsert` looks for the exact column name and `set_timestamps()` for the attribute. A value is "present"
  when, after name mapping, its real column is in the data.
- There is one source of "now". Today it is `date('Y-m-d H:i:s')`, computed separately by `set_timestamps()` and
  `upsert`. Tests can freeze it.
- `Model::without_timestamps($fn)` suspends the whole rule for that class, `save()` included (its
  `set_timestamps()` honors it), like Eloquent's `withoutTimestamps()`.
- For `save()` nothing changes: the data already contains the timestamps, so the engine adds nothing.
- *Deviation from Eloquent:* Eloquent's `insert()` is raw and sets no timestamps.

Details settled by the final review (2026-10-30):

- **`save()` keeps master's rule.** Its `set_timestamps()` sets `updated_at` on every save and `created_at` on a new
  record, over a value the model was given; "missing → now" is the rule of the engine's own statements (`->insert()`,
  `->update()`, `update_all()` with a hash `set`, `upsert`, a direct `Table::insert()`).
- **One rule for the engine's statements (the maintainer, 2026-10-30).** A model's override of `set_timestamps()`
  governs `save()` only: `update_all()` with a hash `set`, a direct `Table::insert()`, `upsert` and the builder's
  writes stamp the timestamps of every model, one that opts out by overriding it included, and only
  `without_timestamps()` suspends them. No model of the monolith overrides `set_timestamps()`. A breaking change, in
  the release notes (§8.3).
- **A key qualified with the model's table names its column.** A data key of two parts whose first is the model's
  table name, quoted or not (`` `authors`.`updated_at` ``, `authors.updated_at`), is read as the column of its second
  part, so a timestamp it gives is kept and assigned once (`Timestamps::fill()`): on MySQL and MariaDB, which take a
  qualified column in a SET or an INSERT, the value given was replaced by now. Postgres and SQLite refuse a qualified
  column there, as they always did. A key qualified with another table names no column of the model.

### 5.7 Errors

`Query` validates **at the call that introduces the problem**, not when the query runs. Messages name the method,
the model and the offending input.

| Case | Exception |
|---|---|
| unknown operator, `null` with a disallowed operator, invalid direction or negative limit/offset | `ActiveRecordException` |
| static `Order::update(...)` / `Order::delete()`, an unsupported clause on a write, empty update values | `ActiveRecordException` |
| `find` / `find_or_fail` / `where_key` / `where_key_not` on a composite pk | `ActiveRecordException` |
| an empty column list in `where_all` / `where_any` / `where_none`, a nested array in `where_in` / `where_between` / `where_key`, an empty column name or alias | `ActiveRecordException` |
| blank raw SQL in `where_raw` / `having_raw` (and `or_` forms), blank or `'0'` in `order_by_raw` / `group_by_raw`, a clause a write cannot take (a `when()` / `unless()` that skipped its callback, on `insert` / `upsert`), a write whose where came out empty | `ActiveRecordException` |
| a statement terminator (`;` outside strings, quoted identifiers and comments), or a block comment left open, in the SQL of a `*_raw` method or a `JOIN` fragment of `joins` (§5.2) | `ActiveRecordException` |
| a distinct aggregate of a column in a window ordered by another column (§5.4) | `ActiveRecordException` |
| a position (`GROUP BY 1`) in `group_by_raw` or in a window's `order_by_raw`, in `count`, an aggregate of a column or `exists`, with no select of the query's own (§5.3) | `ActiveRecordException` |
| legacy: a statement terminator, or a block comment left open, in the SQL of the `conditions` option, before any statement (§5.2, breaking §8.3) | `ActiveRecordException` |
| a closure group that sets something other than wheres | `ActiveRecordException` |
| a relationship not declared, in `joins` / `left_joins` / `with` | `RelationshipException`, as today |
| an unknown method through `__callStatic` | `ActiveRecordException("Call to undefined method: …")`, today's message |
| no row in `*_or_fail` / `sole()` | `RecordNotFound` |
| more than one row in `sole()` | `MultipleRecordsFound` (new) |
| database errors | `DatabaseException`, unchanged |

---

## 6. Backward compatibility

### 6.1 Criterion

For **every call that works today**, the SQL and the bind values are **byte-identical** on all four adapters, for
reads and writes alike. Golden tests prove it (§7).

### 6.2 Approved behavior changes

These were explicitly approved by the maintainer on 2026-10-21, and all go in the release notes.

1. **Aliases are resolved in `count`, `exists`, `update_all` and `delete_all`** as in `find`. Those calls fail
   today with `Unknown column`. An alias can no longer have the same name as a real column (change 6), so the
   mapping is never ambiguous.
2. **Breaking:** `update_all` with a hash `set` sets `updated_at` (157 monolith calls). Opt-out:
   `Model::without_timestamps()`.
3. INSERTs that bypass the model (`Table::insert()` called directly, `->insert()`) fill `created_at` / `updated_at`.
   The monolith never calls `Table::insert()` directly. `upsert` follows the same rule (§5.6), so it recognizes a
   timestamp column by its attribute name: a table whose timestamp columns have odd names (#92: `"created at"`,
   `UPDATED_AT`) now gets them filled by `upsert` too. A row key that respells a timestamp column (`UPDATED_AT`,
   `` `created_at` ``, or the column qualified with the model's table, §5.6) is recognized as that column: on
   MySQL/MariaDB the duplicated column error 1110 becomes a success, and SQLite (which tolerated the duplicate) sends
   a respelled column once and stores the same rows. A qualified column in an INSERT is refused by Postgres and by
   SQLite (a syntax error), as before. The monolith never calls `upsert`.
4. With joins, legacy hash keys that are not model columns are no longer qualified with the model's table.
   Those calls fail today.
5. The PHP warning before `update_all`'s "Updating requires a hash or string." disappears. The exception is
   unchanged.

Approved by the maintainer on 2026-10-23 (issue #144):

6. **Breaking:** an `$alias_attribute` key that names a column of the model's own table, by its real name or by its
   attribute name (#92), or a pseudo-column of the connection (`_rowid`, `rowid`/`oid`/`_rowid_`, `ctid`/`xmin`/…,
   which hash keys already treat like columns), is a configuration error. Loading the table throws `ConfigException`,
   naming the model, the alias and the column. Names are compared the way the database compares column names:
   case-insensitively, except on Postgres. Every other alias resolves in every hash condition: `find`, `count`,
   `exists`, `update_all`, `delete_all` and relationship conditions (change 7).
   - The mapping is static. It never depends on a schema read after the table was loaded, so a schema change at
     runtime cannot change which rows a write touches. That is #144's requirement, and the reason the
     "column wins" rule of #129 was dropped.
   - Residual, documented: some shadowing columns are not seen at load. One is a column added after the table was
     loaded (a long-running process, a stale metadata cache). Another is a SQLite generated column, which
     `PRAGMA table_info` omits. In both cases the alias keeps winning, and the error appears at the next load with
     fresh metadata.
   - Monolith: on 2026-10-23, the development databases of two tenants were checked for the 22 models and
     42 aliases. No alias names a column or a pseudo-column.
7. **Relationship `conditions` resolve aliases (#144).** A hash in a relationship's declared `conditions` maps the
   associated model's `$alias_attribute` keys and attribute names (#92) to real columns, in lazy and eager loads.
   - #129's rule is kept: an alias key and its target column's key in the same hash are both kept and ANDed.
   - Those keys fail today with `Unknown column`. Every other declared condition renders byte-identical SQL.
   - One exception, as in finders with joins: in a `has_many … through`, an unqualified key that names a column of
     the middle table and is also an alias or attribute name of the target now names the target's column. In a
     `through` load every key the mapping changed is written with the target table (`` `venues`.`name` ``), so it is
     never ambiguous with a middle-table column. Keys of the middle table should be written qualified
     (`'events.title'`). Monolith, checked 2026-10-23: of 126 declared
     relationship conditions, 1 is a hash (on one model), and it maps to itself.

### 6.3 Unchanged on purpose

- The semantics of every existing static method, and their overridability, except `Model::count()`, which runs the
  builder's `count()` (§5.3, the maintainer, 2026-11-02): it no longer calls `static::connection()`, a public static that
  a subclass can override, and runs on the Table's connection; the finders by pk, `update_all()` and `delete_all()`
  still call it.
- The public signatures and return values of `Table`.
- The legacy positional date format (§5.2).

The builder does not go through consumer finder overrides (the monolith base model's soft delete), so soft delete arrives with
scopes (sub-project 3). The maintainer confirmed that the monolith base model will adapt.

---

## 7. Testing and verification

1. **Golden tests first.** On `master`, after the #145 fix, record the SQL and the bind values of a corpus
   covering every legacy entry:
   - `find` in all its forms (pk, first/last/all, every option key, the dynamic finders), `count`, `exists`;
   - `update_all` / `delete_all` with string, positional and hash conditions and `set`;
   - `save()` insert/update, including RETURNING and sequences on Postgres, `Model::delete()` and `upsert`;
   - eager and lazy relationship loads, including `through`;
   - joins by association;
   - pk with conditions (#149).

   The capture is the PSR logger at the `Connection::query()` boundary, on all four adapters. The golden files are
   committed, and timestamps are masked on master, where the clock cannot be frozen yet. After the refactor they
   must be identical. The cases of §6.2 update their golden file in the same commit as the change, so the review
   sees the difference.
2. **Frozen clock.** The single source of "now" lets tests fix the time.
3. **Builder tests**, method by method, on the fixtures (`DatabaseTest`) and on the four adapters through
   `PHPAR_CONNECTION`. They assert both `to_sql()` and the results.
4. **Adapter-specific tests**, as subclasses with `set_up('mysql')`, etc.:
   - limited UPDATE/DELETE on Postgres;
   - `in_random_order`;
   - DATE binds on SQLite;
   - exact `avg` per adapter;
   - the `raw_type` classification of §5.4.
5. **`ExactDecimal`.** Unit tests for the division and the rounding. #175's 13 numericality tests stay green,
   unchanged, as the guard of the move.
6. **Static forwarding.** A test checks that every name in `Query::FORWARDABLE` has a matching `@method` tag on
   `Model`, and the other way round.
7. **Quality gate:** PHPStan level 8 with no new baseline entry, `composer run cs`, and the full suite on mysql,
   mariadb, sqlite and pgsql. PHP 8.5 too: rebuild the image with `PHP_VERSION=8.5`, because 8.5-only deprecations
   don't show on 8.3. Nothing may skip (`--fail-on-skipped`).
8. **Performance.** `options_to_sql()` now goes through `Query`. A micro-benchmark of the hot paths (`find` by pk,
   `find('all')`, `count`, `save`) on master and on the branch puts its numbers in the PR. A regression above 5% is
   investigated before merging.

---

## 8. Types, documentation, release

### 8.1 Types

- `Query` is generic (`@template TModel of Model`), and `Model::new_query(): Query<static>`.
- The terminals are typed: `get(): list<TModel>`, `first(): ?TModel`, and `find()` with a conditional return type
  (`?TModel` for a scalar, `list<TModel>` for an array).
- `Model` carries a `@method static Query<static> …` tag for each forwardable method, so consumers get the types
  without a PHPStan extension.

### 8.2 Documentation

- A README "Query builder" section with examples, the Eloquent → snake_case mapping, and the list of deviations
  from Eloquent (§5):
  - auto-qualification with joins;
  - an unknown operator throws;
  - the hash rules, including null in lists;
  - `count()` counts the result rows;
  - aggregates are exact;
  - timestamps on every INSERT;
  - static `update` / `delete` are refused;
  - the builder's `find()` returns `null` where the legacy one throws;
  - `sum` / `min` / `max` / `avg` over a grouped, distinct or windowed query aggregate its result rows (Eloquent
    ignores the LIMIT); a grouped aggregate of an ungrouped column is refused at the call;
  - `or_where(hash)` ANDs its entries; `where_all` / `where_any` / `where_none` refuse an empty column list;
  - `exists()` selects `1`, so an aggregate select cannot make it true, and it leaves out the limit and the offset
    (Eloquent keeps the window);
  - `sum` and `avg` after `distinct()` with a select of the query's own run over the distinct rows it gives
    (Eloquent's run over the column's distinct values);
  - a write whose `when()` / `unless()` skipped its callback, with no other where condition, is refused (Eloquent
    writes every row);
  - SQL written by hand that holds a statement terminator is refused at the call (Eloquent sends it), and so is a
    distinct aggregate in a window ordered by another column;
  - Laravel's `sum()` returns the driver's value, with no cast, where the builder's is exact and typed by the column;
  - `find_or($ids)` calls the callback when any id is missing; `when()` / `unless()` hand back only a same-model
    query; `readonly()` is not forwardable;
  - and the rest of the "Details settled" notes (§4.2, §5.2, §5.3, §5.4).
- A "legacy vs builder" table for the names that exist in both.
- A docblock on every public method, and a runnable example under `examples/`.

### 8.3 Release notes

Add them to the GitHub draft release when the code merges, never before (the changelog lives in releases).

- New: the fluent builder (`Model::new_query()`, static forwarding), `joins` / `left_joins` by relationship,
  `Model::without_timestamps()`, `MultipleRecordsFound`.
- **Breaking:** `update_all` with a hash `set` sets `updated_at`. INSERTs that bypass the model fill the
  timestamps.
- Fixes: aliases in `count` / `exists` / `update_all` / `delete_all` and in relationship `conditions` (#144).
  Legacy hash keys with joins are no longer wrongly qualified.
- **Breaking:** an `$alias_attribute` key named like a column of its own table throws `ConfigException` when the
  table loads (#144).
- On MySQL/MariaDB, `update_all()` with a hash `set` returns the matched rows even when no other value changes,
  because `updated_at` changes too, unless the row already holds the current second.
- **Breaking:** `update_all()` with a hash `set`, a direct `Table::insert()`, `upsert` and the builder's writes stamp
  the timestamps even for a model whose `set_timestamps()` override opts out; only `without_timestamps()` suspends
  them, and `save()` still follows the override (the maintainer, 2026-10-30).
- **Breaking:** the SQL of the legacy `conditions` option (a string, or the first element of a positional list) that
  holds a statement terminator outside its strings and comments, or leaves a block comment open, throws an
  `ActiveRecordException` before any statement, in the finders, `count`, `exists`, `update_all` and `delete_all` (the
  maintainer, 2026-10-30 and 2026-11-02, §5.2); the other legacy options, `Table::update()` / `Table::delete()` and
  `find_by_sql()` are not read.
- **Dates** (the maintainer asked for this item explicitly): the builder binds a DateTime in the format of its
  column's type, which fixes DATE comparisons on SQLite. Legacy positional and legacy hash binds are unchanged
  (they keep the datetime format).
- Aggregates: exact and normalized by column type, and `count()` counts the result rows.
- **Breaking:** `Model::count()` runs the builder's `count()` (§5.3, the maintainer, 2026-11-02): an `int`, the groups
  of a `group`, the rows of a window, the `select` honoured as the builder's, the Table's connection.

### 8.4 Notes for the monolith team

- `update_all` with a hash `set` now touches `updated_at`. Where that must not happen, use `without_timestamps()`.
- Don't use the builder on soft-delete models (the monolith base model) until scopes ship (sub-project 3).
- the monolith base model's normalizer already appends `AND deleted_at IS NULL` without parentheses today, so a top-level OR in
  `conditions` returns soft-deleted rows. This is outside this repository.

---

## 9. Sequencing and delivery

1. **#145 first, in its own small PR.** It fixes the destructive bare-hash `delete_all` / `update_all`. Decided on
   2026-10-21: it **re-lands the reviewed design of PR #160**, which was discarded with the bridge branch.
   - `delete_all()` reads a hash without option keys as conditions, as `count()` / `exists()` do.
   - Every ambiguous shape throws an `ActiveRecordException` before any SQL runs: option keys mixed with other
     keys, `set` plus a non-option key, positional lists, int/float/bool.
   - The unchanged forms keep byte-identical SQL.

   #160 has the full table and its 37 tests.
2. **This spec → the implementation plan** (`writing-plans`). It is delivered in several PRs, each green and gated
   by the golden tests. The proposed slices, to be finalized in the plan:
   - (a) the golden harness and corpus;
   - (b) `Query` with the `options_to_sql()` delegation (reads);
   - (c) the writes through the engine, the timestamp policy and `without_timestamps()`;
   - (d) the builder's public read API, joins, `with`, forwarding and types;
   - (e) the builder's writes, the aggregates and `ExactDecimal`;
   - (f) docs, examples and release notes.
3. Then sub-project 1 (iteration), building on `Query`.

## 10. Verifications to do in the plan

These are concrete checks to run during planning, not open design questions:

- **Alias edge case:** for the 22 monolith models with `$alias_attribute`, confirm that no alias shadows a real
  column (§6.2.1). Done 2026-10-23 on the development databases of two tenants: none (§6.2.6).
- **`raw_type` strings:** record the exact `raw_type` each adapter reports for float/double/real/decimal/numeric
  (§5.4).
- **Golden corpus:** define the list and the timestamp masking on master (§7.1).

---

## Appendix A — Decisions already taken for sub-project 1 (iteration)

Recorded so that sub-project 1 starts from them:

- Eloquent's names in snake_case: `chunk`, `chunk_by_id`, `chunk_by_id_desc`, `lazy`, `lazy_by_id`,
  `lazy_by_id_desc`, `cursor`. `chunk` / `lazy` page by offset, the `_by_id` variants by keyset.
- **`include_new_rows`, default `false`,** on the ascending keyset variants. By default the rows inserted during
  the run are not processed: the iteration is bounded by the `MAX(key)` read at the start. With `true` they are,
  as in Eloquent's `chunkById`. Inserts into other tables must work, so the connection is free between chunks.
- **`cursor()` is buffered by default, with an opt-in `stream` flag.** On MySQL the stream disables buffering
  only around `execute()`, verified to work, while the per-statement prepare option is ignored. While a stream
  is open, any other query on that connection throws a clear ActiveRecord exception instead of PDO's 2014.
- Verified facts:
  - an unbuffered result plus any other query on the same connection gives error 2014;
  - an unbuffered result read more slowly than `net_write_timeout` (60 s) gives 2006 "server has gone away";
  - a buffered result is a snapshot on MySQL and Postgres; an SQLite cursor is live (it sees rows inserted ahead);
  - hydrating everything costs about 5× the raw buffered rows (100k rows: 22.9 MB vs 117.6 MB);
  - a keyset `id > ?` is exact up to 2^64−1 with string binds on MySQL and MariaDB.
- The maintainer approved a BC break on `chunk`, because it clashes with the monolith's
  the monolith base model's `chunk(int, array, callable): int`. If the iteration methods reach `Model` through `__callStatic`
  forwarding instead of real statics (§4.1), the clash disappears: re-evaluate it in sub-project 1.
- The real-world motivation:
  - a job that clears failed login attempts uses the monolith base model's `chunk` (offset, no ORDER BY) and deletes inside
    the loop, so it skips rows;
  - a delivery page loads three relations per row (N+1), which a per-chunk `with` removes.
