# Query Builder Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One engine, `ActiveRecord\Query`, interprets, prepares, generates and executes every SELECT/UPDATE/DELETE/INSERT/UPSERT, for both the legacy options API and a new Eloquent-style fluent builder in snake_case. Every legacy call that works today keeps byte-identical SQL.

**Architecture:**
- `Query` is the mutable fluent API (`@template TModel of Model`). It holds a `QueryState` (plain data).
- `QueryCompiler` is the only code that turns a state into `SQLBuilder` calls.
- The legacy entries become thin wrappers over `Query`: `Table::options_to_sql()`, `find`, `count`, `exists`, `update_all`, `delete_all` and `Table::insert/update/delete/upsert`.
- The work ships as six PRs (A–F), merged in order. Each one is gated by golden statement files recorded on master in Task 1.

**Tech Stack:**
- PHP 8.3–8.5, PDO (mysql, pgsql, sqlite);
- PHPUnit 12, using the snake_case `DatabaseTest`;
- PHPStan level 8;
- php-cs-fixer (PER-CS 3.0);
- Monolog `TestHandler` (dev dependency, already installed) to capture statements.

**Spec:** `docs/superpowers/specs/2026-10-21-query-builder-core-design.md`. Section numbers (§) below refer to it.

## Global Constraints

- **PHP and style.**
  - PHP floor `^8.3`. Use modern idioms: `[]`, typed parameters/returns/properties, `??`, `match`, `readonly` where it fits.
  - The public API is snake_case.
  - PER-CS 3.0 + the PHP 8.3 migration set: `composer run cs` must be clean (fix with `composer run cs-fix`).
- **PHPStan.** Level 8 on `lib` and `examples`. `phpstan-baseline.neon` is frozen: add no entry, fix the code.
- **Tests.**
  - `--fail-on-skipped`, `--fail-on-risky`, `--fail-on-warning`, `--fail-on-deprecation`: a skip is a failure.
  - Every PR is green on mysql, mariadb, sqlite and pgsql: `docker compose exec -e PHPAR_CONNECTION=<conn> tests composer run test`. The `-e` flag is required, because a host-shell prefix is not forwarded.
  - Before each PR, run PHP 8.5: `docker compose build --build-arg PHP_VERSION=8.5 tests && docker compose up -d`, run the suites, then rebuild with `PHP_VERSION=8.3`.
- **Dependencies.** No new composer `require` and no PHP extension requirement: exact arithmetic is pure PHP (#175, §5.4).
- **Manifest.** New `lib/*.php` files are added by hand to `ActiveRecord.php`: there is no autoloader for `lib/`.
- **Golden SQL.** Legacy SQL and bind values stay byte-identical (Task 1's golden files), except the five approved changes of §6.2. Each approved change updates its golden entries in the same commit as the code.
- **Adapters.** MySQL behavior lives in `MysqlAdapter`, Postgres and SQLite behavior in their adapters, defaults in `Connection`.
- **Test conventions.**
  - Tests extend `DatabaseTest`, or `SnakeCase_PHPUnit_Framework_TestCase` when they need no database. Methods are `test_*` and assertions snake_case (`assert_equals`, `assert_same`, `assert_sql_has`).
  - Schema goes in `test/sql/{mysql,pgsql,sqlite}.sql`, rows in `test/fixtures/*.csv`, models in `test/models/`.
  - Adapter-specific tests are subclasses that call `parent::set_up('<connection>')` (pattern: `test/MysqlUpsertTest.php`, `test/MariadbBigintUnsignedTest.php`).
- **Git and GitHub.**
  - Commits use conventional messages, each ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
  - PR bodies end with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
  - Use the maintainer's `gh` account.
- **Release notes** go into the GitHub draft release only when a PR merges. Never create `RELEASES.md`.
- **Prerequisite:** the #145 fix (the re-land of #160) is merged to master **before Task 1 records the golden baseline**.

## Review Focus

1. **A consumer subclass overrides a legacy finder**, like the monolith base model adding conditions in `all()` / `first()` / `count()`. Legacy calls must still run the override; the builder intentionally does not (§6.3). Pinned in Task 3 (legacy) and Task 15 (builder).
2. **Columns whose names differ from their attribute names** (#92: `odd_columns`, with `space name`, and `camelCase` on Postgres), used through builder `where` / `select` / `order_by` / `pluck`. They must reach the real column. Pinned in Tasks 11, 12 and 15.
3. **BIGINT UNSIGNED values beyond `PHP_INT_MAX`** (`big_ids`, MySQL/MariaDB) in `where`, `find`, `value` and `max` / `sum`. They must stay exact strings, never clamped or turned into floats. Pinned in Tasks 15 and 18.
4. **Hash conditions with joins** that mix model columns, joined-table columns and already-qualified keys. Only model columns get qualified (§5.1). Pinned in Tasks 5 and 13.
5. **`without_timestamps()` whose callback throws, and nested calls on two models.** The suspension must be restored and must stay per class. Pinned in Task 6.

## File Structure

| File | Responsibility |
|---|---|
| `lib/QueryState.php` (new, `@internal`) | Plain typed data of one query: selects, from, joins, wheres, orders, groups, havings, limit/offset, includes, readonly, and the `legacy` options array |
| `lib/QueryCompiler.php` (new, `@internal`) | The only translation from `QueryState` to `SQLBuilder` (SELECT / UPDATE / DELETE / INSERT / UPSERT); the column-reference rule (§5.1); condition rendering; the pk-value helpers moved from `Model` |
| `lib/Query.php` (new) | The public fluent API and the terminals; owns one `QueryState`; executes through `Connection::query()` and `Table::find_by_sql()` |
| `lib/JoinClause.php` (new) | The closure form of `join` (`on`, `or_on`, `where`…) |
| `lib/Timestamps.php` (new, `@internal`) | The single "now", the per-class suspension stack, the INSERT/UPDATE timestamp rule (§5.6) |
| `lib/ExactDecimal.php` (new, `@internal`) | Exact decimal parse, compare and long division, moved from `Validations` (#175) |
| `lib/SQLBuilder.php` | Additive: binds outside WHERE, multi-row INSERT, hash-key qualification list |
| `lib/Table.php` | `options_to_sql`, `find`, `exists`, `insert`, `insert_returning_pk`, `update`, `delete`, `upsert` delegate to `Query` (signatures unchanged); `map_names` / `process_data` become `@internal public` |
| `lib/Model.php` | `count` / `exists` / `update_all` / `delete_all` delegate; `__callStatic` forwarding; `new_query()`; `without_timestamps()`; `@method static` tags; `set_timestamps()` uses `Timestamps` |
| `lib/Relationship.php` | `construct_join_sql()` with a join type; `construct_inner_join_sql()` delegates |
| `lib/Connection.php` + `lib/adapters/*` | Hooks `random_function()`, `like_condition()`, `like_pattern()` |
| `lib/Validations.php` | Uses `ExactDecimal` |
| `lib/Exceptions.php` | `MultipleRecordsFound` |
| `ActiveRecord.php` | `require`s for the six new files |
| `test/helpers/GoldenStatements.php`, `test/GoldenSqlTest.php`, `test/golden/*.json` | Golden statements (Task 1) |
| `test/Query*Test.php`, `test/{Mysql,Mariadb,Pgsql,Sqlite}QueryBuilderTest.php`, `test/TimestampsTest.php`, `test/ExactDecimalTest.php` | Builder and engine tests |
| `test/models/Measurement.php`, `measurements` table + fixture | Aggregates (Task 18) |
| `test/benchmarks/hot_paths.php` | The micro-benchmark (Task 2; not a phpunit test) |
| `README.md`, `examples/query_builder/`, `examples/README.md` | Docs (Task 20) |

**PRs and branches**, each cut from the updated `master` after the previous one merges:

| PR | Branch | Tasks |
|---|---|---|
| A | `test/query-builder-golden` | 1, plus the spec and this plan |
| B | `refactor/query-engine-reads` | 2–5 |
| C | `refactor/query-engine-writes` | 6–9 |
| D | `feat/query-builder-reads` | 10–16 |
| E | `feat/query-builder-writes-aggregates` | 17–19 |
| F | `docs/query-builder` | 20 |

Each PR ends with the full gate: four adapters, analyse, cs, and PHP 8.5.

---

## PR A — Golden statements

### Task 1: Golden statement harness and corpus

**Files:**
- Create: `test/helpers/GoldenStatements.php`
- Create: `test/GoldenSqlTest.php`
- Create: `test/golden/mysql.json`, `test/golden/mariadb.json`, `test/golden/pgsql.json`, `test/golden/sqlite.json`
- Modify: `test/helpers/config.php` (require the helper)

**Interfaces:**
- Produces:
  - `GoldenStatements::capture(callable $case): list<array{sql: string, values: list<mixed>}|array{exception: class-string, message: string}>`. It pushes a `Monolog\Handler\TestHandler` on `ActiveRecord\Config::instance()->get_logger()` (the Monolog logger that every `Connection` already holds), runs `$case`, pops the handler, and returns one entry per `Connection::query()` call (`message` = SQL, `context['values']` = binds). A thrown exception is appended as its own entry.
  - `GoldenStatements::mask(array $statements): array`. It replaces a string value matching `/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}( [A-Z]{2,5})?\z/` that lies within ±1 day of `time()` with `'<now>'`. The fixtures only hold past dates, so this masks exactly the timestamps a write generates.
  - `GoldenStatements::load(string $connection): array` and `GoldenStatements::save(string $connection, array $golden): void`. They read and write `test/golden/<connection>.json` with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION`, keys sorted, so `int` / `string` / `float` / `null` survive exactly.

- [ ] **Step 1: Write the masking test**

```php
// test/GoldenSqlTest.php (DatabaseTest)
public function test_mask_replaces_only_recent_datetimes(): void
{
    $now = date('Y-m-d H:i:s');
    $masked = GoldenStatements::mask([['sql' => 'x', 'values' => [$now, "$now UTC", '2009-01-01 10:00:00', 7, null]]]);
    $this->assert_same(['<now>', '<now>', '2009-01-01 10:00:00', 7, null], $masked[0]['values']);
}
```

- [ ] **Step 2:** Run `docker compose exec tests vendor/bin/phpunit --filter test_mask_replaces_only_recent_datetimes`. Expected: FAIL (class `GoldenStatements` not found).
- [ ] **Step 3:** Implement `GoldenStatements` as specified above and require it from `test/helpers/config.php`.
- [ ] **Step 4:** Run Step 2's command. Expected: PASS.

- [ ] **Step 5: Write the corpus.** `GoldenSqlTest::cases()` is a static data provider: `name => [Closure $case, ?list<string> $connections]`. A `null` list means all four. The provider keeps only the cases for `getenv('PHPAR_CONNECTION') ?: 'mysql'`, so nothing ever skips. `test_statements_match_golden(string $name, Closure $case)` captures and masks. When `PHPAR_GOLDEN_RECORD=1` it stores the result under `$name`; otherwise it asserts `assert_same($golden[$name], $masked)`. The fixtures reset per test (`DatabaseTest::set_up`), so writes need no rollback. The corpus:

| Case | Call | Connections |
|---|---|---|
| `find_pk` | `Author::find(1)` | all |
| `find_pk_list` | `Author::find(1, 2)` | all |
| `find_pk_with_conditions` | `Author::find(1, ['conditions' => ['name' => 'Tito']])` | all |
| `find_pk_with_alias_conditions` | `Venue::find(1, ['conditions' => ['marquee' => 'Blender Theater at Gramercy']])` | all |
| `find_pk_non_integer` | `Author::find('abc')` (keep the `RecordNotFound`) | all |
| `find_first_every_window` | `Author::find('first', ['conditions' => ['name' => 'Tito'], 'order' => 'name desc', 'limit' => 5, 'offset' => 1])` | all |
| `find_first_pks` | `Author::find('first', [2, 3])` | all |
| `last_default` / `last_with_order` | `Author::last()` / `Author::last(['order' => 'name asc, author_id desc'])` | all |
| `all_every_option` | `Author::all(['select' => 'author_id, name', 'conditions' => ['name IN(?)', ['Tito', 'Uncle Bob']], 'order' => 'name', 'limit' => 2, 'offset' => 1, 'group' => 'author_id, name', 'having' => 'COUNT(*) > 0', 'readonly' => true])` | all |
| `all_from` | `Author::all(['from' => 'authors', 'conditions' => 'author_id > 1'])` | all |
| `all_string_conditions` | `Author::all(['conditions' => "name = 'Tito'"])` | all |
| `all_hash_null_in_list` | `Author::all(['conditions' => ['parent_author_id' => [1, null]]])` | all |
| `all_hash_empty_list` | `Author::all(['conditions' => ['author_id' => []]])` | all |
| `all_hash_alias` | `Venue::all(['conditions' => ['marquee' => 'Warner Theatre']])` | all |
| `all_hash_real_columns` | `OddColumn::all(['conditions' => ['space_name' => 'a', 'camelcase' => 'b']])` | all |
| `all_hash_date_column` | `DatedCount::all(['conditions' => ['day' => new DateTime('2026-01-02')]])` | all |
| `all_positional_date` | `DatedCount::all(['conditions' => ['day = ?', new DateTime('2026-01-02')]])` | all |
| `all_limit_zero` / `all_offset_only` | `Author::all(['limit' => 0])` / `Author::all(['offset' => 2])` | all |
| `joins_assoc` | `Book::all(['joins' => ['author'], 'conditions' => ['authors.name' => 'Tito']])` | all |
| `joins_assoc_model_key` | `Book::all(['joins' => ['author'], 'conditions' => ['name' => 'Ancient Art of Main Tanking']])` | all |
| `joins_assoc_non_model_key` | `Book::all(['joins' => ['author'], 'conditions' => ['parent_author_id' => 3]])` (fails on master; changes in Task 5) | all |
| `joins_raw` | `Book::all(['joins' => 'INNER JOIN authors a ON a.author_id = books.Author_Id'])` | all |
| `dynamic_find_by` / `dynamic_find_all_by` / `dynamic_count_by` | `Author::find_by_name('Tito')` / `Author::find_all_by_name_and_author_id('Tito', 1)` / `Author::count_by_name('Tito')` | all |
| `find_by_sql` | `Author::find_by_sql('SELECT * FROM authors WHERE author_id = ?', [1])` | all |
| `count_plain` / `count_conditions` / `count_pks` / `count_bare_hash` / `count_group` | `Author::count()` / `Author::count(['conditions' => ['name' => 'Tito']])` / `Author::count(1, 2)` / `Author::count(['name' => 'Tito'])` / `Author::count(['group' => 'parent_author_id'])` | all |
| `count_alias_hash` / `exists_alias_hash` | `Venue::count(['conditions' => ['marquee' => 'Warner Theatre']])` / `Venue::exists(['conditions' => ['marquee' => 'Warner Theatre']])` (fail on master; change in Task 4) | all |
| `exists_pk` / `exists_conditions` | `Author::exists(1)` / `Author::exists(['conditions' => ['name' => 'Tito']])` | all |
| `include_has_many` / `include_nested` / `include_through` | `Author::all(['include' => ['books']])` / `Book::all(['include' => ['author' => ['books']]])` / `Host::find(1, ['include' => ['venues']])` | all |
| `lazy_has_many` | `Author::find(1)->books` | all |
| `update_all_hash` | `Author::update_all(['set' => ['name' => 'X'], 'conditions' => ['author_id' => 1]])` (changes in Task 8) | all |
| `update_all_string_set` | `Author::update_all(['set' => "name = 'X'", 'conditions' => 'author_id = 1'])` | all |
| `update_all_positional_limit_order` | `Author::update_all(['set' => ['name' => 'X'], 'conditions' => ['author_id > ?', 1], 'order' => 'author_id', 'limit' => 1])` | all |
| `update_all_alias_hash` / `delete_all_alias_hash` | `Venue::update_all(['set' => ['state' => 'XX'], 'conditions' => ['marquee' => 'Warner Theatre']])` / `Venue::delete_all(['conditions' => ['marquee' => 'Warner Theatre']])` (fail on master; change in Task 8) | all |
| `delete_all_hash` / `delete_all_positional` / `delete_all_string` / `delete_all_limit_order` | the same shapes as `update_all` | all |
| `save_insert` | `Author::create(['name' => 'New'])` | all |
| `save_update` | `$a = Author::find(1); $a->name = 'Y'; $a->save();` | all |
| `model_delete` | `Author::find(4)->delete()` | all |
| `table_insert_direct` | `Author::table()->insert($d = ['name' => 'Direct'])` (changes in Task 7) | all |
| `upsert` | `Author::upsert([['author_id' => 1, 'name' => 'Up'], ['author_id' => 9, 'name' => 'New']], 'author_id', ['name'])` | all |
| `insert_returning_pk` | `T47Generated::create([])` (the RETURNING path, #47) | pgsql |
| `find_bigint_beyond_int_max` | `BigId::find('18446744073709551615')` (after inserting the row with `BigId::create(['id' => '18446744073709551615', 'note' => 'max'])`) | mysql, mariadb |

- [ ] **Step 6: Record on master, one connection at a time.** For each of `mysql`, `mariadb`, `pgsql`, `sqlite`, run `docker compose exec -e PHPAR_CONNECTION=<conn> -e PHPAR_GOLDEN_RECORD=1 tests vendor/bin/phpunit test/GoldenSqlTest.php`. Expected: OK, and four JSON files are written. Inspect them: the "fails on master" cases must hold an `exception` entry.
- [ ] **Step 7: Run in assert mode** for each connection: `docker compose exec -e PHPAR_CONNECTION=<conn> tests vendor/bin/phpunit test/GoldenSqlTest.php`. Expected: PASS on all four. Then run the full gate.
- [ ] **Step 8: Commit and open PR A** (golden + the spec + this plan): `git add test/helpers/GoldenStatements.php test/GoldenSqlTest.php test/golden test/helpers/config.php docs/superpowers && git commit -m "test: golden statements for every legacy entry (query builder core, PR A)"`.

---

## PR B — The engine for reads

### Task 2: `QueryState`, `QueryCompiler::compile_legacy()`, `Query::from_options()`, and the `options_to_sql()` delegation

**Files:**
- Create: `lib/QueryState.php`, `lib/QueryCompiler.php`, `lib/Query.php` (skeleton)
- Modify: `lib/Table.php:244-303` (`options_to_sql`), `ActiveRecord.php`
- Create: `test/benchmarks/hot_paths.php`
- Test: `test/QueryEngineTest.php`

**Interfaces:**
- Produces:
  - `final class QueryState`, with public typed properties. Task 2 needs only `public ?array $legacy = null` (the options array exactly as given). The later tasks add their own fields; each task names its fields.
  - `final class QueryCompiler { public function __construct(Table $table) {} public function compile_select(QueryState $state): SQLBuilder {} }`. For a state that holds only `legacy`, `compile_select()` executes **today's `options_to_sql()` body, moved verbatim**, into `private function compile_legacy(array $options): SQLBuilder`.
  - `class Query` with:
    - `/** @param class-string<TModel> $model_class */ public function __construct(string $model_class)`;
    - `@internal public static function from_options(Table $table, array $options): self` (stores `$options` in `state->legacy`);
    - `@internal public function to_sql_builder(): SQLBuilder`;
    - `@internal public function state(): QueryState`.
  - `Table::options_to_sql($options)` keeps its signature and returns `Query::from_options($this, $options)->to_sql_builder()`.

- [ ] **Step 1: Write the failing test**

```php
// test/QueryEngineTest.php (DatabaseTest)
public function test_options_to_sql_is_served_by_the_engine(): void
{
    $options = ['conditions' => ['name' => 'Tito'], 'order' => 'name', 'limit' => 1];
    $engine = ActiveRecord\Query::from_options(Author::table(), $options)->to_sql_builder();
    $this->assert_same(Author::table()->options_to_sql($options)->to_s(), $engine->to_s());
    $this->assert_same(['Tito'], $engine->get_where_values());
}
```

- [ ] **Step 2:** Run `docker compose exec tests vendor/bin/phpunit test/QueryEngineTest.php`. Expected: FAIL (class `ActiveRecord\Query` not found).
- [ ] **Step 3:** Create the three classes. Move the body of `options_to_sql()` into `QueryCompiler::compile_legacy()` unchanged. Make `options_to_sql()` delegate. Add the three `require`s to `ActiveRecord.php` after `lib/Table.php`.
- [ ] **Step 4:** Run Step 2's command, then `test/GoldenSqlTest.php` on all four connections. Expected: PASS everywhere: the golden files are untouched.
- [ ] **Step 5: Benchmark.** `test/benchmarks/hot_paths.php` runs 20 000× `Author::find(1)`, 5 000× `Author::all()`, 20 000× `Author::count()`, and 2 000× `create` + `save` inside one rolled-back transaction. It prints the ops/s of each. Run it on master and on the branch (`docker compose exec tests php test/benchmarks/hot_paths.php`). Expected: every path is within 5% of master. Keep both outputs for the PR body; investigate before going on if any path regresses more than 5%.
- [ ] **Step 6: Commit:** `git commit -m "refactor: options_to_sql() is served by the Query engine (byte-identical)"`.

### Task 3: Legacy read terminals through `Query`

**Files:**
- Modify: `lib/Table.php` (`find` `:309-316`, `exists` `:324-332`), `lib/Model.php` (`count` `:2100-2111`), `lib/Query.php`
- Create: `test/models/OverridingAuthor.php` (`extends Author`, with `public static $table_name = 'authors';` and an `all()` override that adds `['conditions' => ['author_id' => 1]]` when the caller passes none, then calls `parent::all(...)`)
- Test: `test/QueryEngineTest.php`

**Interfaces:**
- Consumes: Task 2.
- Produces:
  - `Query::get(): array` (`list<TModel>`), executed through `Table::find_by_sql($sql, $values, $readonly, $includes)`. For a legacy state, `readonly` and `include` come from the options, exactly as `Table::find()` reads them today.
  - `Query::exists(): bool`: the adapter's `exists_sql()` over the compiled SELECT, with binds through `Table::format_date_bind_values()`, as `Table::exists()` does today.
  - `@internal Query::legacy_count(): int|string`: today's `Model::count()` execution, moved (`select COUNT(*)`, `fetch(PDO::FETCH_NUM)`, `0` when there is no row).
  - `Table::find($options)` returns `Query::from_options($this, $options)->get()`; `Table::exists($options)` returns `->exists()`; `Model::count()` keeps its argument parsing and returns `Query::from_options($table, $options)->legacy_count()`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_legacy_count_is_served_by_the_engine(): void
{
    $this->assert_same(1, Author::count(['conditions' => ['name' => 'Tito']]));
    $this->assert_true(method_exists(ActiveRecord\Query::class, 'legacy_count'));
}

// Review Focus 1: a subclass overriding a legacy finder keeps its override.
// OverridingAuthor extends Author and overrides all() to add ['conditions' => ['author_id' => 1]]
// when no conditions are given (as the monolith base model injects deleted_at).
public function test_legacy_finder_override_still_runs(): void
{
    $this->assert_same([1], array_map(fn($a) => $a->author_id, OverridingAuthor::all()));
}
```

- [ ] **Step 2:** Run `docker compose exec tests vendor/bin/phpunit test/QueryEngineTest.php`. Expected: the first test FAILS (`legacy_count` missing); the second PASSES. It pins current behavior and must stay green.
- [ ] **Step 3:** Implement the three terminals and the three delegations.
- [ ] **Step 4:** Run the test file and the golden suite on all four connections. Expected: PASS, golden untouched.
- [ ] **Step 5: Commit:** `git commit -m "refactor: find(), exists() and count() run through the Query engine"`.

### Task 4: Uniform alias resolution (§6.2 change 1)

**Files:**
- Modify: `lib/QueryCompiler.php` (`compile_legacy`), `lib/Model.php` (`and_pk_conditions` `:2065-2087`)
- Modify: `test/golden/*.json` (`count_alias_hash`, `exists_alias_hash`)
- Test: `test/QueryEngineTest.php`

**Interfaces:**
- Produces: `compile_legacy()` maps hash names with `$options['mapped_names'] ?? <model>::$alias_attribute`. `and_pk_conditions()` passes `'mapped_names' => []` for its pk part, so a pk key is never aliased and its SQL stays identical.

- [ ] **Step 1: Write the failing tests**

```php
public function test_count_and_exists_resolve_aliases_like_find(): void
{
    $this->assert_same(1, Venue::count(['conditions' => ['marquee' => 'Warner Theatre']]));
    $this->assert_true(Venue::exists(['conditions' => ['marquee' => 'Warner Theatre']]));
}
```

- [ ] **Step 2:** Run the test. Expected: FAIL with `DatabaseException` "Unknown column 'marquee'".
- [ ] **Step 3:** Implement the default alias map and the explicit `[]` in `and_pk_conditions()`.
- [ ] **Step 4:** Run the test (PASS). Then re-record **only** `count_alias_hash` and `exists_alias_hash` on all four connections: the record mode rewrites the whole file, so restore every other entry from git (`git diff test/golden` must show only those two entries). Then run the golden suite in assert mode on all four (PASS).
- [ ] **Step 5: Alias-shadowing check (§10).**
  - Read-only, in the monolith's checkout: list every `(model, alias, target)` from `static $alias_attribute`.
  - For each model's table, the maintainer runs `SHOW COLUMNS FROM <table> LIKE '<alias>'` on a monolith dev database, or you run it if one is reachable.
  - Write the result in the PR body. Any alias that is also a real column is reported to the maintainer before PR B merges.
- [ ] **Step 6: Commit:** `git commit -m "fix: count()/exists() resolve alias_attribute names like find() (spec §6.2.1)"`.

### Task 5: Qualify only model columns in hash conditions with joins (§6.2 change 4)

**Files:**
- Modify: `lib/SQLBuilder.php` (`prepend_table_name_to_fields` `:573-593`, a new setter), `lib/QueryCompiler.php`
- Modify: `test/golden/*.json` (`joins_assoc_non_model_key`)
- Test: `test/QueryEngineTest.php`

**Interfaces:**
- Produces: `@internal SQLBuilder::qualify_hash_keys(list<string> $columns): static`. When joins are set, only an unqualified hash key that names one of `$columns` (the model's real column names) gets the table prefix; the other keys stay as written. If the setter is never called, today's behavior is kept, because other internal callers have no joins. `compile_legacy()` calls it with `array_keys($table->columns)` before applying the conditions.

- [ ] **Step 1: Write the failing test (Review Focus 4)**

```php
public function test_joins_qualify_only_model_columns(): void
{
    $books = Book::all(['joins' => ['author'], 'conditions' => ['name' => 'Ancient Art of Main Tanking', 'authors.name' => 'Tito', 'parent_author_id' => 3]]);
    $this->assert_same([1], array_map(fn($b) => $b->book_id, $books));
    $this->assert_sql_has('books.name', Book::table()->last_sql);
    $this->assert_sql_doesnt_has('books.parent_author_id', Book::table()->last_sql);
}
```

- [ ] **Step 2:** Run the test. Expected: FAIL (unknown column `books.parent_author_id`).
- [ ] **Step 3:** Implement `qualify_hash_keys()` and call it from the compiler.
- [ ] **Step 4:** Run the test (PASS). Re-record **only** `joins_assoc_non_model_key` on all four connections, restoring the other entries from git. Run the golden suite in assert mode (PASS) and the full gate for PR B.
- [ ] **Step 5: Commit, then open PR B** with the benchmark numbers and the alias check: `git commit -m "fix: hash conditions with joins qualify only the model's columns (spec §6.2.4)"`.

---

## PR C — The engine for writes

### Task 6: The timestamp policy and `Model::without_timestamps()`

**Files:**
- Create: `lib/Timestamps.php`
- Modify: `lib/Model.php` (`set_timestamps` `:1462-1474`, a new static `without_timestamps`), `ActiveRecord.php`
- Test: `test/TimestampsTest.php`

**Interfaces:**
- Produces, `final class Timestamps` (all static, `@internal`):
  - `now(): string` → `date('Y-m-d H:i:s')`, unless frozen;
  - `freeze(?string $now): void` (tests);
  - `suspend(string $class, callable $fn): mixed`, a per-class counter restored in `finally`;
  - `suspended(string $class): bool`;
  - `columns(Table $table): array{created_at: ?string, updated_at: ?string}`, the real column names whose attribute (inflected) names are `created_at` / `updated_at`;
  - `apply_to_insert(Table $table, string $class, array $row): array`;
  - `apply_to_update(Table $table, string $class, array $set): array`. These two add the missing columns (keys compared after `real_column_name()`) with `now()`, unless suspended.
- Produces on `Model`: `public static function without_timestamps(callable $fn): mixed` → `Timestamps::suspend(static::class, $fn)`. `set_timestamps()` takes `now()` from `Timestamps` and does nothing while `static::class` is suspended.

- [ ] **Step 1: Write the failing tests (Review Focus 5)**

```php
public function test_without_timestamps_skips_save_timestamps(): void
{
    $a = Author::without_timestamps(fn() => Author::create(['name' => 'Quiet']));
    $this->assert_null(Author::find($a->author_id)->created_at);
}

public function test_suspension_is_restored_after_an_exception(): void
{
    try { Author::without_timestamps(function () { throw new RuntimeException('boom'); }); } catch (RuntimeException) {}
    $this->assert_false(ActiveRecord\Timestamps::suspended(Author::class));
}

public function test_suspension_is_per_class_and_nests(): void
{
    Author::without_timestamps(function () {
        Author::without_timestamps(fn() => null);
        $this->assert_true(ActiveRecord\Timestamps::suspended(Author::class));
        $this->assert_false(ActiveRecord\Timestamps::suspended(Book::class));
    });
}

public function test_frozen_now_feeds_set_timestamps(): void
{
    ActiveRecord\Timestamps::freeze('2026-10-21 10:00:00');
    $a = Author::create(['name' => 'Frozen']);
    $this->assert_equals('2026-10-21 10:00:00', Author::find($a->author_id)->created_at->format('Y-m-d H:i:s'));
    ActiveRecord\Timestamps::freeze(null);
}
```

- [ ] **Step 2:** Run `docker compose exec tests vendor/bin/phpunit test/TimestampsTest.php`. Expected: FAIL (`without_timestamps` undefined).
- [ ] **Step 3:** Implement `Timestamps` and the two `Model` changes. `tear_down` in the test calls `Timestamps::freeze(null)`.
- [ ] **Step 4:** Run the test (PASS) and the golden suite (PASS, untouched: `save()` still sends the same columns).
- [ ] **Step 5: Commit:** `git commit -m "feat: one timestamp policy and Model::without_timestamps()"`.

### Task 7: Inserts through the engine (§6.2 change 3)

**Files:**
- Modify: `lib/SQLBuilder.php` (a new `insert_rows`; `build_insert` unchanged for a single row), `lib/Query.php`, `lib/QueryCompiler.php`, `lib/Table.php` (`insert`, `insert_returning_pk`, `run_insert` `:524-582`)
- Modify: `test/golden/*.json` (`table_insert_direct`)
- Test: `test/QueryEngineTest.php`

**Interfaces:**
- Produces:
  - `@internal SQLBuilder::insert_rows(list<string> $columns, int $row_count): static`. It renders `INSERT INTO t (c1, c2) VALUES (?, ?), (?, ?)`, with the VALUES list generalized from `build_upsert()`.
  - `@internal Query::execute_insert(array $row, ?string $pk = null, ?string $sequence = null, ?string $returning = null): \PDOStatement`. It runs today's `run_insert()` body (process_data, real_column_keys, sequence, RETURNING #47) after `Timestamps::apply_to_insert()`.
  - `Table::insert()`, `run_insert()` and `insert_returning_pk()` keep their signatures and results, and delegate.

- [ ] **Step 1: Write the failing test**

```php
public function test_direct_table_insert_fills_timestamps(): void
{
    ActiveRecord\Timestamps::freeze('2026-10-21 10:00:00');
    $data = ['name' => 'Direct'];
    Author::table()->insert($data);
    $row = Author::find_by_name('Direct');
    $this->assert_equals('2026-10-21 10:00:00', $row->created_at->format('Y-m-d H:i:s'));
    $this->assert_equals('2026-10-21 10:00:00', $row->updated_at->format('Y-m-d H:i:s'));
    ActiveRecord\Timestamps::freeze(null);
}

public function test_insert_rows_renders_multi_values(): void
{
    $sql = (new ActiveRecord\SQLBuilder(Author::connection(), 'authors'))->insert_rows(['name', 'parent_author_id'], 2);
    $this->assert_sql_has('INSERT INTO authors (name, parent_author_id) VALUES (?, ?), (?, ?)', $sql->to_s());
}
```

- [ ] **Step 2:** Run the tests. Expected: both FAIL.
- [ ] **Step 3:** Implement `insert_rows()` and `execute_insert()`, and delegate `Table`.
- [ ] **Step 4:** Run the tests (PASS). Re-record **only** `table_insert_direct`. Run the golden suite (PASS: `save_insert`, `upsert` and `insert_returning_pk` are untouched).
- [ ] **Step 5: Commit:** `git commit -m "refactor: inserts run through the Query engine; direct Table::insert() fills timestamps (spec §6.2.3)"`.

### Task 8: Updates and deletes through the engine (§6.2 changes 2 and 5)

**Files:**
- Modify: `lib/Query.php`, `lib/QueryCompiler.php`, `lib/Table.php` (`update` `:711-734`, `delete` `:740-755`), `lib/Model.php` (`delete_all`, `update_all`: keep #145's normalization in front)
- Modify: `test/golden/*.json` (`update_all_hash`, `update_all_positional_limit_order`, `update_all_alias_hash`, `delete_all_alias_hash`)
- Test: `test/QueryEngineTest.php`

**Interfaces:**
- Produces:
  - `@internal Query::execute_update(array|string $set): \PDOStatement`. An array goes through `Timestamps::apply_to_update()`, then `real_column_keys()` and `format_date_bind_values()`; a string is a raw SET, with no timestamps (§5.6).
  - `@internal Query::execute_delete(): \PDOStatement`.
  - `QueryCompiler::compile_update(QueryState $state, array|string $set): SQLBuilder` and `QueryCompiler::compile_delete(QueryState $state): SQLBuilder`, which set `key_columns(array_map($table->real_column_name(...), $table->pk))` for the Postgres limited path (#92).
  - `Table::update($data, $where)` and `Table::delete($data)` keep their signatures and return the `\PDOStatement`. `update_all()` and `delete_all()` hand the engine only `conditions`, `order` and `limit`, and return `rowCount()`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_update_all_hash_set_touches_updated_at(): void
{
    ActiveRecord\Timestamps::freeze('2026-10-21 10:00:00');
    Author::update_all(['set' => ['name' => 'X'], 'conditions' => ['author_id' => 1]]);
    $this->assert_equals('2026-10-21 10:00:00', Author::find(1)->updated_at->format('Y-m-d H:i:s'));
    ActiveRecord\Timestamps::freeze(null);
}

public function test_update_all_string_set_and_without_timestamps_leave_updated_at(): void
{
    Author::update_all(['set' => "name = 'S'", 'conditions' => 'author_id = 2']);
    Author::without_timestamps(fn() => Author::update_all(['set' => ['name' => 'Q'], 'conditions' => ['author_id' => 3]]));
    $this->assert_null(Author::find(2)->updated_at);
    $this->assert_null(Author::find(3)->updated_at);
}

public function test_bulk_writes_resolve_aliases(): void
{
    $this->assert_same(1, Venue::update_all(['set' => ['state' => 'XX'], 'conditions' => ['marquee' => 'Warner Theatre']]));
    $this->assert_same(1, Venue::delete_all(['conditions' => ['marquee' => 'Warner Theatre']]));
}

public function test_update_all_empty_set_throws_without_warning(): void
{
    $this->assert_exception_message_contains('Updating requires a hash or string.', fn() => Author::update_all(['set' => []]), ActiveRecord\ActiveRecordException::class);
}
```

- [ ] **Step 2:** Run the tests. Expected: the first and third FAIL; the second and fourth PASS (they pin behavior). If the fourth fails because of a PHP warning, `failOnWarning` reports it: that is the warning §6.2.5 removes.
- [ ] **Step 3:** Implement the engine terminals and delegate `Table` and the two `Model` methods.
- [ ] **Step 4:** Run the tests (PASS). Re-record **only** the four listed golden entries. Run the golden suite (PASS: `save_update`, `model_delete`, `update_all_string_set` and the `delete_all_*` shapes are untouched).
- [ ] **Step 5: Commit:** `git commit -m "refactor: updates and deletes run through the Query engine; update_all touches updated_at (spec §6.2.1-2,5)"`.

### Task 9: Upsert through the engine

**Files:**
- Modify: `lib/Query.php`, `lib/QueryCompiler.php`, `lib/Table.php` (`upsert` `:589-704`)
- Test: the existing `test/{Mysql,Mariadb,Pgsql,Sqlite}UpsertTest.php` (unchanged) and `test/TimestampsTest.php`

**Interfaces:**
- Produces: `Query::upsert(array $values, array|string $unique_by, ?array $update = null): int`, which is today's `Table::upsert()` body moved. Its timestamps come from `Timestamps::now()` / `columns()`, and the suspension skips them. `Table::upsert()` delegates with the same signature.

- [ ] **Step 1: Write the failing test**

```php
public function test_upsert_honors_without_timestamps(): void
{
    Author::without_timestamps(fn() => Author::upsert([['author_id' => 1, 'name' => 'U']], 'author_id', ['name']));
    $this->assert_null(Author::find(1)->updated_at);
}
```

- [ ] **Step 2:** Run it. Expected: FAIL (upsert sets `updated_at` today).
- [ ] **Step 3:** Move the body and switch it to `Timestamps`.
- [ ] **Step 4:** Run the four `*UpsertTest` files, `TimestampsTest` and the golden suite (all PASS; `upsert` golden untouched). Run the full gate for PR C on four adapters, analyse, cs and PHP 8.5.
- [ ] **Step 5: Commit and open PR C:** `git commit -m "refactor: upsert runs through the Query engine and the timestamp policy"`.

---

## PR D — The builder's public read API

### Task 10: Entry points and static forwarding

**Files:**
- Modify: `lib/Model.php` (`__callStatic` `:1802-1851`, a new `new_query()`, the class docblock's `@method static` tags), `lib/Query.php` (`public const FORWARDABLE`)
- Create: `test/models/ShadowingAuthor.php` (`extends Author`, with `public static $table_name = 'authors';` and `public static function latest(): string { return 'own'; }`)
- Test: `test/QueryForwardingTest.php`

**Interfaces:**
- Produces:
  - `public static function new_query(): Query` (`@return Query<static>`).
  - `Query::FORWARDABLE`, the `list<string>` of the public builder methods of §4.2 that can be reached statically. It leaves out:
    - `update` and `delete`;
    - every name that is already a real static of `Model` (`find`, `first`, `last`, `all`, `count`, `exists`, `create`, `upsert`, `query`, `find_by_sql`, `update_all`, `delete_all`, `transaction`, `table`, `connection`…). These keep their legacy semantics and are reached only through `new_query()` or a chain;
    - the internals (`from_options`, `to_sql_builder`, `state`, `execute_*`, `legacy_*`).

    The list starts with the methods that exist at this point (`get`), and **every later task adds its own names to `FORWARDABLE` and to the `@method` tags in the same commit**, so the parity test below stays green.
  - `__callStatic` resolves in this order:
    1. dynamic finders, as today;
    2. `update` / `delete` throw `ActiveRecordException("<Class>::<method>() would write every row: use <Class>::where(...)-><method>(...) or <Class>::new_query()-><method>(...)")`;
    3. a name in `FORWARDABLE` returns `static::new_query()->$method(...$args)`;
    4. anything else keeps today's `"Call to undefined method: $method"`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_new_query_and_forwarding(): void
{
    $this->assert_instance_of(ActiveRecord\Query::class, Author::new_query());
    $this->assert_same(4, count(Author::get())); // forwarded to new_query()->get()
    $this->assert_same('Tito', Author::find_by_name('Tito')->name); // dynamic finders keep precedence
}

public function test_static_update_and_delete_are_refused(): void
{
    $this->assert_exception_message_contains('would write every row', fn() => Author::update(['name' => 'x']), ActiveRecord\ActiveRecordException::class);
}

public function test_unknown_static_keeps_the_legacy_message(): void
{
    $this->assert_exception_message_contains('Call to undefined method: frobnicate', fn() => Author::frobnicate(), ActiveRecord\ActiveRecordException::class);
}

public function test_a_subclass_method_shadows_a_forwarded_one(): void
{
    $this->assert_same('own', ShadowingAuthor::latest());
}

public function test_forwardable_matches_the_method_tags(): void
{
    preg_match_all('/@method static \S+ (\w+)\(/', (string) (new ReflectionClass(ActiveRecord\Model::class))->getDocComment(), $m);
    $this->assert_equals(array_values(array_unique(ActiveRecord\Query::FORWARDABLE)), array_values(array_unique($m[1])));
}
```

- [ ] **Step 2:** Run `docker compose exec tests vendor/bin/phpunit test/QueryForwardingTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the test file and the golden suite (PASS).
- [ ] **Step 5: Commit:** `git commit -m "feat: Model::new_query() and static forwarding to the query builder"`.

### Task 11: Column references and the select/from family

**Files:**
- Modify: `lib/Query.php`, `lib/QueryState.php`, `lib/QueryCompiler.php`, `lib/SQLBuilder.php` (`select($select, array $values = [])`, `select_bind_values(): list<mixed>`)
- Test: `test/QuerySelectTest.php`

**Interfaces:**
- Produces:
  - `QueryCompiler::column(string $name, QueryState $state): string`. This is the rule of §5.1: model column, attribute or alias → real column via `Table::real_column_name()` and the alias map, quoted with `quote_name()`. When the state has joins, it is also qualified with the model table, or with the `from()` alias. Non-model names, `table.column`, `*` and `table.*` are quoted part by part and never qualified.
  - `select(string|array ...$columns): static` (`'col as alias'` becomes `col AS alias`), `add_select(...)`, `select_raw(string $sql, array $bindings = []): static`, `distinct(bool $value = true): static`, `from(string $table, ?string $as = null): static`, `from_raw(string $sql, array $bindings = []): static`, `to_sql(): string`, `get_bindings(): list<mixed>`.
  - `SQLBuilder::select_bind_values()` returns the binds in clause order: select, from, joins, where, group, having, order.

- [ ] **Step 1: Write the failing tests (Review Focus 2)**

```php
public function test_select_maps_attributes_and_aliases_to_real_columns(): void
{
    $this->assert_sql_has('SELECT space name FROM odd_columns', OddColumn::new_query()->select('space_name')->to_sql());
    $this->assert_sql_has('SELECT name FROM venues', Venue::new_query()->select('marquee')->to_sql());
}

public function test_select_alias_distinct_and_raw_binds(): void
{
    $q = Author::new_query()->distinct()->select('name as n')->select_raw('LENGTH(name) > ? AS long_name', [3]);
    $this->assert_sql_has('SELECT DISTINCT name AS n, LENGTH(name) > ? AS long_name', $q->to_sql());
    $this->assert_same([3], $q->get_bindings());
}

public function test_from_alias_and_from_raw(): void
{
    $this->assert_sql_has('FROM books AS b', Book::new_query()->from('books', 'b')->to_sql());
    $q = Book::new_query()->from_raw('(SELECT * FROM books WHERE book_id > ?) books', [0]);
    $this->assert_sql_has('FROM (SELECT * FROM books WHERE book_id > ?) books', $q->to_sql());
    $this->assert_same([0], $q->get_bindings());
}
```

- [ ] **Step 2:** Run `docker compose exec tests vendor/bin/phpunit test/QuerySelectTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement. The default select is `*`, or `<table>.*` when there are joins (the same rule as `compile_legacy()`, shared).
- [ ] **Step 4:** Run it (PASS) and the golden suite (PASS).
- [ ] **Step 5: Commit:** `git commit -m "feat: builder select/from with the engine's column-reference rule"`.

### Task 12: The where family

**Files:**
- Modify: `lib/Query.php`, `lib/QueryState.php`, `lib/QueryCompiler.php`, `lib/Connection.php`, `lib/adapters/MysqlAdapter.php`, `lib/adapters/PgsqlAdapter.php`, `lib/adapters/SqliteAdapter.php`
- Test: `test/QueryWhereTest.php`, `test/MysqlQueryBuilderTest.php`, `test/MariadbQueryBuilderTest.php`, `test/PgsqlQueryBuilderTest.php`, `test/SqliteQueryBuilderTest.php`

**Interfaces:**
- Produces, on `Query` (all return `static`; each `or_` variant has the same parameters):
  - `where(Closure|string|array $column, mixed $operator = null, mixed $value = null)`, `or_where`, `where_not`, `or_where_not`;
  - `where_column(string $first, ?string $operator = null, ?string $second = null)`;
  - `where_raw(string $sql, array $bindings = [])`;
  - `where_in(string $column, array $values)` / `where_not_in`;
  - `where_null(string|array $columns)` / `where_not_null`;
  - `where_between(string $column, array $values)` / `where_not_between`;
  - `where_between_columns(string $column, array $columns)` / `where_not_between_columns`;
  - `where_like(string $column, string $value, bool $case_sensitive = false)` / `where_not_like`;
  - `where_all(array $columns, mixed $operator = null, mixed $value = null)` / `where_any` / `where_none`;
  - `where_key(mixed $id)` / `where_key_not(mixed $id)`.
- Rules:
  - Operators are matched case-insensitively against Laravel 13's base list (`=`, `<`, `>`, `<=`, `>=`, `<>`, `!=`, `<=>`, `like`, `like binary`, `not like`, `ilike`, `&`, `|`, `^`, `<<`, `>>`, `&~`, `is`, `is not`, `rlike`, `not rlike`, `regexp`, `not regexp`, `~`, `~*`, `!~`, `!~*`, `similar to`, `not similar to`, `not ilike`, `~~*`, `!~~*`) and rendered in upper case. Anything else throws `ActiveRecordException("Unknown operator '<op>' in where() on <Class>")`.
  - A hash goes through `Expressions` exactly like a legacy conditions hash.
  - `where_not_in` with a null in the list renders `(col NOT IN(?) AND col IS NOT NULL)`; with only nulls, `col IS NOT NULL`; with an empty list, `1=1`.
  - A closure gets `new Query(<same model>)`; if its state has anything but wheres, it throws `ActiveRecordException('A where() group can only hold where clauses')`.
  - `null` with an operator other than `=`, `!=`, `<>`, `<=>` throws `ActiveRecordException("Illegal operator and value combination: '<op>' with null in where() on <Class>")`.
  - `where_between` / `where_between_columns` with anything but exactly two values throw `ActiveRecordException('<method>() needs two values')`.
- Produces, on `Connection` (defaults; overrides where noted):
  - `like_condition(string $column_sql, bool $case_sensitive, bool $not): string`. Default: `"$column_sql LIKE ?"` / `NOT LIKE`, and a case-sensitive call throws. `MysqlAdapter`: case-sensitive → `"$column_sql LIKE CAST(? AS BINARY)"`. `PgsqlAdapter`: case-insensitive `ILIKE`, case-sensitive `LIKE`. `SqliteAdapter`: case-sensitive `GLOB`.
  - `like_pattern(string $pattern, bool $case_sensitive): string`. Default: unchanged. `SqliteAdapter` with case-sensitive: `[` → `[[]`, `*` → `[*]`, `?` → `[?]`, then `%` → `*`, `_` → `?`.
- Data: each bound value is prepared by `Table::process_data([$column => $value])`, so a DateTime takes the format of its column's type (§5.2).

- [ ] **Step 1: Write the failing cross-adapter tests**

```php
public function test_basic_operator_and_null_forms(): void
{
    $this->assert_sql_has('WHERE name = ?', Author::where('name', 'Tito')->to_sql());
    $this->assert_sql_has('WHERE author_id >= ?', Author::where('author_id', '>=', 2)->to_sql());
    $this->assert_sql_has('WHERE parent_author_id IS NULL', Author::where('parent_author_id', null)->to_sql());
    $this->assert_sql_has('WHERE parent_author_id IS NOT NULL', Author::where('parent_author_id', '!=', null)->to_sql());
    $this->assert_exception_message_contains("Unknown operator 'equals'", fn() => Author::where('name', 'equals', 'Tito'), ActiveRecord\ActiveRecordException::class);
    $this->assert_exception_message_contains('null', fn() => Author::where('author_id', '>', null), ActiveRecord\ActiveRecordException::class);
}

public function test_hash_list_and_in_forms_follow_the_legacy_rules(): void
{
    $q = Author::where(['parent_author_id' => [1, null], 'author_id' => []]);
    $this->assert_sql_has('(parent_author_id IN(?) OR parent_author_id IS NULL) AND 1=0', $q->to_sql());
    $this->assert_same([1], $q->get_bindings());
    $this->assert_sql_has('WHERE author_id > ? AND name = ?', Author::where([['author_id', '>', 1], ['name', 'Tito']])->to_sql());
    $this->assert_sql_has('1=0', Author::where_in('author_id', [])->to_sql());
    $this->assert_sql_has('1=1', Author::where_not_in('author_id', [])->to_sql());
    $this->assert_sql_has('(parent_author_id NOT IN(?) AND parent_author_id IS NOT NULL)', Author::where_not_in('parent_author_id', [1, null])->to_sql());
}

public function test_groups_or_not_and_results(): void
{
    $q = Author::where('name', 'Tito')->or_where(fn($q) => $q->where('author_id', '>', 2)->where('parent_author_id', 2));
    $this->assert_sql_has('WHERE name = ? OR (author_id > ? AND parent_author_id = ?)', $q->to_sql());
    $ids = array_map(fn($a) => $a->author_id, $q->get());
    sort($ids);
    $this->assert_same([1, 4], $ids);
    $this->assert_sql_has('WHERE name = ?', Author::where(fn($q) => $q)->where('name', 'x')->to_sql());
    $this->assert_exception_message_contains('where', fn() => Author::where(fn($q) => $q->order_by('name')), ActiveRecord\ActiveRecordException::class);
    $this->assert_sql_has('NOT (name = ?)', Author::where_not('name', 'Tito')->to_sql());
}

public function test_column_raw_between_all_any_none_key(): void
{
    $this->assert_sql_has('author_id = parent_author_id', Author::where_column('author_id', 'parent_author_id')->to_sql());
    $this->assert_sql_has('LENGTH(name) > ?', Author::where_raw('LENGTH(name) > ?', [3])->to_sql());
    $this->assert_sql_has('author_id BETWEEN ? AND ?', Author::where_between('author_id', [1, 3])->to_sql());
    $this->assert_exception_message_contains('two values', fn() => Author::where_between('author_id', [1]), ActiveRecord\ActiveRecordException::class);
    $this->assert_sql_has('author_id BETWEEN parent_author_id AND author_id', Author::where_between_columns('author_id', ['parent_author_id', 'author_id'])->to_sql());
    $this->assert_sql_has('(name = ? OR some_text = ?)', Author::where_any(['name', 'some_text'], 'Tito')->to_sql());
    $this->assert_sql_has('NOT (name = ? OR some_text = ?)', Author::where_none(['name', 'some_text'], 'Tito')->to_sql());
    $this->assert_sql_has('author_id IN(?,?)', Author::where_key([1, 2])->to_sql());
}

// Review Focus 2
public function test_real_column_names_and_forwarding(): void
{
    $this->assert_sql_has('WHERE space name = ?', OddColumn::where('space_name', 'x')->to_sql());
    $this->assert_instance_of(ActiveRecord\Query::class, Author::where('name', 'Tito')); // where() is now on FORWARDABLE
}

public function test_dates_bind_in_their_column_format(): void
{
    $this->assert_same(1, count(DatedCount::where('day', new DateTime('2026-01-02'))->get()));
}
```

- [ ] **Step 2: Write the failing adapter-specific tests** (`set_up` calls `parent::set_up('mysql')` / `'mariadb'` / `'pgsql'` / `'sqlite'`):

```php
// Mysql and Mariadb
public function test_case_sensitive_like_uses_cast_binary(): void
{
    $this->assert_sql_has('name LIKE CAST(? AS BINARY)', Author::where_like('name', 'T%', true)->to_sql());
    $this->assert_same(1, count(Author::where_like('name', 'T%', true)->get()));
    $this->assert_same(0, count(Author::where_like('name', 't%', true)->get()));
}
// Pgsql
public function test_like_is_ilike_unless_case_sensitive(): void
{
    $this->assert_sql_has('name ILIKE ?', Author::where_like('name', 't%')->to_sql());
    $this->assert_sql_has('name LIKE ?', Author::where_like('name', 'T%', true)->to_sql());
}
// Sqlite
public function test_case_sensitive_like_is_glob_with_translated_pattern(): void
{
    $q = Author::where_like('name', 'T_t%', true);
    $this->assert_sql_has('name GLOB ?', $q->to_sql());
    $this->assert_same(['T?t*'], $q->get_bindings());
}
public function test_legacy_positional_date_differs_from_the_builder(): void
{
    $this->assert_same(0, DatedCount::count(['conditions' => ['day = ?', new DateTime('2026-01-02')]])); // documented (§5.2)
    $this->assert_same(1, count(DatedCount::where('day', new DateTime('2026-01-02'))->get()));
}
```

- [ ] **Step 3:** Run `docker compose exec tests vendor/bin/phpunit test/QueryWhereTest.php` and each adapter file. Expected: FAIL.
- [ ] **Step 4:** Implement the methods, the adapter hooks and the rendering. The compiled WHERE is wrapped in parentheses when the engine combines it with its own predicates (pk, legacy entries).
- [ ] **Step 5:** Run every test file of this task on its connection and the golden suite on all four (PASS).
- [ ] **Step 6: Commit:** `git commit -m "feat: builder where family with operator whitelist and adapter LIKE hooks"`.

### Task 13: Joins

**Files:**
- Create: `lib/JoinClause.php`
- Modify: `lib/Query.php`, `lib/QueryState.php`, `lib/QueryCompiler.php`, `lib/Relationship.php` (`construct_inner_join_sql` `:697-744`), `lib/Table.php` (`create_joins` `:199-238`), `lib/SQLBuilder.php` (`joins($joins, array $values = [])`), `ActiveRecord.php`
- Test: `test/QueryJoinTest.php`

**Interfaces:**
- Produces:
  - `JoinClause`: `__construct(string $type, string $table)`, `on(string $first, ?string $operator = null, ?string $second = null): static`, `or_on(...)`, `where(string $column, mixed $operator = null, mixed $value = null): static`, `or_where(...)`, `where_null(string $column)`, `where_not_null`, `where_in(string $column, array $values)`, `where_not_in`.
  - `Query`: `join(string $table, Closure|string $first, ?string $operator = null, ?string $second = null): static`, `left_join(...)`, `right_join(...)`, `cross_join(string $table): static`, `joins(string|array $relationships_or_sql): static` (INNER, plus raw `JOIN …` fragments as `create_joins()` accepts them), `left_joins(string|array $relationships): static`.
  - `Relationship::construct_join_sql(Table $from_table, bool $using_through = false, ?string $alias = null, string $type = 'INNER'): string`. `construct_inner_join_sql()` keeps its signature and returns `construct_join_sql(..., 'INNER')`. `Table::create_joins($joins, string $type = 'INNER')`.
  - Join binds come before where binds in `get_bindings()`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_join_forms(): void
{
    $q = Book::join('authors', 'authors.author_id', '=', 'books.Author_Id');
    $this->assert_sql_has('SELECT books.* FROM books INNER JOIN authors ON authors.author_id = books.Author_Id', $q->to_sql());
    $q = Book::left_join('authors', fn(ActiveRecord\JoinClause $j) => $j->on('authors.author_id', '=', 'books.Author_Id')->where('authors.name', 'Tito'))->where('book_id', '>', 0);
    $this->assert_sql_has('LEFT JOIN authors ON authors.author_id = books.Author_Id AND authors.name = ?', $q->to_sql());
    $this->assert_same(['Tito', 0], $q->get_bindings());
}

public function test_relationship_joins_match_the_legacy_option(): void
{
    $this->assert_same(Book::table()->options_to_sql(['joins' => ['author']])->to_s(), Book::joins('author')->to_sql());
    $this->assert_sql_has('LEFT JOIN authors ON(books.Author_Id = authors.author_id)', Book::left_joins('author')->to_sql());
}

public function test_left_joins_keep_rows_without_a_match(): void
{
    // event 6 points at venue 500, which does not exist
    $this->assert_same(Event::count(), count(Event::left_joins('venue')->get()));
    $this->assert_same(Event::count() - 1, count(Event::joins('venue')->get()));
}

// Review Focus 4: with joins only the model's columns are qualified
public function test_hash_where_with_joins_qualifies_only_model_columns(): void
{
    $q = Book::joins('author')->where(['name' => 'Ancient Art of Main Tanking', 'authors.name' => 'Tito', 'parent_author_id' => 3]);
    $this->assert_sql_has('books.name = ? AND authors.name = ? AND parent_author_id = ?', $q->to_sql());
    $this->assert_same([1], array_map(fn($b) => $b->book_id, $q->get()));
}

public function test_undeclared_relationship_throws(): void
{
    $this->assert_exception_message_contains('nope', fn() => Book::left_joins('nope')->to_sql(), ActiveRecord\RelationshipException::class);
}
```

- [ ] **Step 2:** Run `docker compose exec tests vendor/bin/phpunit test/QueryJoinTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement. Compare the exact ON format of `construct_join_sql()` with `construct_inner_join_sql()`'s current output: only the keyword changes.
- [ ] **Step 4:** Run the tests (PASS) and the golden suite (PASS: `joins_assoc*` untouched).
- [ ] **Step 5: Commit:** `git commit -m "feat: builder joins, JoinClause, and joins()/left_joins() by relationship"`.

### Task 14: Order, group, having and the window

**Files:**
- Modify: `lib/Query.php`, `lib/QueryState.php`, `lib/QueryCompiler.php`, `lib/SQLBuilder.php` (`order($order, array $values = [])`, `group($group, array $values = [])`, `having($having, array $values = [])`), `lib/Connection.php` + `lib/adapters/MysqlAdapter.php` (`random_function()`)
- Test: `test/QueryOrderGroupTest.php`

**Interfaces:**
- Produces:
  - `order_by(string $column, string $direction = 'asc')`, `order_by_desc(string $column)`, `order_by_raw(string $sql, array $bindings = [])`, `latest(string $column = 'created_at')`, `oldest(string $column = 'created_at')`, `reorder(?string $column = null, string $direction = 'asc')`, `in_random_order()`;
  - `group_by(string ...$columns)`, `group_by_raw(string $sql, array $bindings = [])`;
  - `having(string $column, mixed $operator = null, mixed $value = null)`, `or_having`, `having_raw(string $sql, array $bindings = [])`, `or_having_raw`, `having_null(string $column)`, `having_not_null`, `having_between(string $column, array $values)`;
  - `limit(int $value)`, `take`, `offset(int $value)`, `skip`, `for_page(int $page, int $per_page = 15)`. All return `static`.
  - A negative value throws `ActiveRecordException('<method>() must be >= 0')`. A direction other than asc/desc throws `ActiveRecordException("Invalid order direction '<dir>' in order_by()")`.
  - `Connection::random_function(): string` → `'RANDOM()'`; `MysqlAdapter` → `'RAND()'`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_order_forms(): void
{
    $this->assert_sql_has('ORDER BY name ASC, author_id DESC', Author::order_by('name')->order_by_desc('author_id')->to_sql());
    $this->assert_sql_has('ORDER BY created_at DESC', Author::latest()->to_sql());
    $this->assert_sql_has('ORDER BY author_id ASC', Author::order_by('name')->reorder('author_id')->to_sql());
    $this->assert_exception_message_contains('direction', fn() => Author::order_by('name', 'sideways'), ActiveRecord\ActiveRecordException::class);
    $this->assert_sql_has('ORDER BY ' . Author::connection()->random_function(), Author::in_random_order()->to_sql());
}

public function test_group_having_and_window(): void
{
    $q = Author::select('parent_author_id')->group_by('parent_author_id')->having('parent_author_id', '>', 1);
    $this->assert_sql_has('GROUP BY parent_author_id HAVING parent_author_id > ?', $q->to_sql());
    $this->assert_same([1], $q->get_bindings());
    $this->assert_same([3, 4], array_map(fn($a) => $a->author_id, Author::order_by('author_id')->for_page(2, 2)->get()));
    $this->assert_same([], Author::limit(0)->get());
    $this->assert_exception_message_contains('limit', fn() => Author::limit(-1), ActiveRecord\ActiveRecordException::class);
}
```

- [ ] **Step 2:** Run the file. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run it (PASS) and the golden suite (PASS).
- [ ] **Step 5: Commit:** `git commit -m "feat: builder ordering, grouping, having and limit/offset"`.

### Task 15: Read terminals

**Files:**
- Modify: `lib/Query.php`, `lib/QueryCompiler.php` (the pk helpers moved from `lib/Model.php:2440-2485`), `lib/Model.php` (`pk_finder_rows` calls the moved helpers), `lib/Exceptions.php`
- Test: `test/QueryReadTest.php`, the adapter files of Task 12

**Interfaces:**
- Produces:
  - `MultipleRecordsFound extends ActiveRecordException` in `lib/Exceptions.php`.
  - `QueryCompiler::pk_value_for_column(Table $table, string $name, mixed $value): mixed` and `QueryCompiler::keep_integer_pk_values(Table $table, string $name, mixed &$values): bool`, the former `Model::date_pk_finder_value()` / `keep_integer_pk_finder_values()`, moved unchanged. `Model::pk_finder_rows()` calls them.
  - `first(): ?Model`, `first_or_fail(): Model`, `first_or(callable $callback): mixed`, `sole(): Model`;
  - `find(mixed $id): Model|array|null` (`@return ($id is array ? list<TModel> : TModel|null)`), `find_or_fail(mixed $id)`, `find_many(array $ids): array`, `find_or(mixed $id, callable $callback)`;
  - `value(string $column): mixed`, `sole_value(string $column): mixed`, `pluck(string $column, ?string $key = null): array`;
  - `doesnt_exist(): bool`, `count(string $column = '*'): int`.
  - Each terminal is exactly as §5.3 defines it. `count()` wraps the query in `SELECT COUNT(*) FROM (…) AS t` when the state has `group_by`, `having`, `distinct`, `limit` or `offset`.
  - `find*` on a model whose pk has more than one column throws `ActiveRecordException('find() needs a single-column primary key; <Class> has a composite one')`. `sole()` with two rows throws `MultipleRecordsFound('sole() found more than one <Class>')`.
  - Add the forwardable names (`first_or_fail`, `first_or`, `sole`, `find_or_fail`, `find_many`, `find_or`, `value`, `sole_value`, `pluck`, `doesnt_exist`…) to `FORWARDABLE` and to the tags.

- [ ] **Step 1: Write the failing tests**

```php
public function test_first_sole_and_failures(): void
{
    $this->assert_same('Tito', Author::where('author_id', 1)->first()->name);
    $this->assert_exception_message_contains('', fn() => Author::where('author_id', 99)->first_or_fail(), ActiveRecord\RecordNotFound::class);
    $this->assert_same('none', Author::where('author_id', 99)->first_or(fn() => 'none'));
    $this->assert_exception_message_contains('', fn() => Author::where('author_id', '>', 1)->sole(), ActiveRecord\MultipleRecordsFound::class);
    $this->assert_same(1, Author::where('name', 'Tito')->sole()->author_id);
    $this->assert_same('Tito', Author::where('author_id', 1)->sole_value('name'));
}

public function test_find_forms(): void
{
    $this->assert_null(Author::new_query()->find(99));
    $this->assert_same([1, 2], array_map(fn($a) => $a->author_id, Author::new_query()->find([1, 2, 99])));
    $this->assert_same([2, 3], array_map(fn($a) => $a->author_id, Author::new_query()->find_many([2, 3])));
    $this->assert_same('missing', Author::new_query()->find_or(99, fn() => 'missing'));
    $this->assert_exception_message_contains("Couldn't find Author with ID=99", fn() => Author::new_query()->find_or_fail(99), ActiveRecord\RecordNotFound::class);
    $this->assert_exception_message_contains('composite', fn() => DatedCount::new_query()->find(1), ActiveRecord\ActiveRecordException::class);
}

public function test_value_pluck_exists_count(): void
{
    $this->assert_same(9223372036854775807, BigId::where('note', 'int max')->value('id'));
    $this->assert_same(['Tito', 'George W. Bush', 'Bill Clinton', 'Uncle Bob'], Author::order_by('author_id')->pluck('name'));
    $this->assert_same([1 => 'Tito', 2 => 'George W. Bush', 3 => 'Bill Clinton', 4 => 'Uncle Bob'], Author::order_by('author_id')->pluck('name', 'author_id'));
    $this->assert_true(Author::where('name', 'Tito')->exists());
    $this->assert_true(Author::where('name', 'Nobody')->doesnt_exist());
    $this->assert_same(4, Author::new_query()->count());
    $this->assert_same(3, Author::group_by('parent_author_id')->count());
    $this->assert_same(3, Author::offset(1)->count());
    $this->assert_same(2, Author::limit(2)->count());
    $this->assert_same(3, Author::distinct()->select('parent_author_id')->count());
    $this->assert_same(1, Author::count(['group' => 'parent_author_id'])); // legacy unchanged: first group's count
}

// Review Focus 2: pluck reaches the real column
public function test_pluck_maps_attribute_names_to_real_columns(): void
{
    OddColumn::create(['space_name' => 'v']);
    $this->assert_same(['v'], OddColumn::where('space_name', 'v')->pluck('space_name'));
}

// Review Focus 1: the builder does not run legacy finder overrides (spec §6.3)
public function test_builder_bypasses_legacy_finder_overrides(): void
{
    $this->assert_same(4, count(OverridingAuthor::new_query()->get()));
}
```

- [ ] **Step 2: Write the failing adapter tests**: in `Pgsql`, the integer-pk guard (#92); in `Mysql` and `Mariadb`, BIGINT UNSIGNED beyond `PHP_INT_MAX` (Review Focus 3).

```php
// Pgsql
public function test_find_non_integer_pk_never_queries(): void
{
    $statements = GoldenStatements::capture(fn() => $this->assert_null(Author::new_query()->find('abc')));
    $this->assert_same([], $statements);
}
// Mysql and Mariadb
public function test_bigint_unsigned_stays_exact(): void
{
    BigId::create(['id' => '18446744073709551615', 'note' => 'max']);
    $this->assert_same('18446744073709551615', BigId::new_query()->find('18446744073709551615')->id);
    $this->assert_same('18446744073709551615', BigId::where('note', 'max')->value('id'));
}
```

- [ ] **Step 3:** Run the files. Expected: FAIL.
- [ ] **Step 4:** Implement.
- [ ] **Step 5:** Run every test file of this task on its connection and the golden suite on all four. Expected: PASS.
- [ ] **Step 6: Commit:** `git commit -m "feat: builder read terminals; pk validation shared with the legacy finders"`.

### Task 16: Model options, utilities and debug

**Files:**
- Modify: `lib/Query.php`, `lib/QueryState.php`
- Test: `test/QueryOptionsTest.php`

**Interfaces:**
- Produces:
  - `with(string|array ...$relations)`: dot notation `'author.books'` becomes `['author' => ['books']]`, merged into the include tree exactly as the `include` option takes it (`QueryState::$includes`);
  - `without(string ...$relations)`, `with_only(string|array ...$relations)`, `readonly(bool $value = true)`;
  - `when(mixed $value, callable $callback, ?callable $default = null)`, `unless(...)`, `tap(callable $callback)`. All return `static`;
  - `clone(): static`, a deep copy including `JoinClause`s and nested groups;
  - `to_raw_sql(): string`, which inlines each bind with `Connection::escape()`;
  - their names added to `FORWARDABLE` and to the tags.

- [ ] **Step 1: Write the failing tests**

```php
public function test_with_dot_notation_without_and_eager_load(): void
{
    $this->assert_equals(['author' => ['books']], Book::with('author.books')->state()->includes);
    $this->assert_equals(['author' => []], Book::with('author.books')->without('author.books')->state()->includes);
    $author = Author::with('books')->find(1);
    $this->assert_same([], GoldenStatements::capture(fn() => $author->books)); // already loaded: no query
}

public function test_readonly_conditionals_clone_and_raw_sql(): void
{
    $this->assert_true(Author::readonly()->first()->is_readonly());
    $this->assert_sql_doesnt_has('WHERE', Author::when(false, fn($q) => $q->where('name', 'x'))->to_sql());
    $this->assert_sql_has('WHERE name = ?', Author::unless(false, fn($q) => $q->where('name', 'x'))->to_sql());
    $a = Author::where('name', 'Tito');
    $a->clone()->where('author_id', 1);
    $this->assert_sql_doesnt_has('author_id', $a->to_sql());
    $this->assert_sql_has('name = ' . Author::connection()->escape("O'Neil"), Author::where('name', "O'Neil")->to_raw_sql());
}
```

- [ ] **Step 2:** Run the file. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run it, the golden suite, and the full gate for PR D (four adapters, analyse, cs, PHP 8.5). Expected: PASS.
- [ ] **Step 5: Commit and open PR D:** `git commit -m "feat: builder with/without/readonly, conditionals, clone and raw SQL"`.

---

## PR E — Writes, aggregates, exact arithmetic

### Task 17: `ExactDecimal`

**Files:**
- Create: `lib/ExactDecimal.php`
- Modify: `lib/Validations.php` (remove `to_exact_decimal` / `exact_integer_digits` / `compare_exact_decimals`; call `ExactDecimal`), `ActiveRecord.php`
- Test: `test/ExactDecimalTest.php` (`SnakeCase_PHPUnit_Framework_TestCase`), and the unchanged `test/ValidatesNumericalityOfTest.php`

**Interfaces:**
- Produces, `final class ExactDecimal` (static, `@internal`):
  - `parse(mixed $value): ?array` (`array{bool, string, string}`);
  - `integer_digits(?array $decimal): ?string`;
  - `compare(array $a, array $b): int`. These three are #175's helpers, moved unchanged.
  - `to_int_or_string(int|string $value): int|string`: an `int` when the exact value fits `[PHP_INT_MIN, PHP_INT_MAX]`, else the normalized digits.
  - `divide(int|string $dividend, int $divisor, int $scale): string`: exact long division, rounded half-up (away from zero) at `$scale`. The divisor is > 0; anything else throws `ActiveRecordException`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_divide_rounds_half_up_at_the_scale(): void
{
    $this->assert_same('1.5000', ExactDecimal::divide('3', 2, 4));
    $this->assert_same('1.6667', ExactDecimal::divide('5', 3, 4));
    $this->assert_same('-1.6667', ExactDecimal::divide('-5', 3, 4));
    $this->assert_same('617283945061.730000', ExactDecimal::divide('1234567890123.46', 2, 6));
    $this->assert_same('4611686018427387850.0000', ExactDecimal::divide('9223372036854775700', 2, 4));
    $this->assert_same('9223372036854775807.5000', ExactDecimal::divide('18446744073709551615', 2, 4));
    $this->assert_same('0.0000', ExactDecimal::divide('0', 5, 4));
    $this->assert_same('1', ExactDecimal::divide('2', 3, 0));
}

public function test_to_int_or_string_keeps_64_bit_exact(): void
{
    $this->assert_same(PHP_INT_MAX, ExactDecimal::to_int_or_string('9223372036854775807'));
    $this->assert_same('9223372036854775808', ExactDecimal::to_int_or_string('9223372036854775808'));
    $this->assert_same(PHP_INT_MIN, ExactDecimal::to_int_or_string('-9223372036854775808'));
    $this->assert_same(90, ExactDecimal::to_int_or_string('0090'));
}

public function test_compare_matches_the_175_semantics(): void
{
    $this->assert_same(-1, ExactDecimal::compare(ExactDecimal::parse('9007199254740992'), ExactDecimal::parse('9007199254740993')));
    $this->assert_same(0, ExactDecimal::compare(ExactDecimal::parse('+10.50'), ExactDecimal::parse('10.5')));
    $this->assert_null(ExactDecimal::parse('1e1'));
}
```

- [ ] **Step 2:** Run `docker compose exec tests vendor/bin/phpunit test/ExactDecimalTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement. Move the helpers out of `Validations`, which now calls `ExactDecimal`.
- [ ] **Step 4:** Run `ExactDecimalTest` and `ValidatesNumericalityOfTest` on all four connections. Expected: PASS, with #175's tests unchanged.
- [ ] **Step 5: Commit:** `git commit -m "refactor: one exact-decimal helper shared by Validations and the engine (#175)"`.

### Task 18: Aggregates

**Files:**
- Modify: `test/sql/mysql.sql`, `test/sql/pgsql.sql`, `test/sql/sqlite.sql`. Add `measurements`: `id` auto-increment pk, `qty INT`, `big BIGINT`, `amount DECIMAL(20,2)`, `ratio DOUBLE` (`DOUBLE PRECISION` on Postgres), `label VARCHAR(20)`.
- Create: `test/fixtures/measurements.csv`, with three rows: `(1, 1, 4611686018427387904, 1234567890123.45, 0.1, 'a')`, `(2, 2, 4611686018427387904, 0.01, 0.2, 'b')`, `(3, 2, NULL, NULL, NULL, 'c')`.
- Create: `test/models/Measurement.php`
- Modify: `lib/Query.php`, `lib/QueryCompiler.php`
- Test: `test/QueryAggregateTest.php`, the adapter files

**Interfaces:**
- Produces:
  - `min(string $column): int|string|float|null`, `max(...)`, `sum(string $column): int|string|float`, `avg(string $column): string|float|null`, `average(...)` (an alias of `avg`).
  - The rules are the §5.4 table. The column is classified by `Column::$type` and `Column::$raw_type`: `decimal` / `numeric` / `dec` are exact; `float` / `double` / `real` / `double precision` are floating. First verify and record each adapter's `raw_type` strings (§10) in a test that asserts them.
  - The exact `avg` runs `SELECT SUM(col), COUNT(col)` and returns `ExactDecimal::divide($sum, $count, <SUM's fraction digits> + 4)`.

- [ ] **Step 1: Write the failing cross-adapter tests**

```php
public function test_raw_types_classify_exact_and_floating(): void
{
    $columns = Measurement::table()->columns;
    $this->assert_true(in_array($columns['amount']->raw_type, ['decimal', 'numeric'], true));
    $this->assert_true(in_array($columns['ratio']->raw_type, ['double', 'double precision', 'float', 'real'], true));
}

public function test_integer_aggregates(): void
{
    $this->assert_same(5, Measurement::sum('qty'));
    $this->assert_same('1.6667', Measurement::avg('qty'));
    $this->assert_same(1, Measurement::min('qty'));
    $this->assert_same(2, Measurement::max('qty'));
}

public function test_empty_sets(): void
{
    $none = fn() => Measurement::where('label', 'zzz');
    $this->assert_same(0, $none()->sum('qty'));
    $this->assert_null($none()->avg('qty'));
    $this->assert_null($none()->min('qty'));
    $this->assert_same(0.0, $none()->sum('ratio'));
}

public function test_floating_aggregates_are_floats(): void
{
    $this->assert_true(is_float(Measurement::sum('ratio')));
    $this->assert_true(is_float(Measurement::avg('ratio')));
}

public function test_non_model_column_returns_the_driver_value(): void
{
    $this->assert_equals(1, Book::joins('author')->min('authors.author_id'));
}
```

- [ ] **Step 2: Write the failing adapter tests**

```php
// Mysql, Mariadb, Pgsql: exact decimals, and a BIGINT sum beyond PHP_INT_MAX
public function test_exact_decimal_and_big_sums(): void
{
    $this->assert_same('1234567890123.46', Measurement::sum('amount'));
    $this->assert_same('617283945061.730000', Measurement::avg('amount'));
    $this->assert_same('9223372036854775808', Measurement::sum('big'));
    $this->assert_same('0', Measurement::where('label', 'zzz')->sum('amount'));
}
// Mysql, Mariadb only (Review Focus 3)
public function test_max_of_unsigned_bigint_beyond_int_max(): void
{
    BigId::create(['id' => '18446744073709551615', 'note' => 'max']);
    $this->assert_same('18446744073709551615', BigId::max('id'));
}
// Sqlite: the documented limits
public function test_sqlite_decimal_is_floating_and_big_sum_overflows(): void
{
    $this->assert_true(is_float(Measurement::sum('amount')));
    $this->assert_exception_message_contains('overflow', fn() => Measurement::sum('big'), ActiveRecord\DatabaseException::class);
}
```

- [ ] **Step 3:** Run the files. Expected: FAIL.
- [ ] **Step 4:** Implement.
- [ ] **Step 5:** Run every aggregate test on its connection and the golden suite on all four (PASS).
- [ ] **Step 6: Commit:** `git commit -m "feat: builder aggregates normalized by column type, exact avg"`.

### Task 19: Public write terminals

**Files:**
- Modify: `lib/Query.php`, `lib/QueryCompiler.php`
- Test: `test/QueryWriteTest.php`

**Interfaces:**
- Consumes: `execute_insert` (Task 7), `execute_update` / `execute_delete` (Task 8), `upsert` (Task 9), `SQLBuilder::insert_rows` (Task 7).
- Produces:
  - `insert(array $values): bool`: one hash, or a list of hashes with identical keys (else `ActiveRecordException('insert() needs every row to have the same keys')`). A list becomes multi-VALUES chunks split by `Connection::$MAX_BIND_PARAMS`, inside a transaction when there is more than one chunk and no transaction is already open. Each row goes through `Timestamps::apply_to_insert()`.
  - `update(array $values): int`: empty values throw `ActiveRecordException('Updating requires a hash or string.')`.
  - `delete(): int`.
  - A write whose state has joins, groups, havings, an offset, distinct, a select or includes throws `ActiveRecordException("<method>() cannot use <clause>")`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_update_and_delete(): void
{
    ActiveRecord\Timestamps::freeze('2026-10-21 10:00:00');
    $this->assert_same(1, Author::where('author_id', 1)->update(['name' => 'X']));
    $this->assert_equals('2026-10-21 10:00:00', Author::find(1)->updated_at->format('Y-m-d H:i:s'));
    $this->assert_same(1, Author::where('author_id', 4)->delete());
    $this->assert_same(1, Author::where('author_id', '>', 1)->order_by('author_id')->limit(1)->update(['name' => 'L']));
    ActiveRecord\Timestamps::freeze(null);
}

public function test_write_refusals(): void
{
    $this->assert_exception_message_contains('Updating requires a hash or string.', fn() => Author::where('author_id', 1)->update([]), ActiveRecord\ActiveRecordException::class);
    $this->assert_exception_message_contains('cannot use joins', fn() => Book::joins('author')->delete(), ActiveRecord\ActiveRecordException::class);
    $this->assert_exception_message_contains('cannot use offset', fn() => Author::offset(1)->update(['name' => 'x']), ActiveRecord\ActiveRecordException::class);
}

public function test_insert_one_and_many_with_timestamps(): void
{
    ActiveRecord\Timestamps::freeze('2026-10-21 10:00:00');
    $this->assert_true(Author::new_query()->insert([['name' => 'A'], ['name' => 'B']]));
    $this->assert_same(2, Author::where('created_at', '2026-10-21 10:00:00')->count());
    $this->assert_exception_message_contains('same keys', fn() => Author::new_query()->insert([['name' => 'A'], ['parent_author_id' => 1]]), ActiveRecord\ActiveRecordException::class);
    Author::without_timestamps(fn() => Author::new_query()->insert(['name' => 'C']));
    $this->assert_null(Author::find_by_name('C')->created_at);
    ActiveRecord\Timestamps::freeze(null);
}
```

- [ ] **Step 2:** Run `docker compose exec tests vendor/bin/phpunit test/QueryWriteTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the file on all four connections (Postgres covers the limited UPDATE subquery), the golden suite, and the full gate for PR E. Expected: PASS.
- [ ] **Step 5: Commit and open PR E:** `git commit -m "feat: builder insert/update/delete with write-clause validation"`.

---

## PR F — Documentation

### Task 20: README, example, release-notes text

**Files:**
- Modify: `README.md` (a new `## Query builder` section after `### Hash conditions`), `examples/README.md` (one table row)
- Create: `examples/query_builder/query_builder.php`, `examples/query_builder/query_builder.sql`, `examples/query_builder/models/*.php`, following `examples/upsert/`'s layout (SQLite database created on the fly)

**Interfaces:**
- Consumes: the whole public API.

- [ ] **Step 1: README section**:
  - entry points (`new_query()`, static forwarding, the refused static `update` / `delete`);
  - the Eloquent → snake_case mapping table;
  - **the deviations from Eloquent**, the eight bullets of spec §8.2;
  - the "legacy vs builder" table for `find`, `first`, `count`, `exists`, `all`, `update_all` / `update`, `delete_all` / `delete`, `create` / `insert`, `upsert`, `query`;
  - `without_timestamps()`;
  - the documented SQLite limits of §5.4.
- [ ] **Step 2: Example.** It demonstrates the where family, joins / `left_joins`, `with`, the read terminals, aggregates (exact `avg`), the writes with timestamps and `without_timestamps()`. It must be typed for PHPStan, e.g. `foreach (Book::where('name', 'x')->get() as $book) { echo $book->name; }`.
- [ ] **Step 3: Run the example** with `docker compose exec tests php examples/query_builder/query_builder.php`. Expected: it prints every demonstrated value with no error. Then run `composer run analyse` and `composer run cs` (both PASS; `examples/` is analysed).
- [ ] **Step 4: Final benchmark.** Re-run Task 2's script on master and on the branch; keep the numbers for the PR body.
- [ ] **Step 5: Release notes and monolith notes.** Write §8.3's items and §8.4's monolith notes in the PR body, ready to paste. At merge, add them to the draft release with `gh release edit <draft tag> --notes-file …`, under Breaking / Features / Fixes with `(#issue / #PR)` refs.
- [ ] **Step 6: Commit and open PR F:** `git commit -m "docs: query builder README section and runnable example"`.
