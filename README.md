# PHP ActiveRecord #

[![CI](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml/badge.svg)](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml)

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

- **MySQL** — the primary production target
- **MariaDB**
- **PostgreSQL**
- **SQLite**

Continuous integration runs the full test suite across PHP 8.3, 8.4 and 8.5 against MySQL 9.7, MariaDB 11.4, PostgreSQL 18 and SQLite. The Oracle (`oci`) adapter was removed in v1.8.0.

## Features ##

- Finder methods
- Dynamic finder methods
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
ActiveRecord\Config::initialize(function($cfg)
{
   $cfg->set_connections(
     array(
       'development' => 'mysql://username:password@localhost/development_database_name',
       'test' => 'mysql://username:password@localhost/test_database_name',
       'production' => 'mysql://username:password@localhost/production_database_name'
     )
   );
});
```

Alternatively (without a closure):

```php
$cfg = ActiveRecord\Config::instance();
$cfg->set_connections(
  array(
    'development' => 'mysql://username:password@localhost/development_database_name',
    'test' => 'mysql://username:password@localhost/test_database_name',
    'production' => 'mysql://username:password@localhost/production_database_name'
  )
);
```

MariaDB uses the same `mysql://` connection scheme (and the MySQL adapter) as MySQL.

PHP ActiveRecord will default to use your development database. For testing or production, you simply set the default
connection according to your current environment ('test' or 'production'):

```php
ActiveRecord\Config::initialize(function($cfg)
{
  $cfg->set_default_connection(your_environment);
});
```

Once you have configured these settings you are done. ActiveRecord takes care of the rest for you.
It does not require that you map your table schema to yaml/xml files. It will query the database for this information and
cache it so that it does not make multiple calls to the database for a single schema.

### Optional: caching the schema ###

php-activerecord introspects each table's schema (columns, types, primary key) from the database. Within a single request this is kept in memory, but PHP's shared-nothing model means it is re-introspected on every request. To persist it across requests, configure an external cache. Two backends are bundled:

**Memcached** — requires the `memcached` PHP extension:

```php
$cfg->set_cache('memcache://localhost:11211', array('expire' => 120, 'namespace' => 'my_app'));
```

**File** — a filesystem cache (added by this fork for hosts without memcached); no extension required:

```php
$cfg->set_cache('file:///var/tmp/php-activerecord-cache');
```

The file backend stores one serialized file per cache key inside the directory you pass (creating the directory if it does not exist), and reads it back with `unserialize()`. Unlike Memcached, it does **not** honor the `expire` option — file entries have no TTL and persist until you remove them. Call `ActiveRecord\Cache::flush()` to invalidate the cache for either backend (for the file cache this deletes the cached files) — for example after running a schema migration.

Both backends accept a `namespace` option that prefixes every cache key, which is useful when several applications share one cache store.

## Basic CRUD ##

### Retrieve ###
These are your basic methods to find and retrieve records from your database.
See the *Finders* section for more details.

```php
$post = Post::find(1);
echo $post->title; # 'My first blog post!!'
echo $post->author_id; # 5

# also the same since it is the first record in the db
$post = Post::first();

# finding using dynamic finders
$post = Post::find_by_name('The Decider');
$post = Post::find_by_name_and_id('The Bridge Builder',100);
$post = Post::find_by_name_or_id('The Bridge Builder',100);

# finding using a conditions array
$posts = Post::find('all',array('conditions' => array('name=? or id > ?','The Bridge Builder',100)));
```

### Create ###
Here we create a new post by instantiating a new object and then invoking the save() method.

```php
$post = new Post();
$post->title = 'My first blog post!!';
$post->author_id = 5;
$post->save();
# INSERT INTO `posts` (title,author_id) VALUES('My first blog post!!', 5)
```

### Update ###
To update you would just need to find a record first and then change one of its attributes.
It keeps an array of attributes that are "dirty" (that have been modified) and so our
sql will only update the fields modified.

```php
$post = Post::find(1);
echo $post->title; # 'My first blog post!!'
$post->title = 'Some real title';
$post->save();
# UPDATE `posts` SET title='Some real title' WHERE id=1

$post->title = 'New real title';
$post->author_id = 1;
$post->save();
# UPDATE `posts` SET title='New real title', author_id=1 WHERE id=1
```

### Delete ###
Deleting a record will not *destroy* the object. This means that it will call sql to delete
the record in your database but you can still use the object if you need to.

```php
$post = Post::find(1);
$post->delete();
# DELETE FROM `posts` WHERE id=1
echo $post->title; # 'New real title'
```

## Contributing ##

Please refer to [CONTRIBUTING.md](CONTRIBUTING.md) for information on how to contribute to this fork.

## License ##

MIT — see [LICENSE](LICENSE).
