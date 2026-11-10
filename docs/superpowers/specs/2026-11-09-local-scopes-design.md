# Local scopes — `scope_x` / `#[Scope]` on models, reachable statically and on the query builder

**Date:** 2026-11-09
**Status:** Approved (2026-11-09); implemented on `feat/local-scopes`.
**Author:** Claude (paired with maintainer)
**Program:** sub-project 3 ("Scopes") of the "Eloquent fluent query builder" program (builder core spec
`2026-10-21-query-builder-core-design.md` §1.2), split on 2026-11-09 into **3b local scopes (this spec, first)** and
**3a global scopes + soft delete (next)**.
**Research input:** `.superpowers/scopes/2026-11-09-scopes-brainstorm-brief.md` and notes 01–05 (git-ignored, primary
checkout) — a read-only, critic-verified survey of the library, the monolith, Eloquent and Rails.
**Branches:** this document was written on `spec/local-scopes`, cut from `origin/master` 3316cbf. Sub-project 1 (iteration:
`feat/query-iteration-paging` / `feat/query-iteration-cursor`) has merged (#197, #198), and the implementation lives on
`feat/local-scopes`, cut from `master` after that merge.

---

## 1. Context

### 1.1 Where this started

On 2026-11-09 the maintainer asked for soft delete "inspired by Eloquent and Ruby", then for a plan for scopes. The
research split sub-project 3 in two. The research recommended global scopes + soft delete first. The maintainer chose
**local scopes first**.

### 1.2 Decisions (maintainer, 2026-11-09)

1. Split: 3b local scopes first, 3a global scopes + soft delete next. 3b fixes the scope callable signature that 3a
   reuses.
2. Declaration: **both** forms. A `public static scope_x(...)` method, and a static method marked `#[Scope]`
   (Eloquent 12 has both `scopeX` and `#[Scope]`).
3. Collisions are checked when the model's scopes are first read (reflection), and throw `ConfigException`. This
   includes the static methods of the consumer's own class hierarchy, e.g. an inherited `RCModel::chunk`. It is an
   error, not a shadow.
4. An unknown method on a `Query` throws `\BadMethodCallException`. **This is a BC break, explicitly approved.**
5. The wheres a scope adds are **always** wrapped in one parenthesized group.
6. Instance context: `static::x()` from an instance method is not supported. Document
   `static::new_query()->x()`, as for `where`.
7. `has_scope()` on `Model` and on `Query`.

### 1.3 Goals

1. `Post::popular()` and `Post::where(...)->popular()->...` work as Eloquent's local scopes do, in snake_case.
2. A scope can never make an `OR` leak out of its own conditions.
3. A misnamed scope fails loudly and deterministically, never as a silently unreachable method.
4. No SQL change for code that does not use scopes (the golden corpus stays byte-identical).

### 1.4 Non-goals

- Global scopes and soft delete (3a).
- `scopes([...])` (applying several scopes from an array), dynamic scopes on relationship queries
  (`$post->comments()->active()`: sub-project 4), scopes in eager-load constraints (sub-project 4).
- Dispatching scopes from `Model::__call` (instance context).

---

## 2. Facts the design relies on

Verified on 2026-11-09 (PHP 8.3.35 in the Docker `tests` image, and the code on `origin/master` 3316cbf):

- A real static method is called by PHP without reaching `__callStatic`. A real instance method is called without
  reaching `__call`. A `protected static` method called from outside the class **does** route to `__callStatic`.
  (Probe: `Order::chunk()` ran an inherited `RC::chunk`; `Order::hidden()` on a protected static reached
  `__callStatic`; `(new Q)->where()` ran the real method.) So a collision can only be detected by reflection, not at the
  call.
- `ReflectionClass::getMethods(ReflectionMethod::IS_STATIC)` on the concrete class lists inherited statics too.
- `Query` has no `__call` today. An unknown method is PHP's `\Error` "Call to undefined method
  ActiveRecord\Query::x()".
- `Model::__callStatic` (lib/Model.php ~2051) resolves, in order: dynamic finders, the refusal of `update`/`delete`,
  `Query::FORWARDABLE`, then `ActiveRecordException` "Call to undefined method".
- A closure where group is a `Where` of shape `['type' => 'group', 'boolean' => 'AND'|'OR', 'not' => bool, 'group' =>
  QueryState]` (lib/QueryState.php:32). `ConditionCompiler` compiles it in parentheses (lib/ConditionCompiler.php:103).
  `Query::closure_wheres()` refuses a group whose state holds anything but `wheres` / `empty_where` / `skipped_when`
  (lib/Query.php ~3224).
- Calling the static scope from an instance method (`static::active()`) reaches `Model::__call`, which handles only
  `build_*` / `create_*` (brief §2.4).
- Monolith (read-only grep, `application/`, vendor excluded): 0 methods named `scope*`, 0 `#[Scope]`, 0 `has_scope`.

---

## 3. Public API and semantics

### 3.1 Declaration

```php
use ActiveRecord\Model;
use ActiveRecord\Query;
use ActiveRecord\Scope;

class Post extends Model
{
    public static function scope_popular(Query $q, int $min = 100): ?Query
    {
        return $q->where('votes', '>', $min)->or_where('featured', 1);
    }

    #[Scope]
    protected static function published(Query $q): ?Query
    {
        return $q->where('published', 1);
    }
}

Post::popular()->get();
Post::where('author_id', 3)->published()->popular(50)->get();
Post::has_scope('popular');            // true
Post::new_query()->has_scope('nope');  // false
```

1. **What is a scope:**
   - a `public static` method whose name starts with `scope_` (and is longer than the prefix);
   - a `protected static` method that carries the attribute `ActiveRecord\Scope` (as Eloquent requires `protected`).
   - A non-static `scope_*` method is **ignored**, so an existing consumer method of that shape does not break.
   - A `#[Scope]` method that is not `protected static` is an **error**, because the attribute is an explicit
     declaration (§5). A `public static` one would be called by PHP directly, without the query (§2), and fail with
     `ArgumentCountError`. A `private` one would not be inherited as a scope by subclasses.
     *(Correction made while writing the spec: section 1 in chat said "public or protected".)*
2. **Public name:** for `scope_popular` it is the method name without the `scope_` prefix, as written (`popular`).
   For `#[Scope]` it is the method name. Names are case-sensitive, as every builder name is.
3. **Signature:** `fn(Query $q, mixed ...$args): ?Query`, the signature 3a's global scopes reuse.
   - The call's arguments pass through unchanged. PHP's own `TypeError` / `ArgumentCountError` apply.
   - The scope may return `null` (the chain is unchanged) or the same `Query` instance.
   - Any other return value, **another** `Query` included, throws `ActiveRecordException` after the scope runs (§5).
4. **What a scope may do:** anything the builder does (`where*`, `order_by`, `select`, `join*`, `with`, `limit`, …).
5. **Grouping (decision 5):** the conditions a scope adds to the WHERE are replaced by **one** `group` condition,
   ANDed with what the query held before the scope ran.
   - `Post::where('published', 1)->popular()` compiles to `WHERE published = ? AND (votes > ? OR featured = ?)`.
   - A scope that adds no WHERE condition adds no group.
   - Everything else a scope adds (order, joins, select, eager loads, limit) is applied as it is.
   - A scope's group is a group even when it holds one condition: `AND (status = ?)`.
   - The flags a where call sets when it adds nothing (`empty_where`, `skipped_when`, see `Query::add_wheres()`) are
     handled as for a closure group.
6. **Reaching a scope:**
   - **statically:** `Post::popular(...)` = `Post::new_query()` with the scope applied, through `Model::__callStatic`.
     It is checked after dynamic finders, the `update`/`delete` refusal and `Query::FORWARDABLE`, and before the
     "undefined method" error;
   - **on a query:** `$query->popular(...)`, through a new `Query::__call`.
   - **Inside a closure group:** `where(fn ($q) => $q->popular())` is allowed. A scope that adds anything but wheres
     there throws the builder's existing "A where() group can only hold where clauses" `ActiveRecordException`.
7. **Unknown names:**
   - on a query, `\BadMethodCallException('Call to undefined method ActiveRecord\Query::<name>()')`;
   - statically, `ActiveRecordException` "Call to undefined method", as today.
8. **`has_scope(string $name): bool`:** a real static on `Model` and a method on `Query`. Both answer whether the model
   declares a scope reachable by that public name.
9. **Instance context** *(amended during implementation, R4)*: inside the model's own code a scope is reached as
   `static::new_query()->popular()`, documented next to the same note for `where`.
   - For a `scope_x` scope, `static::popular()` from an instance method is not supported (PHP routes it to
     `Model::__call`) and still throws "Call to undefined method".
   - For a `#[Scope]` method, `static::published()` anywhere inside the model class, in a static or an instance
     context, calls the protected method itself, without the query, and fails with PHP's `TypeError`.

### 3.2 Collisions (decision 3)

When the model's scopes are first read (§4.1), each public scope name is checked. Any of these throws
`ConfigException` naming the class, the scope and the reason:

| # | The name … | Example |
|---|---|---|
| 1 | is a public method of `Query` | `scope_where`, `scope_chunk`, `scope_has_scope` |
| 2 | is a real static method of the model class or any ancestor, `Model` included (by `ReflectionClass::getMethods(IS_STATIC)` on the concrete class, the scope methods themselves excluded) | `scope_find`, `scope_create`, `scope_chunk` on a subclass of `RCModel` |
| 3 | starts with a dynamic-finder prefix: `find_by_`, `find_all_by_`, `count_by_`, `find_or_create_by_` | `scope_find_by_title` |
| 4 | is `update` or `delete` | `scope_delete` |
| 5 | is declared twice (`scope_x` and a `#[Scope]` method `x`) | |

A `#[Scope]` method that is not `protected static` also throws `ConfigException` (§3.1 item 1).

---

## 4. Architecture

### 4.1 Components

| Unit | Kind | Responsibility |
|---|---|---|
| `Scope` (new, `lib/Scope.php`) | public | `#[\Attribute(\Attribute::TARGET_METHOD)] final class Scope {}` |
| `ScopeRegistry` (new, `lib/ScopeRegistry.php`) | `@internal` | Built lazily for one model class and cached per class in a private static array of `ScopeRegistry` itself (`for_class()`; a failed build is not cached): reflects its scope methods, validates §3.2, holds `name → method`. `has(string $name): bool`; `apply(Query $query, string $name, array $args): Query` runs the scope, checks the return value, and regroups the wheres it added (§4.2). |
| `Table` (changed) | `@internal` accessor | `scopes(): ScopeRegistry`, a named accessor that returns `ScopeRegistry::for_class()` for the model. *Amended during implementation (R5, maintainer decision 2026-11-10):* the registry is cached in `ScopeRegistry`, not on the Table, and `Model::__callStatic` and `Model::has_scope` read it without calling `Model::table()`. Reading the scopes is reflection only, so it never loads the Table, opens the connection or reads the schema: an unknown static name on a model whose table is missing (or when the database is down) still throws "Call to undefined method", as before. |
| `Model` (changed) | public | `__callStatic` gains the scope step (§3.1 item 6). New static `has_scope(string $name): bool`. |
| `Query` (changed) | public | New `__call(string $name, array $args): static` (scope or `\BadMethodCallException`) and `has_scope(string $name): bool`. |

New files need only their file in `lib/` (PSR-4; `LibraryLoadingTest` guards it).

"When the model's scopes are first read" means the first `ScopeRegistry` build for that class. That happens at the
first `Model::__callStatic` that falls through to the scope step, the first `Query::__call`, or the first `has_scope`.
A collision therefore surfaces at the first interaction with scopes, even when the colliding name itself is never
called.

### 4.2 Grouping

`ScopeRegistry::apply()`:

1. Notes `$before = count($query->state()->wheres)`, and the `empty_where` / `skipped_when` flags.
2. Calls the scope with `($query, ...$args)`, then refuses a return that is not `null` and not `$query`.
3. If the scope added wheres:
   - it moves `array_slice($wheres, $before)` into a new `QueryState` (the flags it set go with them);
   - it replaces them with one `['type' => 'group', 'boolean' => 'AND', 'not' => false, 'group' => $state]`.
   The compiled form is the existing closure-group form, so no new compilation code is needed.

### 4.3 Flow

```
Post::where('published', 1)->popular(50)->get()
  Model::__callStatic('where')       → FORWARDABLE → Query::where
  Query::__call('popular', [50])     → Table::scopes() (built + validated on first use)
                                     → ScopeRegistry::apply: Post::scope_popular($q, 50), regroup its wheres
  Query::get()                       → QueryCompiler (unchanged)
```

### 4.4 Interaction with other work

- Sub-project 1: `chunk` / `lazy` / `*_by_id` / `cursor` work after a scope with no extra code. The scope changes the
  query; pages and cursors read it, or clones of it.
- 3a will reuse the signature (§3.1 item 3) and the grouping (§4.2) for global scopes.

---

## 5. Errors

| Case | Exception | When |
|---|---|---|
| Collision (§3.2 rows 1–5), a `#[Scope]` method that is not `protected static` | `ConfigException`, naming class, scope and reason | first read of the model's scopes |
| A scope returns neither `null` nor the same Query | `ActiveRecordException`, naming the scope and the returned type | after the scope runs |
| Unknown name on a query | `\BadMethodCallException('Call to undefined method ActiveRecord\Query::<name>()')` | at the call |
| Unknown name statically | `ActiveRecordException` "Call to undefined method", unchanged | at the call |
| A scope adds a non-where clause inside a `where(fn)` group | the builder's existing `ActiveRecordException` | at the call |
| Wrong arguments for the scope | PHP `TypeError` / `ArgumentCountError`, unchanged | at the call |

---

## 6. Backward compatibility

All four items go in the GitHub draft release on merge (changelog policy: every observable change is listed; breaking ones
are marked BREAKING).

1. **BREAKING (approved 2026-11-09):** an unknown method on a `Query` throws `\BadMethodCallException` instead of PHP's
   `\Error`. The message for an undefined method is unchanged. A private or protected method of `Query` called from
   outside (e.g. `compiler()`) throws the same `\BadMethodCallException` "Call to undefined method
   ActiveRecord\Query::<name>()" too, where PHP's message was "Call to private method ...". Code that catches
   `\Error` for either must catch `\BadMethodCallException` (or `\Throwable`).
2. **BREAKING (potential):** a consumer's existing `public static scope_*` method or `#[ActiveRecord\Scope]` method
   becomes a scope. If its public name collides (§3.2), the model throws `ConfigException` at its first interaction
   with scopes. Monolith: 0 such methods.
3. **BREAKING (potential):** new public names `Model::has_scope` (static) and `Query::has_scope`. A consumer static
   `has_scope` with an incompatible signature is a fatal error at class load. Monolith: 0.
4. **Added:** `Post::x()` / `->x()` for declared scopes, which threw "undefined method" before.

Unchanged: SQL and binds for every call that does not use a scope (golden corpus); the static "undefined method" error, also for a model whose
table is missing (R5: the registry is read without loading the Table); `Query::FORWARDABLE` (scopes are not added to it).

---

## 7. Testing, documentation, release

### 7.1 Gates

Full suite on MySQL (default), MariaDB and SQLite (`PHPAR_CONNECTION`), PHP 8.3 and 8.5, **no skips**; PHPStan level 8
with **no new baseline entry**; `composer run cs`; `ScopeRegistry` ≥ 90% line coverage.

### 7.2 Tests

New `test/QueryScopeTest.php` (extends `DatabaseTest`) and test models in `test/models/` (a scoped model on an existing
fixture table, plus one model per collision kind; collision models must not break other tests, which only load them
when touched):

- both declaration forms, statically and on a chain, with arguments; `null` return; same-query return; other return →
  `ActiveRecordException`;
- **grouping:** `where(<A>)->scope(<B OR C>)` gives `<A> AND (<B> OR <C>)` (SQL checked quote-agnostically), **and** a
  fixture row matching `C` but not `A` is not returned; a scope adding no where adds no group; a one-condition scope is
  grouped; order/join/with/limit from a scope apply;
- every §3.2 collision row throws `ConfigException`, including an inherited consumer static (`RCModel::chunk` shape) and
  `has_scope`; a non-static `scope_x` is ignored (not a scope, no error); a `#[Scope]` method that is public static,
  private static or non-static throws;
- `has_scope` on `Model` and `Query`, true/false;
- `\BadMethodCallException` for an unknown chained name; the static error unchanged;
- a scope inside `where(fn)`: wheres-only works and is grouped; one adding an order throws;
- `chunk`, `lazy`, `cursor` after a scope (if sub-project 1 has merged by then);
- `static::x()` from an instance method still throws "undefined method" (pins §3.1 item 9);
- the golden SQL corpus test stays green; `QueryForwardingTest` stays green.

### 7.3 Documentation and release

README section "Local scopes": both forms, arguments, the always-grouped rule, collisions, `has_scope`, the
instance-context note. The draft release gets the §6 items (three BREAKING, one added).

---

## 8. Delivery

One PR, on a branch cut from `master` after sub-project 1 merges. Plan: written with `writing-plans` after this spec is
approved.

---

## 9. Verifications to do in the plan

1. `#[Scope]` on a `protected static` method: `Post::published()` reaches `__callStatic` (verified for a protected
   static, §2), and the registry can invoke it (`ReflectionMethod::invoke`, accessible since PHP 8.1).
2. `Query::__call` does not change any existing path: `QueryForwardingTest` and the golden corpus stay green, and a
   PHPStan level-8 run accepts the magic method without a baseline entry (`@method` tags are not needed on `Query`,
   since scope names are per model).
3. The regrouped state passes `closure_wheres`-style checks and compiles identically on MySQL, Postgres and SQLite
   (bind order preserved).
4. The registry is built once per class: count reflection calls in a test.
