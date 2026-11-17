# Global scopes and soft delete — `#[GlobalScope]`, `SoftDeletable` / `SoftDeletes`

**Date:** 2026-11-16 (revised 2026-11-17 after a five-reviewer Goldfish review, round 1)
**Status:** Draft, awaiting the maintainer's written-spec review.
**Author:** Claude (paired with maintainer)
**Program:** sub-project 3 ("Scopes") of the "Eloquent fluent query builder" program
([builder core spec](2026-10-21-query-builder-core-design.md) §1.2), part **3a**. Part 3b (local scopes,
[spec](2026-11-09-local-scopes-design.md)) merged as [#201](https://github.com/ristocloud-group/php-activerecord/pull/201)
(8170a20).
**Branch:** written on `spec/global-scopes`, cut from `origin/master` 8170a20.
**Related issues:** follow-ups filed with this design:
[#202](https://github.com/ristocloud-group/php-activerecord/issues/202) (delete cascade/restrict),
[#203](https://github.com/ristocloud-group/php-activerecord/issues/203) (sub-project 4, relationship querying),
[#204](https://github.com/ristocloud-group/php-activerecord/issues/204) (possible future extensions), and a comment on
[#186](https://github.com/ristocloud-group/php-activerecord/issues/186) (joins by relationship name). Independent PRs:
[#194](https://github.com/ristocloud-group/php-activerecord/issues/194) (`constraints` relationship key) and
[#150](https://github.com/ristocloud-group/php-activerecord/issues/150) (a `'0'` condition acts on every row).
**Research input:** a local working brief (`.superpowers/scopes/`, not in the repository). Every fact the decisions rely
on is restated in §2.

---

## 1. Context

### 1.1 Problem

The library has no way to declare a filter that every query of a model applies, and no soft delete. Its main consumer,
the RistoCloud application that vendors this library (called "the monolith" below; base model `RCModel`, still on
`zamzar/php-activerecord` v1.7.1, see the builder core spec §8.4), implements both by hand:

- it overrides `all/first/last/count/exists/find_by_id` and appends `AND deleted_at IS NULL` **without parentheses**, so
  a top-level `OR` in the caller's conditions leaks soft-deleted ("trashed") rows;
- `find()`, `find_by_*`, `has_many`/`has_one` loads and every eager load are not filtered at all;
- the query builder bypasses those overrides, so the builder core spec tells users not to use it on soft-delete models.

The maintainer asked (2026-11-09) for soft delete "inspired by Eloquent and Rails" (the `paranoia`/`discard` gems).
3a delivers global scopes and soft delete in the library, so that the filter is applied once, correctly grouped, on
every path.

### 1.2 Decisions (design session with the maintainer, 2026-11-16/17)

| # | Question | Decision and reason |
|---|---|---|
| Q1 | Where global scopes apply | **Everywhere**: the builder, the legacy finders (`find`, `first`, `last`, `all`, `find_by_*`, `count`, `exists`), bulk writes and lazy/eager relationship loads. One rule on every path; relationships are covered because they load through the legacy finders. The builder core spec §1.4 ("not changing the existing statics' semantics") is not affected, because only models that **opt in** change. `save()`, instance `delete()` and `reload()` are never scoped. |
| Q2 | Soft-delete opt-in | **Trait `SoftDeletes` + interface `SoftDeletable`, both required.** The instance methods then exist only on opted-in models. The column is customised by overriding the method `soft_delete_column()`, because a trait's static property cannot be redeclared with another default (fatal, §2). |
| Q3 | Declaring a global scope | **`#[GlobalScope]` on a `protected static` method** `fn(Query $q): ?Query`, named by the method, **WHERE-only**, same signature as 3b's local scopes. |
| Q4 | Removing scopes | Builder methods `without_global_scope()`, `without_global_scopes()`, `with_trashed()`, `only_trashed()`, `without_trashed()`, and the relationship option `'unscoped'`. **No public block form and no option key** (D3). |
| Q5 | Relationships | Filtered by the target's scopes by default. Per-relationship option **`'unscoped' => true \| list<string>`**. Per-load opt-out is sub-project 4 ([#203](https://github.com/ristocloud-group/php-activerecord/issues/203)). |
| Q6 | `:through` middle model | **Only the middle model's soft delete**, as Eloquent's `SoftDeletableHasManyThrough` scope does inside `HasManyThrough`. Not its other global scopes. |
| Q7 | Bulk writes | **Scoped everywhere** (legacy `update_all`/`delete_all`, builder `update()`/`delete()`). On a soft-delete model `delete_all`/`delete()` become an `UPDATE` of the column. Removing a scope never makes a delete hard: that is `force_delete()`/`force_delete_all()`. |
| Q8 | Hard bulk delete | **`force_delete()` and `force_delete_all()` drop only the soft-delete scope** and keep the others: a real `DELETE` must never reach rows another scope hides, because it cannot be undone. Deliberate deviation from Eloquent, whose `forceDelete()` drops every scope. |
| Q9 | Instance soft delete | Direct by-PK `UPDATE`, no validations, no save callbacks, destroy callbacks fire, the object stays live. `restore()` is a direct UPDATE too (§5.4, deviation). `reload()` drops every global scope. No in-memory rollback of the column (as Eloquent). |
| Q10 | `validates_uniqueness_of` | **Drops every global scope**: a database unique index sees every row, so the validation must too (Eloquent's `unique` rule does the same). A per-tenant rule uses the validation's column list. |
| Q11 | Smaller points | `to_sql()` shows scopes; aggregates/count/exists/iteration are scoped; the unscoped paths of §3.6; qualification of §3.6; non-WHERE clause in a scope throws; `ConfigException` rules of §6; the soft-delete column is checked lazily and must be `DATE`/`DATETIME`/`TIMESTAMP`; an unknown scope name throws (deviation: Eloquent ignores it); `trashed()` without the column loaded throws (deviation: Eloquent returns false). |
| D1 | Engine approach | **A meta field on `QueryState`**, set by the scoped entries and applied at the compiler's two WHERE sites, guarded by reflection tests over every entry (§4.2, §9.3). |
| D2 | Scope name | **`soft_deletes`**, as Eloquent names it after the trait, exposed as `SoftDeletable::SCOPE`. |
| D3 | No public `unscoped()` block | A block form would only serve paths that expose no `Query`: the legacy statics (the builder covers them) and relationship loads (a gap that sub-project 4 closes). It would add public implicit state shared by every caller of the process. The library keeps an internal suspension for its own synchronous calls (§4.5). |
| D4 | `find_or_create_by_*` and a trashed match | Throws a clear `ActiveRecordException` instead of a silent unsaved model or a unique-index error (§3.8). A `restore_or_create` is in #204. |
| D5 | Migration aid | An optional, off-by-default warning in `Config` for hand-written conditions on the soft-delete column (§3.7). |
| D6 | `'0'` condition | Out of 3a: [#150](https://github.com/ristocloud-group/php-activerecord/issues/150), approved as a separate BREAKING PR. 3a follows `SQLBuilder`'s rule for empty conditions, whatever it is. |
| D7 | Static `force_delete` | Refused by `Model::__callStatic` with the whole-table-write error of `update`/`delete` (§3.3). |

### 1.3 Goals

1. A model can declare filters that every read, bulk write and relationship load of it applies, and that a query can
   remove by name.
2. Soft delete with Eloquent's API and semantics in snake_case, opt-in per model; deviations are listed (§9.4).
3. A scope can never let an `OR` leak, in the builder or in legacy conditions.
4. No SQL change for a model that declares no global scope and has no active soft delete: the golden corpus (the
   `GoldenSqlTest` cases that pin SQL and binds byte for byte) stays identical.
5. The monolith can drop its overrides and migrate table by table (Appendix A).

### 1.4 Non-goals

- Delete cascade/restrict ([#202](https://github.com/ristocloud-group/php-activerecord/issues/202)).
- Relationship querying, per-load eager constraints and per-load opt-out such as `$order->lines()->with_trashed()`
  ([#203](https://github.com/ristocloud-group/php-activerecord/issues/203)).
- Scopes on joins by relationship name, which belong in the `ON` clause
  ([#186](https://github.com/ristocloud-group/php-activerecord/issues/186)); documented as a known limit.
- In [#204](https://github.com/ristocloud-group/php-activerecord/issues/204): a boot lifecycle and runtime registration,
  `GlobalScope` classes, a boolean soft-delete sentinel, a `without_trashed` uniqueness option, a bulk `restore()` on the
  builder, `restore_or_create`.
- A public block form (D3).
- The `constraints` relationship key (#194) and the `'0'` condition (#150).

### 1.5 Alternatives considered

| Topic | Rejected option | Reason |
|---|---|---|
| Where scopes apply (Q1) | Builder only | Legacy and builder diverge; relationships (legacy path) stay unfiltered; the monolith keeps its overrides. |
| | Legacy only behind a per-model opt-in flag | Two modes to test forever. |
| Opt-in (Q2) | Auto-detect a `deleted_at` column | A BC break for every consumer that has such a column. |
| | Static property `$soft_delete` | Methods would land on every model (collision surface); a trait cannot carry an overridable static property. |
| Declaration (Q3) | Static array of conditions | A second condition language to compile and qualify; no logic; no closures in a static initializer on PHP 8.3. |
| | `GlobalScope` classes, closures registered in a boot hook (Eloquent) | No boot lifecycle exists; a trait with a `#[GlobalScope]` method already shares a scope (#204). |
| Removal (Q4, D3) | New `$VALID_OPTIONS` keys | Changes how `is_options_hash()` classifies a conditions hash; relationship loads receive no caller options. |
| | Public block `Model::unscoped(fn, names)` | See D3. A fiber-local variant was prototyped and works, but the public API is not needed. |
| Bulk writes (Q7) | Split by API (builder scoped, legacy unchanged) | Same model, two rules; the maintainer preferred one rule. |
| Hard delete (Q8) | Drop every scope (Eloquent) | Deletes rows another scope hides, irreversibly. |
| Engine (D1) | Attach scopes when the query is built | `Query::from_options` is shared with `save()`'s and `delete()`'s by-PK writes, which must stay unscoped. |
| | A flag on `Query` passed to `QueryCompiler` | Eight compiler constructions plus `window()`/`clone()` to keep in sync. |
| | Apply in every terminal (Eloquent's `applyScopes`) | Dozens of terminals and every legacy entry; a new terminal is unscoped until someone adds the call. |
| Group position | After the user's conditions | Before matches `and_pk_conditions`, whose `X AND (own)` shape and bind order the legacy wrap reuses (§4.4). |
| Uniqueness (Q10) | Drop only `soft_deletes` | A row hidden by another scope would pass validation and then fail on the index. |
| Rollback (Q9) | Restore the in-memory column on rollback | The destroyed flag is tracked because it blocks later writes; a soft-deleted object blocks nothing, and `save()` restores nothing in memory either. |

---

## 2. Facts the design relies on

Verified on 2026-11-16/17 (PHP 8.5.12 locally for the language checks, rules unchanged since 8.3; `lib/` on `master`
8170a20):

- **Traits.** A class that redeclares a static property of its trait with another default is a fatal composition error.
  A class method wins over the trait's. A subclass can override an inherited trait method with a compatible signature.
  The attributes of a trait method are visible through reflection on the using class and its subclasses. **Attributes
  are not inherited** by an overriding method.
- **Trait constants** cannot be read through the trait name; interface constants can, also through the class.
- **Trait methods run in the using class**: they cannot reach `Model`'s private members (`invoke_callback`,
  `verify_not_readonly`, `verify_not_destroyed`, `$__dirty`, `bulk_write_query`, …).
- **Fibers.** Per-fiber state through a `WeakMap` keyed by `Fiber::getCurrent()` works (prototype: a block opened in a
  suspended fiber is not seen by another fiber; entries are freed when fibers end). Not used (D3), recorded for #204.
- **Compiler WHERE sites.** Every SELECT, COUNT, EXISTS and aggregate goes through `QueryCompiler::compile_select()`
  (`lib/QueryCompiler.php:189`): legacy options through `compile_legacy()` (:1067) → `apply_conditions()` (:1479);
  builder clauses through `compile_clauses()` (:1125), whose `$conditions` are the engine's own conditions, compiled
  first. Every UPDATE/DELETE goes through `apply_write_clauses()` (:1521). Aggregate subqueries compile the inner state
  only (:320).
- **Qualification.** `column_reference()` qualifies a model column only when the builder's `$state->joins` is non-empty
  and there is no `from_raw` (:1720). Legacy joins live in `$state->legacy['joins']`; only hash-condition keys get
  qualified there (`qualify_hash_keys`, :1072-1080).
- **Held fields.** `held_fields()` (:1353), `holds_no_builder_clause()` (:1378) and `refuse_unwritable_clauses()` (:604)
  treat every `QueryState` field as a clause; `QueryEngineTest::test_held_fields_reports_every_field_of_query_state`
  pins it. `Query::closure_wheres()` (`lib/Query.php:3559-3574`) validates a where group through `held_fields()`.
- **Entries.** `Model::new_query()` (`lib/Model.php:1046`), `Table::find()` (`lib/Table.php:293`), `Table::exists()`
  (:304), `Model::count()` (:2437), `bulk_write_query()` (:1372). `Table::options_to_sql()` (:282), `Table::update()`
  (:626) and `Table::delete()` (:659) also use `Query::from_options` and must stay unscoped.
- **`and_pk_conditions`** (`lib/Model.php:2385-2410`) builds `key AND (own)` with `SQLBuilder::get_where()`,
  `get_where_values()` and `where_condition()`, and keeps `SQLBuilder`'s rule that a falsy WHERE (`'0'` included) is no
  WHERE.
- **Relationship loads** call the target's legacy statics: `BelongsTo.php:86`, `HasMany.php:226` (lazy),
  `AbstractRelationship.php:281` (eager). The lazy `:through` join string is built once and kept on the relationship
  (`HasMany.php:150-206`); the eager path rebuilds it on every call (`AbstractRelationship.php:190-238`).
- **Timestamps.** `Timestamps::now()` is a fixed `Y-m-d H:i:s` string, freezable in tests (`lib/Timestamps.php:48-51`).
  Formatting by column type happens only for `DateTimeInterface` values (`Table::process_data()`, `Query::update()`).
- **Uniqueness** calls `$this->model->exists(...)` (`lib/Validations.php:671`).
- **Static dispatch.** `Model::__callStatic` (:2080) refuses `update`/`delete` and forwards `Query::FORWARDABLE`;
  `QueryForwardingTest` checks `FORWARDABLE` against `Model`'s `@method` tags.
- **3b's registry.** `ScopeRegistry` reflects local scopes without loading the Table, validates them in its constructor
  (`lib/ScopeRegistry.php:92-110`), and today runs only when a scope is used, on `has_scope()` or on an unknown static.
- **CI** runs MySQL, MariaDB, SQLite and Postgres on PHP 8.3–8.5 (`.github/workflows/ci.yml`).
- **Monolith** (grep of the monolith's `application/`, 2026-11-09 and 2026-11-17): about 100 models on soft-delete
  tables; dozens of hand-written conditions on `deleted_at`, a few of them with `IS NOT NULL`; dozens of `delete_all`
  on soft-delete models, including a retention purge; one model with default conditions; two `restore()` methods with
  signatures compatible with an untyped `restore()`; no method named like the other new names. The detailed inventory
  is in the monolith's internal migration ticket.

---

## 3. Public API and semantics

### 3.1 Declaring a global scope

```php
use ActiveRecord\{GlobalScope, Model, Query};

class Booking extends Model
{
    #[GlobalScope]
    protected static function real(Query $q): ?Query
    {
        return $q->where('dummy', 0);
    }
}
```

- `ActiveRecord\GlobalScope` is a method attribute, twin of 3b's `ActiveRecord\Scope`.
- The method is `protected static`, takes the query as its only required parameter, and returns `null` or that query.
- Its name is the scope's name. It follows 3b's name rules: not a public `Query` method, not a dynamic-finder prefix,
  not `update`/`delete`.
- It may only add WHERE conditions: `where*` methods, where groups, `when()`/`unless()`, an empty `where([])`, a local
  scope that only adds wheres. A scope that ends up adding no condition produces no group. Any other clause throws when
  the query is compiled.
- Scopes are inherited. A subclass that redeclares a scope method must repeat `#[GlobalScope]`; redeclaring without it
  is a `ConfigException` (a scope cannot vanish by accident). A trait can carry a scope for unrelated models.
- Order: the root ancestor's scopes first, then each subclass down to the concrete class; declaration order within a
  class; a redeclared method keeps its parent's position.

### 3.2 Opting into soft delete

```php
use ActiveRecord\{Model, SoftDeletable, SoftDeletes};

class Order extends Model implements SoftDeletable
{
    use SoftDeletes;

    // optional: another column, or null to turn soft delete off for this class
    public static function soft_delete_column(): ?string
    {
        return 'removed_at';
    }
}
```

- `SoftDeletable` declares `public const string SCOPE = 'soft_deletes'` and
  `public static function soft_delete_column(): ?string`.
- `SoftDeletes` implements `soft_delete_column()` (`'deleted_at'`), declares the scope
  `#[GlobalScope] protected static function soft_deletes(Query $q)`, and the methods of §3.4. Its methods are thin:
  the logic lives in `Model` (§5), which the trait cannot otherwise reach.
- **Both are required.** The trait without the interface, or the interface without the trait, is a `ConfigException`.
- **Soft delete is active** when the model is `SoftDeletable` and `soft_delete_column()` returns a name. With `null`,
  the scope adds nothing and the model behaves exactly as one without the trait (byte-identical SQL).
- `soft_delete_column()` is read lazily: at compile time, when an instance verb (`delete()`, `restore()`,
  `force_delete()` on an object) runs, and when `*_trashed()` is called; never in `Table::__construct`. An override may
  call `static::table()`.
- The column must be a `DATE`, `DATETIME` or `TIMESTAMP` column of the table.

### 3.3 Builder methods

All on `Query`. All **new** methods but `force_delete` are added to `Query::FORWARDABLE` and to `Model`'s `@method`
tags (`Order::with_trashed()->...`); the existing `update` and `delete` stay unforwarded.

| Method | Semantics |
|---|---|
| `without_global_scope(string $name): static` | Removes one global scope from this query. |
| `without_global_scopes(?array $names = null): static` | Removes the scopes named, or every global scope with `null`. |
| `with_trashed(): static` | Trashed and live rows. |
| `only_trashed(): static` | Replaces the soft-delete filter with `<column> IS NOT NULL`; never ANDs both (the "Rails trap": `paranoia`'s `only_deleted` ANDed with the default scope returns nothing). |
| `without_trashed(): static` | The default filter again, whatever came before. |
| `force_delete(): int` | A real `DELETE` of the matching rows, minus the soft-delete scope only. On a model without active soft delete, `delete()`. |
| `update(array $values): int` (existing) | Scoped. |
| `delete(): int` (existing) | Scoped; an `UPDATE` of the column on an active soft-delete model (§5.1). |

The five removal methods throw on a query that is not a query of the model: inside a `where(fn …)` group or a global
scope's own query (§6).

A static `force_delete` call would be a whole-table write. On a model without the trait, `Model::__callStatic` refuses
it with the error it gives `update`/`delete` (D7). On a `SoftDeletable` model PHP refuses it first ("Non-static method …
cannot be called statically"), because the trait's instance `force_delete()` exists.

### 3.4 Model methods

| Method | Kind | Semantics |
|---|---|---|
| `force_delete_all($options = []): int` | static, trait | `delete_all()`'s options and checks, as a real `DELETE`, minus the soft-delete scope only. |
| `delete()` (existing) | instance | Soft on an active soft-delete model (§5.3). |
| `restore()` | instance, trait, untyped | §5.4. |
| `trashed()` | instance, trait, untyped | `true` when the column holds a value; `false` without active soft delete. |
| `force_delete()` | instance, trait, untyped | Today's hard delete (§5.5). |
| `is_force_deleting(): bool` | instance, trait | `true` while `force_delete()` runs, callbacks included (Eloquent's `isForceDeleting()`), so that a `delete()` override or a destroy callback can tell a hard delete from a soft one. |
| `reload()` (existing) | instance | Reads with every global scope removed. |

The instance methods are untyped like `delete()`, so that existing untyped overrides stay compatible.

### 3.5 Callbacks and relationship option

- `before_restore` and `after_restore` join `CallBack::$VALID_CALLBACKS`. As for every callback name, an existing
  method of that name is registered automatically. `before_restore` returning `false` cancels the restore.
- `'unscoped' => true | list<string>` on `belongs_to`, `has_many` and `has_one` removes those global scopes of the
  relationship's **own target class** from its loads (`true` = all; names checked against the target). On a
  `:through` relationship it does not touch the middle model: the middle hop follows the `'unscoped'` of the
  relationship named in `'through'` (the owner→middle declaration, which loads the middle model).

```php
static $belongs_to = [['customer', 'unscoped' => [SoftDeletable::SCOPE]]];   // a trashed customer still loads

static $has_many = [
    ['taggings', 'unscoped' => [SoftDeletable::SCOPE]],   // trashed taggings: also lifts the middle filter below
    ['tags', 'through' => 'taggings'],
];
```

### 3.6 Where scopes apply, and where they do not

Scoped:
- every builder read: `get`, `first`, `sole`, `find*`, `value`, `pluck`, `count`, aggregates, `exists`/`doesnt_exist`,
  `chunk`, `chunk_by_id` (including its initial `MAX(key)` probe), `lazy*`, `cursor`, and `to_sql()`/`to_raw_sql()`/
  `get_bindings()`, which show the scopes;
- builder `update()`, `delete()`, `force_delete()` (minus the soft-delete scope);
- legacy `find` (all forms), `first`, `last`, `all`, `find_by_*`/`find_all_by_*`/`count_by_*`, `count`, `exists`,
  `update_all`, `delete_all`, `force_delete_all` (minus the soft-delete scope);
- lazy and eager relationship loads (the target's scopes), and the middle model's soft delete on `:through` loads.

In an aggregate or count subquery the scope appears once, in the inner query.

Not scoped, by construction:
- hand-written SQL: `find_by_sql`, `Model::query`, `Connection::query*`;
- `Table::options_to_sql()` and the public `Table::update()`/`Table::delete()` (the by-PK writes of `save()`/`delete()`);
- `insert` and `upsert` (no WHERE). An `upsert` that hits the unique key of a trashed row updates it and leaves it
  trashed;
- `join('table', …)`, raw join strings and joins by relationship name (#186);
- where groups and a global scope's own query.

**Qualification.** A scope's model columns are qualified as follows.
- Builder query: as `column_reference()` does, with the name the model's rows are read under (the `from()` alias, else
  the table) when the query has joins; unqualified under `from_raw()`.
- Legacy query with a `'joins'` option and no `'from'`: with the model's table, as `qualify_hash_keys` qualifies hash
  keys today. With `'from'`: unqualified, as hash keys today.
- The `:through` middle predicate: always qualified with the middle table.

### 3.7 Migration warning (optional)

`Config::set_warn_soft_delete_conditions(bool $enabled = true): void` and its getter (names final in the plan), off by
default, as `set_tinyint_as_boolean()`. When on, a query on a model with active soft delete raises an `E_USER_WARNING`
when:
- hand-written conditions (legacy string or positional conditions, builder `where_raw`) name the soft-delete column;
- an `update_all` `set` or a builder `update()` writes the soft-delete column.

The message names the model, the fragment and the ways out (`only_trashed()`, `with_trashed()`, `restore()`). It is a
textual check, so a redundant `deleted_at IS NULL` is reported too (documented).

### 3.8 `find_or_create_by_*` on a soft-delete model

When the scoped find misses and soft delete is active, the finder looks for a trashed match. If there is one, it throws
an `ActiveRecordException` that names the ways out (`restore()`, or a read with `with_trashed()`), instead of creating
a record that fails uniqueness or hits the unique index. Without a trashed match it creates as today. Eloquent's
`firstOrCreate` has the silent behaviour; its `restoreOrCreate` is in #204, and the check can be relaxed once it ships.

---

## 4. Architecture

### 4.1 Components

| Component | Status | Role |
|---|---|---|
| `lib/GlobalScope.php` | new | The method attribute. |
| `lib/SoftDeletable.php` | new | Interface: `SCOPE`, `soft_delete_column()`. |
| `lib/SoftDeletes.php` | new | Trait: the scope, the methods of §3.4 as thin calls into `Model`. |
| `ScopeRegistry` | changed | A cached per-class index of global scopes (one attribute scan per class, invokers cached), which does **not** trigger the validation of local scopes (that stays lazy, as in 3b). Their validation (§6). The internal suspension (§4.5). |
| `QueryState` | changed | Meta field `?array $scopes` (§4.2). |
| `QueryCompiler` | changed | Applies the scopes in `compile_select()` and `apply_write_clauses()`; the meta fields are skipped by the three held-field checks. |
| `Query` | changed | §3.3 methods; `FORWARDABLE`; the throw on group/scope queries. |
| `Model` | changed | Scoped entries; the soft-delete logic (private) behind `delete()`, `delete_all()`; protected entry points for the trait; `reload()`; the static `force_delete` refusal; `find_or_create_by_*` check; `@method` tags. |
| `Table` | changed | `find()`/`exists()` mark their query scoped. |
| `relationships/*` | changed | `'unscoped'`; the middle soft delete on lazy and eager `:through` loads. |
| `Validations` | changed | Uniqueness with every scope removed. |
| `CallBack`, `Config` | changed | Two callback names; the optional warning. |

`SQLBuilder` is not changed. All new classes are in `lib/`, one per file; `LibraryLoadingTest` covers them.

### 4.2 State

`QueryState` gets `public ?array $scopes = null`, with the shape
`array{all: bool, removed: list<string>, trashed: 'without'|'with'|'only'}`.

- `null` means "no scopes". It is the default, so `Query::for_table()`, `from_options()`, where groups, a scope's own
  query and every write by PK carry none without any code change.
- A scoped entry (§4.3) sets `['all' => false, 'removed' => [], 'trashed' => 'without']`, then applies the internal
  suspension active for its class (§4.5).
- The soft-delete scope is governed **only** by `trashed`: `without_global_scope('soft_deletes')` sets `trashed` to
  `with`; `without_global_scopes()` sets `all` and `trashed` to `with`; `with_trashed()`/`only_trashed()`/
  `without_trashed()` set `with`/`only`/`without`. The last call wins, so `without_trashed()` restores the filter even
  after `without_global_scopes()`. The other names go in `removed` (or `all`).
- It is a **meta field**: the three held-field checks skip the declared meta fields, and the invariant test becomes
  "every field of `QueryState` but the declared meta fields is a clause". The removal methods throw on a `null` field
  (§3.3), so a meta field is never written where it would be ignored.
- `clone()`, `window()`, the pager (`QueryPager`, behind `chunk`/`lazy`) and the cursor copy the state, so they copy the
  field.

### 4.3 Scoped entries

`Model::new_query()`, `Table::find()`, `Table::exists()`, `Model::count()` and `bulk_write_query()` set the field. Every
other legacy static reaches one of them.

### 4.4 Application in the compiler

At each of the two WHERE sites, when `$state->scopes` is not `null`:

1. Read the model's global scopes from the cached index (order of §3.1). A class with none and no active soft delete
   costs one cached lookup.
2. Drop `soft_deletes` unless `trashed` is `without`, and drop every other scope in `removed` (all with `all`).
3. Run each remaining scope on a fresh where-only query of the same model (the shape of a where group). A scope that adds
   anything but wheres throws. A re-entrancy guard throws when a scope's own body runs a query of the same model.
4. With `trashed` = `only`, add a group `<column> IS NOT NULL`.
5. Combine: one parenthesized AND group per scope, **before** the user's conditions, which are parenthesized as a
   whole: `(scope1) AND (scope2) AND (<user conditions>)`. Builder: through the `$conditions` channel of
   `compile_clauses()` and the builder branch of `apply_write_clauses()`. Legacy: `apply_conditions()` writes the user's
   WHERE as today, then the compiler reads it back with `get_where()`/`get_where_values()` and writes
   `(<scopes>) AND (<user>)` with `where_condition()`, as `and_pk_conditions` does, keeping `SQLBuilder`'s rule for an
   empty or falsy user WHERE (D6).
6. No group means no change: the SQL is byte-identical.

### 4.5 Internal suspension

`ScopeRegistry` keeps a private per-class stack of "all" or a list of names, with two `@internal` methods: one runs a
callable with the scopes of exactly one class suspended (pushed, run, popped in `finally`), one returns what is
suspended for a class. A scoped entry copies the suspended names into `scopes` **when it creates the query**, so the
compiler only reads the state. It is used only around synchronous library calls:
- `reload()` (every scope of the model's class);
- uniqueness (every scope of the validated model's class);
- relationship loads with `'unscoped'` (the listed scopes of the target class, or of the middle class for the middle
  hop).

Nothing else can push onto the stack. PDO is blocking, so a fiber cannot switch inside those calls unless a consumer's
own override of `find`/`first` performs asynchronous work (documented in §8).

### 4.6 Relationships

- `'unscoped'`: the three load calls (`BelongsTo.php:86`, `HasMany.php:226`, `AbstractRelationship.php:281`) run inside
  the internal suspension of the target class. The user's overrides of `first`/`find` keep being called.
- `:through` middle soft delete: when the middle model has active soft delete and the relationship named in `'through'`
  does not lift it, the load adds `<middle table>.<column> IS NULL` to the conditions it builds for that load: in
  `HasMany::load()` per lazy load (the join string itself stays frozen), and in the eager path, which rebuilds its
  options on every call. Both `:through` shapes are covered: the join-table one and the has_many→has_many reverse-FK
  chain (#22, [spec](2026-08-05-has-many-through-chain-design.md)). The decision "is the middle soft delete lifted"
  reads the same registry and suspension state as the compiler. #186 will move joined-model scopes into `ON`; this
  path stays for loads.

### 4.7 Interaction with other work

- 3b local scopes: 3a reuses their signature, grouping and registry. Their validation stays lazy (§4.1).
- Sub-project 1 (iteration): pager and cursor copy the state, so they are scoped; the keyset condition and the scope
  groups are both parenthesized.
- #186 / #203 apply the joined or related model's scopes when they land.
- #150 changes what an empty or `'0'` legacy WHERE means; §4.4 step 5 follows it.

---

## 5. Soft-delete writes

"Active" and "column": §3.2. "Now": a `DateTime` built from `Timestamps::now()` (the single, freezable source). The
engine binds a `DateTime` in the format of its column, so a `DATE` column gets a date. An instance attribute receives
the same `DateTime` type a find produces.

### 5.1 Bulk soft delete

The switch from DELETE to UPDATE lives only in `Model::delete_all()` and `Query::delete()`, keyed on active soft
delete. `Table::delete()` (by PK) is untouched. On an active model both run the engine's `update([<column> => now])` on
the same query, so the WHERE, order and limit (the Postgres primary-key subquery included) are those of the delete, and
`updated_at` is added by the engine's existing rule unless `without_timestamps()` runs for the class:

```sql
UPDATE t SET <column> = ?, <updated_at> = ? WHERE (<scopes>) AND (<conditions>) [ORDER BY …] [LIMIT …]
```

They return the number of rows the statement reports. With `with_trashed()` they re-stamp trashed rows too (Eloquent).

### 5.2 Bulk hard delete

`force_delete_all($options)` and `Query::force_delete()` run a `DELETE` with the soft-delete scope removed and every
other scope kept. On a model without active soft delete they are `delete_all()`/`delete()`.

### 5.3 Instance `delete()`

`Model::delete()` keeps its checks (readonly, destroyed, primary key) and `before_destroy`/`after_destroy`. Between
them, on an active model and unless `force_delete()` runs, it performs the soft delete itself (private Model code):
`UPDATE … SET <column> = ?, <updated_at> = ? WHERE <pk>` through the unscoped by-PK path, then writes the values into
the object's attributes without marking them dirty and without touching the user's other pending changes. The object is
not marked destroyed. Otherwise it performs today's hard delete. No validation and no save callback run. It returns
`true`, or `false` when `before_destroy` returns `false`; whether the row still existed is not checked, as today.

Because the branch is inside `Model::delete()`, an override of `delete()` that calls `parent::delete()` stays soft.
The trait reaches Model through a few `protected` entry points (`perform_restore_on_model()`,
`perform_force_delete_on_model()`, static `perform_force_delete_all()`; names final in the plan).

### 5.4 `restore()`

Checks readonly and destroyed. `before_restore` (`false` → returns `false`). `UPDATE … SET <column> = NULL,
<updated_at> = ? WHERE <pk>`, the attributes written clean as in §5.3, `after_restore`, `true`. It runs on a record that
is not trashed too (Eloquent). Without active soft delete it throws (§6).

**Deviation from Eloquent**, whose `restore()` goes through `save()`: here `save()` also runs **validations**, so a
record that no longer satisfies today's rules could never be restored, and `save()` would also write the object's other
dirty attributes. The direct UPDATE mirrors the soft `delete()`, which skips `save()` as well.

### 5.5 `force_delete()`

Sets the force flag, calls `delete()` (hard path, same callbacks, `is_force_deleting()` is `true` meanwhile), and
clears the flag in `finally`, so that a throwing callback or statement never leaves a later plain `delete()` hard.
Without active soft delete it is `delete()`.

### 5.6 Transactions

Nothing new: a `Model::transaction()` rollback undoes the hard delete's destroyed flag as today. A soft delete or a
restore changes no in-memory state on rollback (as `save()` and as Eloquent); a caller that needs fresh state calls
`reload()`.

### 5.7 Uniqueness

`validates_uniqueness_of` runs its `exists()` on the validated model (`$this->model`) inside the internal suspension
with every global scope removed, on every model that has global scopes or active soft delete: trashed rows and rows
hidden by any scope count, as the database's unique index counts them.

---

## 6. Errors

Every error is thrown before any SQL. A builder method that throws leaves the query as it was.

`ConfigException`, from `ScopeRegistry` the first time the model's global scopes are read (3b's message shape:
`<Class> declares the scope x(), which …`):

1. `#[GlobalScope]` on a method that is not `protected static`;
2. a method marked both `#[Scope]` and `#[GlobalScope]`;
3. a global scope named like a local scope of the same hierarchy, or breaking 3b's name rules (§3.1);
4. a global scope with a required parameter besides the query;
5. a subclass method that redeclares a `#[GlobalScope]` method without the attribute;
6. the `SoftDeletes` trait without the `SoftDeletable` interface, or the interface without the trait.

`ConfigException`, the first time the soft-delete column is read:

7. `soft_delete_column()` names no column of the table;
8. the column's type is not `DATE`, `DATETIME` or `TIMESTAMP`.

`ActiveRecordException`:

9. a global scope adds a clause other than WHERE (the message names the scope and the clause), at compile time;
10. a global scope returns something other than `null` or its query (3b's message);
11. a global scope's body runs a query of its own model (re-entrancy);
12. an unknown name in `without_global_scope(s)` or in a relationship's `'unscoped'` (at the call, or at the
    relationship's first load); the message lists the class's global scopes;
13. a removal method (`without_global_scope(s)`, `*_trashed`) on a where group's query or a global scope's query;
14. `with_trashed()`, `only_trashed()`, `without_trashed()` on a model without active soft delete; the message says
    whether the model is not `SoftDeletable` or its `soft_delete_column()` is `null`;
15. `restore()` on a model without active soft delete;
16. `find_or_create_by_*` meeting a trashed match (§3.8);
17. `force_delete` called statically on a model without the trait, with the message of `update`/`delete`. With the
    trait, PHP's own `Error` comes first.

`RelationshipException`:

18. `'unscoped'` with a value that is neither `true` nor a list of strings, when the relationship is built. Its names are
    checked at the first load (error 12): checking them while the Table loads would need the target's registry inside
    `set_associations()`, a recursion risk.

Unchanged: `trashed()` without the column loaded throws the existing `UndefinedPropertyException`; writes on a readonly
or destroyed record throw today's errors.

---

## 7. Backward compatibility

Every change is listed. In the release notes, per the changelog policy, the observable ones are marked **BREAKING**;
the column "Who sees it" says who is actually affected, and pure additions are marked as such.

| # | Change | Who sees it |
|---|---|---|
| 1 | Legacy finders, `count`/`exists`, relationship loads and bulk writes are scoped; `delete_all`/builder `delete()` turn soft; `reload()` and uniqueness drop every scope | Only models that declare a `#[GlobalScope]` or are `SoftDeletable`. For them, **hand-written conditions on the soft-delete column** are ANDed with the filter: `<column> IS NOT NULL` silently returns no row, and a restore written as `update_all` with `<column> = null` touches no row (§3.7 helps find them). |
| 2 | New public `Query` methods and `FORWARDABLE` names (§3.3) | Everyone: a consumer static of the same name shadows the forwarded one; a local scope of that name becomes a `ConfigException` at its first use (3b's rule; 3b ships in the same draft, 3.2.0). |
| 3 | New protected `Model` methods (`perform_restore_on_model()`, `perform_force_delete_on_model()`, `perform_force_delete_all()`, names final in the plan) | Everyone: a consumer method of that name with an incompatible signature is a fatal error. The monolith is checked when the names are fixed. |
| 4 | `before_restore`/`after_restore` in `$VALID_CALLBACKS` | Existing methods of those names are registered automatically; they fire only on `restore()`. |
| 5 | `'unscoped'` relationship option | Addition (an unknown key throws today). |
| 6 | `force_delete` refused statically | Addition on models without the trait (today "undefined method"). |
| 7 | `Config::set_warn_soft_delete_conditions()` | Addition, off by default. |
| 8 | `find_or_create_by_*` throws on a trashed match | Only `SoftDeletable` models. |
| 9 | `QueryState` meta field, held-field invariant | `@internal`, no consumer. |

Golden SQL: unchanged for every model that is not opted in.

---

## 8. Risks and mitigations

1. **A global scope is not a security boundary.** Raw SQL, `Table::options_to_sql()`, `Table::update`/`Table::delete`,
   joins, `insert`/`upsert` and the removal methods all bypass it (§3.6). Mitigation: the README says so plainly; tenant
   isolation must not rely on a scope alone.
2. **Shared state.** The internal suspension is per process. It only wraps synchronous library calls; a consumer
   override of `find`/`first` that suspends a fiber inside a relationship load with `'unscoped'` could expose the
   suspension to another fiber. Accepted and documented; per-fiber state is proven feasible (§2) if needed.
3. **Per-statement cost.** One cached lookup per compile for models without scopes; per scope, one cached invoker call
   and a where-group compile. Mitigation: the index and invokers are cached per class; the plan benchmarks the legacy
   find and the builder `get` with the project's recipe (as for #185), on a model without scopes and with two.
4. **Recursion.** A scope that queries its own model would recurse: error 11.
5. **Silent empty results** from hand-written conditions on the soft-delete column (BC row 1): the optional warning
   (§3.7), the README, and table-by-table adoption (`soft_delete_column()` returning `null` per table keeps today's SQL).
6. **Trashed rows and unique keys:** `upsert` updates a trashed row (documented); `find_or_create_by_*` throws (§3.8).
7. **Indexing:** the soft-delete predicate benefits from an index on the column; the adopter's job (README).

---

## 9. Testing, documentation, release

### 9.1 Gates (every PR)

The full suite on MySQL, MariaDB, SQLite and Postgres with nothing skipped; PHPStan level 8 with no new baseline entry;
`composer run cs`; the suite on PHP 8.5 locally too (rebuild with `PHP_VERSION=8.5`), since local runs default to 8.3.

### 9.2 Schema and fixtures

New tables only, in `test/sql/mysql.sql` (also MariaDB), `pgsql.sql`, `sqlite.sql` (+ `pgsql-after-fixtures.sql` for
sequences), fixtures in `test/fixtures/`:
- `trash_posts`: `deleted_at DATETIME NULL`, `updated_at`, a UNIQUE `code`, a `draft` flag (a non-soft-delete scope);
- `trash_comments`: has_many target with soft delete;
- `trash_tags`, `trash_taggings`: `:through` with a soft-delete middle **and** target (both with `deleted_at`), in both
  shapes;
- a `DATE` soft-delete column, and a column of a wrong type.

Test models in `test/models/`, including a model on an existing table with only a global scope (PR 1).

### 9.3 Tests

1. **Byte-identity:** `GoldenSqlTest` unchanged and green on the four databases.
2. **Guards by reflection:** every public `Query` terminal and every scoped legacy entry → the scope is in the SQL;
   every unscoped path (`save()`, instance `delete()`/`reload()`, `Table::update`/`delete`, `insert`, `upsert`,
   `options_to_sql`, where groups) → it is not; aggregate and count subqueries → exactly once.
3. **`QueryEngineTest`** invariant with the meta fields.
4. **Global scopes:** order (root first, redeclared position); grouping with an `OR` in the user's conditions in the
   builder (`or_where`) and in legacy (`'a OR b'`); removal by name, all, last call wins; `when(false, …)` in a scope;
   qualification with builder joins, `from()`, legacy `'joins'` and `:through` where target and middle both have
   `deleted_at`; every error of §6.
5. **Relationships:** lazy/eager parity with scopes (`RelationshipEagerLazyParityTest`); belongs_to to a trashed parent
   with and without `'unscoped'`; `:through` with a trashed middle row, both shapes, lazy and eager, lifted by the
   through relationship's option; target-only `'unscoped'` leaves the middle filter.
6. **Soft delete:** legacy and builder bulk writes, `force_*`, `with_trashed()->delete()` re-stamping, limit on
   Postgres; instance verbs and callback order, cancellation by `before_*`; `force_delete()` throwing then a plain
   `delete()` staying soft; `is_force_deleting()`; uniqueness counting trashed and scope-hidden rows;
   `find_or_create_by_*` with a trashed, a live and no match; `updated_at` and `without_timestamps()`; the `DATE`
   column; attribute types after delete/restore; a `SoftDeletable` model with a `null` column (byte-identical SQL,
   the three `*_trashed` methods throw); the optional warning on and off.
7. **Iteration:** `chunk`, `lazy`, `chunk_by_id` (with its `MAX` probe), `cursor`, keyset paging with an `or_where`.
8. **Forwarding:** `QueryForwardingTest` with the new tags; static `force_delete` with and without the trait.

### 9.4 Documentation and release

- README: a "Global scopes and soft delete" section with Eloquent-style examples; the known limits (§3.6); "a scope is
  not a security boundary"; the deviations from Eloquent: Q8 (`force_*` keep other scopes), `restore()` without
  `save()` (§5.4), unknown names throw, `trashed()` without the column throws, `find_or_create_by_*` throws on a trashed
  match; the optional warning; indexing.
- PHPDoc on every new public method.
- Release notes added to the 3.2.0 draft when each PR merges, never before.

---

## 10. Delivery

One spec, two stacked PRs:

1. **PR 1 — engine and global scopes:** `GlobalScope`, the cached index and validation, the meta field, compiler
   application, removal methods, internal suspension, relationship `'unscoped'`, `reload()`, uniqueness, guard tests,
   benchmark. Tested with a non-soft-delete scope; useful on its own.
2. **PR 2 — soft delete:** `SoftDeletable`, `SoftDeletes`, `*_trashed`, bulk soft delete, `force_*`, instance verbs,
   `is_force_deleting()`, callbacks, `find_or_create_by_*` check, the `:through` middle soft delete, the optional
   warning, schema and fixtures.

#150 and #194 are separate PRs, independent of these two.

---

## 11. Verifications for the plan

1. The legacy wrap keeps positional binds in order on the four databases, including the Postgres limited-write
   subquery.
2. `and_pk_conditions` + `find()` emit the scope once.
3. MySQL's `rowCount()` for a bulk soft delete (changed rows; a re-stamp in the same second changes nothing on a
   `DATETIME` without fractions).
4. The final names of the protected Model entry points and of the `Config` warning, checked against the monolith.
5. The benchmark of §8.3.

---

## Appendix A — Monolith migration

The monolith's own migration is tracked in its internal ticket, which holds the inventory (sites, counts, the commands
that produce them). In short:

1. `RCModel` implements `SoftDeletable`, uses `SoftDeletes`, and overrides `soft_delete_column()` to return
   `'deleted_at'` only for the tables already migrated (`null` for the others keeps today's SQL), so adoption goes
   table by table. Its own injection and finder overrides go when every table is migrated.
2. Its `'deleted' => true` and `'no_default_conditions'` options are rewritten with the builder (`with_trashed()`,
   `without_global_scope('real')`); the default conditions become a `#[GlobalScope]`.
3. Hand-written conditions with `deleted_at IS NOT NULL` and restores written with `update_all` move to
   `only_trashed()`/`with_trashed()`/`restore()` (§3.7 can find them).
4. `delete_all` on soft-delete models turns soft: purges that must delete use `force_delete_all`.
5. Its cascade stays in `RCModel` (#202), using `is_force_deleting()`; #194 is a prerequisite.
