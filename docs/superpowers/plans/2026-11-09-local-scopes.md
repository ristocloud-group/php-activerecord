# Local Scopes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let models declare local scopes (`public static scope_x(Query $q, ...$args): ?Query` or `#[Scope] protected static x(...)`), reachable as `Post::x()` and `->x()` on the query builder, with collision checks, always-grouped wheres and `has_scope()`.

**Architecture:** A lazily built, per-class `ScopeRegistry` (cached on the model's `Table`) discovers scope methods by reflection, validates name collisions, and applies a scope to a `Query`. The scope's wheres are regrouped into one `group` condition, the shape closure groups already compile to. Dispatch is a new step in `Model::__callStatic` and a new `Query::__call`.

**Tech Stack:** PHP 8.3–8.5 (attributes, reflection), PDO (MySQL/MariaDB/Postgres/SQLite), PHPUnit via `SnakeCase_PHPUnit_Framework_TestCase`, PHPStan level 8, php-cs-fixer (PER-CS 3.0).

**Spec:** `docs/superpowers/specs/2026-11-09-local-scopes-design.md` (§ references point there).

## Global Constraints

- **Start only after sub-project 1 (iteration: `chunk`/`lazy`/`cursor`) has merged to `master`.** Cut the branch `feat/local-scopes` from that `master` and cherry-pick or merge `spec/local-scopes` (spec + this plan) onto it. Both touch `lib/Query.php` and `lib/Model.php`.
- Everything runs in Docker, in an isolated worktree with its own compose project (`cd <worktree> && docker compose -p <unique> up -d`, or the reuse-image wrapper recipe). Commands:
  - full suite: `docker compose exec tests composer run test` (MySQL default);
  - other adapters: `-e PHPAR_CONNECTION=mariadb` / `sqlite`;
  - a single test: `vendor/bin/phpunit --filter <name>`;
  - `composer run analyse` and `composer run cs`.
- **No skipped tests** (`--fail-on-skipped`). **PHPStan level 8 with `phpstan-baseline.neon` frozen:** no new entry, no new ignore.
- Before each full suite, `: > test/log/query.log`. After each task, purge the test MySQL binlogs. Never run suites in the background or concurrently.
- snake_case public API; modern PHP ≥ 8.3 idioms; docblocks in the style of `lib/Query.php` (`@internal …; not a supported API.` on internal members).
- Backward compatibility (spec §6) has exactly three approved/listed BREAKING items. Nothing else may change observably. The golden SQL corpus (`test/GoldenSqlTest.php`) stays byte-identical, and `Query::FORWARDABLE` is not touched.
- Names, verbatim:
  - attribute `ActiveRecord\Scope`;
  - registry `ActiveRecord\ScopeRegistry`;
  - methods `Table::scopes()`, `Model::has_scope()`, `Query::has_scope()`, `Query::__call()`;
  - prefix `scope_`;
  - unknown-name message `Call to undefined method ActiveRecord\Query::<name>()` (a `\BadMethodCallException`).
- Every gate on PHP 8.3 **and** 8.5. Rebuild the tests image with `--build-arg PHP_VERSION=8.5` for the 8.5 run, then back to 8.3.
- Local only; no push until the maintainer says so.

## Review Focus

1. **A scope that calls another scope** (`scope_a` does `$q->b()`): the inner scope's wheres form a group inside the outer group, and the binds stay in order. Test in Task 2.
2. **Calling a private `Query` method from outside** (e.g. `$q->compiler()`): with `__call` it now throws `\BadMethodCallException` "Call to undefined method …" instead of PHP's "Call to private method" `\Error`. This is part of BREAKING item 1, and must be pinned and documented in the release text. Test in Task 2; documented in Task 3.
3. **A scope on a joined query:** the unqualified columns the scope uses are qualified with the model table as builder wheres always are (no ambiguity error). Test in Task 2.
4. **Inherited scopes:** a scope declared on a parent model (both forms, including a `protected static #[Scope]`) is reachable on a child model, and the child's registry is its own. Test in Task 1 (`has_scope`) and Task 2 (call).
5. **A collision surfaces on any first interaction:** `has_scope('valid_name')` on a model that also declares a colliding scope throws `ConfigException`, deterministically, even though the colliding name is never called. Test in Task 1.

---

### Task 1: `Scope` attribute, `ScopeRegistry` discovery and validation, `has_scope()`

**Files:**
- Create: `lib/Scope.php`, `lib/ScopeRegistry.php`
- Modify:
  - `lib/Table.php`: new `scopes()` accessor and its cached property;
  - `lib/Model.php`: new static `has_scope()`; move the dynamic-finder prefixes to the shared constant below;
  - `lib/Query.php`: new `has_scope()`.
- Create test models in `test/models/`:
  - `ScopedAuthor.php`, on table `authors`, with:
    - `scope_tito_or_parent(Query $q, int $parent): ?Query` → `where('parent_author_id', $parent)->or_where('name', 'Tito')`;
    - `#[Scope] protected static function named(Query $q, string $name): ?Query` → `where('name', $name)`;
    - `scope_newest_first(Query $q): ?Query` → `order_by_desc('author_id')`;
    - `scope_noop(Query $q): ?Query` → `null`;
    - `scope_rogue(Query $q): ?Query` → `static::new_query()`;
    - untyped `public static function scope_untyped_bad($q) { return 'x'; }`;
    - `scope_tito_named(Query $q): ?Query` → `$q->named('Tito')` (nested);
    - non-static `public function scope_instance_only() {}`;
  - `ScopedAuthorChild.php`: `extends ScopedAuthor`, no scopes of its own;
  - `ScopeStaticHelperBase.php`: on `authors`, with `public static function helper(): int { return 1; }`;
  - one model per collision, each on `authors`:
    - `CollidingQueryMethodScope` (`scope_where`);
    - `CollidingModelStaticScope` (`scope_create`: a Model static that is not a Query method);
    - `CollidingInheritedStaticScope` (`extends ScopeStaticHelperBase`, `scope_helper`);
    - `CollidingFinderPrefixScope` (`scope_find_by_title`);
    - `CollidingWriteScope` (`scope_delete`);
    - `CollidingDoubleScope` (`scope_twice` + `#[Scope] protected static function twice`);
    - `CollidingHasScopeScope` (`scope_has_scope`);
    - `CollidingValidAndBadScope` (`scope_fine` + `scope_where`);
  - one model per invalid `#[Scope]` visibility:
    - `PublicAttributeScope` (`#[Scope] public static`);
    - `PrivateAttributeScope` (`#[Scope] private static`);
    - `InstanceAttributeScope` (`#[Scope] public function`).
- Test: `test/ScopeRegistryTest.php`

**Interfaces:**
- Produces:
  - `#[\Attribute(\Attribute::TARGET_METHOD)] final class Scope {}` (public).
  - `final class ScopeRegistry` (`@internal`):
    - `public const array DYNAMIC_FINDER_PREFIXES = ['find_by', 'find_all_by', 'count_by', 'find_or_create_by'];` — `Model::is_dynamic_finder()` must use it, and its behavior stays unchanged;
    - `public function __construct(string $model_class)`, which reflects and validates eagerly;
    - `public function has(string $name): bool`;
    - `public function apply(Query $query, string $name, array $args): Query` (implemented in Task 2; Task 1 may leave it out).
  - `Table::scopes(): ScopeRegistry` (`@internal`), built on the first call and cached; `assert_same` on two calls.
  - `Model::has_scope(string $name): bool` (public static).
  - `Query::has_scope(string $name): bool` (public).

- [ ] **Step 1: Write the failing tests** in `test/ScopeRegistryTest.php` (`extends DatabaseTest`):
  - `test_has_scope_on_model_and_query`:
    - `ScopedAuthor::has_scope('tito_or_parent')` and `ScopedAuthor::has_scope('named')` are true;
    - `ScopedAuthor::has_scope('scope_tito_or_parent')`, `has_scope('nope')` and `has_scope('instance_only')` are false;
    - `ScopedAuthor::new_query()->has_scope('named')` is true.
  - `test_registry_is_built_once`: `assert_same(ScopedAuthor::table()->scopes(), ScopedAuthor::table()->scopes())`.
  - `test_scopes_are_inherited` (Review Focus 4): `ScopedAuthorChild::has_scope('named')` and `has_scope('tito_or_parent')` are true, and `ScopedAuthorChild::table()->scopes()` is not the same object as `ScopedAuthor::table()->scopes()`.
  - `test_collisions_throw_config_exception`: for each colliding model, `$class::has_scope('x')` throws `ActiveRecord\ConfigException`, and the message contains the class name, the scope name, and the reason word:

    | Model | Message contains |
    |---|---|
    | `CollidingQueryMethodScope` | `where`, `Query` |
    | `CollidingModelStaticScope` | `create`, `static` |
    | `CollidingInheritedStaticScope` | `helper`, `static` |
    | `CollidingFinderPrefixScope` | `find_by_title`, `dynamic finder` |
    | `CollidingWriteScope` | `delete` |
    | `CollidingDoubleScope` | `twice`, `declared twice` |
    | `CollidingHasScopeScope` | `has_scope` |

  - `test_collision_surfaces_on_any_first_interaction` (Review Focus 5): `CollidingValidAndBadScope::has_scope('fine')` throws `ConfigException`.
  - `test_attribute_scope_must_be_protected_static`: `PublicAttributeScope`, `PrivateAttributeScope` and `InstanceAttributeScope` each throw `ConfigException` on `has_scope('x')`, and the message contains `protected static`.

- [ ] **Step 2: Run them to verify they fail.**
  `vendor/bin/phpunit test/ScopeRegistryTest.php`. Expected: ERROR, `Call to undefined method: has_scope`.

- [ ] **Step 3: Implement** `Scope`, `ScopeRegistry::__construct()`/`has()`, `Table::scopes()`, `Model::has_scope()` and `Query::has_scope()`. Rules, from spec §3.1–§3.2:
  - **Discovery.** Over `(new \ReflectionClass($model_class))->getMethods(\ReflectionMethod::IS_STATIC)`:
    - a `public` method whose name starts with `scope_` and is longer than the prefix becomes a scope named by the rest of the name;
    - a method carrying `Scope` (`getAttributes(Scope::class)`) must be `protected` (else `ConfigException`) and is named by the method name.
    - Then, over the non-static methods: a `Scope`-carrying one is a `ConfigException`, and a non-static `scope_*` is ignored.
  - **Validation**, in this order, one `ConfigException` per first failure:
    1. a double declaration;
    2. a public method of `Query` (`ReflectionClass(Query::class)->getMethods(IS_PUBLIC)`, magic methods `__*` excluded);
    3. a static method of the class hierarchy, from the same `IS_STATIC` list minus the scope methods themselves;
    4. a dynamic-finder prefix (`DYNAMIC_FINDER_PREFIXES`, matched as `Model::is_dynamic_finder()` matches);
    5. `update` or `delete`.
  - **Message:** `sprintf('%s declares the scope %s(), which %s', $class, $name, $reason)`. The reasons must contain the words the tests assert.
  - `Table::scopes()` is NOT called from `Table::__construct` (recursion hazard, see `Table::load()`).
- [ ] **Step 4: Run** `ScopeRegistryTest`, `QueryForwardingTest`, `LibraryLoadingTest` and `GoldenSqlTest`. Expected: PASS.
- [ ] **Step 5: Full suite on mysql, mariadb and sqlite + `analyse` + `cs`.** All green.
- [ ] **Step 6: Commit**
  `git commit -m "feat: discover and validate local scopes (scope_x, #[Scope]) and has_scope()"`

### Task 2: Dispatch, grouping and return contract

**Files:**
- Modify:
  - `lib/ScopeRegistry.php` (`apply()`);
  - `lib/Model.php` (`__callStatic` scope step, ~line 2105, after the `FORWARDABLE` branch);
  - `lib/Query.php` (new `__call`);
  - `test/models/ScopedAuthor.php` (add the instance method `call_scope_statically()` used below).
- Create: `test/QueryScopeTest.php`

**Interfaces:**
- Consumes (Task 1): `ScopeRegistry::has()`, `Table::scopes()`, the `ScopedAuthor` / `ScopedAuthorChild` models.
- Produces:
  - `ScopeRegistry::apply(Query $query, string $name, array $args): Query`.
  - `Query::__call(string $name, array $args): static`.
  - `Model::__callStatic` gains a step before the final throw: `if (static::table()->scopes()->has($method)) return static::table()->scopes()->apply(static::new_query(), $method, $args);`.

- [ ] **Step 1: Write the failing tests** in `test/QueryScopeTest.php`. Fixtures: authors 1 Tito (parent 3), 2 George W. Bush (parent 2), 3 Bill Clinton (parent 1), 4 Uncle Bob (parent 2). Compare ids of `get()`.
  - `test_static_and_chained_with_arguments`:
    - `ScopedAuthor::tito_or_parent(2)` gives `[1,2,4]`;
    - `ScopedAuthor::where('author_id', '>', 1)->named('Uncle Bob')` gives `[4]`;
    - `ScopedAuthor::named('Tito')` gives `[1]`.
  - `test_where_of_a_scope_is_grouped`:
    - `ScopedAuthor::where('author_id', '>', 1)->tito_or_parent(2)->order_by('author_id')->get()` gives `[2,4]` (Tito, id 1, must NOT be returned);
    - its `to_sql()` matches the regex `/WHERE .*author_id.* > \? AND \(.*parent_author_id.* = \? OR .*name.* = \?\)/`.
  - `test_one_condition_scope_is_grouped`: `ScopedAuthor::where('author_id', '>', 0)->named('Tito')->to_sql()` contains `AND (` before the `name` condition.
  - `test_scope_without_where_adds_no_group`: `ScopedAuthor::newest_first()->get()` gives `[4,3,2,1]`, and `to_sql()` has no `WHERE`.
  - `test_null_return_keeps_chain`: `ScopedAuthor::where('author_id', 2)->noop()->get()` gives `[2]`.
  - `test_other_query_returned_throws`: `ScopedAuthor::rogue()` throws `ActiveRecordException` containing `rogue` and `ActiveRecord\Query`.
  - `test_non_query_returned_throws`: `ScopedAuthor::untyped_bad()` throws `ActiveRecordException` containing `untyped_bad` and `string`.
  - `test_nested_scope_groups` (Review Focus 1): `ScopedAuthor::where('author_id', '<', 4)->tito_named()->get()` gives `[1]`, and `to_sql()` contains `AND ((`, i.e. the outer group holding the inner group.
  - `test_scope_on_joined_query` (Review Focus 3): `ScopedAuthor::join('books', 'books.author_id', '=', 'authors.author_id')->select('authors.*')->named('Tito')->get()` gives `[1]`, with no ambiguity error.
  - `test_scope_inside_closure_group`:
    - `ScopedAuthor::where(fn($q) => $q->named('Tito'))->get()` gives `[1]`;
    - `ScopedAuthor::where(fn($q) => $q->newest_first())` throws `ActiveRecordException` containing `A where() group can only hold where clauses`.
  - `test_inherited_scope_call` (Review Focus 4): `ScopedAuthorChild::named('Tito')->get()` returns one model of class `ScopedAuthorChild`.
  - `test_unknown_chained_name_throws_bad_method_call`: `ScopedAuthor::new_query()->wehre('x')` throws `\BadMethodCallException` with exactly `Call to undefined method ActiveRecord\Query::wehre()`.
  - `test_private_query_method_from_outside` (Review Focus 2): `ScopedAuthor::new_query()->compiler()` throws `\BadMethodCallException` with exactly `Call to undefined method ActiveRecord\Query::compiler()`. If `compiler` is not private in the merged code, use another private method of `Query` and note it.
  - `test_unknown_static_name_unchanged`: `ScopedAuthor::wehre()` throws `ActiveRecordException` with message `Call to undefined method: wehre` (today's text).
  - `test_static_scope_from_instance_method_is_undefined`: an instance method on `ScopedAuthor`, `public function call_scope_statically() { return static::named('Tito'); }`, called on a found model, throws `ActiveRecordException` containing `Call to undefined method`. This pins spec §3.1 item 9.
  - `test_iteration_after_scope`:
    - `ScopedAuthor::newest_first()->chunk(2, $fn)` gives pages `[[4,3],[2,1]]`;
    - `iterator_to_array(ScopedAuthor::named('Tito')->lazy())` gives one model;
    - `iterator_to_array(ScopedAuthor::named('Tito')->cursor())` gives one model.

- [ ] **Step 2: Run them to verify they fail.** Expected: ERROR, `Call to undefined method` on the scope names.

- [ ] **Step 3: Implement `ScopeRegistry::apply()`** (spec §4.2):
  1. note `$before = count($query->state()->wheres)`;
  2. invoke the scope method through reflection (`ReflectionMethod::invoke(null, $query, ...$args)`; a protected static is invocable since PHP 8.1);
  3. a return that is neither `null` nor `$query` (identity) throws `ActiveRecordException`: `sprintf('The scope %s() of %s must return null or the query it was given, %s returned', $name, $class, get_debug_type($result))`;
  4. if `count(wheres) > $before`, move `array_slice($wheres, $before)` into a new `QueryState` and replace them with one `['type' => 'group', 'boolean' => 'AND', 'not' => false, 'group' => $state]`;
  5. the `empty_where` / `skipped_when` flags stay on the query's state as the scope's calls set them;
  6. return `$query`.
- [ ] **Step 4: Implement `Query::__call()`.** If `$this->table()->scopes()->has($name)`, apply and return `$this`; else `throw new \BadMethodCallException(sprintf('Call to undefined method %s::%s()', self::class, $name))`. Then add the `__callStatic` step.
- [ ] **Step 5: Run** `QueryScopeTest`, `ScopeRegistryTest`, `QueryForwardingTest` and `GoldenSqlTest`. Expected: PASS. This also verifies spec §9.1–§9.3. `analyse` must accept `__call` with no baseline entry (§9.2).
- [ ] **Step 6: Full suite on mysql, mariadb and sqlite + `analyse` + `cs`.**
- [ ] **Step 7: Commit**
  `git commit -m "feat!: local scopes on models and the query builder, always grouped; unknown Query methods throw BadMethodCallException"`

### Task 3: README, release text, gate on PHP 8.3 and 8.5

**Files:**
- Modify: `README.md` (the query builder section, after the iteration subsection)
- Create (git-ignored): `<worktree>/.superpowers/sdd/2026-11-09-local-scopes/release-notes.md`

- [ ] **Step 1: Write the README subsection "Local scopes"** per spec §7.3:
  - both declaration forms, with the `Post` example of spec §3.1;
  - arguments, and the null/same-query return rule;
  - the always-grouped rule, with the `published = ? AND (votes > ? OR featured = ?)` example;
  - collisions (the §3.2 table, condensed) and that they surface at the first scope interaction;
  - `has_scope()`;
  - the instance-context note (`static::new_query()->x()`), next to the `where` note.
- [ ] **Step 2: Write the release text**: the four spec §6 items, three BREAKING and one added. Item 1 must name both effects: an unknown method, and a private `Query` method called from outside, both throw `\BadMethodCallException` instead of PHP's `\Error`. It goes into the GitHub draft release only when the PR merges.
- [ ] **Step 3: Gate.**
  - Full suite on mysql, mariadb and sqlite, plus `analyse` and `cs`, on PHP 8.3 and on PHP 8.5. Expected: green, no skips, no deprecations.
  - `ScopeRegistry` ≥ 90% lines: `vendor/bin/phpunit --coverage-text --filter 'Scope'`.
  - Rebuild back to 8.3.
- [ ] **Step 4: Commit**
  `git commit -m "docs: local scopes (README)"`

After Task 3: the final whole-branch review by two independent reviewers on different models, consolidated by the controller.
