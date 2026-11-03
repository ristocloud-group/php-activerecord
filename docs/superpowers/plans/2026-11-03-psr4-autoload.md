# PSR-4 Autoload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Load every `ActiveRecord\` class through Composer's PSR-4 autoloader, on first use, with one class per file. This removes the `ActiveRecord.php` manifest, its version constant and every `require` in `lib/` (issue #190, release 3.0.0).

**Architecture:**
- `composer.json` maps `ActiveRecord\` to six base dirs: `lib/`, `lib/adapters/`, `lib/cache/`, `lib/exceptions/`, `lib/relationships/` and `lib/serializers/`. `lib/Utils.php` stays in `files`.
- The five multi-class files are split.
- Adapters and cache backends resolve through two `@internal` native enums.
- A token-based `LibraryLoadingTest` guards the layout. It starts with allowlists of today's violations, and each task empties part of them.

**Tech Stack:** PHP 8.3–8.5, Composer 2.10, PHPUnit (snake_case wrapper), PHPStan level 8, php-cs-fixer (PER-CS 3.0), Docker compose `tests` service.

**Spec:** `docs/superpowers/specs/2026-11-03-psr4-autoload-design.md`, approved on 2026-11-03. Read it before any task: §4.3 (layout table), §4.4 (enum code), §5.1 (approved behavior changes).

## Global Constraints

- **Branch:** `feat/190-psr4-autoload`, cut from `spec/190-psr4-autoload` (master 86aa21b plus the spec and this plan), so the docs ship in the same PR.
- **Classes are moved byte for byte.** No class is renamed and no code inside a class changes, except the `require` lines removed and the two resolutions of Task 6. Each new file repeats the source file's `<?php`, its `/** @package ActiveRecord */` header, `namespace ActiveRecord;`, and only the `use` imports its class needs.
- **One `git mv` per split,** to the class named in spec §4.3 (`ActiveRecordException`, `AbstractRelationship`, `Serialization`). The other classes of the split go into new files.
- **Modern PHP ≥ 8.3 and snake_case for new code.** Short arrays, typed signatures, native enums.
- **The PHPStan baseline is frozen.** Only the `path:` of existing entries may be re-pointed, with message and count unchanged. If PHPStan wants a count split across two files or a new entry, **stop and ask the maintainer**.
- **Skipped tests fail the build.** Nothing may skip.
- **After any `composer.json` `autoload` edit, run `docker compose exec -T tests composer dump-autoload`.** `vendor/` is mounted from the host, so CI regenerates it on its own but the local container does not.
- **Before each full suite run `: > test/log/query.log`.** At each task close, purge the test MySQL's binlogs: `docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "FLUSH BINARY LOGS; PURGE BINARY LOGS BEFORE NOW();"'`.
- **Commands:**
  - one test: `docker compose exec -T tests vendor/bin/phpunit --filter <name>`
  - suite: `docker compose exec -T -e COMPOSER_PROCESS_TIMEOUT=0 [-e PHPAR_CONNECTION=<mysql|mariadb|pgsql|sqlite>] tests composer run test`
  - `docker compose exec -T tests composer run analyse`
  - `docker compose exec -T tests composer run cs`, and `cs-fix` to apply it.
- **Each task ends with the full suite (MySQL), `analyse` and `cs` green,** and one commit whose message ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Protocol casing.** `Mysql://` resolves the MySQL adapter, while `MYSQL://` throws "ActiveRecord\MYSQLAdapter not found!". Today the case-insensitive bind mount on macOS lets `MYSQL://` load the file locally, and production on Linux refuses it; the enum makes every environment behave like production. Task 6.
2. **`unserialize()` in a fresh process.** A library object serialized into a cache or session (`ActiveRecord\DateTime`) must come back in a fresh process that has loaded nothing but `vendor/autoload.php`. Task 7.
3. **A static property of a class not yet loaded.** Reading `Serialization::$DATETIME_FORMAT` before any `to_array()` must autoload the class; the monolith does this in `Fornitori_ordini`. Task 7.
4. **Catching and type-checking in a fresh process.** `catch (ActiveRecord\RecordNotFound $e)` catches what `throw` raises, and `$x instanceof ActiveRecord\BelongsTo` answers `false` without an error, both in a fresh process. Task 7.
5. **A consumer's optimized classmap.** `composer dump-autoload --optimize --strict-psr` exits 0 with 62 `ActiveRecord\` entries, and the suite passes on that classmap. The monolith sets `optimize-autoloader: true`. Task 8.

---

### Task 1: PSR-4 autoload and the layout guards, with allowlists

**Files:**
- Modify: `composer.json` (`autoload`)
- Modify: `test/LibraryLoadingTest.php`

**Interfaces:**
- Produces, in `LibraryLoadingTest`:
  - `private const array BASE_DIRS = ['', 'adapters', 'cache', 'exceptions', 'relationships', 'serializers'];` (dirs relative to `lib/`, `''` being `lib/` itself);
  - `private const array MULTI_CLASS_FILES = ['Exceptions.php', 'Inflector.php', 'Relationship.php', 'Serialization.php', 'Validations.php'];`
  - `private const array REQUIRING_FILES` holds today's 8 files that contain `require`/`include`: `Cache.php`, `ConditionCompiler.php`, `Conditions.php`, `Connection.php`, `Model.php`, `Relationship.php`, `SQLBuilder.php`, `Table.php`.

  Later tasks remove entries from both lists, and Task 7 deletes them.
- Produces the helper `private function declarations(string $code): array{namespace: ?string, classes: list<string>, functions: list<string>}`. It is token-based: `class`/`interface`/`trait`/`enum` declarations are counted, while `::class` and `new class` are not, and only functions declared at brace depth 0 are counted.

- [ ] **Step 1: Add PSR-4 to `composer.json`, keeping `files: ["ActiveRecord.php"]`**

```json
"autoload": {
    "psr-4": { "ActiveRecord\\": ["lib/", "lib/adapters/", "lib/cache/", "lib/exceptions/", "lib/relationships/", "lib/serializers/"] },
    "files": ["ActiveRecord.php"]
}
```

`lib/exceptions/`, `lib/relationships/` and `lib/serializers/` do not exist yet, and Composer accepts a missing base dir. Run `composer dump-autoload`.

- [ ] **Step 2: Write the guard tests**

Keep the two existing tests. Add:

```php
public function test_the_psr4_base_dirs_of_composer_json_are_the_ones_the_guards_read(): void
// composer.json autoload.psr-4["ActiveRecord\\"] === ['lib/', 'lib/adapters/', 'lib/cache/', 'lib/exceptions/', 'lib/relationships/', 'lib/serializers/']

public function test_every_library_file_sits_in_a_psr4_base_dir(): void
// every *.php under lib/: its dir relative to lib/ is in BASE_DIRS

public function test_no_file_name_repeats_across_the_base_dirs(): void
// basenames of all lib/ *.php are unique

public function test_every_library_file_declares_one_class_named_after_it(): void
// for each file not in MULTI_CLASS_FILES: namespace === 'ActiveRecord', classes === [basename without .php];
// functions === [] except for Utils.php
// and for each file in MULTI_CLASS_FILES: it still holds more than one class (an allowlist entry that no longer applies fails)

public function test_no_library_file_requires_another(): void
// files with any T_REQUIRE/T_REQUIRE_ONCE/T_INCLUDE/T_INCLUDE_ONCE === REQUIRING_FILES (sorted, paths relative to lib/), both ways

public function test_declarations_reads_classes_functions_and_namespace_from_tokens(): void
// declarations("<?php\nnamespace ActiveRecord;\n// class Commented {}\nclass A { function m() { return B::class; } }\n$x = new class {};\nfunction f() {}\n")
//   === ['namespace' => 'ActiveRecord', 'classes' => ['A'], 'functions' => ['f']]
```

The file-name checks compare paths relative to `lib/`, for example `relationships/AbstractRelationship.php`.

- [ ] **Step 3: Run `--filter LibraryLoadingTest`**

Expected: PASS. The allowlists describe today's tree exactly. Then check that the guards really fail:
- temporarily remove `'Inflector.php'` from `MULTI_CLASS_FILES`: the one-class test FAILS naming `Inflector.php`;
- temporarily remove `'Table.php'` from `REQUIRING_FILES`: the requires test FAILS naming `Table.php`.

Restore both entries.

- [ ] **Step 4: Run the full suite, `analyse` and `cs`. Commit**

`test: guard the PSR-4 layout of lib/ and autoload ActiveRecord\ through Composer (#190)`

### Task 2: Split the exceptions

**Files:**
- Move: `lib/Exceptions.php` → `lib/exceptions/ActiveRecordException.php` (`git mv`)
- Create: one file per class in `lib/exceptions/`: `RecordNotFound`, `MultipleRecordsFound`, `DatabaseException`, `ModelException`, `ExpressionsException`, `ConfigException`, `CacheException`, `UndefinedPropertyException`, `MassAssignmentException`, `ReadOnlyException`, `ValidationsArgumentError`, `RelationshipException`, `HasManyThroughAssociationException`
- Modify: `ActiveRecord.php`, `test/LibraryLoadingTest.php`

**Interfaces:**
- Consumes: Task 1's `MULTI_CLASS_FILES`.

- [ ] **Step 1: Remove `'Exceptions.php'` from `MULTI_CLASS_FILES`, and run `--filter LibraryLoadingTest`**

Expected: FAIL. `lib/Exceptions.php` declares 14 classes.

- [ ] **Step 2: Split the file**

Each class moves with its own docblock, byte for byte. In `ActiveRecord.php`, replace `require __DIR__ . '/lib/Exceptions.php';` with:

```php
foreach (glob(__DIR__ . '/lib/exceptions/*.php') as $file) {
    require_once $file;
}
```

The 14 exceptions therefore stay declared up front until Task 7. It has to be `require_once`: an exception whose parent sorts later (`HasManyThroughAssociationException` before `RelationshipException`) autoloads that parent, and a plain `require` of the parent's file would then redeclare it.

- [ ] **Step 3: Run `--filter LibraryLoadingTest`, then the full suite, `analyse` and `cs`**

Expected: PASS. `git log --follow --oneline lib/exceptions/ActiveRecordException.php` lists the history of `lib/Exceptions.php`.

- [ ] **Step 4: Commit**

`refactor: one exception class per file in lib/exceptions/ (#190)`

### Task 3: Split the relationships

**Files:**
- Move: `lib/Relationship.php` → `lib/relationships/AbstractRelationship.php` (`git mv`)
- Create: `lib/relationships/{InterfaceRelationship,HasMany,HasOne,HasAndBelongsToMany,BelongsTo}.php`
- Modify:
  - `lib/Table.php`, removing the `require_once __DIR__ . '/Relationship.php';` in `set_associations()`;
  - `test/RelationshipTest.php`, removing its `require_once` and the comment about the lazy require above it;
  - `test/RelationshipOptionsWiringTest.php`, removing its `require_once`;
  - `phpstan-baseline.neon`;
  - `test/LibraryLoadingTest.php`.

**Interfaces:**
- Consumes: Task 1's allowlists.

- [ ] **Step 1: Update the allowlists, and run `--filter LibraryLoadingTest`**

Remove `'Relationship.php'` from `MULTI_CLASS_FILES`. In `REQUIRING_FILES`:
- replace `'Relationship.php'` with `'relationships/AbstractRelationship.php'`, which keeps its `Expressions` require until Task 7;
- remove `'Table.php'`.

Expected: FAIL. `Relationship.php` is a multi-class file, and the listed requiring files no longer match the tree.

- [ ] **Step 2: Split the file and remove the requires listed above**

`AbstractRelationship::to_positional_conditions()` keeps its `require_once __DIR__ . '/Expressions.php'`. Fix that path to `__DIR__ . '/../Expressions.php'`, because the file moved.

- [ ] **Step 3: Re-point the baseline**

In `phpstan-baseline.neon`, the entry whose message is `Access to an undefined property ActiveRecord\\AbstractRelationship\:\:\$primary_key` with `count: 3` changes `path: lib/Relationship.php` to `path: lib/relationships/AbstractRelationship.php`. Run `analyse`.

Expected: `[OK] No errors`. If PHPStan reports the count split across files, **stop and ask** (Global Constraints).

- [ ] **Step 4: Run `--filter LibraryLoadingTest`, then the full suite (MySQL and SQLite) and `cs`**

Expected: PASS.

- [ ] **Step 5: Commit**

`refactor: one relationship class per file in lib/relationships/ (#190)`

### Task 4: Split the serializers

**Files:**
- Move: `lib/Serialization.php` → `lib/serializers/Serialization.php` (`git mv`)
- Create: `lib/serializers/{ArraySerializer,JsonSerializer,XmlSerializer,CsvSerializer}.php`. Only `XmlSerializer` needs `use XmlWriter;`.
- Modify:
  - `lib/Model.php`, removing `require_once __DIR__ . '/Serialization.php';` in `serialize()`;
  - `test/SerializationTest.php`, removing its `require_once`;
  - `test/LibraryLoadingTest.php`.

- [ ] **Step 1: Remove `'Serialization.php'` from `MULTI_CLASS_FILES`, and run `--filter LibraryLoadingTest`**

Expected: FAIL.

- [ ] **Step 2: Split the file and remove the two requires**

`Model.php` keeps its two `Validations` requires until Task 5, so it stays in `REQUIRING_FILES`.

- [ ] **Step 3: Run `--filter LibraryLoadingTest`, then the full suite, `analyse` and `cs`**

Expected: PASS. Then run `docker compose exec -T tests php examples/serialization/serialization.php`. Expected: the same output as on master.

- [ ] **Step 4: Commit**

`refactor: one serializer class per file in lib/serializers/ (#190)`

### Task 5: Split `Inflector` and `Validations`

**Files:**
- Create: `lib/StandardInflector.php`, with the class moved out of `lib/Inflector.php`.
- Create: `lib/Errors.php`, with the class moved out of `lib/Validations.php`. It keeps `use IteratorAggregate;` and `use ArrayIterator;` if it uses them; `Validations.php` keeps the imports it needs.
- Modify:
  - `ActiveRecord.php`, adding `require __DIR__ . '/lib/StandardInflector.php';` right after the `Inflector.php` line;
  - `lib/Model.php`, removing both `require_once __DIR__ . '/Validations.php';` lines;
  - `test/InflectorTest.php`, removing its `require_once`;
  - `test/LibraryLoadingTest.php`;
  - `phpstan-baseline.neon`, only if needed.

- [ ] **Step 1: Update the allowlists, and run `--filter LibraryLoadingTest`**

Remove `'Inflector.php'` and `'Validations.php'` from `MULTI_CLASS_FILES`, and `'Model.php'` from `REQUIRING_FILES`.

Expected: FAIL.

- [ ] **Step 2: Split both files and remove the requires**

- [ ] **Step 3: Run `analyse`**

The `Call to function is_null\(\) with string will always evaluate to false\.` entry keeps `path: lib/Validations.php` if PHPStan reports it there. If PHPStan reports it in `lib/Errors.php`, re-point the path. Any other outcome: **stop and ask**.

Expected: `[OK] No errors`.

- [ ] **Step 4: Run `--filter LibraryLoadingTest`, then the full suite and `cs`**

Expected: PASS. `MULTI_CLASS_FILES` is now `[]`.

- [ ] **Step 5: Commit**

`refactor: StandardInflector and Errors in their own files (#190)`

### Task 6: Resolve adapters and cache backends through native enums

**Files:**
- Create: `lib/adapters/ConnectionAdapter.php` and `lib/cache/CacheBackend.php`, as in spec §4.4.
- Modify:
  - `lib/Connection.php`, rewriting `load_adapter_class()`;
  - `lib/Cache.php`, rewriting the resolution in `initialize()`.
- Modify tests:
  - `test/ConnectionTest.php` and `test/CacheTest.php`, which get the new tests;
  - `test/LibraryLoadingTest.php`, removing `'Cache.php'` from `REQUIRING_FILES`. `Connection.php` keeps its top-level `Column` require until Task 7.
- Modify (removing the `require_once` of a `lib/` file): `test/MysqlAdapterTest.php`, `test/PgsqlAdapterTest.php`, `test/SqliteAdapterTest.php`, `test/ConnectionTransactionTest.php`, `test/FileCacheTest.php`, `test/RedisCacheTest.php`.
- Modify: `test/helpers/file_cache_atomicity_writer.php`, which is a standalone process: `require __DIR__ . '/../../vendor/autoload.php';` replaces the `require` of `lib/cache/File.php`.

**Interfaces:**
- Produces:
  - `enum ConnectionAdapter: string` with the cases `Mysql = 'MysqlAdapter'`, `Pgsql = 'PgsqlAdapter'` and `Sqlite = 'SqliteAdapter'`, and `public function class_name(): string`, which returns `class-string<Connection>`;
  - `enum CacheBackend: string` with the cases `File = 'File'`, `Memcache = 'Memcache'` and `Redis = 'Redis'`, and `public function class_name(): string`.

  Both are `@internal`.

- [ ] **Step 1: Write the failing tests**

```php
// ConnectionTest
public function test_every_shipped_adapter_has_a_case_and_every_case_a_connection_class(): void
// sorted array_column(ActiveRecord\ConnectionAdapter::cases(), 'value')
//   === sorted basenames (no .php) of lib/adapters/*Adapter.php, minus 'ConnectionAdapter';
// for each case: is_subclass_of($case->class_name(), ActiveRecord\Connection::class)

public function test_an_unknown_protocol_names_the_adapter_it_did_not_find(): void
// Connection::instance('foo://test:test@127.0.0.1/db') throws DatabaseException, message 'ActiveRecord\FooAdapter not found!'

public function test_a_protocol_resolves_with_its_first_letter_in_either_case_only(): void
// with $url = getenv('PHPAR_MYSQL'), whose MySQL server is up on every connection the suite runs:
// Connection::instance('Mysql' . substr($url, 5)) is an ActiveRecord\MysqlAdapter;
// Connection::instance('MYSQL' . substr($url, 5)) throws DatabaseException 'ActiveRecord\MYSQLAdapter not found!'.
// Not SQLite: parse_connection_url() allows the blank database of a sqlite URL only for the exact protocol 'sqlite'

// CacheTest
public function test_every_shipped_cache_backend_has_a_case(): void
// sorted CacheBackend values === sorted basenames of lib/cache/*.php, minus 'CacheBackend'

public function test_an_unknown_cache_scheme_throws_a_cache_exception(): void
// Cache::initialize('foo://localhost') throws ActiveRecord\CacheException, message "Unsupported cache scheme 'foo': use file, memcache or redis"
```

- [ ] **Step 2: Run `--filter 'ConnectionTest|CacheTest'`**

Expected: FAIL. The enum classes don't exist yet, and `foo://` dies with a fatal `require_once` error. Run the unknown-scheme test on its own, so the fatal does not hide the others.

- [ ] **Step 3: Implement**

- **Both enums:** spec §4.4. `class_name()` returns `__NAMESPACE__ . '\\' . $this->value`.
- **`Connection::load_adapter_class()`:** the `oci` check stays first. `ConnectionAdapter::tryFrom(ucwords($adapter) . 'Adapter')` resolves, and a `null` result throws `new DatabaseException("ActiveRecord\\{$class} not found!")`. Drop `file_exists()` and `require_once`.
- **`Cache::initialize()`:** `CacheBackend::tryFrom(ucwords(Inflector::instance()->camelize($parsed_url['scheme'])))` resolves, and a `null` result throws `new CacheException("Unsupported cache scheme '{$parsed_url['scheme']}': use file, memcache or redis")`. Then `new ($backend->class_name())($parsed_url, $options)`. Keep the `@var File|Memcache|Redis` annotation on the result.

- [ ] **Step 4: Run the new tests, `--filter LibraryLoadingTest`, the full suite on MySQL and SQLite, `analyse` and `cs`**

Expected: PASS.

- [ ] **Step 5: Commit**

`refactor: resolve adapters and cache backends through native enums; an unknown cache scheme throws CacheException (#190)`

### Task 7: Lazy loading. Remove `ActiveRecord.php` and every `require` in `lib/`

**Files:**
- Delete: `ActiveRecord.php`.
- Modify: `composer.json`, with `"files": ["lib/Utils.php"]`, then run `composer dump-autoload`.
- Modify `lib/`, removing every remaining `require`/`require_once`:
  - `SQLBuilder.php` (×3, `Expressions`);
  - `relationships/AbstractRelationship.php` (`Expressions`);
  - `Conditions.php`;
  - `ConditionCompiler.php`;
  - the top of `Connection.php` (`Column`).
- Modify (dropping `require_once ... ActiveRecord.php`):
  - `test/helpers/config.php`;
  - `test/benchmarks/hot_paths.php`;
  - the 14 examples that `grep -rln "ActiveRecord.php" examples` lists.

  Each keeps its `vendor/autoload.php` require. `test/ExpressionsTest.php` drops its `require_once` of `lib/Expressions.php`.
- Modify: `phpstan.neon`, removing the `bootstrapFiles:` block.
- Modify: `test/LibraryLoadingTest.php`.
- Modify: `docs/superpowers/specs/2026-11-03-psr4-autoload-design.md` §6.2, changing "the five examples" to "the 14 examples".

**Interfaces:**
- Produces `private function run_in_fresh_process(string $code): string` in `LibraryLoadingTest`:
  - it starts `[PHP_BINARY, '-d', 'pcov.enabled=0']` through `proc_open`;
  - it writes `<?php require '<repo>/vendor/autoload.php'; ` followed by `$code` to stdin;
  - it asserts exit code 0 and an empty stderr, and returns stdout.

- [ ] **Step 1: Write the failing tests in `LibraryLoadingTest`**

```php
public function test_the_autoloader_declares_only_utils_up_front(): void
// json_decode(run_in_fresh_process('echo json_encode(array_values(array_filter([...get_declared_classes(), ...get_declared_interfaces()],
//   fn($c) => str_starts_with($c, "ActiveRecord\\\\"))));')) === ['ActiveRecord\Utils']

public function test_every_library_class_autoloads_from_its_own_file(): void
// for each lib/ file and its declared class (declarations()): class_exists/interface_exists/enum_exists('ActiveRecord\\' . $class) is true,
// and realpath((new ReflectionClass(...))->getFileName()) === realpath($file)

public function test_library_objects_and_statics_work_in_a_fresh_process(): void
// run_in_fresh_process(...) prints 'ok' after each of:
//   unserialize(serialize(new ActiveRecord\DateTime('2026-01-02 03:04:05'))) instanceof ActiveRecord\DateTime;
//   ActiveRecord\Serialization::$DATETIME_FORMAT !== '';
//   try { throw new ActiveRecord\RecordNotFound('x'); } catch (ActiveRecord\RecordNotFound $e) { ok };
//   (new stdClass()) instanceof ActiveRecord\BelongsTo === false
```

Also delete `MULTI_CLASS_FILES` (empty since Task 5) and its branch of the one-class test, and replace `REQUIRING_FILES` with the assertion that no file in `lib/` contains a `require` or `include`. The old `test_the_library_files_load_one_another_by_an_absolute_path` is now covered and goes; keep `literal_includes`' own test only if a remaining test uses the helper, otherwise delete both.

- [ ] **Step 2: Run `--filter LibraryLoadingTest`**

Expected: FAIL. 40 classes are declared up front, and the lib files still hold requires.

- [ ] **Step 3: Apply the Files list above, then run `composer dump-autoload`**

- [ ] **Step 4: Run `--filter LibraryLoadingTest`, then the full suite on all four connections, `analyse` and `cs`**

Expected: PASS. Then run `docker compose exec -T tests php examples/simple/simple.php` and `docker compose exec -T tests php examples/query_builder/query_builder.php`: the output is the same as on master. Last, check that nothing refers to the deleted file any more:

```sh
grep -rn "ActiveRecord.php" lib test examples composer.json phpstan.neon .github
```

Expected: no output.

- [ ] **Step 5: Commit**

`feat!: autoload every class on first use; remove ActiveRecord.php and PHP_ACTIVERECORD_VERSION_ID (#190)`

### Task 8: Documentation and final verification

**Files:**
- Modify: `README.md`, Installation section (around line 61).
- Modify: `CLAUDE.md`, the Architecture paragraph (lines 66–69) and the "Working in the repo" gotchas.

- [ ] **Step 1: Update `README.md`**

After the `composer.json` block of the Installation section, add a short paragraph saying that the library is loaded only through Composer's autoloader (`require 'vendor/autoload.php';`) and has no other entry point. It also says that the installed version is `\Composer\InstalledVersions::getPrettyVersion('ristocloud-group/php-activerecord')`.

- [ ] **Step 2: Update `CLAUDE.md`**

Replace the Architecture lines that describe the manual `require` manifest with a paragraph that says:
- the library loads through Composer PSR-4, `ActiveRecord\` mapped to the six base dirs, one class per file, with `LibraryLoadingTest` as the guard;
- a new class only needs a file named after it in the right base dir;
- a new adapter or cache backend also needs a case in `ConnectionAdapter` / `CacheBackend`;
- `lib/Utils.php` (the functions) is the only file in `autoload.files`.

Add a gotcha under "Working in the repo": run `composer dump-autoload` after an `autoload` change, because `vendor/` is mounted from the host.

- [ ] **Step 3: Final verification**

Each item below must succeed:
1. The full suite on mysql, mariadb, pgsql and sqlite (PHP 8.3), then `analyse` and `cs`.
2. The MySQL suite on PHP 8.5:

   ```sh
   docker build -q --build-arg PHP_VERSION=8.5 -t php-activerecord-tests:php85-190 .
   ```

   Then use the `docker run` recipe of the `docker-tests-mount-blocks-worktrees` memory (network `php-activerecord_default`, the `PHPAR_*` env, `-v $PWD:/code`), and remove the image afterwards.
3. `docker compose exec -T tests composer dump-autoload --optimize --strict-psr` exits 0.
   - `docker compose exec -T tests php -r '$m = require "vendor/composer/autoload_classmap.php"; echo count(array_filter(array_keys($m), fn($c) => str_starts_with($c, "ActiveRecord\\")));'` prints `62`.
   - Run the MySQL suite again on that optimized classmap. Expected: PASS.
   - Restore with `composer dump-autoload`.
4. `test/benchmarks/hot_paths.php`, before (master) and after, with `-d pcov.enabled=0`, with OPcache off and on. Use the CPU columns and the `.superpowers/perf185` harness if it still exists. Expected: no per-operation difference beyond the noise (±0.3 µs).

- [ ] **Step 4: Commit**

`docs: PSR-4 autoload in README and CLAUDE.md (#190)`

---

## After the plan (not tasks)

- **Two independent final reviews,** on different models, of the whole branch, the PR text and the release notes, as the maintainer asked for on 2026-10-30.
- **Release notes on merge:** the five items of spec §5.1 go into the **3.0.0** draft. The draft is the changelog.
