# Release Notes

## v2.1.0 (TBD)
* **BREAKING:** relationship declarations are now validated: an unknown option
  key in `$has_many` / `$has_one` / `$belongs_to` / `$has_and_belongs_to_many`
  (e.g. `primary_key` on `belongs_to`) throws `RelationshipException` instead
  of being silently ignored, and malformed definitions (e.g. a missing name)
  throw too (#24)
* **BREAKING (behavior):** hash conditions with a `null` value render a literal
  `IS NULL` instead of binding the null to an `IS ?` marker — identical results
  on MySQL (emulated prepares already inlined `IS NULL` on the wire), fixes the
  syntax error under Postgres real prepares (#26 / #93)
* **BREAKING (behavior):** an empty array in conditions yields an empty result
  set instead of a `DatabaseException` from invalid `IN()` SQL — the hash form
  renders `1=0`, an empty array bound in a positional fragment expands to
  `IN(NULL)`; note a user-written `NOT IN (?)` bound to `[]` therefore also
  yields 0 rows (#36 / #96)
* **BREAKING (behavior):** arrays containing `null` in library-built IN
  conditions (hash conditions and dynamic finders) now also match rows where
  the column IS NULL — `['flag' => [1, null]]` renders
  `(flag IN(?) OR flag IS NULL)`; previously NULL rows were silently excluded.
  User-authored SQL fragments are not rewritten (#98)
* **BREAKING:** boolean columns cast to native PHP `true`/`false` on every
  adapter via a new `Column::BOOLEAN` type: Postgres `boolean` and SQLite
  `boolean`/`bool` declarations (previously untyped strings — saving `false`
  on Postgres even crashed with `SQLSTATE[22P02]`, and an introspected
  `DEFAULT false` became the truthy string `'false'`), and MySQL/MariaDB
  `tinyint(1)` — exactly display width 1, the expansion of MySQL's own
  `BOOLEAN` DDL alias — by Rails-style convention (previously int `0`/`1`;
  wider tinyints and all other int types keep INTEGER semantics). Bools are
  converted to `0`/`1` at bind time, so saves and boolean condition values
  work on every adapter, and JSON serialization now emits literal
  `true`/`false` for boolean columns. **Migration note:** a `tinyint(1)`
  column used to store real small numbers is now boolean by convention — the
  value `5` hydrates as `true` and writes back as `1`; migrate such columns to
  `tinyint(2)` or wider BEFORE upgrading (#30)
* **BREAKING (behavior):** eager-loading a `has_many`/`has_one` `'through'`
  relationship with multiple parents now groups related rows per parent exactly
  like lazy loading — previously every parent received the union of ALL related
  rows (silently wrong data). Eager-loaded through models now carry the
  middle-table FK attribute used for grouping (#27 / #97)
* Fixed a SQLite-only eager-load failure (integer condition values were
  compared as TEXT and never matched): `SqliteAdapter` now binds parameters by
  PHP type (#26 / #93)
* Fixed `Expressions` leaking the glue argument into bind values when
  constructed from an all-null hash with an explicit glue (#94 / #95)
* Fixed `ActiveRecord\DateTime::modify()`/`add()`/`sub()`/`setTimezone()` not
  flagging the attribute dirty: mutating a datetime attribute in place with
  these methods then calling `save()` previously returned `true` without
  issuing an UPDATE (silent data loss); the mutation is now persisted —
  visible behavior change for code that relied on the silent no-op (#29)
* The test suite runs against a selectable connection via `PHPAR_CONNECTION`;
  CI now covers MySQL, MariaDB and SQLite (Postgres deferred — #92) (#26 / #93)
* `Model::exists()` now uses `EXISTS` instead of `COUNT(*)` (#23)
* Adds PHPStan/IDE stubs for the relationship-declaration option shape (#24)

## v2.0.0 (TBD)
* **BREAKING:** drops support for PHP < 8.3; the library now requires PHP ^8.3 and runs on 8.5
* **BREAKING:** upgrades psr/log to ^3.0
* Migrates the test suite to PHPUnit 12 and CI to GitHub Actions
* Adds support for MariaDB 11.4, MySQL 9.7, and PostgreSQL 18
* Adds PHPStan (level 5) and coverage generation
* Adds a Redis cache adapter (via `predis/predis`) for the schema cache, verified against Redis 6/7/8 and Valkey 7/8/9
* The file cache now honors the `expire` option (writes are atomic; entries expire lazily). **Behavior change:** with the default `expire` of 30s, file entries no longer persist indefinitely — pass `expire => 0` for the previous behavior
* Fixed `has_many … 'through'` (and the inherited `has_one … 'through'`) for the
  has_many→has_many chain, where the intermediate model `has_many` the target
  (reverse FK) — e.g. `Author has_many book_reviews, through: books`. Both lazy
  access and eager `include` are supported. The existing join-table / `belongs_to`
  `through` shape is unchanged. (#22)

## v1.8.0 (27 July 2026)
* Removes the incomplete, untested OCI/Oracle adapter

## v1.7.1 (1 August 2024)
* Various dev improvements [#13](https://github.com/zamzar/php-activerecord/pull/13)

## v1.7.0 (29 April 2023)
* Adds support for PHP 8.0, 8.1 and 8.2
* Drops support for PHP 7.3

## v1.6.0 (1 January 2021)
* Adds support for PHP 7.4
* Drops support for PHP 7.2

## v1.5.0 (20 October 2018)
* Adds support for PHP 7.2 [#9](https://github.com/zamzar/php-activerecord/issues/9)

## v1.4.6 (26 July 2018)
* Fixed a bug that prevent reflection on dynamic properties for relationships [#8](https://github.com/zamzar/php-activerecord/issues/8)

## v1.4.5 (22 March 2018)
* Made it possible to close a DB connection by calling `Table::drop_connection` [#6](https://github.com/zamzar/php-activerecord/pull/6)

## v1.4.4 (11 January 2017)
* Fixed a bug that prevented all records from being loaded when eager loading a has many through relationship of the form A->B->C when there was no relationship C->B

## v1.3.5 (11 January 2017)
* Fixed a bug that prevented all records from being loaded when eager loading a has many through relationship of the form A->B->C when there was no relationship C->B

## v1.4.3 (16 December 2016)
* Fixed a further bug in which a has many through relationship of the form A->B->C needlessly required the relationship C->B to be defined [#4](https://github.com/zamzar/php-activerecord/issues/4)

## v1.3.4 (16 December 2016)
* Fixed a further bug in which a has many through relationship of the form A->B->C needlessly required the relationship C->B to be defined [#4](https://github.com/zamzar/php-activerecord/issues/4)

## v1.4.2 (16 December 2016)
* Fixed a bug in which a has many through relationship of the form A->B->C needlessly required the relationship C->B to be defined [#4](https://github.com/zamzar/php-activerecord/issues/4)

## v1.3.3 (16 December 2016)
* Fixed a bug in which a has many through relationship of the form A->B->C needlessly required the relationship C->B to be defined [#4](https://github.com/zamzar/php-activerecord/issues/4)

## v1.4.1 (16 December 2016)
* Fixed a bug in MySQL date time handling [upstream #412](https://github.com/jpfuentes2/php-activerecord/issues/412)
* Adds docker configuration to ease testing

## v1.3.2 (16 December 2016)
* Fixed a bug in MySQL date time handling [upstream #412](https://github.com/jpfuentes2/php-activerecord/issues/412)
* Adds docker configuration to ease testing

## v1.4.0 (12 December 2016)
* Removes PHP ActiveRecord's autoloader, as Composer usage is now widespread [#5](https://github.com/zamzar/php-activerecord/issues/5)

## v1.3.1 (1 December 2016)
* Fixed a bug in which PDO exceptions thrown when starting a transactions would not be reported correctly [#3](https://github.com/zamzar/php-activerecord/issues/3)

## v1.3.0 (31 October 2016)
* Adds PHP 7 compatibility

## v1.2.0 (30 September 2016)
* First release of Zamzar fork of `php-activerecord/php-activerecord`
* Added a simple, file-based implementation of PHP AR cache for installs without memcached [#2](https://github.com/zamzar/php-activerecord/issues/2)
* Fixed a bug in which foreign keys would not be set when using `create_association` [#1](https://github.com/zamzar/php-activerecord/issues/1)
