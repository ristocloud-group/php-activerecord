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
  them (out of scope).
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
  - `sum()` casts the driver's string with `(int)` or `(float)`.
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
- **`lib/JoinClause.php` (new).** The closure form of joins (`on`, `or_on`, `where`…), with bound values.
- **`lib/ExactDecimal.php` (new, `@internal`).** It takes the three private helpers of
  `Validations` (#175) — `to_exact_decimal()`, `compare_exact_decimals()`, `exact_integer_digits()` — and adds an
  exact long division with half-up rounding at a given scale. It is pure PHP, as decided in #175 (no bcmath/gmp,
  no extension requirement). `Validations` calls it with unchanged behavior; #175's tests guard that.
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
  - Values become literals: strings escaped, numbers as written, `null` as `NULL`, bools quoted `'1'`/`'0'` (Postgres
    accepts those for a boolean, not a bare `1`), DateTimes formatted as the builder binds them.
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
| `count($col = '*')` | **the number of rows of the result**. With `group_by`, `having`, `distinct`, `limit` or `offset`, the query is wrapped: `SELECT COUNT(*) FROM (…) AS t`. Returns `int` (COUNT never exceeds `PHP_INT_MAX`) | Laravel counts the first group, and returns 0 with an offset. Legacy `Model::count()` is unchanged |
| `min` / `max` / `sum` / `avg` | see §5.4 | Laravel casts `sum` |

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
  - The replacement avoids MySQL/MariaDB's derived-table error 1060 for two same-named columns, e.g. `books.*` with
    `authors.name`.
  - Residual: when the select is kept, same-named columns can still hit 1060 on MySQL/MariaDB.
  - Residual, as in Eloquent: a `count()` that is not wrapped (no group, having, distinct, limit or offset) counts the
    source rows, even when the select is a lone aggregate whose `get()` returns one row.
  - Residual, SQLite only: SQLite accepts a select alias in WHERE; such a query's `exists()` fails there, because its
    select becomes `1`. The other databases refuse the alias in `get()` already.
- **`count($column)`** counts the column's non-null values. After `distinct()` with no select, it counts distinct rows.
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
| `avg` | `SELECT SUM(col), COUNT(col)`, then the **exact long division** of `ExactDecimal` at scale **SUM's scale + 4, half-up**, which is MySQL's AVG rule (`'1.5000'`, `'1.6667'`); no rows → `null` | the same division (SUM keeps the column's scale on MySQL and Postgres) | the database's `AVG`, as `float` | the driver's value |

The results are therefore identical on MySQL, MariaDB and Postgres, and on SQLite for integer columns. The
documented SQLite exceptions are: DECIMAL columns are REAL storage, so the floating path applies; and a SUM
beyond 2^63 fails with SQLite's `integer overflow` (`DatabaseException`). The computation is pure PHP and
deterministic, with no extension (#175's decision).

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
| `delete(): int` | DELETE with the same rules, returns `rowCount`. Without a where it deletes every row, but only from an explicit form such as `new_query()->delete()`, because static forwarding refuses it |
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

### 5.6 Timestamps: one rule, in the engine

| Statement | `created_at` | `updated_at` |
|---|---|---|
| INSERT (`save()` of a new record, `->insert()`, `Table::insert()`) | missing → now | missing → now |
| UPDATE (`save()`, `->update()`, `update_all` with a hash `set`) | — | missing → now |
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

### 5.7 Errors

`Query` validates **at the call that introduces the problem**, not when the query runs. Messages name the method,
the model and the offending input.

| Case | Exception |
|---|---|
| unknown operator, `null` with a disallowed operator, invalid direction or negative limit/offset | `ActiveRecordException` |
| static `Order::update(...)` / `Order::delete()`, an unsupported clause on a write, empty update values | `ActiveRecordException` |
| `find` / `find_or_fail` / `where_key` / `where_key_not` on a composite pk | `ActiveRecordException` |
| an empty column list in `where_all` / `where_any` / `where_none`, a nested array in `where_in` / `where_between` / `where_key`, an empty column name or alias | `ActiveRecordException` |
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
   `` `created_at` ``) is recognized as that column: on MySQL/MariaDB the duplicated column error 1110 becomes a
   success, and SQLite (which tolerated the duplicate) sends the column once and stores the same rows. The monolith
   never calls `upsert`.
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

- The semantics of every existing static method, and their overridability.
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
  - the builder's `find()` returns `null` where the legacy one throws.
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
  because `updated_at` changes too.
- **Dates** (the maintainer asked for this item explicitly): the builder binds a DateTime in the format of its
  column's type, which fixes DATE comparisons on SQLite. Legacy positional and legacy hash binds are unchanged
  (they keep the datetime format).
- Aggregates: exact and normalized by column type, and `count()` counts the result rows.

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
