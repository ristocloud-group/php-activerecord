# Design: optimize `Model::exists()` to avoid `COUNT(*)`

**Date:** 2026-08-05
**Status:** approved (design)
**Branch:** `feat/optimize-model-exists`

## Problem

`Model::exists()` currently delegates to `Model::count()`:

```php
public static function exists(/* ... */)
{
    return call_user_func_array([static::class, 'count'], func_get_args()) > 0;
}
```

`count()` emits `SELECT COUNT(*) FROM t WHERE …`, which forces the database to
find and **count every matching row** even though the caller only needs to know
whether *at least one* exists. For an existence check on a non-unique condition
that matches many rows, this is wasted work: the engine enumerates the whole
matching set before we throw the number away and compare `> 0`.

Goal: make `exists()` stop at the first matching row, improving latency and
resource use, while keeping its `boolean` contract and leaving `count()`
untouched (counting legitimately needs `COUNT`).

## Approach

Emit a standard SQL `EXISTS` query that the optimizer can short-circuit at the
first matching row:

```sql
SELECT EXISTS(SELECT 1 FROM `t` WHERE …)          -- MySQL / MariaDB / SQLite → 1 / 0
SELECT EXISTS(SELECT 1 FROM  t  WHERE …)::int      -- Postgres → 1 / 0
```

`EXISTS` is supported natively by every adapter we ship (MySQL, MariaDB,
Postgres, SQLite). It always returns exactly one row (a scalar `1`/`0`), so it
reuses `Connection::query_and_fetch_one()` cleanly. The only per-adapter wrinkle
is that `SELECT EXISTS(…)` yields `1/0` on MySQL/SQLite but a `t/f` boolean on
Postgres; casting Postgres to `::int` normalizes every backend to `1/0`.

### Why not the alternatives

- **`SELECT 1 … LIMIT 1` + row-presence check** — equally fast and fully
  portable, but zero matches return *no row*, so it can't use
  `query_and_fetch_one` (`$row[0]` on `false`) and needs a bespoke fetch. The
  `EXISTS` scalar is cleaner and is the form the maintainer selected.
- **`SELECT CASE WHEN EXISTS(…) THEN 1 ELSE 0 END`** — portable with no
  per-adapter code, but more verbose; rejected in favor of the direct `EXISTS`
  form with a one-line Postgres cast.
- **Keep `COUNT(*)` but add `LIMIT 1`** — does not help: `COUNT` aggregates and
  ignores `LIMIT`.

## Components

Three focused changes, following the existing object model (Model → Table →
Connection/adapter).

### 1. `lib/Model.php`

Extract the argument-parsing logic currently inline in `count()` into a private
helper so `count()` and `exists()` cannot drift:

```php
/**
 * Turn the variadic finder args (pk value(s) | conditions hash | options hash)
 * into a validated options array. Mirrors the parsing count() has always done.
 *
 * @param list<mixed> $args
 * @return array<string, mixed>
 */
private static function finder_conditions_from_args(array $args): array
{
    $options = static::extract_and_validate_options($args); // by-ref: pops the options hash
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

`count()` becomes: `$options = self::finder_conditions_from_args($args); $options['select'] = 'COUNT(*)'; …`
(behavior identical to today).

`exists()` becomes:

```php
public static function exists(/* ... */)
{
    $options = self::finder_conditions_from_args(func_get_args());
    // existence only needs conditions; drop ordering/paging/grouping noise
    $options = ['conditions' => $options['conditions'] ?? null, 'select' => '1'];
    if (is_null($options['conditions'])) {
        unset($options['conditions']);
    }
    return static::table()->exists($options);
}
```

### 2. `lib/Table.php`

```php
/**
 * @param array<string, mixed> $options
 */
public function exists($options): bool
{
    $sql = $this->options_to_sql($options);           // builds SELECT 1 FROM t WHERE …
    $existence_sql = $this->conn->exists_sql($sql->to_s());
    $values = $sql->get_where_values();
    return (bool) (int) $this->conn->query_and_fetch_one($existence_sql, $values);
}
```

### 3. `lib/Connection.php` + `lib/adapters/PgsqlAdapter.php`

```php
// Connection (default — correct for MySQL, MariaDB, SQLite)
public function exists_sql(string $inner): string
{
    return "SELECT EXISTS($inner)";
}

// PgsqlAdapter override — normalize the boolean result to 1/0
public function exists_sql(string $inner): string
{
    return "SELECT EXISTS($inner)::int";
}
```

## Data flow

```
Model::exists($args)
  → finder_conditions_from_args($args)         // pk | hash | conditions
  → ['select' => '1', 'conditions' => …]
  → Table::exists($options)
      → options_to_sql()                        // SELECT 1 FROM `t` WHERE …  (+ bind values)
      → Connection::exists_sql($innerSql)       // wrap: SELECT EXISTS( … )  [ ::int on pgsql ]
      → Connection::query_and_fetch_one($sql, $values)   // scalar 1 / 0
  → (bool) (int) $scalar
```

## Edge cases

- **No arguments** — `exists()` → `SELECT EXISTS(SELECT 1 FROM t)` → `true` iff
  the table has any row (same semantics as `count() > 0` today).
- **Argument forms** — `exists(123)` (pk), `exists(['conditions' => …])`,
  `exists(['id' => 1, 'name' => 'x'])` (hash) all preserved via the shared
  `finder_conditions_from_args()` helper.
- **Inner query kept minimal** — only `conditions` + `select '1'`; `order`,
  `limit`, `offset`, `group`, `having` are dropped because they are irrelevant
  to existence and keep the `EXISTS` subquery clean and valid on every backend.
- **Result normalization** — MySQL/SQLite return `1/0`; Postgres returns a
  boolean normalized to `1/0` via `::int`. `(bool)(int)$scalar` yields the
  correct boolean everywhere.

## Testing

**Regression** — the existing `exists()` assertions in `ActiveRecordFindTest`,
`CallbackTest`, and `ActiveRecordWriteTest` assert only the boolean result and
must stay green.

**New behavioral tests** (`ActiveRecordFindTest`, default connection = MySQL):
- `exists(pk)` true/false, hash form, `conditions` form, and no-argument form
  (`true` on a populated table).
- Query shape: `assert_sql_has('EXISTS', $table->last_sql)` and
  `assert_sql_doesnt_has('COUNT', …)` — proves the `COUNT` is gone.
- A "many matches" condition that returns `true` — behavioral evidence of the
  short-circuit.

**Per-adapter coverage** — add a method to the shared `AdapterTest` battery so
existence is verified on `Mysql / Mariadb / Pgsql / Sqlite`. This is what
actually exercises the Postgres `::int` path.

## Performance validation (empirical)

Include a documented micro-benchmark in the PR on MySQL: the same condition
matching many rows, comparing `SELECT COUNT(*)` vs `SELECT EXISTS(SELECT 1 …)`
with `EXPLAIN` plus repeated timing.

Expectation: `EXISTS` terminates at the first matching row (EXPLAIN `rows` ≈ 1),
`COUNT` enumerates the full matching set. `EXISTS` is **never worse** —
equivalent for PK/unique lookups and for zero-match conditions (both must scan
the index range to conclude), and materially better as the number of matching
rows grows.

## Backward compatibility

No contract break: `exists()` still returns `boolean`, `count()` is unchanged,
and the extracted helper is private. The only observable change is the SQL text
`exists()` emits (`COUNT(*)` → `EXISTS`). No public signature, return type, or
thrown-exception type changes. Flagged explicitly in the PR per the repo's BC
gate; a consumer depending on `exists()` emitting a `COUNT` is not a supported
contract.

## Out of scope

- Optimizing `count()` (it must count).
- Any new public API surface (the adapter method and the Model helper are
  internal plumbing).
