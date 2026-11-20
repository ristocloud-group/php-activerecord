# Global scopes and soft delete Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Global scopes (`#[GlobalScope]`) applied on every read, bulk write and relationship load of an opted-in model, and Eloquent-style soft delete (`SoftDeletable` + `SoftDeletes`) on top of them.

**Architecture:** A meta field `QueryState::$scopes`, set by `Query::__construct` and cleared by the few unscoped paths, tells `QueryCompiler` to AND one parenthesized group per global scope into the WHERE at its two sites (`compile_select()`, `apply_write_clauses()`), for the builder and the legacy options alike. `ScopeRegistry` keeps a cached, validated per-class index of the scope methods and runs them on fresh where-only queries. Soft delete is a global scope named `soft_deletes` plus write paths in `Model`/`Query`.

**Tech Stack:** PHP 8.3+ (CI 8.3–8.5), PHPUnit through `DatabaseTest`, PHPStan level 8, php-cs-fixer (PER-CS 3.0), MySQL/MariaDB/SQLite/Postgres in Docker.

**Spec:** `docs/superpowers/specs/2026-11-16-global-scopes-soft-delete-design.md` (commit d452d53). Section numbers below (§x.y) refer to it.

## Global Constraints

- Two stacked PRs (§10): **PR 1** = Tasks 1–8 on branch `feat/global-scopes`, cut from `spec/global-scopes` (it carries the spec and this plan); **PR 2** = Tasks 9–15 on `feat/soft-delete`, cut from `feat/global-scopes`.
- Every command runs in Docker: `docker compose exec tests …`. Another adapter: `docker compose exec -e PHPAR_CONNECTION=<sqlite|pgsql|mariadb> tests …` (the `-e` is required).
- Gates before each PR (§9.1): `composer run test` green on mysql, mariadb, sqlite and pgsql with nothing skipped; `composer run analyse` with **no new entry in `phpstan-baseline.neon`**; `composer run cs`; the suite on PHP 8.5 (`docker compose build --build-arg PHP_VERSION=8.5`).
- After each full-suite run: truncate `test/log/query.log` and purge the test MySQL binlogs (`PURGE BINARY LOGS BEFORE NOW()`); never delete named Docker volumes.
- Never run two suites at once, nor a suite in the background.
- Golden SQL (`test/golden/*.json`) of existing cases must stay byte-identical (§1.3 goal 4). New golden cases are additions only.
- Public API is snake_case; modern PHP (typed params/returns/properties/constants, `[]`, `?->`, `match`); one class/interface/trait per file in `lib/`.
- Backward compatibility: only the changes of §7 are allowed. Any other observable change found while implementing: stop and ask the maintainer.
- Commit messages: conventional (`feat:`, `test:`, `docs:`), **no attribution lines**.
- Error copy is fixed by this plan (table in Task 1 and the tasks that add errors); tests assert on these fragments with `assert_exception_message_contains()`.

## Review Focus

1. `find([1, 2])` on a scoped model where id 2 is hidden by a scope: expect `RecordNotFound` (the count no longer matches), as an unscoped miss does today. Test in Task 4.
2. A scope that names an `alias_attribute` alias (`where('alias', …)`): expect the aliased column in both the builder and the legacy wrap. Test in Task 4.
3. A relationship whose declared `conditions` contain a top-level `OR`, on a scoped target: expect `(<declared>) AND <key>` inside the user group and the scope groups outside it, lazy and eager equal. Test in Task 6.
4. `reload()` on an opted-in model with a composite primary key, whose row a scope hides: expect the row to reload (every scope removed). Test in Task 5.
5. `Model::last()` and `find('first', ['from' => 'authors'])` (a `'from'` without joins) on a scoped model: expect the scope applied, unqualified, and the reversed order kept. Test in Task 4.

---

## Part 1 — PR 1: engine and global scopes

### Task 1: `#[GlobalScope]` and the global-scope index

**Files:**
- Create: `lib/GlobalScope.php`
- Modify: `lib/ScopeRegistry.php`
- Create: `test/GlobalScopeRegistryTest.php`
- Create test models: `test/models/GlobalScopedAuthor.php`, `GlobalScopedAuthorChild.php`, `GlobalScopedTraitAuthor.php`, `HidesTitoScope.php` (trait), `GlobalScopePublic.php`, `GlobalScopeBothAttributes.php`, `GlobalScopeLikeLocal.php`, `GlobalScopeRequiredParam.php`, `GlobalScopeRedeclaredWithout.php`

**Interfaces:**
- Produces:
  - `#[\Attribute(\Attribute::TARGET_METHOD)] final class ActiveRecord\GlobalScope {}` (twin of `lib/Scope.php`, docblock with the §3.1 example).
  - `ScopeRegistry::global_scopes(string $model_class): array<string, \Closure(Query): mixed>`: the class's global scopes, name → invoker (`ReflectionMethod::getClosure(null)`), in §3.1 order; cached in a new `private static array $globals` separate from `$registries`; a build that throws is not cached. It does **not** construct the 3b registry (local validation stays lazy).
  - `ScopeRegistry::has_global_scopes(string $model_class): bool` ("opted-in model", §3).
- Test models:
  - `GlobalScopedAuthor` (table `authors`, `$pk = 'author_id'`): `#[GlobalScope] protected static function not_bush(Query $q): ?Query` → `where('author_id', '!=', 2)`.
  - `GlobalScopedAuthorChild extends GlobalScopedAuthor`: `#[GlobalScope] protected static function named(Query $q): ?Query` → `where_not_null('name')`, declared before a redeclared `#[GlobalScope] not_bush` (same body).
  - `HidesTitoScope` trait: `#[GlobalScope] protected static function not_tito(Query $q): ?Query` → `where('author_id', '!=', 1)`. `GlobalScopedTraitAuthor extends GlobalScopedAuthor` uses it and declares its own `#[GlobalScope] protected static function own(Query $q): ?Query` → `where('author_id', '<', 100)`.
  - Error models, one per error 1–5 (bodies return `$q`): `GlobalScopePublic` (public static), `GlobalScopeBothAttributes` (`#[Scope] #[GlobalScope]`), `GlobalScopeLikeLocal` (`#[GlobalScope] protected static function tito(...)` and `public static function scope_tito(...)`), `GlobalScopeRequiredParam` (`(Query $q, int $x)`), `GlobalScopeRedeclaredWithout extends GlobalScopedAuthor` (`protected static function not_bush(Query $q): ?Query` without the attribute).

Error copy (ConfigException, 3b's shape `"%s declares the scope %s(), which %s"`):

| # | reason text |
|---|---|
| 1 | `is marked #[GlobalScope] but is not a protected static method` |
| 2 | `is marked both #[Scope] and #[GlobalScope]` |
| 3 | `is also a local scope of the model` (and 3b's reasons: `is a public method of Query`, `starts like a dynamic finder`, `is update or delete, refused by a model without conditions`) |
| 4 | `takes a required parameter besides the query` |
| 5 | `redeclares a global scope without #[GlobalScope]` |

- [ ] **Step 1: Write the failing tests** in `GlobalScopeRegistryTest extends DatabaseTest`:

```php
public function test_index_order_root_first_redeclared_keeps_position(): void
{
    $this->assert_same(['not_bush', 'named'], array_keys(ScopeRegistry::global_scopes(GlobalScopedAuthorChild::class)));
    $this->assert_same(['not_bush', 'own', 'not_tito'], array_keys(ScopeRegistry::global_scopes(GlobalScopedTraitAuthor::class)));
}

public function test_model_without_global_scopes(): void
{
    $this->assert_same([], ScopeRegistry::global_scopes(Author::class));
    $this->assert_false(ScopeRegistry::has_global_scopes(Author::class));
    $this->assert_true(ScopeRegistry::has_global_scopes(GlobalScopedAuthor::class));
}

#[DataProvider('invalid_declarations')]
public function test_invalid_declaration_throws(string $class, string $reason): void
{
    $this->assert_exception_message_contains("declares the scope", fn() => ScopeRegistry::global_scopes($class), ConfigException::class);
    $this->assert_exception_message_contains($reason, fn() => ScopeRegistry::global_scopes($class), ConfigException::class);
}
// provider: the five error models with the reasons of the table above

public function test_global_index_does_not_validate_local_scopes(): void
{
    // CollidingQueryMethodScope (3b fixture) has an invalid local scope and no global one
    $this->assert_same([], ScopeRegistry::global_scopes(CollidingQueryMethodScope::class));
}
```

(Use whichever existing 3b `Colliding*Scope` fixture `ScopeRegistryTest::test_collisions_throw_config_exception` lists first.)

- [ ] **Step 2: Run** `docker compose exec tests vendor/bin/phpunit test/GlobalScopeRegistryTest.php` → FAIL (class `ActiveRecord\GlobalScope` / method `global_scopes` not found).

- [ ] **Step 3: Implement** `lib/GlobalScope.php` and the two static methods. Scan only methods whose declaring class is a subclass of `Model` (bound the scan below `Model`, §8 risk 3). Order algorithm: walk the parent chain from the class just below `Model` down to `$model_class`; for each class, take its own methods (declaring class = it, not provided by one of its traits) in `getMethods()` order, then each trait of `getTraits()` in `use` order and its attributed methods; a name already placed keeps its position. Local names for error 3 are read as 3b reads them (`#[Scope]` methods and public `scope_*`), without calling the 3b constructor.

- [ ] **Step 4: Run** the test file → PASS. Run `test/ScopeRegistryTest.php test/QueryScopeTest.php` → PASS.

- [ ] **Step 5: Commit** `feat: #[GlobalScope] attribute and the cached global-scope index`.

### Task 2: the `scopes` meta field, scoped by default

**Files:**
- Modify: `lib/QueryState.php`, `lib/Query.php` (`__construct`, `closure_wheres()`), `lib/QueryCompiler.php` (`held_fields()`), `lib/Table.php` (`options_to_sql()`, `update()`, `delete()`)
- Modify: `test/QueryEngineTest.php`

**Interfaces:**
- Produces:
  - In the `QueryState` docblock: `@phpstan-type ScopeState array{all: bool, removed: list<string>, trashed: 'default'|'without'|'with'|'only'}`.
  - `public ?array $scopes = null;` (`@var ScopeState|null`) on `QueryState`, after `readonly`.
  - `public const array META_FIELDS = ['scopes'];` and `public const array ACTIVE_SCOPES = ['all' => false, 'removed' => [], 'trashed' => 'default'];` on `QueryState`.
  - `Query::__construct` sets `$this->state->scopes = QueryState::ACTIVE_SCOPES`.
  - `closure_wheres()` sets the group query's `state->scopes = null` before running the callback.
  - `Table::options_to_sql()`, `Table::update()`, `Table::delete()` set `$query->state()->scopes = null` on the query they build.
  - `QueryCompiler::held_fields()` treats `QueryState::META_FIELDS` as always excepted. `holds_no_builder_clause()` is unchanged (its docblock names the meta-field exception).

- [ ] **Step 1: Update the invariant test** `test_held_fields_reports_every_field_of_query_state`: skip `QueryState::META_FIELDS` in the loop, and add

```php
public function test_meta_fields_are_never_held(): void
{
    $state = new ActiveRecord\QueryState();
    $state->scopes = ActiveRecord\QueryState::ACTIVE_SCOPES;
    $this->assert_same([], ActiveRecord\QueryCompiler::held_fields($state, []));
    $state->legacy = ['conditions' => 'author_id = 1'];
    $this->assert_same(['legacy'], ActiveRecord\QueryCompiler::held_fields($state, []));
}

public function test_queries_are_scoped_by_default_and_unscoped_paths_clear_it(): void
{
    $this->assert_same(ActiveRecord\QueryState::ACTIVE_SCOPES, Author::new_query()->state()->scopes);
    $this->assert_same(ActiveRecord\QueryState::ACTIVE_SCOPES, (new ActiveRecord\Query(Author::class))->state()->scopes);
    $this->assert_same(ActiveRecord\QueryState::ACTIVE_SCOPES, ActiveRecord\Query::from_options(Author::table(), [])->state()->scopes);
}
```

- [ ] **Step 2: Run** `vendor/bin/phpunit test/QueryEngineTest.php` → FAIL (no `scopes` property / constants).
- [ ] **Step 3: Implement** the field, constants, constructor default, the three `Table` opt-outs, the group opt-out and the `held_fields()` exception.
- [ ] **Step 4: Run** the full suite on mysql → PASS (nothing is applied yet: every existing test and `GoldenSqlTest` stays green).
- [ ] **Step 5: Commit** `feat: QueryState::$scopes meta field, scoped by default`.

### Task 3: applying global scopes on the builder path; removal methods

**Files:**
- Modify: `lib/ScopeRegistry.php`, `lib/QueryCompiler.php` (`compile_select()`, `compile_clauses()`, `where_clause()`, `apply_write_clauses()`), `lib/Query.php` (removal methods, `FORWARDABLE`), `lib/Model.php` (`@method` tags)
- Create: `test/GlobalScopeTest.php`; test models `GlobalScopeOrderBy.php`, `GlobalScopeReturnsOther.php`, `GlobalScopeReentrant.php`, `GlobalScopeCycleA.php`, `GlobalScopeCycleB.php`, `GlobalScopeInnerRemoval.php`

**Interfaces:**
- Consumes: `ScopeRegistry::global_scopes()` (Task 1), `QueryState::$scopes` (Task 2).
- Produces:
  - `ScopeRegistry::global_wheres(string $model_class, Table $table, array $scopes): list<WhereGroup>`: §4.4 steps 1–4 for a `ScopeState`. Each remaining scope runs on `new Query($model_class)` with its table set and `state->scopes = null`; after the call, `QueryCompiler::held_fields($q->state(), ['wheres', 'empty_where', 'skipped_when'])` must be `[]` (else error 9); the return must be `null` or `$q` (else 3b's error 10 text); non-empty wheres become one AND group (`self::group()`); `empty_where`/`skipped_when` are dropped with the query.
  - Re-entrancy (error 11): a `private static \WeakMap $running` keyed by `\Fiber::getCurrent() ?? self::$main` (a private static sentinel object), holding `"<class>::<scope>" => true` while a scope body runs; marked before the call, unmarked in `finally`.
  - `QueryCompiler::where_clause(QueryState $state, array $conditions = [], ?array $scopes = null)`: `$scopes` is the rendered `array{sql: string, values: list<mixed>}` of the scope groups (`"(s1) AND (s2)"`). Output: engine conditions (bare, as today) AND scope SQL AND the user WHERE, the user WHERE parenthesized unless `enclosed()`, whenever engine conditions or scopes precede it. No scopes → today's output byte for byte.
  - `Query` public methods, in `FORWARDABLE` and in `Model`'s `@method` tags: `without_global_scope(string $name): static`, `without_global_scopes(?array $names = null): static`. Semantics §4.2 (`removed`, `all`; last call wins). Throw error 13 on a `null` field, error 12 on an unknown name.

Error copy (`ActiveRecordException`):
- 9: `The global scope %s() of %s can only add where clauses: it also adds %s` (clause names via `CLAUSE_NAMES`).
- 11: `The global scope %s() of %s is applied again inside its own body: a query of %s run there must remove it with without_global_scope('%s')`.
- 12: `%s has no global scope named %s: its global scopes are %s` (or `: it has none`).
- 13: `%s() cannot be used on a where() group or on a global scope's query: they carry no scopes`.

- [ ] **Step 1: Write the failing tests** in `GlobalScopeTest extends DatabaseTest` (ids from `test/fixtures/authors.csv`: 1 Tito, 2 George W. Bush, 3 Bill Clinton, 4 Uncle Bob):

```php
public function test_builder_reads_are_scoped(): void
{
    $this->assert_equals([1, 3, 4], $this->ids(GlobalScopedAuthor::order_by('author_id')->get()));
    $this->assert_same(3, GlobalScopedAuthor::new_query()->count());
    $this->assert_false(GlobalScopedAuthor::where('author_id', 2)->exists());
    $this->assert_null(GlobalScopedAuthor::new_query()->find(2));
    $this->assert_equals([1, 3, 4], GlobalScopedAuthor::order_by('author_id')->pluck('author_id'));
}

public function test_user_or_is_grouped_after_the_scope(): void
{
    $query = GlobalScopedAuthor::where('author_id', 1)->or_where('author_id', 2);
    $this->assert_equals([1], $this->ids($query->get()));
    $this->assert_matches_regular_expression('/WHERE \(.*author_id.* != \?\) AND \(.*author_id.* = \? OR .*author_id.* = \?\)$/', $query->to_sql());
}

public function test_find_key_comes_first_and_bare(): void
{
    // engine condition, then the scope group, then the user group
    $this->assert_matches_regular_expression('/WHERE .*author_id.* = \? AND \(.*author_id.* != \?\) AND \(.*name.* = \?\)/', /* SQL captured from */ fn() => GlobalScopedAuthor::where('name', 'Tito')->find(1));
}

public function test_removal_by_name_all_and_last_call_wins(): void
{
    $this->assert_same(4, GlobalScopedAuthor::without_global_scope('not_bush')->count());
    $this->assert_same(4, GlobalScopedAuthorChild::without_global_scopes()->count());
    $this->assert_same(3, GlobalScopedAuthorChild::without_global_scopes(['named'])->count());
}

public function test_builder_writes_are_scoped(): void
{
    $this->assert_same(3, GlobalScopedAuthor::new_query()->update(['name' => 'x']));
    $this->assert_same('George W. Bush', Author::find(2)->name);
}

public function test_errors(): void
{
    $this->assert_exception_message_contains('has no global scope named nope', fn() => GlobalScopedAuthor::without_global_scope('nope'), ActiveRecordException::class);
    $this->assert_exception_message_contains("cannot be used on a where() group", fn() => GlobalScopedAuthor::where(fn($q) => $q->without_global_scope('not_bush'))->get(), ActiveRecordException::class);
    $this->assert_exception_message_contains('can only add where clauses: it also adds order_by', fn() => GlobalScopeOrderBy::new_query()->get(), ActiveRecordException::class);
    $this->assert_exception_message_contains('must return null or the query it was given', fn() => GlobalScopeReturnsOther::new_query()->get(), ActiveRecordException::class);
    $this->assert_exception_message_contains('is applied again inside its own body', fn() => GlobalScopeReentrant::new_query()->get(), ActiveRecordException::class);
    $this->assert_exception_message_contains('is applied again inside its own body', fn() => GlobalScopeCycleA::new_query()->get(), ActiveRecordException::class);
}

public function test_inner_query_that_removes_the_scope_is_allowed(): void
{
    $this->assert_same(4, GlobalScopeInnerRemoval::new_query()->count());
}

public function test_when_false_in_a_scope_adds_no_group_and_does_not_block_writes(): void { /* GlobalScopeSkippedWhen: when(false, …) → no WHERE; ->update([...]) runs */ }

public function test_aggregate_subquery_has_the_scope_once(): void { /* to_sql of ->limit(2)->count()-style subquery: substr_count('!=', sql) === 1 */ }

public function test_model_without_scopes_sql_unchanged(): void
{
    $this->assert_same(Author::where('author_id', 1)->to_sql(), (new ActiveRecord\Query(Author::class))->where('author_id', 1)->to_sql());
}
```

Models: `GlobalScopeOrderBy` (scope `->order_by('name')`), `GlobalScopeReturnsOther` (returns `new Query(static::class)`), `GlobalScopeReentrant` (scope body runs `static::new_query()->count()`), `GlobalScopeCycleA`/`B` (each scope counts the other model), `GlobalScopeInnerRemoval` (scope body runs `static::without_global_scope('x')->count()`), `GlobalScopeSkippedWhen` (scope `->when(false, fn($q) => $q->where('author_id', 1))`). All on `authors`, `$pk = 'author_id'`. Capture SQL for `find()` with the existing `CapturesStatements` helper.

- [ ] **Step 2: Run** `vendor/bin/phpunit test/GlobalScopeTest.php` → FAIL.
- [ ] **Step 3: Implement** `global_wheres()`, the `where_clause()` parameter, the calls in `compile_clauses()` (render the groups with `$this->conditions($state)`, so builder joins/`from()`/`from_raw()` qualify them as §3.6 says) and in the builder branch of `apply_write_clauses()`, the two `Query` methods, `FORWARDABLE` and the `@method` tags.
- [ ] **Step 4: Run** the test file, `test/QueryForwardingTest.php`, `test/GoldenSqlTest.php` → PASS.
- [ ] **Step 5: Commit** `feat: apply global scopes on the builder path; without_global_scope(s)`.

### Task 4: legacy path wrap and qualification

**Files:**
- Modify: `lib/QueryCompiler.php` (`compile_select()` legacy branch, `compile_legacy()`, `apply_write_clauses()` legacy branch)
- Modify: `test/GlobalScopeTest.php`, `test/GoldenSqlTest.php`, `test/golden/{mysql,mariadb,sqlite,pgsql}.json`; test model `GlobalScopedAliasAuthor.php`

**Interfaces:**
- Consumes: `ScopeRegistry::global_wheres()` (Task 3).
- Produces: `private function wrap_legacy_where(SQLBuilder $sql, array $options, QueryState $state): void`, called after `apply_conditions()` in both legacy sites when `$state->scopes !== null`. It renders the scope groups with a context `QueryState` whose `joins` is non-empty iff `$options` has `'joins'` and no `'from'` (so `column_reference()` qualifies with the table name, §4.4 step 5), reads the user WHERE back with `get_where()`/`get_where_values()`, passes its SQL through `self::raw_fragment()`, and writes `(s1) AND (s2) AND (<user>)` with `where_condition()`. Empty/falsy user WHERE → scopes only (D6). No scope group → nothing changes.

- [ ] **Step 1: Write the failing tests** (add to `GlobalScopeTest`):

```php
public function test_legacy_finders_are_scoped(): void
{
    $this->assert_null(GlobalScopedAuthor::find('first', ['conditions' => ['author_id = ?', 2]]));
    $this->assert_same(3, GlobalScopedAuthor::count());
    $this->assert_false(GlobalScopedAuthor::exists(2));
    $this->assert_null(GlobalScopedAuthor::find_by_name('George W. Bush'));
    $this->assert_same(3, count(GlobalScopedAuthor::all()));
    $this->assert_same(4, GlobalScopedAuthor::last()->author_id);
    $this->assert_same(1, count(GlobalScopedAuthor::all(['conditions' => 'author_id = 1 OR author_id = 2'])));
}

public function test_find_many_with_a_hidden_id_throws(): void   // Review Focus 1
{
    $this->expect_exception(RecordNotFound::class);
    GlobalScopedAuthor::find([1, 2]);
}

public function test_legacy_bulk_writes_are_scoped(): void
{
    $this->assert_same(3, GlobalScopedAuthor::update_all(['set' => ['name' => 'x']]));
    $this->assert_same(3, GlobalScopedAuthor::delete_all());
    $this->assert_same(1, Author::count());
}

public function test_legacy_joins_qualify_the_scope_and_from_does_not(): void
{
    // authors joined with books: author_id exists in both tables
    $this->assert_sql_has('WHERE (authors.author_id != ?)', /* SQL of */ GlobalScopedAuthor::table()->options_to_sql(…)); // via a captured find('all', ['joins' => ['books']])
    // 'from' without joins: unqualified and working (Review Focus 5)
    $this->assert_same(3, count(GlobalScopedAuthor::find('all', ['from' => 'authors'])));
}

public function test_line_comment_in_user_where_is_closed(): void
{
    $this->assert_same(1, count(GlobalScopedAuthor::all(['conditions' => "author_id = 1 -- note"])));
}

public function test_scope_on_an_alias_attribute(): void   // Review Focus 2
{
    // GlobalScopedAliasAuthor: $alias_attribute = ['handle' => 'name']; scope where('handle', '!=', 'Tito')
    $this->assert_same(3, GlobalScopedAliasAuthor::count());
    $this->assert_same(3, GlobalScopedAliasAuthor::new_query()->count());
}
```

Golden cases (Global Constraints: additions only), added to `GoldenSqlTest::corpus()` with `null` connections and `GlobalScopedAuthor` added to `MODELS` if the runner needs it: `scoped_find_pk` (`GlobalScopedAuthor::find(1)`), `scoped_legacy_or` (`all(['conditions' => 'author_id = 1 OR author_id = 2'])`), `scoped_builder_find_with_where` (`where('name', 'Tito')->find(1)`), `scoped_joins` (`find('all', ['joins' => ['books']])`), `scoped_update_all`, `scoped_delete_all`, `scoped_builder_update`, `scoped_keyset_page` (`GlobalScopedAuthor::where('author_id', '>', 0)->or_where('name', 'x')->chunk_by_id(2, fn() => false)`).

- [ ] **Step 2: Run** `vendor/bin/phpunit test/GlobalScopeTest.php` → FAIL.
- [ ] **Step 3: Implement** `wrap_legacy_where()` and the two calls.
- [ ] **Step 4: Run** the test file → PASS. Record the golden cases on each connection: `docker compose exec -e PHPAR_CONNECTION=<conn> -e PHPAR_GOLDEN_RECORD=1 tests vendor/bin/phpunit test/GoldenSqlTest.php --filter scoped_`; read each recorded string and check it against §4.4 step 6 (builder: `<pk> AND (scope) AND (user)`; legacy: `(scope) AND (user)`; keyset: `(scope) AND ((<user>) AND key > ?)`); `git diff --stat test/golden` must show additions only. Re-run `GoldenSqlTest` without recording on each connection → PASS.
- [ ] **Step 5: Commit** `feat: apply global scopes on the legacy path`.

### Task 5: removal by the library: `Table` helpers, `reload()`, uniqueness

**Files:**
- Modify: `lib/Table.php`, `lib/Model.php` (`reload()`), `lib/Validations.php` (`validates_uniqueness_of()`)
- Modify: `test/GlobalScopeTest.php`; test models `GlobalScopedUniqueAuthor.php`, `GlobalScopedCompositeItem.php` (on an existing composite-pk table, e.g. the one `PrimaryKeyWriteGuardTest` uses)

**Interfaces:**
- Consumes: `Query::without_global_scopes()` (Task 3), `ScopeRegistry::has_global_scopes()` (Task 1).
- Produces (`@internal`, §4.5):
  - `Table::find_without_global_scopes(array $options, ?array $names = null): array` — `Query::from_options($this, $options)->without_global_scopes($names)->get()`.
  - `Table::exists_without_global_scopes(array $options, ?array $names = null): bool` — same with `->exists()`.
  - `reload()`: on an opted-in class, both key shapes use the conditions-hash path with `find_without_global_scopes(['conditions' => $conditions, 'limit' => 1, 'offset' => 0, 'mapped_names' => []])` and today's `RecordNotFound` message; other classes unchanged.
  - Uniqueness: on an opted-in model, `static::table()->exists_without_global_scopes(['conditions' => $conditions, 'mapped_names' => [], 'select' => '1'])` instead of `$this->model->exists(...)`; other models unchanged.

- [ ] **Step 1: Write the failing tests**:

```php
public function test_reload_drops_every_scope(): void
{
    $scoped = GlobalScopedAuthor::without_global_scopes()->find(2);
    $this->assert_same('George W. Bush', $scoped->reload()->name);
}

public function test_reload_composite_key_drops_every_scope(): void { /* Review Focus 4 on GlobalScopedCompositeItem */ }

public function test_uniqueness_counts_rows_hidden_by_scopes(): void
{
    // GlobalScopedUniqueAuthor: validates_uniqueness_of name; scope hides author 2
    $author = new GlobalScopedUniqueAuthor(['name' => 'George W. Bush']);
    $this->assert_false($author->is_valid());
}

public function test_reload_and_uniqueness_of_unscoped_models_still_use_the_public_methods(): void
{
    // OverridingAuthor-style subclass overriding find(); reload() on a plain model still calls it
}
```

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** `test/GlobalScopeTest.php test/ValidationsTest.php test/ActiveRecordTest.php` → PASS.
- [ ] **Step 5: Commit** `feat: reload() and uniqueness drop every global scope on opted-in models`.

### Task 6: relationships: `'unscoped'`

**Files:**
- Modify: `lib/relationships/AbstractRelationship.php` (phpstan shapes, constructor check, a load helper, eager load call, `merge_association_options()` comment), `BelongsTo.php`, `HasMany.php`, `HasOne.php`
- Create: `test/GlobalScopeRelationshipTest.php`; test models `GlobalScopedBook.php`, `GlobalScopedBookAuthorOr.php`

**Interfaces:**
- Consumes: `Table::find_without_global_scopes()` (Task 5).
- Produces:
  - Option lists (§3.5, maintainer rule): `BelongsTo::$valid_association_options = ['unscoped']`; `HasMany` adds `'unscoped'`; `HasOne::$valid_association_options = ['primary_key', 'order', 'group', 'having', 'limit', 'offset', 'through', 'source', 'unscoped']` (today's `HasMany` list + `unscoped`). `AbstractRelationship`'s base list is unchanged. Both phpstan shapes get `unscoped?: true|list<string>`.
  - Constructor: a value that is neither `true` nor a list of strings → `RelationshipException("The option 'unscoped' of relationship '%s' must be true or a list of scope names")` (error 18).
  - `protected function find_related(string $type, array $options): Model|array|null` on `AbstractRelationship` (`$type` is `'all'` or `'first'`): without `'unscoped'` → `$class::find($type, $options)` (today's call); with it → the target table's `find_without_global_scopes()` with the options `Model::find()` would build (`mapped_names` = target's `$alias_attribute`; for `'first'`: `limit` 1 unless 0, `offset` 0) and the names (`null` for `true`). Used by `BelongsTo::load()`, `HasMany::load()` and the eager `query_and_attach_related_models_eagerly()`. Unknown names surface as error 12 at the first load.
- Test models: `GlobalScopedBook` (table `books`, `$pk = 'book_id'`), `$belongs_to = [['author', 'class_name' => 'GlobalScopedAuthor', 'foreign_key' => 'author_id'], ['any_author', 'class_name' => 'GlobalScopedAuthor', 'foreign_key' => 'author_id', 'unscoped' => true]]`; `GlobalScopedAuthor` gains `$has_many = [['books', 'class_name' => 'GlobalScopedBook', 'foreign_key' => 'author_id']]`; `GlobalScopedBookAuthorOr` declares a `has_many` with `'conditions' => 'name = \'x\' OR name = \'y\''` (Review Focus 3).

- [ ] **Step 1: Write the failing tests**:

```php
public function test_belongs_to_target_scope_lazy_and_eager(): void
{
    $book = GlobalScopedBook::find(2);              // author_id 2 is hidden
    $this->assert_null($book->author);
    $this->assert_same(2, $book->any_author->author_id);
    $eager = GlobalScopedBook::find(2, ['include' => ['author', 'any_author']]);
    $this->assert_null($eager->author);
    $this->assert_same(2, $eager->any_author->author_id);
}

public function test_unscoped_option_validation_and_unknown_name(): void
{
    $this->assert_exception_message_contains("must be true or a list of scope names", fn() => new ActiveRecord\BelongsTo(['author', 'unscoped' => 'x']), RelationshipException::class);
    // a GlobalScopedBook relationship with 'unscoped' => ['nope'] → error 12 at first load
}

public function test_unscoped_accepted_by_belongs_to_has_many_has_one(): void { /* construct each with 'unscoped' => true */ }

public function test_unscoped_load_skips_find_override_and_plain_load_calls_it(): void { /* target class overriding find() records calls */ }

public function test_nested_same_class_include_inside_unscoped_load_keeps_its_scopes(): void { /* any_author with include of a has_many on GlobalScopedAuthor */ }

public function test_declared_or_conditions_with_target_scope(): void { /* Review Focus 3: lazy == eager, SQL shape (scope) AND ((<declared>) AND <key>) */ }
```

Add `GlobalScopedAuthor`/`GlobalScopedBook` cases to `RelationshipEagerLazyParityTest` with `assert_parity()` (lazy == eager with a target scope).

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** `test/GlobalScopeRelationshipTest.php test/RelationshipTest.php test/RelationshipEagerLazyParityTest.php test/HasManyThroughTest.php` → PASS.
- [ ] **Step 5: Commit** `feat: 'unscoped' relationship option`.

### Task 7: guards, iteration, benchmark

**Files:**
- Create: `test/GlobalScopeGuardTest.php`
- Modify: `test/benchmarks/hot_paths.php`

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Write the guard tests** (§9.3 items 2, 7):
  - every public method of `Query` named in `FORWARDABLE` that returns rows, values, a count or a boolean, plus `find`, run on `GlobalScopedAuthor` with statements captured → each SELECT contains the scope predicate; derive the list by reflection and keep an explicit skip list (methods that need arguments get fixed sample arguments in a table);
  - the scoped legacy entries (`find` forms, `first`, `last`, `all`, `find_by_*`, `find_all_by_*`, `count_by_*`, `count`, `exists`, `update_all`, `delete_all`, `Table::find()`, `Table::exists()`) → predicate present;
  - unscoped paths (`save()` insert and update, instance `delete()`, `Table::update()`, `Table::delete()`, `insert`, `upsert`, `options_to_sql()`) → predicate absent;
  - a `new_query()` override (anonymous subclass) and `new Query(GlobalScopedAuthor::class)` → present;
  - iteration: `chunk`, `lazy`, `chunk_by_id` (including the `MAX` probe statement), `lazy_by_id`, `cursor`, keyset paging with an `or_where` → present in every statement.
- [ ] **Step 2: Run** → the test must PASS against Tasks 1–6; any failure is a missed entry: fix it in the owning code, not in the test.
- [ ] **Step 3: Benchmark** (§8 risk 3). Add to `test/benchmarks/hot_paths.php`: legacy `find(1)` and builder `where()->get()` on `Author` (not opted in), on `GlobalScopedAuthorChild` (two scopes), and a cold first compile per class (fresh `Table::clear_cache()` + a class never compiled). Run the perf185 harness interleaved against `master` (`.superpowers/perf185/bin/pairs.sh mysql 10 <out.csv> master190 <this tree>`, pcov off, OPcache on and off, CPU time). Acceptance thresholds: not-opted-in warm paths ≤ +3% CPU median vs master; two scopes ≤ +25% vs the same query on `Author`; cold first compile ≤ +1 ms CPU per class. A miss: stop and report the numbers to the maintainer.
- [ ] **Step 4: Commit** `test: guards for every scoped and unscoped entry; benchmark cases`.

### Task 8: documentation and PR 1

**Files:**
- Modify: `README.md`

- [ ] **Step 1:** README section "Global scopes" (§9.4, PR 1 part): declaring, removal, `'unscoped'`, where scopes apply and do not (§3.6), the qualification limit and raw SQL in scopes, "a scope is not a security boundary", uniqueness and tenants (§3.8), deviations 3, 5–8 of §3.9. Modern PHP examples. PHPDoc on every new public method.
- [ ] **Step 2:** Gates of Global Constraints (four adapters, PHPStan, cs, PHP 8.5).
- [ ] **Step 3:** Commit `docs: global scopes in the README`; push `feat/global-scopes`; open PR 1 ("feat!: global scopes (#[GlobalScope]) …"), body listing §7 rows 1, 2, 5, 8, 9 and linking the spec. Release notes go into the 3.2.0 draft only when it merges.

---

## Part 2 — PR 2: soft delete

### Task 9: schema, fixtures, test models

**Files:**
- Modify: `test/sql/mysql.sql`, `sqlite.sql`, `pgsql.sql` (append after `measurements`), `test/sql/pgsql-after-fixtures.sql`
- Create: `test/fixtures/trash_posts.csv`, `trash_comments.csv`, `trash_tags.csv`, `trash_taggings.csv`, `trash_votes.csv`, `trash_dated.csv`, `trash_wrongs.csv`
- Create test models: `TrashPost.php`, `TrashComment.php`, `TrashTag.php`, `TrashTagging.php`, `TrashVote.php`, `TrashDated.php`, `TrashWrong.php`, `TrashTraitOnly.php`, `TrashInterfaceOnly.php`

Tables (id = adapter's auto pk idiom; `deleted_at` DATETIME NULL unless stated):
- `trash_posts(id, code VARCHAR(10) NOT NULL UNIQUE, title VARCHAR(50), draft INT NOT NULL DEFAULT 0, deleted_at, updated_at DATETIME NULL)`; rows: 1 `A` live, 2 `B` trashed, 3 `C` live draft, 4 `D` live.
- `trash_comments(id, trash_post_id INT, body VARCHAR(50), deleted_at)`; rows: 1 post 1 live, 2 post 1 trashed, 3 post 2 live.
- `trash_tags(id, name VARCHAR(20), deleted_at)`; rows: 1 live, 2 trashed, 3 live.
- `trash_taggings(id, trash_post_id INT, trash_tag_id INT, deleted_at)`; rows: post 1 → tag 1 live, post 1 → tag 2 live, post 1 → tag 3 trashed link.
- `trash_votes(id, trash_comment_id INT, value INT, deleted_at)`; rows: comment 1 live, comment 2 live (its middle comment is trashed), comment 1 trashed.
- `trash_dated(id, name VARCHAR(20), removed_on DATE NULL)`; `trash_wrongs(id, name VARCHAR(20), deleted_at VARCHAR(20) NULL)`.
- Models: `TrashPost implements SoftDeletable` + `use SoftDeletes`, `#[GlobalScope] protected static function published(Query $q): ?Query` → `where('draft', 0)`, `$has_many = ['trash_comments', 'trash_taggings', ['trash_tags', 'through' => 'trash_taggings'], ['trash_votes', 'through' => 'trash_comments']]`, `$validates_uniqueness_of = [['code']]`; `TrashComment`/`TrashTag`/`TrashVote`/`TrashTagging` soft-delete with the matching `belongs_to`/`has_many`; `TrashComment` also `['any_post', 'class_name' => 'TrashPost', 'foreign_key' => 'trash_post_id', 'unscoped' => [SoftDeletable::SCOPE]]`; `TrashDated` overrides `soft_delete_column()` → `'removed_on'`; `TrashWrong` soft-delete on the VARCHAR column; `TrashTraitOnly` uses the trait only, `TrashInterfaceOnly` implements the interface only (both on `trash_dated`).

- [ ] **Step 1:** Write the DDL in the three dialects, the CSVs (every new table has one: the loader drops only tables with a CSV), and the `setval` lines for Postgres.
- [ ] **Step 2: Run** the full suite on each adapter → PASS (models unused yet; the trait/interface do not exist yet, so create the models in Task 10, Step 1).
- [ ] **Step 3: Commit** `test: soft-delete schema and fixtures`.

### Task 10: `SoftDeletable`, `SoftDeletes`, the `soft_deletes` scope and the trashed methods

**Files:**
- Create: `lib/SoftDeletable.php`, `lib/SoftDeletes.php`
- Modify: `lib/ScopeRegistry.php`, `lib/Query.php`, `lib/Model.php` (`@method` tags), `test/LibraryLoadingTest.php`
- Create: `test/SoftDeleteReadTest.php`

**Interfaces:**
- Produces:
  - `interface SoftDeletable { public const string SCOPE = 'soft_deletes'; public static function soft_delete_column(): string; }`
  - `trait SoftDeletes`: `public static function soft_delete_column(): string` (`'deleted_at'`); `#[GlobalScope] protected static function soft_deletes(Query $q): ?Query` → `$q->where_null(static::soft_delete_column())`; `public function trashed()` (`null !== $this->{static::soft_delete_column()}`; an unloaded column throws today's `UndefinedPropertyException`); the instance verbs of Task 12; `public static function force_delete_all($options = []): int` (Task 11).
  - `ScopeRegistry::soft_delete_column(Table $table): string`: validated once per class (errors 7, 8) and cached; called lazily (compile, instance verbs, `*_trashed()`).
  - `ScopeRegistry::global_scopes()` gains error 6 (both looked up through the parents with `class_implements()`/`class_uses()` over the chain).
  - `global_wheres()`: `soft_deletes` runs when `trashed` is `default`/`without`, is skipped for `with`, and is replaced in its own position by a group `where_not_null(<column>)` for `only` (§4.4 steps 2, 4).
  - `Query` public methods in `FORWARDABLE` and `@method` tags: `with_trashed(): static`, `only_trashed(): static`, `without_trashed(): static` (set `trashed`; error 13 on a `null` field; error 14 on a non-`SoftDeletable` model). `without_global_scope('soft_deletes')` and a list naming it set `with` (§4.2).
  - `LibraryLoadingTest`: add `trait_exists($class)` to the autoload check.

Error copy: 6 `%s uses SoftDeletes but does not implement SoftDeletable` / `%s implements SoftDeletable but does not use SoftDeletes` (ConfigException); 7 `%s::soft_delete_column() names %s, which is not a column of %s`; 8 `%s::soft_delete_column() names %s, a %s column: soft delete needs a DATE or DATETIME column` (ConfigException); 14 `%s() needs a model with soft delete: %s is not SoftDeletable` (ActiveRecordException).

- [ ] **Step 1: Write the failing tests** in `SoftDeleteReadTest`:

```php
public function test_reads_hide_trashed_rows_everywhere(): void
{
    $this->assert_equals([1, 4], $this->ids(TrashPost::order_by('id')->get()));          // 2 trashed, 3 draft
    $this->assert_same(2, TrashPost::count());
    $this->assert_null(TrashPost::find('first', ['conditions' => ['code = ?', 'B']]));
}

public function test_with_only_without_trashed_and_last_call_wins(): void
{
    $this->assert_equals([1, 2, 4], $this->ids(TrashPost::with_trashed()->order_by('id')->get()));
    $this->assert_equals([2], $this->ids(TrashPost::only_trashed()->get()));
    $this->assert_equals([1, 4], $this->ids(TrashPost::without_global_scopes()->without_trashed()->where('draft', 0)->order_by('id')->get()));
    $this->assert_equals([1, 2, 4], $this->ids(TrashPost::without_global_scope(SoftDeletable::SCOPE)->order_by('id')->get()));
    $this->assert_sql_doesnt_has('IS NULL', TrashPost::only_trashed()->to_sql());   // never both (Rails trap)
}

public function test_trashed(): void { /* find with_trashed()->find(2)->trashed() === true; find(1)->trashed() === false; select('id') → UndefinedPropertyException */ }
public function test_date_column(): void { /* TrashDated scope uses removed_on */ }
public function test_errors_6_7_8_14(): void { /* TrashTraitOnly, TrashInterfaceOnly, a TrashDated subclass returning 'nope', TrashWrong, Author::with_trashed() */ }
public function test_trait_autoloads(): void { /* covered by LibraryLoadingTest */ }
```

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** `test/SoftDeleteReadTest.php test/LibraryLoadingTest.php test/QueryForwardingTest.php test/GlobalScopeTest.php` → PASS.
- [ ] **Step 5: Commit** `feat: SoftDeletable/SoftDeletes, the soft_deletes scope and with/only/without_trashed`.

### Task 11: bulk soft delete, force delete, the `$now` and method-name paths

**Files:**
- Modify: `lib/Query.php` (`delete()`, new `force_delete()`, `execute_update()`, `update()`/`bindable_values()` caller name), `lib/QueryCompiler.php` (`compile_update()`), `lib/Model.php` (`delete_all()`, `__callStatic()` refusal), `lib/SoftDeletes.php` (`force_delete_all()`)
- Create: `test/SoftDeleteWriteTest.php`

**Interfaces:**
- Produces:
  - `Query::execute_update(array|string $set, bool $timestamps = true, bool $format_dates = true, ?Connection $connection = null, bool $refuse_terminator = true, ?string $now = null, string $method = 'update'): \PDOStatement` — `$now` goes to `Timestamps::apply_to_update(..., $now)`, `$method` to `compile_update()`.
  - `QueryCompiler::compile_update(QueryState $state, array|string $set, string $method = 'update'): SQLBuilder` — `$method` replaces the hard-coded `'update'` in `refuse_unwritable_clauses()`, `refuse_empty_where()` and `apply_write_clauses()`.
  - `Query::delete(): int` and `Model::delete_all($options = [])` on a `SoftDeletable` model: `$now = Timestamps::now()`, `execute_update([<column> => new DateTime($now)], now: $now, method: 'delete', connection: …)`.
  - `Query::force_delete(): int` (not in `FORWARDABLE`): on a `SoftDeletable` model, maps `trashed` `default` → `with` on the query's state and runs today's delete; otherwise `delete()`.
  - `SoftDeletes::force_delete_all($options = []): int`: `delete_all()`'s options and checks as a real DELETE with `trashed` = `with` (through a `final protected static function perform_force_delete_all(array $options): int` on `Model`).
  - `Model::__callStatic()`: `force_delete` joins `update`/`delete` in the static refusal (error 16, same message).

- [ ] **Step 1: Write the failing tests**:

```php
public function test_bulk_delete_is_soft_with_one_now(): void
{
    Timestamps::freeze('2026-11-18 10:00:00');
    $this->assert_same(1, TrashPost::where('code', 'A')->delete());
    $row = TrashPost::with_trashed()->find(1);
    $this->assert_equals('2026-11-18 10:00:00', $row->deleted_at->format('Y-m-d H:i:s'));
    $this->assert_equals($row->deleted_at, $row->updated_at);
    $this->assert_same(1, TrashPost::delete_all(['conditions' => ['code = ?', 'D']]));
    $this->assert_same(0, TrashPost::count());
}

public function test_one_now_with_a_real_clock(): void { /* no freeze: deleted_at == updated_at */ }
public function test_with_trashed_delete_restamps(): void { /* with_trashed()->delete() updates row 2's deleted_at */ }

public function test_force_delete_respects_only_and_without_trashed_and_other_scopes(): void
{
    $this->assert_same(1, TrashPost::only_trashed()->force_delete());                        // row 2 only
    $this->assert_same(4 - 1, TrashPost::without_global_scopes()->with_trashed()->count() ); // 3 left
    $this->assert_same(2, TrashPost::without_trashed()->force_delete());                      // rows 1, 4 (draft 3 kept by published)
    $this->assert_same(1, TrashPost::without_global_scope('published')->force_delete());     // row 3
}

public function test_force_delete_all_and_static_refusal(): void { /* force_delete_all(['conditions' => …]) hard-deletes trashed + live, keeps drafts; Author::force_delete() → "would write every row" */ }
public function test_refusal_names_delete(): void { /* TrashPost::new_query()->group_by('id')->delete() message contains "delete() cannot use group_by" */ }
public function test_limit_on_postgres(): void { /* where(...)->order_by('id')->limit(1)->delete() soft on every adapter */ }
```

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** `test/SoftDeleteWriteTest.php test/QueryWriteTest.php test/BulkWriteOptionsTest.php test/TimestampsTest.php` → PASS on mysql and pgsql.
- [ ] **Step 5: Commit** `feat: soft bulk delete, force_delete and force_delete_all`.

### Task 12: instance verbs and callbacks

**Files:**
- Modify: `lib/Model.php`, `lib/SoftDeletes.php`, `lib/CallBack.php`
- Create: `test/SoftDeleteInstanceTest.php`; test model `TrashPostWithCallbacks.php` (records callbacks; `before_restore` returns `false` when a flag is set), `TrashPostPrivateCallback.php` (a `protected function before_restore()`)

**Interfaces:**
- Produces:
  - `CallBack::$VALID_CALLBACKS` gains `before_restore`, `after_restore`.
  - `Model::delete()`: after today's checks and `before_destroy`, if `$this instanceof SoftDeletable` and the force flag is off: `private function soft_delete_row(): void` (`$now`, `Table::update()`-style by-PK UPDATE of `<column>` and, through `apply_to_update($table, static::class, $set, $now)`, `updated_at`; then `private function write_clean(array $attributes): void` assigns each value with `assign_attribute()` and removes only those names from `__dirty`); not marked destroyed; `after_destroy`; `true`.
  - `final protected function perform_restore_on_model(): bool` — reads `ScopeRegistry::global_scopes(static::class)` first (error 6 before 15); throws error 15 without soft delete; readonly/destroyed checks; `values_for_pk()` empty → error 17; `verify_pk_for_write('restore')`; `before_restore` (`false` → `false`); UPDATE `<column> = NULL` + `updated_at`; `write_clean()`; `after_restore`; `true`.
  - `final protected function perform_force_delete_on_model(): bool` — sets `private bool $__force_deleting`, calls `$this->delete()`, clears it in `finally`.
  - `final protected function force_deleting_flag(): bool` — read by `SoftDeletes::is_force_deleting(): bool`.
  - `SoftDeletes`: `public function restore()` → `perform_restore_on_model()`; `public function force_delete()` → `perform_force_delete_on_model()`; `public function is_force_deleting(): bool`.
  - `verify_pk_for_write()`/`verify_not_destroyed()` `@param` widened to `'update'|'delete'|'restore'`.

Error copy: 15 `Cannot restore, %s has no soft delete` ; 17 `Cannot restore, no primary key defined for: %s` (ActiveRecordException).

- [ ] **Step 1: Write the failing tests**:

```php
public function test_instance_delete_is_soft_and_object_stays_writable(): void
{
    $post = TrashPost::find(1);
    $post->title = 'pending';
    $this->assert_true($post->delete());
    $this->assert_true($post->trashed());
    $this->assert_instance_of(ActiveRecord\DateTime::class, $post->deleted_at);
    $this->assert_same(['title'], array_keys($post->dirty_attributes()));   // other pending change untouched, column clean
    $this->assert_null(TrashPost::find('first', ['conditions' => ['id = ?', 1]]));
    $this->assert_true($post->delete());   // re-stamps, never hard
    $this->assert_same(1, TrashPost::with_trashed()->where('id', 1)->count());
}

public function test_restore_and_callbacks_order_and_cancel(): void { /* before_restore/after_restore order; false cancels; restored row visible; deleted_at null and clean */ }
public function test_force_delete_and_flag(): void { /* is_force_deleting() true inside after_destroy; false after; a throwing before_destroy leaves a later delete() soft */ }
public function test_restore_without_primary_key_throws(): void { /* a SoftDeletable on pkless_items: restore() and delete() throw "no primary key defined" */ }
public function test_restore_without_soft_delete_and_error_order(): void { /* TrashTraitOnly->restore() → error 6, not 15 */ }
public function test_non_public_before_restore_breaks_table_load(): void { /* TrashPostPrivateCallback::find(1) → "Callback methods need to be public" */ }
public function test_without_timestamps_and_table_without_updated_at(): void { /* TrashPost::without_timestamps(fn() => $post->delete()): updated_at unchanged; TrashComment has no updated_at */ }
public function test_transaction_rollback_leaves_in_memory_state(): void { /* §5.6: after rollback the object still says trashed(); reload() fixes it */ }
public function test_uniqueness_counts_trashed_rows(): void { /* new TrashPost(['code' => 'B']) invalid */ }
```

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** `test/SoftDeleteInstanceTest.php test/ModelCallbackTest.php test/CallbackTest.php test/PrimaryKeyWriteGuardTest.php test/ConnectionTransactionTest.php` → PASS.
- [ ] **Step 5: Commit** `feat: soft delete(), restore(), force_delete() and restore callbacks`.

### Task 13: `:through` middle soft delete

**Files:**
- Modify: `lib/relationships/HasMany.php` (`load()`), `lib/relationships/AbstractRelationship.php` (eager options)
- Create: `test/SoftDeleteRelationshipTest.php`

**Interfaces:**
- Produces: `protected function middle_soft_delete_condition(AbstractRelationship $through): ?string` on `AbstractRelationship` — `<quoted middle table>.<quoted column> IS NULL` when the through relationship's class is `SoftDeletable`, else `null`; appended with ` AND ` after the conditions each load builds (lazy: per call in `HasMany::load()`, never into the frozen join string; eager: in the options built per call).

- [ ] **Step 1: Write the failing tests**:

```php
public function test_middle_trashed_link_hidden_lazy_and_eager_both_shapes(): void
{
    $post = TrashPost::find(1);
    $this->assert_equals([1], $this->ids($post->trash_tags));      // tag 2 trashed (target), tag 3 via trashed link
    $this->assert_equals([1], $this->ids($post->trash_votes));     // vote 2 via trashed comment, vote 3 trashed
    $eager = TrashPost::find(1, ['include' => ['trash_tags', 'trash_votes']]);
    $this->assert_equals([1], $this->ids($eager->trash_tags));
    $this->assert_equals([1], $this->ids($eager->trash_votes));
}

public function test_unscoped_never_lifts_the_middle_filter(): void { /* a TrashPost subclass with 'unscoped' on trash_taggings and on trash_tags: link 3 still hidden */ }
public function test_belongs_to_trashed_parent_and_unscoped(): void { /* TrashComment::find(3)->trash_post === null; ->any_post->id === 2; eager the same */ }
public function test_shapes_pinned(): void { /* to_sql/captured SQL of one lazy and one eager load: ... AND trash_taggings.deleted_at IS NULL */ }
```

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** `test/SoftDeleteRelationshipTest.php test/HasManyThroughTest.php test/RelationshipEagerLazyParityTest.php` → PASS on the four adapters.
- [ ] **Step 5: Commit** `feat: the :through middle model's soft delete`.

### Task 14: migration warning

**Files:**
- Modify: `lib/Config.php`, `lib/QueryCompiler.php`
- Create: `test/SoftDeleteWarningTest.php`

**Interfaces:**
- Produces: `Config::set_warn_soft_delete_conditions(bool $enabled = true): void`, `Config::get_warn_soft_delete_conditions(): bool` (default `false`, shape of `set_tinyint_as_boolean()`). In `QueryCompiler`, when on and the model is `SoftDeletable`: `private function warn_soft_delete_conditions(QueryState $state, string|array|null $set): void`, called at the two WHERE sites and in `compile_update()`; detects (§3.7) legacy string/positional conditions and `where_raw` SQL containing the column name (word match), legacy hash keys and builder `Where`s (`basic`, `null`, `in`, `between`, `hash`, …) whose column is the soft-delete column (bare or qualified), and a `set` naming it; logs `Config::instance()->get_logger()?->warning(sprintf('%s: the condition %s names the soft-delete column %s, which the soft_deletes scope already filters: use only_trashed(), with_trashed() or restore()', ...))` (writes: `the update of …`). Never throws, never changes the SQL.

- [ ] **Step 1: Write the failing tests** with a test PSR logger collecting messages: off → nothing; on → one warning each for `all(['conditions' => 'deleted_at IS NOT NULL'])`, `all(['conditions' => ['deleted_at' => null]])`, `where_not_null('deleted_at')`, `where('trash_posts.deleted_at', '!=', null)`, `update_all(['set' => ['deleted_at' => null]])`, `new_query()->update(['deleted_at' => null])`; nothing for `Author` (not SoftDeletable); no logger set → no error.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat: optional soft-delete migration warning through the PSR logger`.

### Task 15: documentation and PR 2

**Files:**
- Modify: `README.md`

- [ ] **Step 1:** README "Soft delete" section (§9.4): opt-in, column, builder methods, instance verbs, callbacks and `after_destroy` + `is_force_deleting()`, `force_*` keeping other scopes, `restore()` without `save()`, `find_or_create_by_*` and trashed rows, uniqueness schemas, `:through`, the warning and its logger, indexing, the deviations and missing features of §3.9.
- [ ] **Step 2:** Gates of Global Constraints.
- [ ] **Step 3:** Commit `docs: soft delete in the README`; push `feat/soft-delete`; open PR 2 based on `feat/global-scopes`, body listing §7 rows 1, 3, 4, 6, 7, 10.
