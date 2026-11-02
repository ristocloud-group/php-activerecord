# PHP ActiveRecord #

[![CI](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml/badge.svg)](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml)
[![Coverage](https://raw.githubusercontent.com/ristocloud-group/php-activerecord/badges/coverage.svg)](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml)

> **This is a fork maintained by Ristocloud Group S.r.l.**
>
> It is based on [`zamzar/php-activerecord`](https://github.com/zamzar/php-activerecord) (itself a fork of the original [`jpfuentes2/php-activerecord`](https://github.com/jpfuentes2/php-activerecord)). We vendor it into our own applications and maintain it — fixing bugs and keeping it running on modern PHP and database versions. It is not affiliated with, nor endorsed by, the original authors.

Originally created by:

* [@kla](https://github.com/kla) - Kien La
* [@jpfuentes2](https://github.com/jpfuentes2) - Jacques Fuentes
* [and these contributors](https://github.com/kla/php-activerecord/contributors)

Upstream documentation: <http://www.phpactiverecord.org/>

## Introduction ##
A brief summarization of what ActiveRecord is:

> Active record is an approach to access data in a database. A database table or view is wrapped into a class,
> thus an object instance is tied to a single row in the table. After creation of an object, a new row is added to
> the table upon save. Any object loaded gets its information from the database; when an object is updated, the
> corresponding row in the table is also updated. The wrapper class implements accessor methods or properties for
> each column in the table or view.

More details can be found [here](http://en.wikipedia.org/wiki/Active_record_pattern).

This implementation is inspired and thus borrows heavily from Ruby on Rails' ActiveRecord.
We have tried to maintain their conventions while deviating mainly because of convenience or necessity.
Of course, there are some differences which will be obvious to the user if they are familiar with rails.

## Minimum Requirements ##

- PHP 8.3+ (tested on PHP 8.3, 8.4 and 8.5)
- PDO driver for your respective database

## Supported Databases ##

- **MySQL** — the primary production target. Supported minimum: MySQL 8+.
- **MariaDB** — supported minimum: MariaDB 10.11+.
- **PostgreSQL** — supported minimum: PostgreSQL 10+ (column introspection reads `IDENTITY` columns).
- **SQLite**

These are policy minimums; `composer.json` carries no database-version constraint. Continuous integration runs the full test suite across PHP 8.3, 8.4 and 8.5 against MySQL 8.4 & 9.7, MariaDB 10.11, 11.4, 11.8 & 12.3, PostgreSQL 15, 16, 17 & 18, and SQLite 3. The Oracle (`oci`) adapter was removed in v1.8.0.

## Features ##

- Finder methods
- Dynamic finder methods
- A fluent query builder, Eloquent-style in snake_case
- Writer methods
- Relationships
- Validations
- Callbacks
- Serializations (json/xml)
- Transactions
- Support for multiple adapters
- Miscellaneous options such as: aliased/protected/accessible attributes

## Installation ##

This fork is not published on Packagist. Install it with [Composer](https://getcomposer.org/) from its Git repository — add a VCS repository to your `composer.json` and require the package by its name (`ristocloud-group/php-activerecord`):

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ristocloud-group/php-activerecord" }
    ],
    "require": {
        "ristocloud-group/php-activerecord": "dev-master"
    }
}
```

Setup is very easy and straight-forward. There are essentially only two configuration points you must concern yourself with:

1. Configuring your database connections.
2. Setting the database connection to use for your environment.

Example:

```php
ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) {
    $cfg->set_connections([
        'development' => 'mysql://username:password@localhost/development_database_name',
        'test' => 'mysql://username:password@localhost/test_database_name',
        'production' => 'mysql://username:password@localhost/production_database_name',
    ]);
});
```

Alternatively (without a closure):

```php
$cfg = ActiveRecord\Config::instance();
$cfg->set_connections([
    'development' => 'mysql://username:password@localhost/development_database_name',
    'test' => 'mysql://username:password@localhost/test_database_name',
    'production' => 'mysql://username:password@localhost/production_database_name',
]);
```

MariaDB uses the same `mysql://` connection scheme (and the MySQL adapter) as MySQL.

### Connection URLs ###

Connections are configured with URLs of the form:

```
protocol://username:password@host[:port]/dbname
```

- **Protocols:** `mysql://` (MySQL and MariaDB), `pgsql://` (PostgreSQL) and `sqlite://`. The Oracle adapter was removed in v1.8.0 — an `oci://` URL throws a `DatabaseException`.
- **Port** is optional and defaults to the adapter's standard port (3306 for MySQL/MariaDB, 5432 for PostgreSQL).
- **Unix sockets:** pass the socket path in place of the host: `mysql://user:pass@unix(/var/run/mysqld/mysqld.sock)/dbname`.

The query string may carry two optional parameters, separated by `&` as usual:

- `charset=<charset>` — character set for the connection, applied with `SET NAMES` right after connecting. Supported by MySQL/MariaDB and PostgreSQL; SQLite does not support it and throws.
- `decode=true` — URL-decode the username and password before connecting. Use it when credentials contain characters that are reserved in URLs (`@`, `:`, `/`, …): percent-encode them and add `decode=true`.

```php
$cfg->set_connections([
    'development' => 'mysql://username:password@localhost/database_name?charset=utf8mb4',
    // password is "p@ss:word", percent-encoded because of decode=true
    'production' => 'mysql://username:p%40ss%3Aword@db.example.com:3307/database_name?charset=utf8mb4&decode=true',
]);
```

SQLite needs no credentials or database name — the URL is a path to an existing database file:

```
sqlite://file.db                            relative to the current working directory
sqlite://../relative/path/to/file.db
sqlite://unix(/absolute/path/to/file.db)    absolute path on Unix
sqlite://windows(c%3A/absolute/path/to/file.db)   absolute path on Windows (drive colon percent-encoded)
```

PHP ActiveRecord will default to use your development database. For testing or production, you simply set the default
connection according to your current environment ('test' or 'production'):

```php
ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) {
    $cfg->set_default_connection('production'); // 'development', 'test', or 'production'
});
```

Once you have configured these settings you are done. ActiveRecord takes care of the rest for you.
It does not require that you map your table schema to yaml/xml files. It will query the database for this information and
cache it so that it does not make multiple calls to the database for a single schema.

### Optional: strict mass assignment ###

By default, mass assignment (`new Model($attributes)`, `set_attributes()`, `update_attributes()`, `create()`) drops
every attribute blocked by a model's `$attr_accessible` / `$attr_protected` and, if a logger is configured, logs a
warning. Turn on strict mode to throw an `ActiveRecord\MassAssignmentException` instead — raised before anything is
assigned, its message lists every blocked attribute. It is global and off by default; assigning a single attribute
(`$model->name = …`) is not affected. The foreign key that association builders (`build_*` / `create_*`) inject is
assigned directly, like Rails: it needs no `$attr_accessible` entry and is never dropped or reported. The attributes
passed to a builder are guarded as usual, and that includes a foreign key passed there with a value: the builder then
injects nothing for it, so a blocked one is dropped (the record gets no foreign key) or, in strict mode, throws. A
foreign key passed as `null` counts as not passed and is replaced by the injected value.

```php
ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) {
    $cfg->set_strict_mass_assignment(true);
});
```

### Optional: caching the schema ###

php-activerecord introspects each table's schema (columns, types, primary key) from the database. Within a single request this is kept in memory, but PHP's shared-nothing model means it is re-introspected on every request. To persist it across requests, configure an external cache. Three backends are bundled:

**Memcached** — requires the `memcached` PHP extension:

```php
$cfg->set_cache('memcache://localhost:11211', ['expire' => 120, 'namespace' => 'my_app']);
```

**File** — a filesystem cache (added by this fork for hosts without memcached); no extension required:

```php
$cfg->set_cache('file:///var/tmp/php-activerecord-cache');
```

The file backend stores one serialized file per cache key inside the directory you pass (creating the directory if it does not exist), and reads it back with `unserialize()`.

The **file** backend also honors the `expire` option: each entry stores an expiry timestamp
and is treated as a miss once it lapses (deleted lazily on the next read). Writes are atomic
(temp file + `rename`). **Behavior change:** because the default `expire` is 30 seconds, file
entries that previously persisted forever now expire after 30s by default — pass
`['expire' => 0]` to keep entries until you `flush()` them. Files written by older
versions are treated as a miss and regenerated, so no manual purge is needed when upgrading.

**Redis** — requires the `predis/predis` Composer package (`composer require predis/predis`); no PHP extension needed:

```php
$cfg->set_cache('redis://localhost:6379/0', ['expire' => 120, 'namespace' => 'my_app']);
```

Connection parameters are taken from the DSN, including its query string, so any Predis
connection parameter is reachable — e.g. TLS and tuning:

```php
$cfg->set_cache('redis://user:secret@redis.example.com:6379/0?read_write_timeout=2', [
    'namespace' => 'my_app',
]);
```

The same `redis://` DSN targets **Redis 6/7/8 and Valkey 7/8/9** interchangeably; the adapter
is exercised against all six in CI. Values are serialized on write and unserialized on read.
`ActiveRecord\Cache::flush()` deletes only the keys under the configured `namespace`
(via `SCAN`/`DEL`); with no namespace it falls back to `FLUSHDB`, which clears the whole
selected Redis database — set a `namespace` when the Redis instance is shared. **Do not** pass
a Predis `prefix` client option for key isolation: Predis does not apply `prefix` to the plain
`SCAN` command that namespace-scoped `flush()` relies on, so keys end up stored under
`prefix + key` while `flush()` only matches `namespace::*`, silently deleting nothing — use the
`namespace` option instead, which `flush()` already understands.

`ActiveRecord\Cache::flush()` invalidates the cache for any backend (for the file cache it
deletes the cached files) — for example after running a schema migration. All backends accept
a `namespace` option that prefixes every cache key, useful when several applications share one
cache store.

The cache is lock-free: at each expiry, concurrent requests all recompute the cached value
once (a brief stampede). For very hot deployments raise `expire` (or set it to `0`). Prefer a
local filesystem for the file backend — TTLs rely on the host clock, so shared storage (NFS)
across clock-skewed hosts can expire entries early or late.

| Backend | Requirement | TTL (`expire`) | Persistence | Namespace / flush | Concurrency | Best for |
|---|---|---|---|---|---|---|
| **Memcached** | `memcached` PHP extension | Yes (server-side) | In-memory, evictable | `namespace` prefix; `flush()` clears the whole server | Atomic server-side TTL | Existing memcached infra |
| **File** | none | Yes (since this fork) | On disk until expiry/flush | `namespace` prefix; `flush()` deletes files | Lock-free; atomic writes, lazy GC, local-FS assumption | Single host, no extra services |
| **Redis / Valkey** | `predis/predis` package | Yes (server-side) | In-memory (optionally persisted by the server) | `namespace`-scoped `SCAN`/`DEL`, else `FLUSHDB` | Atomic server-side TTL | Shared/networked cache, HA |

### Hash conditions ###

In a conditions hash (`['conditions' => ['name' => 'Tito']]`, also for `update_all` / `delete_all`) every key is a
column name, always quoted as identifiers and never spliced in as SQL. A key may name its table (`'books.name'`, e.g.
with `joins`), and a partly quoted name is quoted part by part (`` '`books`.name' `` → `` `books`.`name` ``); with
`joins` an unqualified key that names a column of the model gets the model's table, and any other one (a column of a
joined table) stays as written. With the `from` option every key is still qualified, with the `from` table, as before.
The columns of the model include the ones every table has without listing them
(`_rowid` on MySQL and MariaDB; `rowid`, `oid` and `_rowid_` on SQLite; `ctid`, `xmin`, `xmax`, `cmin`, `cmax` and
`tableoid` on Postgres), which are qualified too. On SQLite, `PRAGMA table_info` omits generated columns and the hidden
columns of a virtual table, so they are not seen as columns of the model and stay as written: a joined table with a
column of the same name makes such a key ambiguous, so write it qualified (`'books.total'`). Keys that name the same
column (`'id'`, `` '`id`' ``, `'books.id'`,
and an `$alias_attribute` name and the column it stands for) are all kept and ANDed. An
attribute name whose column is named differently is mapped to that column, also for `update_all` / `delete_all` (and
for the keys of an `update_all` `set` hash and of `upsert`): `'space_name'` → `space name`, `'dash_name'` →
`dash-name`, and on Postgres, whose quoted names are case-sensitive, a mixed-case `'some_date'` → `some_Date` (MySQL
and SQLite match it as is); space and hyphen differences are mapped on every adapter, and `upsert`'s
`$unique_by` / `$update` names are mapped too. Written next to the column's own name, two conditions are kept and
ANDed, whereas in a `set` hash or an `upsert` row the later key wins. An
expression such as `'LOWER(name)' => 'tito'` is one (unknown) column name, not a function call, so the database rejects
it: write expressions as a positional condition instead, `['conditions' => ['LOWER(name) = ?', 'tito']]`.

An `$alias_attribute` name stands for its column in the conditions hash of `find` / `all` / `first` / `last`, `count`,
`exists`, `update_all` and `delete_all` alike, in a relationship's declared `conditions` hash, and in every column name
the [query builder](#query-builder) takes: with `'marquee' => 'name'`, `['marquee' => 'Warner Theatre']` reads as
`name = 'Warner Theatre'`. A relationship's hash reads
the names of its associated model, the one its load finds (the target of a `has_many … through`): its aliases and its
attribute names (`'space_name'` for the column `space name`), in the lazy load and the eager `include` alike. An alias
next to its column's own name keeps both conditions, ANDed, and any other key, a qualified one included, is written as
before. In a `through`, which joins the middle table, a key the mapping changes (an alias, or an attribute name whose
column is named differently) is named with the target's table, `` `venues`.`city` ``, so it stays unambiguous when the
middle table has a column of the same name. Still write a key of the middle table qualified (`'events.title'`):
unqualified, a name that is also an alias or an attribute name of the target names the target's column. So that a name
is never ambiguous, an alias may not be named like a column of the model's
own table, by the column's real name or by its attribute name (`'space_name'` for the column `space name`), nor like a
column every table has without listing it (`_rowid` on MySQL and MariaDB; `rowid`, `oid` and `_rowid_` on SQLite;
`ctid`, `xmin`, `xmax`, `cmin`, `cmax` and `tableoid` on Postgres): such a model does not load, and its first use
throws `ActiveRecord\ConfigException`, naming the model, the alias and the column. Names are compared as the
database compares column names, regardless of case, except on Postgres
(`'Author_Id'` is the column `author_id` on MySQL, MariaDB and SQLite, not on Postgres). The check reads the columns as
the table loads, so a shadowing column it does not see (one added to the table afterwards, in a long-running process or
behind a stale schema cache, or on SQLite a generated column, which `PRAGMA table_info` omits) leaves the alias winning
in every condition; the next load with fresh metadata throws.

The identifiers-only guarantee relies on doubling the quote character, so it assumes a connection charset in which
that byte cannot belong to a multibyte character: `utf8mb4` / `utf8` (the default) or a single-byte charset. Legacy
multibyte MySQL charsets (`gbk`, `big5`, `sjis` / `cp932`, `gb18030`) are not covered.

`delete_all()` and `update_all()` read their argument as the finders do. `delete_all()` takes an options hash
(`conditions`, `limit`, `order`), a conditions string, or, as `count()` / `exists()` do, a bare conditions hash:
`Author::delete_all(['name' => 'Tito'])` deletes only the matching rows, and with no argument (or `null` / `[]`) it
still deletes every row. `update_all()` takes an options hash with `set`; a hash `set` also sets `updated_at`, unless it
gives one or the model class is suspended with `without_timestamps()`, and a string `set` is sent as written (see
[Timestamps](#timestamps)). A key gives `updated_at` by its column's name or attribute name, also pre-quoted or qualified
with the model's table (`` '`authors`.`updated_at`' ``), and the column is then assigned once, the value given: MySQL and
MariaDB take a qualified column in a `SET`, and Postgres and SQLite refuse it, as before. The other
finder options are ignored, as before. An argument that could be misread throws `ActiveRecordException` before any SQL
runs: a positional list (write `['conditions' => ['name = ?', 'Tito']]`), a hash mixing option keys with other keys
(`Unknown key(s): …`), an int, float or bool, and, for `update_all()`, a key beside `set` that is not an option, such as a
column name, or an argument without `set`.

**A statement terminator in the `conditions` SQL throws.** The SQL of the `conditions` option, a string
(`'author_id > 1'`) or the first element of a positional list (`['author_id > ?', 1]`), must not hold a `;` outside its
string literals and comments: `find` / `all` / `first` / `last`, `count`, `exists`, `update_all` and `delete_all` throw
`ActiveRecordException` before any SQL runs, and so does the load of a relationship whose declared `conditions` hold one
(every database refused its statement before). MySQL, MariaDB and SQLite end the statement there and drop what follows
it, the `order` and the `limit` among it, so a limited `update_all()` or `delete_all()` wrote every row it matched and a
find read every row; Postgres refuses the statement. A `;` with nothing after it is refused as well. A `;` in a string
literal (`"name = 'a;b'"`), in a comment or in a bound value (`['name = ?', 'a;b']`) is sent as before. **A block comment
left open throws too**: a `/*` outside the string literals and comments with no `*/` after it (`'author_id > 0 /* note'`).
SQLite runs it to the end of the statement, so a limited `delete_all()` deleted every row it matched; MySQL, MariaDB and
Postgres refuse the statement. A closed comment, or a `/*` in a string literal, is sent as before. The other options
(`select`, `joins`, `from`, `group`, `having`, `order`), the `$where` of a direct `Table::update()` / `Table::delete()`
and `find_by_sql()` send their SQL as written, unread. The rule is the builder's (see [Conditions](#conditions)).

## Query builder ##

Every model also has an Eloquent-style fluent query builder, with Eloquent's names in snake_case. It sits on the engine
that the finders (`find`, `first`, `all`, `count`, `exists`), `update_all`, `delete_all`, `save`, `delete` and `upsert` also
run through, so a condition or an alias is read by the same rules whichever API builds the statement. Dates are the
exception: the builder binds a `DateTime` in the format of its column, where the legacy finders keep the datetime format
(see [Conditions](#conditions)). It is additive: every existing static method keeps its meaning (see
[Legacy and builder side by side](#legacy-and-builder-side-by-side)).

```php
$invoices = Invoice::where('status', 'open')
    ->where_in('customer_id', [1, 2])
    ->where('total_cents', '>=', 10000)
    ->order_by_desc('issued_on')
    ->limit(10)
    ->get();

foreach ($invoices as $invoice) {
    echo $invoice->number, "\n";
}
```

The terminals return arrays, as `find('all')` does: there is no collection class. The snippets of this section use the
models of [`examples/query_builder/`](examples/query_builder/) (a `Customer` has many `Invoice`s, and an invoice belongs
to a customer), a runnable demonstration of the builder.

### Entry points ###

`Invoice::new_query()` starts a query on a model: an `ActiveRecord\Query`, typed `Query<Invoice>`. The builder methods can
also be called on the class itself: `Invoice::where(...)` is `Invoice::new_query()->where(...)`. The forwarded names are
listed in `Query::FORWARDABLE`, and each has a `@method static` tag on `Model`, so an IDE or PHPStan knows it, and its
return type, without an extension.

A query is **mutable**, as in Eloquent: a builder method changes the query and returns that same object, and the terminals
(`get()`, `count()`, ...) read it and leave it as it was. To go two ways from one point, fork it with `clone()`, which is a
deep copy:

```php
$open = Invoice::where('status', 'open')->order_by('id');
$first_two = $open->clone()->limit(2)->get();
$all_open = $open->get();                       // the limit was set on the clone
```

A query refuses what it cannot read at the call that gives it, not when it runs: an unknown operator, a negative `limit`,
an empty column name, SQL written by hand that holds a statement terminator or leaves a block comment open, a
relationship the model does not declare, ... Those are `ActiveRecordException`s (or a subclass, such as
`RelationshipException`), and a database error is still a `DatabaseException`.

What a static call such as `Invoice::something()` reaches:

- A public method that `Model` or the model class defines is called by PHP itself, and the forwarding never sees it. That
  is every legacy static (`find`, `first`, `last`, `all`, `count`, `exists`, `create`, `upsert`, `query`, `update_all`,
  `delete_all`, `find_by_sql`, ...) and any method of your own, a static `latest()` for instance: it wins over the
  forwarded one, which stays available on `new_query()`. So `Invoice::first()` is the legacy finder, and
  `Invoice::new_query()->first()` or `Invoice::where(...)->first()` is the builder's.
- Otherwise `__callStatic` answers, in this order: the dynamic finders (`find_by_*`, `find_all_by_*`, `count_by_*`,
  `find_or_create_by_*`), as before; then the refusal of `update` and `delete` below; then the forwarding of the names on
  `Query::FORWARDABLE`; and for any other name `ActiveRecordException: Call to undefined method: ...`, as before.
- **A static `update` or `delete` is refused**, because forwarded it would write every row. `Invoice::update([...])`
  throws an `ActiveRecordException` that points to `where(...)->update(...)`, and `Invoice::delete()` is PHP's own `Error`
  ("Non-static method ... cannot be called statically"), because `Model` has an instance `delete()`. To write every row,
  say so: `Invoice::new_query()->update([...])`, `Invoice::new_query()->delete()`. `insert()` is not forwarded either:
  `Invoice::insert(...)` is an undefined method, and `Invoice::new_query()->insert([...])` is the call.
- **Names are case-sensitive**: `Invoice::Get()` is an undefined method.
- **`readonly()` is not forwarded.** `Model` has an instance `readonly()`, so `Invoice::readonly()` is PHP's own `Error`.
  Call it on a query: `Invoice::new_query()->readonly()`, or `Invoice::where(...)->readonly()`.
- **In an instance method, `static::where(...)` does not work.** A static call on its own class made from an instance
  method of the model (`static::where(...)`, `self::where(...)`, `Invoice::where(...)`) goes to `Model::__call`, not to
  `__callStatic`, and answers `Call to undefined method: where`. Write `static::new_query()->where(...)` there. From a
  static method, or on another model's class, the forwarding works.

The builder does not go through a model's overrides of the legacy finders. A base class whose `all()` or `find()` adds a
condition (a soft-delete filter, say) does not affect `new_query()` or the forwarded calls.

### From Eloquent to snake_case ###

The names are Eloquent's in snake_case, with the `or_` variants it has (`orWhere` is `or_where`, `orWhereIn` is
`or_where_in`, ...):

| Eloquent | Here |
|---|---|
| `where`, `orWhere`, `whereNot`, `orWhereNot` | `where`, `or_where`, `where_not`, `or_where_not` |
| `whereIn`, `whereNotIn`, `whereNull`, `whereNotNull`, `whereBetween`, `whereNotBetween`, `whereBetweenColumns` | `where_in`, `where_not_in`, `where_null`, `where_not_null`, `where_between`, `where_not_between`, `where_between_columns` |
| `whereColumn`, `whereRaw`, `whereLike`, `whereNotLike`, `whereAll`, `whereAny`, `whereNone`, `whereKey`, `whereKeyNot` | `where_column`, `where_raw`, `where_like`, `where_not_like`, `where_all`, `where_any`, `where_none`, `where_key`, `where_key_not` |
| `select`, `addSelect`, `selectRaw`, `distinct`, `from`, `fromRaw` | `select`, `add_select`, `select_raw`, `distinct`, `from`, `from_raw` |
| `join`, `leftJoin`, `rightJoin`, `crossJoin` | `join`, `left_join`, `right_join`, `cross_join` |
| `orderBy`, `orderByDesc`, `orderByRaw`, `latest`, `oldest`, `reorder`, `inRandomOrder` | `order_by`, `order_by_desc`, `order_by_raw`, `latest`, `oldest`, `reorder`, `in_random_order` |
| `groupBy`, `groupByRaw`, `having`, `orHaving`, `havingRaw`, `havingNull`, `havingNotNull`, `havingBetween` | `group_by`, `group_by_raw`, `having`, `or_having`, `having_raw`, `having_null`, `having_not_null`, `having_between` |
| `limit`, `take`, `offset`, `skip`, `forPage` | `limit`, `take`, `offset`, `skip`, `for_page` |
| `with`, `without`, `withOnly`, `when`, `unless`, `tap`, `clone` | `with`, `without`, `with_only`, `when`, `unless`, `tap`, `clone` |
| `toSql`, `toRawSql`, `getBindings` | `to_sql`, `to_raw_sql`, `get_bindings` |
| `get`, `first`, `firstOrFail`, `firstOr`, `sole`, `find`, `findOrFail`, `findMany`, `findOr`, `value`, `soleValue`, `pluck`, `exists`, `doesntExist` | `get`, `first`, `first_or_fail`, `first_or`, `sole`, `find`, `find_or_fail`, `find_many`, `find_or`, `value`, `sole_value`, `pluck`, `exists`, `doesnt_exist` |
| `count`, `min`, `max`, `sum`, `avg`, `average` | `count`, `min`, `max`, `sum`, `avg`, `average` |
| `insert`, `update`, `delete`, `upsert` | `insert`, `update`, `delete`, `upsert` |

Added here: `joins()` and `left_joins()` join by relationship name, `readonly()` flags the models a query finds as
readonly, and `Model::without_timestamps()` suspends the timestamps of a model for a closure.

Eloquent's other builder features are not part of it: relationship queries (`whereHas`, `withCount`, ...), subqueries and
unions, locks, `increment` / `decrement`, `firstOrCreate` and the other `*OrCreate` methods, pagination, chunking and
cursors, query scopes, and the date, JSON and full-text wheres.

### Legacy and builder side by side ###

Some names exist in both worlds. Called on the class, the legacy static runs; called on a query (`new_query()`, or after
another builder call), the builder's runs. The legacy ones are unchanged, so existing code is not affected, except
`count`: there is one count, and the legacy `count` runs the builder's on the query of its options:

| Name | Legacy: `Invoice::...` | Builder: `Invoice::new_query()->...` |
|---|---|---|
| `find` | `find(1)`, `find(1, 2)`, `find([1, 2])`: a missing primary key throws `RecordNotFound`; the option forms `find('first', ...)` and `find($options)` return `null`, and `find('all', ...)` returns `[]` | `find(1)` is the model, or `null`. `find([1, 2])` is an array of the models found, a missing id left out. `find_or_fail($id)` throws `RecordNotFound` (with the legacy message) when an id is missing, `find_many($ids)` is the list form, and `find_or($id, $callback)` calls the callback instead of throwing. A one-column primary key only |
| `first` | `first($options)`; `last($options)` reverses the order (by the primary key when there is none) | `first()` is `LIMIT 1` in the query's own order, none implied, and returns a model or `null`. `first_or_fail()`, `first_or($callback)` and `sole()` are the strict forms. There is no `last()`: reverse the order |
| `count` | `count($options)` or primary key values: the builder's `count()` of the query of those options, so it counts the rows of the result too (a `group` its groups, a `limit` or an `offset` the rows of the window), always an `int` | `count($column = '*')` counts the rows of the result, so a grouped query counts its groups. `count('note')` is `COUNT(note)`, where `Invoice::count('note')` looks up the primary key `'note'` |
| `exists` | `exists($options)` or a primary key value | `exists()` and `doesnt_exist()` |
| `all` | `all($options)`, that is `find('all', $options)` | `get()` |
| `update_all`, `update` | `update_all(['set' => [...], 'conditions' => ...])`; a hash `set` also sets `updated_at` | `update([...])` on the query's where, `order_by()` and `limit()`; it sets `updated_at` too |
| `delete_all`, `delete` | `delete_all($options)` | `delete()` on the query's where, `order_by()` and `limit()` |
| `create`, `insert` | `create($attributes)` builds a model: validations, callbacks, the model returned | `insert($rows)` writes rows: no model, no validation, no callback; it returns `true` |
| `upsert` | `upsert($rows, $unique_by, $update)` | the same call |
| `query` | `query($sql, $values)` runs raw SQL and returns the `PDOStatement` | not the builder: `new_query()` starts one |

### Conditions ###

`where()` takes a column and a value, a column, an operator and a value, a hash, a list of conditions, or a closure:

```php
Invoice::where('status', 'open');                               // status = ?
Invoice::where('total_cents', '>', 10000);                      // total_cents > ?
Invoice::where(['status' => 'open', 'customer_id' => [1, 2]]);  // status = ? AND customer_id IN(?,?)
Invoice::where([['total_cents', '>', 5000], ['status', 'open']]);
Invoice::where('status', 'open')
    ->where(fn(ActiveRecord\Query $query) => $query->where('note', 'rush')->or_where('total_cents', '>=', 20000));
// WHERE status = ? AND (note = ? OR total_cents >= ?)
```

`or_where`, `where_not` and `or_where_not` take the same forms. The rest of the family is `where_in` / `where_not_in`,
`where_null` / `where_not_null` (a column or a list of columns), `where_between` / `where_not_between` (two values),
`where_between_columns`, `where_column` (two columns), `where_like` / `where_not_like`, `where_all` / `where_any` /
`where_none`, `where_key` / `where_key_not` (the primary key, one column) and `where_raw`, each with an `or_` variant but
`where_key` and `where_key_not`.

- **Operators.** Laravel's list, in any case, plus the adapter's own (`sounds like` on MySQL and MariaDB; `@>`, `<@`,
  `&&`, `is distinct from`, ... on Postgres). Any other operator throws at the call, where Laravel takes it for the value
  and compares it with `=`. `null` with `=` or `<=>` is `IS NULL`, with `!=` or `<>` it is `IS NOT NULL`, and with any
  other operator it throws.
- **The hash form follows the legacy hash rules.** A list is `IN`, an empty list is `1=0` (no row), `null` is `IS NULL`,
  and a `null` in a list also matches the NULL rows: `where(['note' => ['rush', null]])` is
  `(note IN(?) OR note IS NULL)`, where Laravel binds the null, which never matches. The entries of a hash are ANDed, also
  in `or_where(['a' => 1, 'b' => 2])`, which is `OR (a = ? AND b = ?)` (Eloquent's `orWhere` with an array ORs them).
- **Lists.** `where_in('status', [])` is `1=0`, and a `null` in the list is read as in a hash. `where_not_in('status', [null])`
  is `status IS NOT NULL`, and `where_not_in('status', ['void', null])` is `(status NOT IN(?) AND status IS NOT NULL)`.
  **A negation of an empty list means "all except none"**: `where_not_in('status', [])` and `where_key_not([])` are `1=1`,
  and `where_not(['status' => []])` is `NOT (1=0)`. They match every row, which matters for a write (see
  [Writing](#writing)).
- **Groups.** A closure is a parenthesized group, and an empty group is left out. The closure is given a new `Query` on the
  same model, and setting anything but wheres on it throws. `where_all(['number', 'note'], 'like', 'T%')` is
  `(number LIKE ? AND note LIKE ?)`, `where_any` joins the same comparisons with OR, and `where_none` negates that. An empty
  column list throws (Laravel adds nothing and matches every row), and so do `where_key` on a composite primary key and an
  array nested in `where_in`, `where_between` or `where_key`.
- **Values** are bound as the database expects them for their column: a bool as `0` or `1`, and a `DateTime` in the format
  of its column's type, a date (`2026-02-01`) for a DATE column and a date and time, in the adapter's datetime format, for a
  DATETIME one. That also fixes DATE comparisons on SQLite. The legacy finders are unchanged: their positional values (`['issued_on = ?', $date]`) and hash
  values keep binding the datetime format.
- **`where_like($column, $pattern, $case_sensitive = false)`** takes the `%` and `_` wildcards, matches in any case by
  default, and in the case given with `true`; how is in [Database notes](#database-notes).
- **`where_raw('LENGTH(note) > ?', [3])`** sends its SQL as written. As in a legacy positional condition, a value that is a
  list is spread over its marker: `where_raw('customer_id IN(?)', [[1, 2]])` is `customer_id IN(?,?)`.

**The `*_raw` methods send their SQL as written.** `where_raw`, `or_where_raw`, `having_raw`, `or_having_raw`,
`order_by_raw`, `group_by_raw`, `select_raw` and `from_raw` (and the `JOIN ...` fragments of `joins()`) insert their SQL
verbatim, with the values of its `?` markers bound, so it must never come from user input. A fragment that holds `--` or
`#` is followed by a newline in the statement, so a line comment at its end (`--`, or `#` on MySQL and MariaDB) stops
there and cannot cut off the clauses that follow it, which on a write include its `ORDER BY` and `LIMIT`. The legacy
`conditions` option sends its SQL as given, so a comment at its end still cuts them off there. **A fragment that holds a
statement terminator is refused at the call**: a `;` outside its strings, its quoted identifiers and its comments, as
the database of the connection reads them, throws `ActiveRecordException`, which names the method. **So does a fragment
that leaves a block comment open**, a `/*` there with no `*/` after it: SQLite runs the comment to the end of the
statement, so `where_raw('id > 0 /* note')->order_by('id')->limit(1)->delete()` deleted every row the `WHERE` matched,
and MySQL, MariaDB and Postgres refuse the statement. Block comments do not nest on MySQL, MariaDB and SQLite (`/* /* */`
is closed) and nest on Postgres (it is open there); MySQL's executable comments (`/*!`, `/*M!`) are open until their
`*/`.

- MySQL and MariaDB read strings in single and double quotes with `\` escapes, backtick identifiers, `#` comments, and
  `--` comments only where a space or a control character follows the dashes (`0--1;` is `0 - -1` and a `;` there).
- Postgres reads standard strings with no `\` escape, `E'...'` strings with them, double-quoted identifiers,
  dollar-quoted strings (`$$a;b$$`, `$tag$a;b$tag$`), `--` comments and nested block comments; `#` is an operator.
- SQLite reads strings and double-quoted, backtick and bracket identifiers with no `\` escape, and `--` comments; `#`,
  `$`, `@` and `:` start a parameter, so `IS NOT #a;` and `$$a;b$$` hold a `;` there, and a parameter is one token up
  to the `)` of a Tcl-style suffix (`$a(;)` holds none, `$a(') ;` does).

MySQL, MariaDB and SQLite would end the statement at such a `;`, so the clauses that follow it would be cut off and a
limited write would touch every row it matches; Postgres refuses the statement. A `;` in a string
(`where_raw("note = 'a;b'")`), an identifier, a comment or a bound value (`where_raw('note = ?', ['a;b'])`) is sent as
written. The legacy `conditions` option refuses one too (see [Hash conditions](#hash-conditions)). The MySQL and MariaDB
reading is that of their default sql_mode: a session with `NO_BACKSLASH_ESCAPES` reads `'C:\'` as a whole string, so
`name != 'C:\' ; '` passes the check and the statement ends at its `;` (the library sets no sql_mode). It also assumes a
connection character set in which a `\` byte is always a backslash (utf8mb4, utf8, latin1, ...): in GBK, Big5 or SJIS a
`\` byte can be the second byte of a character, so a fragment built for it can hide a `;` from the check.

`where_raw`, `or_where_raw`, `having_raw` and `or_having_raw` refuse SQL that is empty or only whitespace at the call, and
`order_by_raw` and `group_by_raw` refuse `0` too, which would name a column position that no statement has: the statement
would otherwise leave the clause out, and a write could touch rows it was not asked to.

### Columns and joins ###

Every method that takes a column (the wheres, `select`, `order_by`, `group_by`, `having`, `pluck`, `value`, the
aggregates, the conditions of a join, and the keys of the values of `update` and `insert`) reads the name by one rule:

- An unqualified name that is a column, an attribute name or an `$alias_attribute` alias of the model is that column,
  quoted: with `'marquee' => 'name'`, `Venue::where('marquee', 'Warner Theatre')` reads `name = ?`.
- **When the query has joins, such a name is also qualified with the model's table**, or with the `from()` alias, so
  `where('id', 5)` is `` `invoices`.`id` = ? `` and is never ambiguous. Any other unqualified name (a column of a joined
  table, the alias of a selected expression) is quoted as written, and with `from_raw()` nothing is qualified. The
  model's columns include the ones every table has without listing them (`rowid`, `ctid`, ..., see
  [Hash conditions](#hash-conditions)), with the same SQLite residual: a generated column is not seen, so it stays
  unqualified.
- `table.column`, `table.*` and `*` are quoted part by part, as in a conditions hash. An expression only goes through a
  `*_raw` method.

```php
Invoice::join('customers', 'customers.id', '=', 'invoices.customer_id')
    ->where('customers.country', 'IT')
    ->where('id', '>', 1)                                // `invoices`.`id` > ?
    ->get();

Invoice::left_join('customers', fn(ActiveRecord\JoinClause $join) => $join
        ->on('customers.id', '=', 'invoices.customer_id')
        ->where('customers.country', 'DE'))
    ->select('invoices.*', 'customers.name as customer_name')
    ->get();                                             // customer_name is null where there is no German customer

Invoice::joins('customer')->where('customers.country', 'DE')->get();   // by relationship name
Customer::left_joins('invoices')->where_null('invoices.id')->get();    // the customers without an invoice
```

- `join`, `left_join` and `right_join` take a table, then two columns and an operator (two columns alone are compared with
  `=`), or a closure. `cross_join($table)` has no condition. The table is read as `from()` reads it: quoted part by part,
  and `'customers as c'` gives it an alias.
- The closure is handed a **`JoinClause`**: `on()` and `or_on()` compare two columns, and `where()`, `or_where()`,
  `where_null()`, `where_not_null()`, `where_in()` and `where_not_in()` compare a column with bound values, by the rules of
  the where family. The query makes the `JoinClause`: one made by hand (`new ActiveRecord\JoinClause(...)`) throws
  `ActiveRecordException` on its first condition. A closure that adds no condition is refused, because a join without one
  pairs every row with every row (`cross_join()` is the explicit form).
- With joins the default select is the model's own columns (`invoices.*`), so the columns of a joined table never overwrite
  the model's attributes of the same name. Select the others by name, under an alias to read them as attributes.
- `joins('customer')` and `left_joins('customer')` join by relationship name, INNER and LEFT, with the legacy join SQL.
  `joins()` also takes hand-written `JOIN ...` fragments, as the legacy `joins` option does (they are raw SQL, see above),
  and `left_joins()` takes names only and refuses a fragment. They have the limits of the legacy option: they name the
  model's table, so they do not follow a `from()` alias; two relationships to the same table in separate calls are not
  aliased (in one call the second gets its relationship's name as alias); a `has_many ... through` is joined without its
  middle table; and the declared `conditions` of a relationship are not added to the ON, so a `left_joins()` row whose
  related rows they would all exclude is joined to them instead of being kept without a match. The database refuses
  the first three.

### Selecting, ordering and paging ###

```php
Invoice::select('number', 'total_cents as cents')->get();             // "column as alias" names the attribute
Invoice::select('customer_id')->select_raw('SUM(total_cents) AS total')
    ->group_by('customer_id')->having_raw('SUM(total_cents) > ?', [22000])->get();
Invoice::order_by('status')->order_by_desc('issued_on')->limit(10)->offset(20)->get();
Invoice::order_by('id')->for_page(3, 25)->get();                      // rows 51 to 75
```

- `select(...)` replaces the select, `add_select(...)` adds to it, and `select()` without a column restores the default (every
  column, or with joins the model's own). `select_raw($sql, $bindings)` adds an expression, and `distinct()` selects each
  row once.
- `order_by($column, $direction = 'asc')` takes `asc` or `desc` in any case and refuses anything else. `order_by_desc()`,
  `latest($column = 'created_at')` and `oldest()` are shorthands. `reorder($column = null, $direction = 'asc')` replaces
  every order (the `order_by_raw()` ones and the random order too), and without a column it only removes them.
  `in_random_order()` is `RAND()` on MySQL and MariaDB and `RANDOM()` on Postgres and SQLite.
- `group_by(...)`, and `having($column, $operator, $value)`, `or_having()`, `having_null()`, `having_not_null()` and
  `having_between()`, which read their operators, values and `null` as the wheres do. The column is a column of the model
  or the alias of a selected expression (MySQL, MariaDB and SQLite accept an alias in a HAVING, Postgres does not); an
  aggregate is written with `having_raw()`.
- `limit()` / `take()` and `offset()` / `skip()` take integers of 0 or more: a negative value throws, and another scalar
  is coerced as PHP coerces an `int` parameter (`'5'` is 5). `limit(0)` is a real `LIMIT 0` and finds nothing, and an
  offset without a limit selects every row after it. `for_page($page, $per_page = 15)` replaces both, for a page of 1 or
  more.

### Eager loading ###

`with()` loads relationships along with the models, as the legacy `include` option does:

```php
Invoice::with('customer')->get();                      // two statements, however many invoices there are
Invoice::with('customer.invoices')->get();             // dot notation: each customer's invoices as well
Invoice::with(['customer' => ['invoices']])->get();    // or as the include option takes them
```

The names merge into the include tree. `without('customer.invoices')` removes by path (the parent stays loaded, and a name
the query does not load takes nothing off and is not looked up), and `with_only(...)` replaces the tree. A relationship the
model does not declare is refused at the call (`RelationshipException`), and so is a constraint on a relationship
(`with(['customer' => fn($query) => ...])`), which is not supported. `get()`, `first()`, `find()` and the other terminals
that return models load them; `count()`, `exists()`, `value()` and `pluck()` load nothing. As with the legacy `include`, a
`select()` that leaves out the keys the relationship loads by fails (`UndefinedPropertyException`).

`readonly()` flags the models the query finds as readonly, as the legacy `readonly` option does, but not those loaded with
them. It is not forwarded statically (see above).

`when($value, $callback, $default = null)` calls the callback when the value is truthy, and `unless()` when it is falsy: the
shape of an optional filter (`$default`, when there is one, is called in the other case). A `when()` or `unless()` that
runs neither is a filter that came out empty, and the writes refuse it: an `update()` or a `delete()` with no other where
condition, and an `insert()` or an `upsert()`, which take no clause (see [Writing](#writing)); a read is unchanged. A
Closure value is called first, and the callback is given the query
and the value. If it returns a `Query` on the same model, that query goes on (a fork, for instance); any other return is
ignored, where Eloquent uses any non-null result. `tap($callback)` calls back and returns the query.

```php
$invoices = Invoice::when($status, fn(ActiveRecord\Query $query, mixed $status) => $query->where('status', $status))->get();
```

### Reading ###

```php
Invoice::where('status', 'open')->get();                    // an array of models
Invoice::where('number', 'INV-004')->first();               // a model, or null
Invoice::where('number', 'INV-004')->first_or_fail();       // a model, or RecordNotFound
Invoice::where('number', 'INV-004')->sole();                // the one model: RecordNotFound, or MultipleRecordsFound
Invoice::new_query()->find(3);                              // a model, or null
Invoice::new_query()->find([1, 3, 99]);                     // the models found: 1 and 3
Invoice::where('number', 'INV-003')->value('total_cents');  // 20000
Invoice::where('status', 'open')->pluck('number');          // ['INV-002', 'INV-005', 'INV-006']
Invoice::order_by('id')->pluck('total_cents', 'number');    // ['INV-001' => 12000, ...]
Invoice::where('status', 'void')->exists();                 // true
Invoice::where('status', 'open')->count();                  // 3
```

- `first_or($callback)` returns the callback's result instead of throwing. `sole()` and `sole_value($column)` look at two rows
  at most. `first()`, `sole()`, `value()` and a one-id `find()` keep a smaller limit that the query already has, so
  `limit(0)->first()` is `null`.
- `find($id)` takes a single-column primary key (a composite one throws `ActiveRecordException`) and shares the legacy
  validation of primary key values: a DATE key is formatted as a date, and on Postgres a value that an INTEGER key can never
  equal, such as `'abc'`, is not sent, so it finds nothing and does not abort the transaction. The key is ANDed first with
  the query's conditions, which are put in parentheses, so that an `or_where` never widens the key.
- `find_or_fail()` and `find_or()` compare the distinct ids with the distinct rows found. `find_or([1, 99], $callback)` calls
  the callback when any id is missing (Eloquent's `findOr` never calls it for a list). When a `select()` leaves the primary
  key out, they can only count the models, so under a join that repeats rows a missing id can go unnoticed.
- `value($column)`, `sole_value()` and `pluck($column, $key = null)` build no model, and cast the column as a model casts its
  attribute: a date is an `ActiveRecord\DateTime`, an integer beyond `PHP_INT_MAX` keeps its exact digits, a DECIMAL is a
  float. A qualified name (`'customers.name'`) gives the driver's value as it is. The keys of a `pluck` map are the
  driver's values, and a `null` key is `''`.
- `exists()` asks the adapter's `EXISTS()`. It leaves out the order, the limit and the offset, and selects `1` (unless there
  is a GROUP BY or a HAVING, which may name an alias of the select), so an aggregate in the select, such as `COUNT(*)`,
  cannot make it true when no row matches. `doesnt_exist()` is its opposite.
- `count()` returns the number of rows of the result. A query with a `group_by`, a `having`, `distinct()`, a `limit` or an
  `offset` is counted as a subquery, `SELECT COUNT(*) FROM (...) AS t`, so a grouped query counts its groups and a limited
  one the rows of its window. `count($column)` counts the column's non-null values, and after `distinct()` with no select
  of the query's own its distinct non-null values (Eloquent's `COUNT(DISTINCT column)`), with the refusal of a window
  ordered by another column that the [aggregates](#aggregates) have; `count()` after `distinct()` counts distinct rows. With no select of the query's own,
  `count()` and `exists()` make their select themselves (`1`), so SQL of `group_by_raw()`, or of a window's
  `order_by_raw()`, that names a column by its position (`group_by_raw('1')`, `'name, 2'`, `'(1)'`,
  `'1 COLLATE NOCASE DESC'`), read as the database of the connection reads its strings and comments, is refused at the
  call: the position would name the constant, not the column it names in `get()`. Give the query a select of its own
  (`select('name')->group_by_raw('1')->count()`), or name the column (`group_by_raw('parent_author_id')`). On a grouped
  query the counted column must be a grouped or selected one, as MySQL's `ONLY_FULL_GROUP_BY` and Postgres require.
- The legacy `Invoice::count($options)` is this count, run on the query of its options (and of its primary key values),
  as `find()` builds it: their `select`, `group`, `having`, `order`, `limit` and `offset` are read as SQL written by hand
  (`select_raw()`, `group_by_raw()`, ...). So `count(['group' => 'customer_id'])` counts the customers,
  `count(['limit' => 2])` at most 2 rows and `count(['offset' => 1])` all rows but one. A `select` is kept where the
  builder keeps a select of its own: in the subquery of a grouped or windowed count, so `count(['select' => 'DISTINCT
  customer_id', 'limit' => 100])` counts distinct values; a count neither grouped nor windowed counts every matching row
  in place (`SELECT COUNT(*)`, the select and the order left out), as `select_raw('DISTINCT customer_id')->count()` does.
  With no `select`, a `group` or a windowed `order` that names a column by its position (`'group' => '1'`) is refused,
  as above. It returns an `int`, runs on the model's `Table` connection (not through `Model::connection()`), and leaves its
  statement in `Table::$last_sql`, as `find()` and `exists()` do. Up to 2.1.0 it counted the rows of the first group,
  ignored the select and the window (0 with an offset), and could return the driver's string.

### Aggregates ###

```php
Invoice::sum('total_cents');                           // 72001
Invoice::min('total_cents');                           // 5000
Invoice::avg('total_cents');                           // '12000.1667', a string
Invoice::where('status', 'lost')->avg('total_cents');  // null
```

`min`, `max`, `sum` and `avg` (and `average`) read the column by its type, so the result is the same on MySQL, MariaDB and
Postgres, and on SQLite for integer columns:

| Column | `min`, `max`, `sum` | `sum` of no row | `avg` |
|---|---|---|---|
| integer (a boolean too) | an `int` when the exact value fits `PHP_INT_MIN` to `PHP_INT_MAX`, else its exact digits as a string | `0` | exact, a string |
| `DECIMAL`, `NUMERIC` | the exact string, as the database returns it, its scale kept | `'0'` | exact, a string |
| floating (`FLOAT`, `DOUBLE`, `REAL`) | a `float` | `0.0` | the database's `AVG`, a `float` |
| anything else: text, a date, a joined table's column, the alias of an expression | the driver's value | the driver's | the driver's |

The column is read by the name you aggregate. A query aggregated as a subquery (grouped, distinct or windowed, below)
keeps its select there, so the alias of an expression named like a column of the model is what is aggregated, read by
that column's type: `select_raw('total_cents * 2 AS total_cents')->order_by('id')->limit(10)->sum('total_cents')` is the
`int` 144002. Any other alias gives the driver's value. A query aggregated in place drops its select, as Eloquent's
aggregates do: `select_raw('total_cents * 2 AS total_cents')->sum('total_cents')` sums the column, 72001.
`min`, `max` and `avg` of no row are `null`. An exact `avg` is the SUM divided by the COUNT in PHP, an exact long division
rounded half up at the scale of the SUM plus 4 (`'1.5000'` for the integers 1 and 2, `'12000.1667'` above), where the
databases' own AVG differ in type and scale.

- They aggregate the rows of the result, as `count()` counts them: a grouped, distinct or windowed query (`limit`,
  `offset`) is aggregated as a subquery, `SELECT SUM(t.col) FROM (...) AS t`, so
  `order_by_desc('total_cents')->limit(2)->sum('total_cents')` sums the two biggest. Eloquent ignores the `limit` and
  aggregates every row. After `distinct()` with no select of the query's own, the subquery selects `DISTINCT col`, so
  `sum()` and `avg()` run over the column's distinct values, Eloquent's `SUM(DISTINCT col)` and `AVG(DISTINCT col)`
  (`min()` and `max()` are the same either way); with a select of its own they run over the distinct rows it selects.
  With no select of the query's own the subquery selects the aggregated column alone, so a position in SQL of
  `group_by_raw()` or of a window's `order_by_raw()` is refused at the call, as `count()` refuses it.
- **A distinct aggregate refuses a window ordered by another column.** With a `limit` or an `offset`, the order of the
  query picks the distinct values in that subquery, and `SELECT DISTINCT col` cannot be ordered by another column: MySQL
  and Postgres refuse it, and MariaDB and SQLite pick the values arbitrarily. So
  `distinct()->order_by('id')->limit(2)->sum('total_cents')` (and `count('total_cents')`) throws
  `ActiveRecordException` at the call. Order by the aggregated column, by any of its names
  (`distinct()->order_by_desc('total_cents')->limit(2)->sum('total_cents')` sums the two biggest distinct amounts), or
  give the query a select of its own. Without a window the order is dropped. SQL of `order_by_raw()` is not read, and
  goes to the database as it is, as does `in_random_order()`, which Postgres refuses there.
- On a grouped query, aggregating a column that the groups do not group is refused at the call, as MariaDB and SQLite would
  silently read an arbitrary row of each group: group by it, give the query a select of its own, or group by the whole
  primary key (each group is then one row, and any column may be aggregated). It is refused only when the query can be
  read that way: with a select of its own or a `group_by_raw()` the statement goes to the database as it is.
- A boolean column aggregates as an integer (`sum` counts the trues), where Postgres refuses `SUM`, `AVG`, `MIN` and `MAX`
  of a boolean with a `DatabaseException`.

### Writing ###

```php
Invoice::new_query()->insert([                                          // true
    ['customer_id' => 4, 'number' => 'INV-007', 'status' => 'open', 'total_cents' => 1500, 'issued_on' => new DateTime('2026-04-01')],
    ['customer_id' => 4, 'number' => 'INV-008', 'status' => 'open', 'total_cents' => 2500, 'issued_on' => new DateTime('2026-04-02')],
]);
Invoice::where('customer_id', 4)->update(['status' => 'void']);         // 2: the rows written
Invoice::where('customer_id', 4)->order_by('id')->limit(5)->delete();   // 2
Invoice::new_query()->update(['note' => null]);                         // every row, because it says so
```

- **A write takes the query's where, `order_by()` and `limit()`, and refuses any other clause** at the call: joins,
  groups, havings, an offset, `distinct()`, a `select()`, `from()`, `with()` and `readonly()` (`update() cannot use joins,
  ...`). `insert()` and `upsert()` take no clause.
- **A write with no where condition writes every row**, up to its `limit()`: `Invoice::new_query()->delete()`, and also a
  chain of `order_by()` or `limit()` alone (`Invoice::order_by('id')->delete()` deletes every row on MySQL, MariaDB and
  Postgres, where SQLite refuses an `ORDER BY` without a `LIMIT` on a write; `Invoice::limit(2)->delete()` deletes two).
  The static forwarding refuses `update` and `delete`, so a bare `Invoice::update([...])` never writes.
- **A write whose where calls all came out empty is refused**: `where([])`, an empty closure group or `where_null([])` mean
  a filter that came out empty, and it must not write every row. A `when()` or `unless()` that ran neither its callback
  nor a default is refused the same way: with `$ids = []`,
  `Invoice::when($ids, fn(ActiveRecord\Query $query, array $ids) => $query->where_key($ids))->delete()` is refused, and
  `insert()` and `upsert()`, which take no clause, refuse it too, naming the `when()` or `unless()`. Reads
  are unchanged (`where([])->get()` returns every row), and so is a write with no where call at all. A negation of an empty list is not empty but "all except none" (see
  [Conditions](#conditions)): `where_not_in('id', [])`, `where_key_not([])` and `where_not(['id' => []])` match every row,
  and a write with them touches every row, by design.
- **The keys of the values** name a column by its alias, its attribute name or its own name (`update(['amount' => 5])` with
  `'amount' => 'total_cents'`). **The values** are scalars, `null`, `DateTime`s (in the format of their column) and objects
  with a `__toString()` (bound as text); an array, an enum (give the scalar it stands for) and any other object are refused.
  Empty values throw `Updating requires a hash or string.`, as `update_all()` does.
- **`update()` and `delete()` return** the rows the driver counts (`PDOStatement::rowCount()`). On MySQL and MariaDB that is
  the rows whose values changed, as `update_all()` returns it. `updated_at` moves to now, so that is normally every matched
  row, but a row already stamped within the same second, its other values unchanged, is not counted.
- **`insert()`** takes one row (a hash) or a list of rows, which must all have the same keys. Several rows become one
  multi-row `INSERT`, split at the adapter's bind-parameter limit, in a transaction when there is more than one statement
  (unless the connection is already in one). No validation, callback or setter runs.
- **A limited write** (`order_by()` and `limit()`) is `UPDATE ... ORDER BY ... LIMIT` on MySQL, MariaDB and SQLite. Postgres
  has no `LIMIT` on `UPDATE` and `DELETE`, so it picks the rows by primary key in a subquery. An adapter that can do
  neither refuses the limited write (`ActiveRecordException`), here and in `update_all()` / `delete_all()`, rather than send
  it without its limit.
- `upsert($rows, $unique_by, $update = null)` is `Model::upsert()`, with the timestamps below.

### Timestamps ###

`save()` sets the timestamps itself, as it always has, and the engine fills them in every other statement it writes:

| Statement | `created_at` | `updated_at` |
|---|---|---|
| `save()` of a new record (`create()`) | now, over a value you assigned | now, over a value you assigned |
| `save()` of a record read (`update_attributes()`) | not set | now, over a value you assigned |
| INSERT: `insert()`, a direct `Table::insert()` | when missing: now | when missing: now |
| UPDATE: `update()`, `update_all()` with a hash `set` | never | when missing: now |
| UPSERT | on the INSERT part, when missing: now | when missing: now; also set on a conflict, unless the update list is empty |
| raw SQL: `update_all()` with a string `set` | untouched | untouched |

`save()` goes through the model's `set_timestamps()`, which a model can override. That override governs `save()` only:
`insert()`, `update()`, `update_all()` with a hash `set`, `upsert()` and a direct `Table::insert()` stamp the timestamps
of every model, one that overrides `set_timestamps()` included, and only `without_timestamps()` suspends them.

A timestamp column is one whose attribute name is `created_at` or `updated_at` (a column named `"created at"` is one), and a
value is missing when the data you give has none for that column, after name mapping (a given `null` is given; a key may be
pre-quoted or qualified with the model's table). All the rows of an `insert()` or an `upsert()` take the same time.
`Model::without_timestamps($fn)` suspends the rule for that model class while `$fn` runs, `save()` included, and returns
`$fn`'s result:

```php
$invoice = Invoice::without_timestamps(fn() => Invoice::create(['customer_id' => 1, 'number' => 'INV-009', 'status' => 'open', 'total_cents' => 100, 'issued_on' => '2026-05-01']));
```

Suspensions nest, and the previous state is restored when `$fn` ends, also if it throws. It is for that class only: not for a
subclass, a parent class or another model of the same table. A timestamp you give is still written, and a suspended
`upsert()` does not add `updated_at` to the columns a conflict overwrites.

Where `update_all()` with a hash `set` must not touch `updated_at`, wrap the call in `without_timestamps()`.

### Debugging ###

```php
$query = Invoice::where('status', 'open')->where('total_cents', '>', 5000)->order_by_desc('issued_on')->limit(2);
$query->to_sql();        // SELECT * FROM `invoices` WHERE `status` = ? AND `total_cents` > ? ORDER BY `issued_on` DESC LIMIT 2
$query->get_bindings();  // ['open', 5000]
$query->to_raw_sql();    // SELECT * FROM `invoices` WHERE `status` = 'open' AND `total_cents` > '5000' ORDER BY `issued_on` DESC LIMIT 2
```

The output is MySQL's.

`get_bindings()` lists the values in the order of the clauses (select, FROM, joins, WHERE, GROUP BY, HAVING, ORDER BY); the
limit and the offset are written in the statement, not bound. **`to_raw_sql()` is a debug aid**, never for execution: it
writes each value as the literal the adapter binds, so the text runs by hand as the bound statement does. A value the
adapter binds as an integer is written bare (on SQLite, ints and bools), every other value as quoted text, as it is sent
(on MySQL, MariaDB and Postgres an int is `'5'` and a bool `'1'`). So Postgres, run by hand, would refuse a bare `1` against a
boolean column or a bare `5` against a text column, and SQLite compares a quoted `'1'` with an integer expression
differently from the bound integer. `null` is `NULL`, and a `DateTime` is written as the builder binds it. It reads the
`?` markers as PDO does, outside strings and comments, and when they and the values do not match it throws an
`ActiveRecordException` rather than write another statement than the one that runs.

### Differences from Eloquent ###

Code ported from the Laravel documentation works by changing the method case, except for these deliberate differences
(the sections above give the details):

- **Columns are qualified with joins.** An unqualified name of one of the model's columns is written with the model's table
  once the query has joins, so `where('id', 5)` is never ambiguous. Eloquent never qualifies.
- **An unknown operator throws** `ActiveRecordException` at the call. Laravel takes it for the value, compared with `=`.
- **The hash form follows the legacy hash rules**, null in lists included: `where(['note' => ['rush', null]])` also matches
  the NULL rows, where Laravel binds the null, which never matches. **`or_where(['a' => 1, 'b' => 2])` ANDs its entries**,
  `OR (a = ? AND b = ?)`, where Eloquent's `orWhere` with an array ORs them.
- **`where_all`, `where_any` and `where_none` refuse an empty column list**, where Laravel adds nothing and matches every
  row. **A closure group refuses anything but wheres**, where Eloquent ignores the rest.
- **`count()` counts the rows of the result**, so a grouped query counts its groups, and so does the static
  `Invoice::count([...])`. Laravel counts the first group, and returns 0 with an offset.
- **Aggregates are exact** and normalized by column type (`avg` is an exact string), where Laravel returns the driver's
  value (or 0) with no cast. **`sum`, `min`, `max` and `avg` of a grouped, distinct or windowed query aggregate its result
  rows**, where Eloquent ignores the `limit`, and a grouped aggregate of an ungrouped column is refused at the call, as is
  a distinct one in a window ordered by another column. After
  `distinct()` with a select of the query's own they aggregate the distinct rows it selects, where Eloquent aggregates the
  column's distinct values (with no select of its own, both do).
- **`exists()` selects `1`**, so an aggregate select cannot make it true when no row matches. Eloquent keeps the select,
  which can. **`exists()` also leaves out the limit and the offset**, so `limit(0)->exists()` is true when a row matches,
  where Eloquent keeps the window.
- **`find()` returns `null` for a missing record**, as Eloquent's does, where the legacy static `Invoice::find()` still
  throws `RecordNotFound`. **`find_or($ids, $callback)` calls the callback when any id is missing**, where Eloquent's
  `findOr` never calls it for a list.
- **`when()` and `unless()` hand back only a `Query` on the same model** that the callback returns, and ignore any other
  return, where Eloquent uses any non-null result. **`readonly()` is not forwardable.**
- **`insert()` fills `created_at` and `updated_at`**, where Eloquent's `insert()` is raw and sets none.
- **A static `update` or `delete` is refused**, a write takes only a where, `order_by()` and `limit()`, and a write whose
  where calls all came out empty, or whose `when()` or `unless()` skipped its callback, is refused, where Eloquent writes
  every row (see [Writing](#writing)).
- **SQL written by hand that holds a statement terminator or leaves a block comment open is refused** at the call (a `;`
  outside its strings and comments, or a `/*` with no `*/` after it, see [Conditions](#conditions)), where Eloquent sends
  it.
- The exceptions are this library's: `RecordNotFound` for `first_or_fail()`, `find_or_fail()` and `sole()` (Eloquent's
  `ModelNotFoundException`) and `MultipleRecordsFound` for `sole()`. The terminals return arrays, where Eloquent returns
  Collections.

### Database notes ###

| | MySQL, MariaDB | PostgreSQL | SQLite |
|---|---|---|---|
| `where_like`, any case | `LIKE`, as the column's collation decides | `ILIKE`, on the column cast `::text`, so a column of any type works | `LIKE`, ASCII letters only |
| `where_like`, case-sensitive | `LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_bin` | `LIKE` (`::text` too) | `GLOB`, the pattern translated (`%` to `*`, `_` to `?`, GLOB metacharacters bracketed) |
| `\` in a pattern | escapes `%` and `_` | escapes `%` and `_` | a literal |
| `in_random_order()` | `RAND()` | `RANDOM()` | `RANDOM()` |
| Aggregates | exact | exact; refuses `SUM`, `AVG`, `MIN` and `MAX` of a boolean (`DatabaseException`); `MIN` and `MAX` of a `bytea` need PostgreSQL 18; a `numeric` NaN or infinity stays the text `'NaN'`, `'Infinity'` or `'-Infinity'` | exact for integer columns; a `DECIMAL` column is stored as a REAL, so its aggregates are floats and the sum of no row is `0.0`; an integer `SUM` beyond `PHP_INT_MAX` raises SQLite's `integer overflow` (`DatabaseException`) |
| `having('alias', ...)` | accepted | refused: write the aggregate with `having_raw()` | accepted |
| A limited write | native `ORDER BY ... LIMIT` | the rows picked by primary key in a subquery; an order without a limit is ignored | native `ORDER BY ... LIMIT`; an `ORDER BY` without a `LIMIT` is refused by SQLite (`DatabaseException`), as in `update_all()` |

- `where_like(..., true)` on MySQL and MariaDB compares characters whatever the column's character set (latin1, utf8mb3,
  utf8mb4), with the explicit collation. It assumes that the connection's character set is the encoding of the data you
  send (`utf8mb4` is recommended). Residual: on MySQL (not MariaDB) a connection made `latin1` by the URL's
  `charset=latin1` misreads non-ASCII patterns.
- SQLite needs version 3.39 or later for `right_join()`, and for a `HAVING` without a `GROUP BY` (`having_raw('COUNT(*) > ?',
  [1])` on an aggregate select); an older SQLite fails at the database.
- A wrapped `count()` of the rows selects `1` in its subquery when the select cannot matter (`sum`, `min`, `max`, `avg`
  and a count of a column select their column, or keep the query's own select), which avoids MySQL's and MariaDB's error
  1060 for two result columns of one name (`invoices.*` beside `customers.name`). When it has to keep the
  caller's select, because a GROUP BY, a HAVING or an aggregate depends on it, such a query can still meet that error
  there. Another residual: SQLite accepts a select alias in a WHERE, so a query that uses one works in `get()` there but
  fails in `exists()`, whose select is `1`.
- MySQL's and MariaDB's own `AVG` truncates instead of rounding when its result scale is 9, 18 or 27. The builder's `avg()`
  rounds half up on every adapter, so on those scales it can differ from the database's `AVG` in the last digit.

## Examples ##

Rather than inline snippets, every major feature is shown as a **runnable,
self-contained example** under [`examples/`](examples/). Each creates its own
SQLite database and runs with no setup:

```sh
php examples/simple/simple.php
```

| Example | Demonstrates |
|---|---|
| [`simple/`](examples/simple/) | Basic CRUD (find/first/create/update/delete), the primary key `create()` fills in (an int, as `find()` gives it), tables without a primary key, the never-saved- and deleted-record update/delete guard, and convention overrides (`$table_name`, `$primary_key`) |
| [`finders/`](examples/finders/) | Dynamic finders, the `conditions`/`order`/`limit`/`offset`/`group`/`having`/`select` options, `last()` (reversed `order`), `first()`/`last()` with an `offset` (the second / second-to-last record) or `limit` 0 (none), `find_by_sql`, static scopes, `exists()`/`count()` pk arguments as in `find()` (`exists(0)`, `count(1, 2, 3)` = `count([1, 2, 3])`), a pk + `conditions` ANDed (`find($id, ['conditions' => …])` → the record or `RecordNotFound`), `find('first'|'last', <pks>)` → the first/last of those pks (`[2, 3]` or `2, 3`, ANDed with `conditions`), `find('abc')` → `RecordNotFound` (on Postgres without querying), `update_all`/`delete_all` with `order` + `limit` (emulated on Postgres with a primary-key subquery), `delete_all()`/`update_all()` arguments (a bare hash is the conditions; an unknown key beside `set` throws) |
| [`validations/`](examples/validations/) | `$validates_*` macros, a custom `validate()`, the `Errors` object |
| [`relationships/`](examples/relationships/) | `belongs_to` (incl. `class_name`/`foreign_key` options), `has_many`, `has_one`, `has_many … through`, eager `include`, `create_*` builders, composite-key `has_many` with declared `conditions`, an `OR` in declared `conditions` stays scoped to the owner, a `has_many` keyed by a declared `primary_key` (lazy load, eager `include` and `create_*` agree, a declared `limit` applies per owner), a `has_one` with a declared `offset` (the child at that offset, lazy and eager) |
| [`callbacks/`](examples/callbacks/) | Lifecycle hooks and halting a save |
| [`attributes/`](examples/attributes/) | Custom `get_*`/`set_*`, `$alias_attribute`, `$attr_accessible` (and strict mass assignment), `$delegate`, dirty tracking, integers beyond `PHP_INT_MAX`, columns whose names are not attribute names (`"first name"` is `first_name`) |
| [`serialization/`](examples/serialization/) | `to_json` / `to_xml` / `to_array` with `only`/`except`/`methods`/`include` |
| [`upsert/`](examples/upsert/) | `Model::upsert()` — bulk insert-or-update with `unique_by`/`update` and managed timestamps |
| [`query_builder/`](examples/query_builder/) | The fluent query builder: `new_query()` and static forwarding, the where family (operators, an `$alias_attribute`, a hash with a `null` in a list, `where_like`, closure groups, `when`), joins (`join` with a `JoinClause` closure, `left_join`, `joins` / `left_joins` by relationship), `with` (eager loading in two statements), the read terminals, exact aggregates (`avg` is a string), `insert` / `update` / `delete` with their timestamps and `without_timestamps()`, the refused empty `where`, `to_sql` / `to_raw_sql` |
| [`orders/`](examples/orders/) | A fuller app combining validations, a callback, and relationships |
| [`inflection/`](examples/inflection/) | Table names derived from class names (`Human` → `humans`, `Fireman` → `firemen`, `Complex` → `complexes`, `MatrixRow` → `matrix_rows`), the `has_many` builders that follow, and `$table_name` as the escape hatch |

See [`examples/README.md`](examples/README.md) for the full index.

## Contributing ##

Please refer to [CONTRIBUTING.md](CONTRIBUTING.md) for information on how to contribute to this fork.

## License ##

MIT — see [LICENSE](LICENSE).
