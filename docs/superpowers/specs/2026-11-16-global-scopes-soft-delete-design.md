# Global scopes and soft delete — `#[GlobalScope]`, `SoftDeletable` / `SoftDeletes`

**Date:** 2026-11-16
**Status:** Draft, awaiting the maintainer's written-spec review.
**Author:** Claude (paired with maintainer)
**Program:** sub-project 3 ("Scopes") of the "Eloquent fluent query builder" program (builder core spec
`2026-10-21-query-builder-core-design.md` §1.2), part **3a**. Part 3b (local scopes, spec
`2026-11-09-local-scopes-design.md`) merged as #201 (8170a20).
**Research input:** `.superpowers/scopes/2026-11-09-scopes-brainstorm-brief.md` and notes 01–05 (git-ignored, primary
checkout). Its facts were re-checked on `master` 8170a20 for this spec.
**Branch:** written on `spec/global-scopes`, cut from `origin/master` 8170a20.
**Follow-ups filed with this design:** #202 (delete cascade/restrict), #203 (sub-project 4, relationship querying), #204
(possible future extensions), a comment on #186 (joins by relationship name). #194 (`constraints` key) is a separate,
independent PR.

---

## 1. Context

### 1.1 Where this started

On 2026-11-09 the maintainer asked for soft delete "inspired by Eloquent and Ruby". The research split sub-project 3
into 3b (local scopes, done) and 3a (this spec). Two needs drive 3a:

- the builder is unsafe on soft-delete models: the builder-core spec says not to use it on them until scopes ship;
- the monolith's base model (`RCModel`) implements soft delete and one default condition by overriding
  `all/first/last/count/exists/find_by_id` and appending `AND deleted_at IS NULL` **without parentheses**. A top-level
  `OR` therefore leaks trashed rows, and `find()`, `find_by_*`, `has_many`/`has_one` and every eager load are not
  filtered at all.

### 1.2 Decisions (maintainer, 2026-11-16)

| # | Question | Decision |
|---|---|---|
| Q1 | Where global scopes apply | **Everywhere**: the builder, the legacy finders (`find`, `first`, `last`, `all`, `find_by_*`, `count`, `exists`) and lazy/eager relationship loads. The maintainer confirmed that the builder-core spec §1.4 ("not changing the existing statics' semantics") does not cover models that **opt in**. `save()`, instance `delete()` and `reload()` are never scoped. |
| Q2 | Soft-delete opt-in | **Trait `SoftDeletes` + interface `SoftDeletable`** (chosen over a static property after the maintainer asked why not a trait). The instance methods then exist only on opted-in models. The column is customised by overriding the method `soft_delete_column()`, never a static property. |
| Q3 | Declaring a global scope | **`#[GlobalScope]` on a `protected static` method** `fn(Query $q): ?Query`, named by the method. **WHERE-only.** No scope classes, no boot lifecycle (both in #204). |
| Q4 | Removing scopes | Builder methods `without_global_scope()`, `without_global_scopes()`, `with_trashed()`, `only_trashed()`, `without_trashed()`. Legacy: a block form **`Model::unscoped(callable, ?array $names)`**, per exact class. No new `$VALID_OPTIONS` keys. |
| Q5 | Relationships | Filtered by the target's scopes by default. A per-relationship option **`'unscoped' => true \| list<string>`** on `belongs_to` / `has_many` / `has_one`. Per-load constraints stay in sub-project 4 (#203). |
| Q6 | `:through` middle model | **Only the middle model's soft delete**, as Eloquent's `SoftDeletableHasManyThrough`. Not its other global scopes. |
| Q7 | Bulk writes | **Scoped everywhere**: legacy `update_all`/`delete_all` and builder `update()`/`delete()`. On a soft-delete model `delete_all`/`delete()` become an `UPDATE` of the column. The escape hatch is `unscoped()`. |
| Q8 | Hard bulk delete | **`force_delete()` (builder) and `force_delete_all()` (legacy, on the trait) drop only the soft-delete scope** and keep the others. A deliberate deviation from Eloquent, whose `forceDelete()` drops every scope. |
| Q9 | Instance soft delete | Direct by-PK `UPDATE`, no validations, no save callbacks, destroy callbacks fire, the object stays live. `restore()` with new `before_restore`/`after_restore` callbacks. `reload()` drops every global scope. **Deviation:** a `Model::transaction()` rollback restores the in-memory `deleted_at`. |
| Q10 | `validates_uniqueness_of` | **Counts trashed rows** (drops only the soft-delete scope, keeps the others). No `without_trashed` option until asked (#204). |
| Q11 | Smaller points | Approved as a package; see §3.6, §4 and §6. Deviations from Eloquent: an unknown scope name throws; `trashed()` without the column loaded throws. |
| — | Engine approach | **A meta field on `QueryState`**, applied in the compiler's two WHERE sites, guarded by reflection tests over every entry (§4). |
| — | Scope name | **`soft_deletes`**, as Eloquent names it after the trait, exposed as `SoftDeletable::SCOPE`. |

### 1.3 Goals

1. A model can declare filters that every read, bulk write and relationship load of it applies, and that can be removed
   by name.
2. Soft delete with Eloquent's API and semantics in snake_case, opt-in per model.
3. A scope can never let an `OR` leak, in the builder or in legacy conditions.
4. No SQL change for a model that declares no global scope and is not an active `SoftDeletable`: the golden corpus stays
   byte-identical.
5. The monolith's base model can drop its overrides (Appendix A).

### 1.4 Non-goals

- Delete cascade/restrict (#202). The monolith keeps its own cascade.
- Relationship querying, per-load eager constraints, `$order->lines()->with_trashed()` (#203).
- Scopes on joins by relationship name, which belong in the `ON` clause (#186). Documented as a known limit.
- A boot lifecycle and runtime registration, `GlobalScope` classes, a boolean soft-delete sentinel, a `without_trashed`
  uniqueness option (#204).
- The `constraints` relationship key (#194).
- **A bulk `restore()` on the builder** (`Order::only_trashed()->where(...)->restore()`; Eloquent has it). Not discussed
  in the brainstorming: see §10, item 1.

---

## 2. Facts the design relies on

Verified on 2026-11-16 (PHP 8.5.12 locally for the language checks; the trait/attribute/constant rules have not changed
since 8.3; `lib/` on `master` 8170a20):

- **Traits.** A class that redeclares a static property of its trait with a different default is a fatal composition
  error ("define the same property … the definition differs and is considered incompatible"). A method of the class
  wins over the trait's (`Base::soft_delete_column()` returned `null` over the trait's `'deleted_at'`). A subclass can
  override a trait method it inherits with a compatible signature (`restore($is_cron_job = false)` over `restore()`).
  The attributes of a trait method are visible through reflection on the using class and its subclasses.
- **Trait constants** cannot be read through the trait name ("Cannot access trait constant SoftDeletes::TSCOPE
  directly"). Interface constants can, also through the class (`Base::SCOPE`).
- **Compiler WHERE sites.** Every SELECT, COUNT, EXISTS and aggregate goes through `QueryCompiler::compile_select()`
  (`lib/QueryCompiler.php:189`), which compiles either the legacy options (`compile_legacy()`, :1067 →
  `apply_conditions()`, :1479) or the builder clauses (`compile_clauses()`, :1125, whose `$conditions` argument is
  the engine's own conditions, compiled first). Every UPDATE and DELETE goes through `apply_write_clauses()` (:1521),
  with a legacy branch and a builder branch.
- **Held fields.** `held_fields()` (:1353), `holds_no_builder_clause()` (:1378) and `refuse_unwritable_clauses()`
  (:604) treat every `QueryState` field as a clause. `QueryEngineTest::test_held_fields_reports_every_field_of_query_state`
  pins that invariant.
- **Entries.** `Model::new_query()` (`lib/Model.php:1046`, `Query::for_table`); `Table::find()` (`lib/Table.php:293`) and
  `Table::exists()` (:304) through `Query::from_options`; `Model::count()` (:2437); `bulk_write_query()` (:1372) for
  `update_all`/`delete_all`. `Table::options_to_sql()` (:282), `Table::update()` (:626) and `Table::delete()` (:659) also
  use `from_options` and must stay unscoped. `Query::from_options` itself is shared by all of them, so a scope cannot be
  attached at construction.
- **`and_pk_conditions`** (`lib/Model.php:2385`) renders a fragment with `options_to_sql()` and feeds it back to `find()`.
  With `options_to_sql()` unscoped, the scope is applied once.
- **Relationship loads** call the target's legacy statics: `BelongsTo.php:86` (`$class::first()`), `HasMany.php:226`
  (`$class_name::find()`), `AbstractRelationship.php:281` (`$class::find('all')`, eager). A `:through` join string is
  built once and kept on the relationship object (`HasMany.php:150-206`).
- **Uniqueness** calls `$this->model->exists(...)` (`lib/Validations.php:671`).
- **Instance delete** (`lib/Model.php:1441`) and the rollback bookkeeping (`$__deleted_in_transaction`,
  `close_transaction_scope()`, :3058) only flip `__destroyed` back on rollback.
- **Static dispatch.** `Model::__callStatic` (:2080) refuses `update` and `delete` (whole-table writes) and forwards
  `Query::FORWARDABLE`. `QueryForwardingTest` checks `FORWARDABLE` against the `@method` tags of `Model`.
- **Per-class suspension precedent:** `Model::without_timestamps()` → `Timestamps::suspend()` (counter per exact class,
  restored in `finally`).
- **3b's registry.** `ScopeRegistry` (`lib/ScopeRegistry.php`) reflects the local scopes without loading the Table and
  already refuses a local scope named like a static method of the model's hierarchy. That rule therefore also covers
  the new statics (`unscoped`, `force_delete_all`, `soft_delete_column`) and every `#[GlobalScope]` method.
- **CI** runs the suite on MySQL, MariaDB, SQLite and Postgres (`.github/workflows/ci.yml:101-110`).
- **Monolith** (grep of `application/`): no method named `unscoped`, `with_trashed`, `only_trashed`, `without_trashed`,
  `without_global_scope(s)`, `force_delete(_all)`, `soft_delete_column`, `trashed`, `before_restore`, `after_restore`,
  `perform_delete_on_model`, `boot*`. `restore()` exists twice: `Variazioni_menu::restore($is_cron_job = false)` and
  `Variazioni_menu_tipi_menu::restore()`.

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
        return $q->where('fittizia', 0);
    }
}
```

- `ActiveRecord\GlobalScope` is a method attribute, twin of 3b's `ActiveRecord\Scope`.
- The method must be `protected static`, take the query as its only required parameter, and return `null` or the query
  it was given (3b's signature).
- Its name is the scope's name, used for removal.
- It may only add WHERE conditions (any `where*` method, closure groups, a local scope that only adds wheres). Any
  other clause throws when the query is compiled (§6).
- Scopes are inherited: a scope declared on a base class applies to every subclass. A subclass can redeclare the
  method to replace it. A trait can carry a scope for unrelated models.

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

- `SoftDeletable` (interface) declares `public const string SCOPE = 'soft_deletes'` and
  `public static function soft_delete_column(): ?string`.
- `SoftDeletes` (trait) implements `soft_delete_column()` returning `'deleted_at'`. It also declares the global scope
  `#[GlobalScope] protected static function soft_deletes(Query $q)`, the instance methods of §3.4, the static
  `force_delete_all()`, and the override of the protected delete hook (§5.3).
- **Soft delete is active** for a model when it is an `instanceof SoftDeletable` **and** `soft_delete_column()` returns
  a non-null name. When it returns `null`, the `soft_deletes` scope adds nothing, and the model behaves exactly as one
  without the trait (byte-identical SQL).
- The library reads `soft_delete_column()` lazily, when a query is compiled or an instance verb runs, never in
  `Table::__construct`. An override may therefore call `static::table()` (the monolith's per-table detection).
- The column must be a `DATE`, `DATETIME` or `TIMESTAMP` column of the table (§6).

### 3.3 Builder methods

All on `Query`. All but `force_delete` are added to `Query::FORWARDABLE` and to the `@method` tags of `Model`, so that
`Order::with_trashed()->...` works.

| Method | Semantics |
|---|---|
| `without_global_scope(string $name): static` | Removes one global scope from this query. |
| `without_global_scopes(?array $names = null): static` | Removes the scopes named, or every global scope with `null`. |
| `with_trashed(): static` | Removes `soft_deletes`: trashed and live rows. |
| `only_trashed(): static` | Replaces the `soft_deletes` filter with `<column> IS NOT NULL`. It does not AND both (the Rails trap). |
| `without_trashed(): static` | Restores the default filter, undoing `with_trashed()` / `only_trashed()` and a `without_global_scope('soft_deletes')` on the same query. An `unscoped()` block still wins. |
| `force_delete(): int` | A real `DELETE` of the matching rows. Drops only `soft_deletes`; the other scopes stay. On a model without active soft delete it is `delete()`. |
| `update(array $values): int` (existing) | Scoped. |
| `delete(): int` (existing) | Scoped. On an active soft-delete model, an `UPDATE` of the column (§5.1). |

`force_delete` is **not** forwardable: like `update` and `delete`, a static call would be a whole-table write, and
`Model::__callStatic` refuses it with the same error. (On a `SoftDeletable` model PHP refuses it even earlier, because
the trait's instance `force_delete()` exists.)

### 3.4 Model methods

| Method | Kind | Semantics |
|---|---|---|
| `Model::unscoped(callable $fn, ?array $names = null): mixed` | static, every model | Runs `$fn` with the named global scopes of **exactly this class** removed (all with `null`), and returns what `$fn` returns. Nests; restored in `finally`. It covers legacy finders, `count`/`exists`, bulk writes, relationship loads of this class and builder queries compiled inside `$fn`. |
| `force_delete_all($options = []): int` | static, trait | `delete_all()`'s options and checks, as a real `DELETE`. Drops only `soft_deletes`. |
| `delete()` (existing) | instance | Soft on an active soft-delete model (§5.3). |
| `restore()` | instance, trait, untyped | §5.4. |
| `trashed()` | instance, trait, untyped | `true` when the column holds a value. `false` on a model without active soft delete. |
| `force_delete()` | instance, trait, untyped | Today's hard delete (§5.5). |
| `reload()` (existing) | instance | Now reads inside `unscoped()` with every global scope removed. |

The instance methods are untyped like `delete()`, so that existing untyped overrides stay compatible.

### 3.5 Callbacks and relationship option

- `before_restore` and `after_restore` join `CallBack::$VALID_CALLBACKS`. `before_restore` returning `false` cancels the
  restore, as `before_destroy` cancels a delete.
- Relationship option `'unscoped' => true | list<string>` on `belongs_to`, `has_many` and `has_one`: the loads of the
  relationship run inside `$class::unscoped(fn () => …, $names)` (`true` = every scope). It joins the valid options and
  the typed option shapes of the `AbstractRelationship` docblock (PHPStan level 8).

```php
static $belongs_to = [
    ['customer', 'unscoped' => [SoftDeletable::SCOPE]],   // a trashed customer still loads
];
```

### 3.6 Where scopes apply, and where they do not

Scoped:
- every builder read: `get`, `first`, `sole`, `find*`, `value`, `pluck`, `count`, aggregates, `exists`/`doesnt_exist`,
  `chunk`, `chunk_by_id` (including its initial `MAX(key)` probe), `lazy*`, `cursor`, and `to_sql()`/`to_raw_sql()`/
  `get_bindings()`, which show the scopes;
- builder `update()`/`delete()`/`force_delete()` (minus `soft_deletes` for the last);
- legacy `find` (all forms), `first`, `last`, `all`, `find_by_*`/`find_all_by_*`/`count_by_*`, `count`, `exists`,
  `update_all`, `delete_all`;
- lazy and eager relationship loads (target's scopes), and the middle model's soft delete on `:through` loads.

In an aggregate or count subquery, the scope appears once, in the inner query.

Not scoped, by construction:
- hand-written SQL: `find_by_sql`, `Model::query`, `Connection::query*`;
- `Table::options_to_sql()` and the public `Table::update()` / `Table::delete()`, the by-PK writes of `save()` and
  `delete()`;
- `insert` and `upsert` (no WHERE). An `upsert` that hits the unique key of a trashed row updates that row and leaves it
  trashed (documented);
- `join('table', …)`, raw join strings, and joins by relationship name (#186);
- closure where-groups: a global scope is never applied to the group's own query.

Qualification: a scope's column names follow the builder's single rule (`QueryCompiler::rows_name()`): unqualified
without joins; with joins, qualified with the name the model's rows are read under (`from()` alias, else the table).
Under `from_raw()` and the legacy `'from'` option, the table name, as today.

---

## 4. Architecture

### 4.1 Components

| Component | Status | Role |
|---|---|---|
| `lib/GlobalScope.php` | new | The method attribute. |
| `lib/SoftDeletable.php` | new | Interface: `SCOPE`, `soft_delete_column()`. |
| `lib/SoftDeletes.php` | new | Trait: §3.2–§3.4 and the delete hook. |
| `lib/ScopeSuspension.php` | new, `@internal` | The `unscoped()` blocks: a stack per exact class of "all" or a set of names. Name to confirm in the plan. |
| `ScopeRegistry` | changed | Also reflects `#[GlobalScope]` methods (top class first, declaration order), validates them (§6), and answers "the global scopes of class X". |
| `QueryState` | changed | Meta field `?array $scopes` (§4.2). |
| `QueryCompiler` | changed | Applies the scopes in `compile_select()` and `apply_write_clauses()`; excludes meta fields from the three held-field checks. |
| `SQLBuilder` | changed, additive | A method that puts a condition before the WHERE already set and parenthesizes the latter. |
| `Query` | changed | §3.3 methods; `FORWARDABLE`. |
| `Model` | changed | `unscoped()`, `perform_delete_on_model()` hook, `reload()`, the scoped entries, `@method` tags, rollback entries. |
| `Table` | changed | `find()`/`exists()` mark their query scoped. |
| `relationships/*` | changed | `'unscoped'` option; middle soft delete on `:through` loads. |
| `Validations` | changed | Uniqueness inside `unscoped(…, [SCOPE])` on active soft-delete models. |
| `CallBack` | changed | Two callback names. |

All new classes are in `lib/` (PSR-4 base dir), one per file; `LibraryLoadingTest` covers them.

### 4.2 State

`QueryState` gets `public ?array $scopes = null`, with the shape
`array{removed: true|list<string>, trashed: 'without'|'with'|'only'}`.

- `null` means "no scopes". It is the default, so `Query::for_table()`, `from_options()`, the closure groups and every
  write by PK carry no scopes **without any code change**.
- The scoped entries (§4.3) set it to `['removed' => [], 'trashed' => 'without']`.
- `without_global_scope(s)` adds to `removed`; `with_trashed`/`only_trashed`/`without_trashed` set `trashed`. Called on a
  query whose `scopes` is `null` (impossible through the public API, which always starts from a scoped entry), they set
  it first.
- It is a **meta field**: `held_fields()`, `holds_no_builder_clause()` and `refuse_unwritable_clauses()` skip it, so a
  legacy query with scopes is not "a query holding both legacy options and builder clauses", and `insert`/`upsert`
  never trip on it. The field list is one private constant of the compiler. The invariant test becomes "every field of
  `QueryState` but the declared meta fields is a clause".
- `clone()`, `window()`, the pager and the cursor copy the state, so they copy the field.

### 4.3 Scoped entries

`Model::new_query()`, `Table::find()`, `Table::exists()`, `Model::count()` and `bulk_write_query()` set the field. Every
other legacy static reaches one of them. `reload()` calls `find()` inside `unscoped()`, and the uniqueness check calls
`exists()` inside `unscoped(…, [SCOPE])`.

### 4.4 Application in the compiler

At each of the two WHERE sites, when `$state->scopes` is not `null`:

1. Read the model's global scopes from `ScopeRegistry` (top class first, declaration order).
2. Drop the names in `removed`, the names an active `unscoped()` block removes for **exactly** the query's model class,
   and `soft_deletes` when `trashed` is `with` or `only`.
3. Run each remaining scope on a fresh where-only query of the same model (the shape of a closure group). A scope that
   adds anything but wheres throws. A scope that adds no condition (the `soft_deletes` scope when the column is `null`)
   produces no group.
4. With `trashed` = `only`, add a group `<column> IS NOT NULL`.
5. Combine: one parenthesized AND group per scope, **before** the user's conditions, which are parenthesized as a
   whole: `(scope1) AND (scope2) AND (<user conditions>)`. Builder: the groups go in through the `$conditions`
   channel of `compile_clauses()` and the builder branch of `apply_write_clauses()`. Legacy: `apply_conditions()`
   writes the user's WHERE as today, then the new `SQLBuilder` method puts the scope SQL and binds in front of it.
6. No group means no change: the SQL is byte-identical.

The model's soft-delete column is validated (§6) the first time step 3 or an instance verb reads it.

### 4.5 `unscoped()` blocks

`ScopeSuspension` keeps, per exact class, a stack of entries (`true` = all, or a list of names). `Model::unscoped()`
validates the names against the registry **before** running `$fn`, pushes, runs `$fn`, and pops in `finally`. The
compiler asks it for the names removed for a class: the union of the stack. Nesting and exceptions are therefore safe,
and a block on `RCModel` does not affect `Articoli`, nor the reverse.

### 4.6 Relationships

- `'unscoped'`: the three load calls (`BelongsTo.php:86`, `HasMany.php:226`, `AbstractRelationship.php:281`) run inside
  `$class::unscoped(fn () => …, $names)`. The user's overrides of `first`/`find` keep being called.
- `:through` middle soft delete: on every lazy and eager `:through` load (both shapes: the join-table one and the #22
  reverse-FK chain), when the middle model has active soft delete, the relationship appends
  `<middle table>.<column> IS NULL` to the conditions it already builds for that load, not to the frozen join string.
  It skips it when the `:through` relationship declares `'unscoped'` with `true` or with `SoftDeletable::SCOPE`, or
  when an `unscoped()` block on the middle class removes `soft_deletes`. The middle model's other global scopes are not
  applied (Q6).

### 4.7 Interaction with other work

- 3b local scopes: unchanged; 3a reuses its signature, grouping and registry.
- Sub-project 1 (iteration): pager and cursor copy the state, so they are scoped. The keyset condition and the scope
  groups are both parenthesized.
- #186 / #203: they apply the joined or related model's scopes when they land (comment on #186, body of #203).

---

## 5. Soft-delete writes

"Active", "column" and "now" below: soft delete active (§3.2), its column, and the PHP clock formatted for the column's
type as `Timestamps` formats (`DATE` → date; `DATETIME`/`TIMESTAMP` → date and time).

### 5.1 Bulk soft delete

On an active model, `delete_all($options)` and `Query::delete()` compile as the UPDATE of `update_all`, with the same
WHERE, order and limit (the Postgres primary-key subquery included):

```sql
UPDATE t SET <column> = ?, <updated_at> = ? WHERE (<scopes>) AND (<conditions>) [ORDER BY …] [LIMIT …]
```

- `<updated_at>` gets the same value, only when the table has the column and `without_timestamps()` is not running for
  the class.
- They return the number of rows the statement reports.
- With `with_trashed()` they re-stamp trashed rows too (Eloquent).

### 5.2 Bulk hard delete

`force_delete_all($options)` and `Query::force_delete()` run a `DELETE` with `soft_deletes` removed and every other
scope kept. On a model without active soft delete they are `delete_all()`/`delete()`.

### 5.3 Instance `delete()`

`Model::delete()` keeps its checks (readonly, destroyed, primary key) and its `before_destroy` /
`after_destroy` callbacks, and delegates the write to a new protected hook, `perform_delete_on_model()` (Eloquent's
name):

- `Model`'s version is today's hard delete: `Table::delete($pk)`, `__destroyed = true`, the rollback entry.
- `SoftDeletes` overrides it. On an active model, unless `force_delete()` is running, it runs
  `UPDATE … SET <column> = ?, <updated_at> = ? WHERE <pk>` through the unscoped by-PK path, writes the values into the
  object's attributes without marking them dirty, and records a rollback entry (§5.6). The object is not marked
  destroyed. Otherwise it calls the parent hook.
- No validation and no save callback run. `delete()` returns `true`, `false` when `before_destroy` returns `false`.
  Whether the row still existed is not checked, as today.

Because the branch is inside `Model::delete()`, an override of `delete()` that calls `parent::delete()` stays soft.

### 5.4 `restore()`

Checks readonly and destroyed. `before_restore` (`false` → returns `false`). `UPDATE … SET <column> = NULL,
<updated_at> = ? WHERE <pk>`, the attributes updated and clean, a rollback entry, `after_restore`, `true`. It runs on a
record that is not trashed too (Eloquent). On a model without active soft delete it throws (§6).

### 5.5 `force_delete()`

Sets an internal flag and calls `delete()`, so that the hook takes the hard path with the same callbacks. On a model
without active soft delete it is `delete()`.

### 5.6 Transactions

Inside `Model::transaction()`, a soft `delete()` and a `restore()` record the previous values of the column and of
`<updated_at>`. A rollback puts them back in the object, newest entry first, so that two changes in one scope return to
the original. An inner scope that commits hands its entries to the enclosing one, as the destroyed flag does today. The
`$__deleted_in_transaction` bookkeeping is generalised to entries that know how to undo themselves; the hard delete's
entry undoes `__destroyed` as today.

### 5.7 Uniqueness

On an active model, `validates_uniqueness_of` runs its `exists()` inside `static::unscoped(…, [SoftDeletable::SCOPE])`:
trashed rows count, other scopes stay.

---

## 6. Errors

Every error is thrown before any SQL. A builder method that throws leaves the query as it was.

`ConfigException` — from `ScopeRegistry`, the first time the model's scopes are read (message shape as in 3b:
`<Class> declares the scope x(), which …`):
1. `#[GlobalScope]` on a method that is not `protected static`;
2. a method marked both `#[Scope]` and `#[GlobalScope]`;
3. a global scope named like a local scope of the same hierarchy;
4. a global scope with a required parameter besides the query;

and the first time the soft-delete column is read:
5. `soft_delete_column()` names no column of the table;
6. the column's type is not `DATE`, `DATETIME` or `TIMESTAMP`.

`ActiveRecordException`:
7. a global scope adds a clause other than WHERE (message names the scope and the clause), at compile time;
8. a global scope returns something other than `null` or its query (3b's message);
9. an unknown name in `without_global_scope(s)` (at the call) or `unscoped()` (before `$fn` runs); the message lists the
   class's global scopes;
10. `with_trashed()`, `only_trashed()`, `without_trashed()` on a model without active soft delete. One rule for both
    cases, with a message saying which: the model is not `SoftDeletable`, or its `soft_delete_column()` is `null`.
    `unscoped(…, [SoftDeletable::SCOPE])` does **not** throw on a `SoftDeletable` model with a `null` column, since the
    scope is declared (it just adds nothing): it is the path for generic base-model code;
11. `restore()` on a model without active soft delete;
12. `force_delete` called statically (§3.3), with the message of `update`/`delete`.

`RelationshipException`:
13. `'unscoped'` with a value that is neither `true` nor a list of strings, when the relationship is built. Its names
    are checked at the first load, with error 9 (checking them while the Table loads would need the target's registry
    inside `set_associations()`, a recursion risk).

Unchanged: `trashed()` without the column loaded throws the existing `UndefinedPropertyException`; writes on a readonly or
destroyed record throw today's errors.

---

## 7. Backward compatibility

Per the changelog policy, every observable change is listed as **BREAKING** in the release notes.

| # | Change | Who sees it |
|---|---|---|
| 1 | Legacy finders, `count`/`exists`, relationship loads and bulk writes are scoped; `delete_all`/builder `delete()` turn soft; `reload()` unscoped | Only models that declare a `#[GlobalScope]` or are `SoftDeletable` (Q1, approved). |
| 2 | New public `Query` methods and `FORWARDABLE` names (§3.3) | Everyone: a consumer static of the same name shadows the forwarded one; a local scope of that name becomes a `ConfigException` (3b's rule). 3b ships in the same draft (3.2.0), so no published release has local scopes yet. |
| 3 | `Model::unscoped()` (static) and `Model::perform_delete_on_model()` (protected) | Everyone: a consumer method of that name with an incompatible signature is a fatal error. |
| 4 | `before_restore`/`after_restore` in `$VALID_CALLBACKS` | Methods of that name are registered; they only fire on `restore()`. |
| 5 | `'unscoped'` relationship option | Additive (an unknown key throws today). |
| 6 | `force_delete` refused statically | Additive (today it is "undefined method"); same error family as `update`/`delete`. |
| 7 | `QueryState` meta field, held-field invariant | `@internal`, no consumer. |

Golden SQL: unchanged for every model that is not opted in.

---

## 8. Testing, documentation, release

### 8.1 Gates (every PR)

The full suite on MySQL, MariaDB, SQLite and Postgres with nothing skipped; PHPStan level 8 with no new baseline entry;
`composer run cs`; the suite on PHP 8.5 too (rebuild with `PHP_VERSION=8.5`).

### 8.2 Schema and fixtures

New tables only, in `test/sql/mysql.sql` (also MariaDB), `pgsql.sql`, `sqlite.sql` (+ `pgsql-after-fixtures.sql` for
sequences), fixtures in `test/fixtures/`:

- `trash_posts`: `deleted_at DATETIME NULL`, `updated_at`, a UNIQUE `code`, a `draft` flag (a non-soft-delete scope);
- `trash_comments`: has_many target with soft delete;
- `trash_tags`, `trash_taggings`: `:through` with a soft-delete middle model, in both shapes;
- a `DATE` soft-delete column, and a column of a wrong type for the `ConfigException`.

Test models in `test/models/`, including a model on an existing table with only a global scope (PR 1).

### 8.3 Tests

1. **Byte-identity:** `GoldenSqlTest` unchanged and green on the four databases.
2. **Guards by reflection:** every public `Query` terminal and every scoped legacy entry → the scope is in the SQL; every
   unscoped path (`save()`, instance `delete()`/`reload()`, `Table::update`/`delete`, `insert`, `upsert`,
   `options_to_sql`, closure groups) → it is not; aggregate and count subqueries → exactly once.
3. **`QueryEngineTest`** invariant: every field but the meta fields; meta fields skipped by the three checks.
4. **Global scopes:** order; grouping with an `OR` in the user's conditions in the builder (`or_where`) and in legacy
   (`'a OR b'` string); removal by name and all; `unscoped()` nested, throwing inside `$fn`, exact class (not parent, not
   child); qualification with joins and `from()`; every error of §6.
5. **Relationships:** lazy/eager parity with scopes (extend `RelationshipEagerLazyParityTest`); belongs_to to a trashed
   parent, with and without `'unscoped'`; `:through` with a trashed middle row, both shapes, lazy and eager, both
   opt-outs.
6. **Soft delete:** legacy and builder bulk writes, `force_*`, `with_trashed()->delete()` re-stamping, limit on
   Postgres; instance verbs and callback order, including cancellation by `before_*`; nested rollbacks; uniqueness
   counting trashed rows; `updated_at` and `without_timestamps()`; the `DATE` column; a `SoftDeletable` model with a
   `null` column (byte-identical SQL, the three `*_trashed` methods throw, `unscoped([SCOPE])` does not).
7. **Iteration:** `chunk`, `lazy`, `chunk_by_id` (with its `MAX` probe), `cursor`, keyset paging with an `or_where`.
8. **Forwarding:** `QueryForwardingTest` with the new tags; `force_delete` refused statically.

### 8.4 Documentation and release

- README: a "Global scopes and soft delete" section, Eloquent-style examples, the known limits (§3.6) and the deviations
  from Eloquent (Q8, Q9 rollback, Q11).
- PHPDoc on every new public method.
- Release notes added to the 3.2.0 draft when each PR merges, never before.

---

## 9. Delivery

One spec, two stacked PRs:

1. **PR 1 — engine and global scopes:** `GlobalScope`, registry, meta field, compiler application, `SQLBuilder`
   method, removal methods, `unscoped()`, relationship `'unscoped'`, `reload()`, guard tests. Tested with a
   non-soft-delete scope; useful on its own.
2. **PR 2 — soft delete:** `SoftDeletable`, `SoftDeletes`, `*_trashed`, bulk soft delete, `force_*`, instance verbs,
   callbacks, rollback, uniqueness, `:through` middle soft delete, schema and fixtures.

---

## 10. Open points and verifications for the plan

1. **Bulk `restore()` on the builder** (non-goal so far): Eloquent has `Order::only_trashed()->where(...)->restore()`.
   To confirm with the maintainer during the spec review: add it to PR 2 (a scoped `UPDATE … SET <column> = NULL`, with
   the `restore` name refused statically like `force_delete`), or file it in #204.
2. Verify on the four databases that the legacy wrap `(<scope>) AND (<legacy>)` keeps positional binds in order,
   including the Postgres limited-write subquery.
3. Verify that `and_pk_conditions` + `find()` emit the scope once.
4. Verify MySQL's `rowCount()` for a bulk soft delete (changed rows; a re-stamp in the same second changes nothing on
   `DATETIME` without fractions).
5. Confirm the internal class name (`ScopeSuspension`) and where the meta-field list lives.

---

## Appendix A — Monolith migration (for the monolith's own ticket)

These are the monolith's decisions; they are listed so that the adoption is planned.

1. **RCModel:** `implements SoftDeletable; use SoftDeletes;`, and `soft_delete_column()` returns `'deleted_at'` when the
   table has the column, else `null`. Its `deleted_at` injection and the `all/first/last/count/exists/find_by_id`
   overrides can go where they exist only for soft delete or default conditions. `$defaultConditions` on
   `Prenotazioni_tavoli` becomes `#[GlobalScope] protected static function real(...)`. `'deleted' => true` (5 sites) →
   `unscoped(…, [SoftDeletable::SCOPE])`; `'no_default_conditions'` (4 sites) → `unscoped(…, ['real'])`.
2. **About 122 hand-written conditions name `deleted_at`.** Today they switch RCModel's filter off. Under the library
   they are ANDed with `deleted_at IS NULL`: `deleted_at IS NOT NULL` or `deleted_at > ?` return **no rows**, silently.
   Each must be reviewed and moved to `only_trashed()` or `unscoped()` (the tautology at `ArticoliPadre.php:88` is one).
3. **About 21 `delete_all` calls on soft-delete models turn soft.** The `DataCleaner` purge and every delete meant to be
   real move to `force_delete_all`. RCModel's cascade (`update_all` on children) becomes scoped and stops re-stamping
   already-trashed children.
4. **Observable changes:** `find`/`find_by_*` start filtering (about 62 sites; the `ArticoliPadre` resurrect path needs
   `unscoped`); `has_many`/`has_one` and eager loads filter; eager `belongs_to` to a trashed parent becomes `null`;
   `:through` relationships filter a trashed middle row; the `OR` leak is closed; soft delete no longer runs
   validations or `before_save`/`after_save`; `Variazioni_menu::restore()` and `Variazioni_menu_tipi_menu::restore()`
   shadow the library's `restore()` and must be renamed.
5. **Prerequisites:** #194; the cascade stays in RCModel (#202).
