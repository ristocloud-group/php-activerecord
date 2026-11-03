# PSR-4 autoload through Composer, one class per file

**Date:** 2026-11-03
**Status:** Design approved section by section in chat (2026-11-03); this written spec is waiting for review.
**Author:** Claude (paired with maintainer)
**Issue:** #190, a follow-up of #185 / #189. The proposal and the analysis are in https://github.com/ristocloud-group/php-activerecord/issues/190#issuecomment-6602179710
**Branches:** this document lives on `spec/190-psr4-autoload`. The implementation branch is cut from `master` after #189 (merged as 86aa21b).

---

## 1. Context

### 1.1 Today

The library lives in the `ActiveRecord\` namespace but has no autoloader:

- **`ActiveRecord.php` is a hand-written `require` manifest.** Composer loads it through `"autoload": {"files": ["ActiveRecord.php"]}`, and every new file in `lib/` has to be added to it by hand.
- **The other classes are loaded lazily by `require_once` calls inside the code:**
  - `Expressions`, from `SQLBuilder` (×3), `Relationship`, `Conditions` and `ConditionCompiler`;
  - `Validations`, from `Model` (×2);
  - `Serialization`, from `Model`;
  - `Relationship`, from `Table::set_associations()`;
  - `Column`, from the top of `Connection.php`;
  - the adapters, by computed path in `Connection::load_adapter_class()`;
  - the cache backends, by computed path in `Cache::initialize()`.
- **Five files hold more than one class:**

  | file | classes |
  |---|---|
  | `lib/Exceptions.php` | 14 |
  | `lib/Relationship.php` | 6, none of them named `Relationship` |
  | `lib/Serialization.php` | 5 |
  | `lib/Inflector.php` | 2 |
  | `lib/Validations.php` | 2 |
- **`lib/Utils.php`** holds the class `Utils` and 12 functions in the `ActiveRecord` namespace.

Until #189 those `require_once` calls used relative paths, which cost 5 µs of CPU per call without OPcache. #189 switched them to `__DIR__`. What remains is a maintenance cost: the manifest, the load order, the scattered `require_once` calls, and the multi-class files.

### 1.2 Goals

1. Composer's PSR-4 autoloader loads every class of the library, on first use.
2. One class, interface or enum per file, named after its file.
3. No `require` or `include` left in `lib/`.
4. `ActiveRecord.php` and its constant `PHP_ACTIVERECORD_VERSION_ID` are removed. The installed version is read from Composer.
5. Adapters and cache backends are resolved from a native enum of the supported ones, not from a path.
6. Behavior is unchanged except for the changes in §5.1, which are approved and documented.

### 1.3 Non-goals

- **No class renames and no new namespaces.** Every class keeps its name `ActiveRecord\X`; moving classes into sub-namespaces would be a far larger break.
- **No code changes inside the classes**, beyond removing `require_once` lines and the two resolutions in §4.4.
- **No support for loading the library without Composer** (see §5.1).
- **No preloading.** Option A of #190 was rejected in favor of option B.

## 2. Decisions (approved in chat, 2026-11-03)

| # | Decision |
|---|---|
| D1 | **Option B.** Every `ActiveRecord\` class loads lazily on first use, and the change is documented as BREAKING. The maintainer approved this BC break explicitly. |
| D2 | **Composer only.** The library is loaded through `vendor/autoload.php`. |
| D3 | **Subfolders as extra PSR-4 base dirs.** The new files go into `lib/exceptions/`, `lib/relationships/` and `lib/serializers/`, next to the existing `lib/adapters/` and `lib/cache/`. |
| D4 | **`ActiveRecord.php` and `PHP_ACTIVERECORD_VERSION_ID` are removed** (BREAKING). Nothing reads the constant: not `lib/`, not the tests, not the monolith. It was stale (`'2.0.0'` while 2.1.0 was released), and Composer reports the installed version with `\Composer\InstalledVersions::getPrettyVersion('ristocloud-group/php-activerecord')`. The maintainer first chose to keep it, then decided to remove it after review (2026-11-03). |
| D5 | **Native enums for adapters and cache backends.** `ConnectionAdapter` and `CacheBackend` list what the library ships, and an unknown name never reaches the filesystem. No `class_exists()` is used. |
| D6 | **PHPStan baseline: re-point only.** The entries whose file is split keep their message and count, and only their `path` changes. No new entries. |

## 3. Evidence

All of it was verified on 2026-11-03.

- **40 of the 60 classes are eager today.** After `require 'vendor/autoload.php'` on master, 40 of the 60 `ActiveRecord\` classes are declared. The other 20 are already lazy: `Expressions`, the 6 relationship classes, the 5 serializer classes, `Validations`, `Errors`, the 3 adapters and the 3 cache backends.
- **No file in `lib/` runs code when it is loaded,** other than the `require_once` of `Column.php` at the top of `Connection.php`, which goes away. The version `define` in `ActiveRecord.php` goes away with the file (D4).
- **The library never relies on a class being declared.** Nothing in `lib/` calls `class_exists(..., false)` or `get_declared_classes()`. `Redis` calls `class_exists('Predis\Client')`, which autoloads.
- **The RistoCloud monolith does not depend on eager loading.** It was checked statically at HEAD `23d2ca846a`, with `vendor/` excluded:
  - it loads the library only through Composer;
  - it has no direct `require` of library files and defines no class or `class_alias` in `ActiveRecord\`;
  - its two `class_exists(..., false)` calls name classes outside the library;
  - it never calls `get_declared_*` and has no `opcache.preload`;
  - its uses of already-lazy classes (`instanceof BelongsTo/HasOne`, `ReflectionProperty(AbstractRelationship::class)`, `Serialization::$DATETIME_FORMAT`) stay correct.

  The monolith sets `optimize-autoloader: true`, which builds the classmap from PSR-4-compliant files only. It is still on `zamzar/php-activerecord ^1.7`.
- **Composer 2.10 accepts nested base dirs.** In a throwaway project, `lib/` and `lib/adapters/` were both declared as base dirs for one prefix. `composer dump-autoload --optimize --strict-psr` exited 0 and the class in `lib/adapters/` autoloaded, both optimized and not.
- **`--strict-psr` does not catch a second class in a file.** The command exits 0 and the extra class is left out of the classmap. A CI step running it would therefore not guard goal 2, and the guard is the token-based test in §6.1.

## 4. Design

### 4.1 `composer.json`

```json
"autoload": {
    "psr-4": {
        "ActiveRecord\\": [
            "lib/", "lib/adapters/", "lib/cache/",
            "lib/exceptions/", "lib/relationships/", "lib/serializers/"
        ]
    },
    "files": ["lib/Utils.php"]
}
```

- `lib/Utils.php` is in `files` for its functions, which cannot be autoloaded. That makes the class `Utils` the one class declared up front, and this cannot be avoided. The file is still PSR-4-compliant, because its class is named after it.
- Composer registers the autoloader before it includes the `files`, as `vendor/composer/autoload_real.php` shows, so nothing in them is loaded too early.

### 4.2 `ActiveRecord.php`

The file is deleted and leaves `autoload.files`, together with its manifest and its `define` (D4). The root of the repository then holds no PHP entry point: the library is `lib/` plus `composer.json`.

### 4.3 File layout

Every class keeps its code byte for byte. Each new file repeats the `namespace` and the `use` imports that its class needs. One file per split is moved with `git mv`, to the class that keeps most of its lines, so that `git log --follow` and `blame` keep working.

| today | after |
|---|---|
| `lib/Exceptions.php` | `lib/exceptions/` (one file per class): `ActiveRecordException` (git mv), `RecordNotFound`, `MultipleRecordsFound`, `DatabaseException`, `ModelException`, `ExpressionsException`, `ConfigException`, `CacheException`, `UndefinedPropertyException`, `MassAssignmentException`, `ReadOnlyException`, `ValidationsArgumentError`, `RelationshipException`, `HasManyThroughAssociationException` |
| `lib/Relationship.php` | `lib/relationships/`: `InterfaceRelationship`, `AbstractRelationship` (git mv), `HasMany`, `HasOne`, `HasAndBelongsToMany`, `BelongsTo` |
| `lib/Serialization.php` | `lib/serializers/`: `Serialization` (git mv), `ArraySerializer`, `JsonSerializer`, `XmlSerializer`, `CsvSerializer` |
| `lib/Inflector.php` | `lib/Inflector.php` (keeps `Inflector`) + `lib/StandardInflector.php` |
| `lib/Validations.php` | `lib/Validations.php` (keeps `Validations`) + `lib/Errors.php` |
| (new) | `lib/adapters/ConnectionAdapter.php`, `lib/cache/CacheBackend.php` (§4.4) |

Basenames stay unique across the six base dirs, which the test in §6.1 enforces. Composer would otherwise take the first match silently.

### 4.4 Code changes in `lib/`

**Removed:**
- every `require` and `require_once`:
  - `Model` (3);
  - `SQLBuilder` (3);
  - `Table::set_associations()`;
  - `AbstractRelationship::to_positional_conditions()`;
  - `Conditions`;
  - `ConditionCompiler`;
  - the top of `Connection.php`;
- the `require_once($source)` calls in `Connection::load_adapter_class()` and `Cache::initialize()`, which the enums replace.

**`lib/adapters/ConnectionAdapter.php`:**

```php
/**
 * The adapters this library ships. A connection protocol names the case whose value is its class name, computed as
 * it always was (ucwords($protocol) . 'Adapter'), so the protocols that resolve are exactly those whose file existed.
 *
 * @internal Serves Connection::load_adapter_class(); not a supported API.
 */
enum ConnectionAdapter: string
{
    case Mysql = 'MysqlAdapter';
    case Pgsql = 'PgsqlAdapter';
    case Sqlite = 'SqliteAdapter';

    /**
     * @return class-string<Connection>
     */
    public function class_name(): string
    {
        return __NAMESPACE__ . '\\' . $this->value;
    }
}
```

`Connection::load_adapter_class()`:
- The `oci` check stays first, with the same message.
- Next, `ConnectionAdapter::tryFrom(ucwords($adapter) . 'Adapter')`. When the result is `null`, it throws `DatabaseException("ActiveRecord\\{$class} not found!")`, the message it has today.
- It returns `->class_name()`, and `new $fqclass(...)` in `Connection::instance()` autoloads the adapter.
- Resolution is case-sensitive, as `file_exists()` is on Linux in production: `mysql` and `Mysql` resolve, `MYSQL` does not, the same as today.

**`lib/cache/CacheBackend.php`:** the same shape. The cases are `File = 'File'`, `Memcache = 'Memcache'` and `Redis = 'Redis'`, and `class_name()` works as above.

`Cache::initialize()`:
- The missing-scheme check and its message stay.
- Next, `CacheBackend::tryFrom(ucwords(Inflector::instance()->camelize($scheme)))`. When the result is `null`, it throws `CacheException("Unsupported cache scheme '$scheme': use file, memcache or redis")`. The URL is left out of the message, because it can carry credentials.
- Otherwise it calls `new ($backend->class_name())($parsed_url, $options)`.
- Today an unknown scheme requires a missing file, a fatal error that cannot be caught. The new behavior is listed in §5.1.

Both enums are `@internal`, so they add no public API.

## 5. Backward compatibility

### 5.1 Approved changes (release notes, draft 2.2.0)

1. **BREAKING: Composer is required, and `ActiveRecord.php` is removed.** The library's classes are loaded only through Composer's autoloader (`require 'vendor/autoload.php'`). A `require` of `ActiveRecord.php`, which used to load the manifest, now fails because the file does not exist: remove it.
2. **BREAKING: classes load on first use.** Before a class is first used, `class_exists('ActiveRecord\X', false)`, `interface_exists(..., false)` and `get_declared_classes()` no longer report it. The one exception is `Utils`, which is declared up front with the library's functions. A compile error in a file now surfaces on first use of its class instead of at boot. For code that does not inspect declared classes nothing changes: `instanceof` and `catch` on a class that was never loaded answer `false`, which is correct, since no object of it can exist.
3. **BREAKING: `lib/` is reorganized.** There is one class per file, and the new folders are `lib/exceptions/`, `lib/relationships/` and `lib/serializers/`. A direct `require` of a file in `lib/` must be removed, because the autoloader loads it.
4. **Fix: unknown cache scheme.** An unknown cache scheme (`Cache::initialize('foo://...')`) throws `CacheException` ("Unsupported cache scheme 'foo': use file, memcache or redis"). It used to stop with a fatal error from `require_once` of a file that does not exist.
5. **BREAKING: `PHP_ACTIVERECORD_VERSION_ID` is removed.** It was stale (`'2.0.0'` in 2.1.0). The installed version is `\Composer\InstalledVersions::getPrettyVersion('ristocloud-group/php-activerecord')`, which gives the tag (`2.2.0`) or the branch (`dev-master`, with `getReference()` for the commit).

### 5.2 Unchanged on purpose

- **Every class name.**
- **The adapters and cache backends that resolve,** with the same case-sensitivity. Every message stays: an unknown protocol, `oci://`, and a missing cache scheme.
- **The functions in `Utils.php`,** which are available as soon as `vendor/autoload.php` is loaded.
- **The SQL.** `GoldenSqlTest` pins it.

## 6. Testing and verification

### 6.1 New and extended tests

1. **`LibraryLoadingTest`** (from #189) becomes the PSR-4 guard. It is token-based, so comments do not count:
   - `lib/` has no `require` or `include` of any path;
   - each file in the six base dirs declares exactly one class, interface, trait or enum, in the `ActiveRecord` namespace, named after the file. `Utils.php` may also declare functions;
   - no basename repeats across the base dirs;
   - every PHP file under `lib/` sits in a base dir.
2. **Lazy loading.** The test runs `PHP_BINARY -r 'require "vendor/autoload.php"; …'` through `proc_open`, because the PHPUnit bootstrap has already loaded everything. It asserts two things:
   - the only `ActiveRecord\` class declared is `Utils`;
   - every class that the scan of item 1 finds is autoloadable (`class_exists($class)` is true and the class lives in the expected file).
3. **Enums.**
   - The cases of `ConnectionAdapter` match the `*Adapter.php` files in `lib/adapters/`, minus the enum's own file, and each resolves to a `Connection` subclass.
   - The cases of `CacheBackend` match the backend files in `lib/cache/`, minus the enum's own file.
   - An unknown protocol keeps "ActiveRecord\FooAdapter not found!", and `oci://` keeps its message.
   - An unknown cache scheme throws `CacheException` with the new message.

### 6.2 Existing tests and tooling

- Remove the direct `require_once` of `lib/` files from 11 test files, among them `RelationshipTest`, whose comment about the lazy require is also updated.
- `test/helpers/file_cache_atomicity_writer.php` is a standalone process: it requires `vendor/autoload.php` instead of `lib/cache/File.php`.
- `test/helpers/config.php`, `test/benchmarks/hot_paths.php` and the five examples drop their `require` of `ActiveRecord.php`, which no longer exists, because `vendor/autoload.php` is enough.
- `phpstan.neon` drops `bootstrapFiles: ActiveRecord.php`; PHPStan already loads Composer's autoloader.
- **`phpstan-baseline.neon`:** re-point the `path` of the entries whose file is split (D6):
  - `lib/Relationship.php` becomes `lib/relationships/AbstractRelationship.php`, for the `AbstractRelationship::$primary_key` entry, count 3;
  - the `lib/Validations.php` entry stays if PHPStan reports it in `Validations`, and becomes `lib/Errors.php` if it reports it in `Errors`.

  If PHPStan splits a count across two of the new files, that entry needs a decision from the maintainer.
- **Local Docker.** `vendor/` is mounted from the host, so `composer dump-autoload` has to be run after the `autoload` change. CI runs `composer update`, which regenerates it.

### 6.3 Verification

- **Gates:** the full suite on MySQL, MariaDB, PostgreSQL and SQLite on PHP 8.3, plus one run on PHP 8.5; `composer run analyse`; `composer run cs`.
- **`composer dump-autoload --optimize --strict-psr`** exits 0, as consumers with `optimize-autoloader` will run it.
- **`test/benchmarks/hot_paths.php`** before and after, with pcov off and OPcache both on and off. The expected per-operation difference is zero, because autoloading costs only on a class's first use.

## 7. Documentation and release

- **README.** The installation section states that the library is loaded through Composer's autoloader only (`require 'vendor/autoload.php'`), and every example drops `require 'ActiveRecord.php'`.
- **`CLAUDE.md`.** Rewrite the Architecture paragraph, which today names `ActiveRecord.php` as the entry point and describes the manual manifest and the missing PSR-4 autoloader. The new text says:
  - the library uses PSR-4 through Composer, with six base dirs and one class per file;
  - a new file only has to sit in the right base dir;
  - a new adapter or cache backend also needs its case in `ConnectionAdapter` / `CacheBackend`.

  It also adds the `composer dump-autoload` gotcha for the mounted `vendor/`.
- **Release notes.** The five items of §5.1 go into the 2.2.0 draft when the PR merges.
- **Notes for the monolith team.** Moving to the fork brings the three BREAKING items. The monolith needs no code change for them, as checked in §3, and they belong next to the `chunk` signature clash.

## 8. Delivery

One PR from `master` after 86aa21b (#189), in commits that are each green:

1. **Split.** Add the PSR-4 `autoload` next to the existing manifest and split the five files (§4.3). The manifest keeps declaring the same 40 classes: the 14 exception files, and `Inflector` together with `StandardInflector`. The lazy `require_once` calls of split files are removed, so the autoloader resolves them. Tests that required a moved file are updated, and no behavior changes.
2. **Lazy.** Delete `ActiveRecord.php` and replace it in `files` with `lib/Utils.php`, remove the remaining `require` calls in `lib/`, add `ConnectionAdapter` and `CacheBackend` with their resolutions, and update the bootstrap, examples, benchmark and PHPStan config.
3. **Guards and documentation.** The tests in §6.1, the baseline re-point, README and `CLAUDE.md`.

Reviewers can then check the pure move (1) apart from the change in loading (2).

## 9. Verifications for the plan

- Which class PHPStan reports the `lib/Validations.php` baseline entry in, and whether any count splits (§6.2).
- `git log --follow` on the three moved files after commit 1. Similarity must stay high enough for Git to detect the rename.
- php-cs-fixer on the new files: the header and `use` blocks of each must pass `composer run cs`.
- Run the §3 Composer spike again on the real `composer.json` at the end of commit 2: `--optimize --strict-psr` exits 0, and the classmap holds 62 `ActiveRecord\` entries: the 60 classes of today plus `ConnectionAdapter` and `CacheBackend`. `Utils` is in it too, though `files` declares it up front.
