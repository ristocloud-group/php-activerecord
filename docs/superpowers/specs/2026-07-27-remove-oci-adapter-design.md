# Remove the OCI/Oracle adapter — Design

**Date:** 2026-07-27
**Status:** Approved (pending spec review)

## Context

`php-activerecord` is a maintained fork of a legacy ActiveRecord ORM, vendored into our
monolith. Our production target is MySQL; Postgres and SQLite are supported and tested.
The Oracle (`oci`) adapter is **incomplete and unused** — `phpunit.xml` already excludes
its test group with the note *"Zamzar don't use the OCI adapter, and it was never complete
anyway"*, and the `oci:` PDO driver it relies on is not even installed in the Docker image
or CI.

Keeping it costs us: it is untested code that drags down the 90% coverage target we are
pursuing alongside the PHP 8.5 upgrade.

## Goal

Remove the OCI/Oracle adapter completely — code, tests, SQL fixtures, config branches, and
docs — without affecting the MySQL, Postgres, or SQLite adapters. Add a clear error for any
residual `oci://` connection string. Record the change as **v1.8.0**.

## Non-goals

- No refactoring of the shared sequence machinery (Postgres depends on it).
- No wider modernization of the touched files (that belongs to the PHP 8.5 work).
- No changes to unrelated adapters' behavior.

## Approach

**Clean removal.** Delete the adapter and everything guarded by `instanceof OciAdapter`,
collapsing the one behavioral conditional to its non-Oracle body. Preserve all generic
machinery that other adapters share.

Alternatives rejected:
- *Deprecate in place* (constructor throws): leaves dead, uncovered code — contradicts the
  coverage goal.
- *Removal + refactor the sequence path*: scope creep; the sequence logic still serves
  Postgres and must not be reshaped in a removal change.

## Scope

### Delete entirely
- `lib/adapters/OciAdapter.php`
- `test/OciAdapterTest.php`
- `test/sql/oci.sql`
- `test/sql/oci-after-fixtures.sql`

### Edit — remove OCI-specific branches
- **`lib/Model.php`** — in the create/insert path (~lines 822-839), remove the
  `if (($conn = static::connection()) instanceof OciAdapter)` branch ("terrible oracle
  makes us select the nextval first") and keep only the `else` body:
  ```php
  if ($table->sequence && !isset($attributes[$pk]))
  {
      // unset pk that was set to null
      if (array_key_exists($pk, $attributes))
          unset($attributes[$pk]);

      $table->insert($attributes, $pk, $table->sequence);
      $use_sequence = true;
  }
  else
      $table->insert($attributes);
  ```
  Also remove the `ar_rnum__` skip (~lines 1211-1214) that existed only to undo
  `OciAdapter::limit()`.
- **`lib/Connection.php`** — in `load_adapter_class()`, add an explicit guard at the top so
  a residual `oci://` connection fails with a readable message instead of
  "class ActiveRecord\OciAdapter not found":
  ```php
  if (strtolower($adapter) === 'oci')
      throw new DatabaseException('The OCI/Oracle adapter was removed in php-activerecord v1.8.0.');
  ```
- **`test/helpers/config.php`** — drop the `oci` entry from `set_connections()`.
- **`test/helpers/DatabaseLoader.php`** — remove the four `protocol == 'oci'` branches
  (the `rm-bldg` skip in `reset_table_data`, and the uppercase/DROP handling in
  `drop_tables` and elsewhere).
- **`test/helpers/AdapterTest.php`** — remove the `instanceof ActiveRecord\OciAdapter`
  guard block.
- **`test/ActiveRecordWriteTest.php`**, **`test/ActiveRecordFindTest.php`**,
  **`test/ActiveRecordTest.php`** — remove the `instanceof ActiveRecord\OciAdapter`
  guard blocks (Oracle-specific assertions/skips).
- **`phpunit.xml`** — remove the now-dead `<groups><exclude><group>oci</group></exclude></groups>`.

### Docs / versioning
- **`RELEASES.md`** — add a **v1.8.0** entry: *"Removes the incomplete, untested OCI/Oracle
  adapter."*
- **`ActiveRecord.php`** — bump `PHP_ACTIVERECORD_VERSION_ID` to `1.8.0`.
- **`README.md`** — drop Oracle from the "Supported Databases" list.
- **`CLAUDE.md`** — update the adapter/priorities section from "OCI slated for removal" to
  reflect that it is gone (adapters: MySQL primary, Postgres/SQLite supported).

### Explicitly NOT touched
- **`lib/Connection.php` `PDO::ATTR_ORACLE_NULLS`** — a standard PDO option applied to all
  connections; despite the name it is not the OCI adapter. Keep.
- **Sequence machinery** — `supports_sequences()`, `$table->sequence`,
  `insert_id($sequence)`. Postgres flows through the retained `else` branch
  (unset pk → `insert(..., $pk, $sequence)` → `insert_id($sequence)` via
  `lastInsertId`). Verified: only OCI used the "select nextval first" path.
- **`RmBldg` model / `rm-bldg` fixture** — a general table-name-quoting test used by SQLite.
  Keep it; only its OCI skip in `DatabaseLoader` is removed.

### Dependency check
There is **no separate OCI dependency to remove.** `composer.json`/`composer.lock` require
only `php` and `psr/log` (no `ext-oci8`, no `suggest`); the `Dockerfile` installs only
`pdo`, `pdo_mysql`, `pdo_pgsql`, `pgsql`, `memcached`; CI declares no Oracle service. The
`oci:` PDO driver the adapter needed was never installed. This is documented here for
completeness; no infrastructure change is required.

## Verification

1. `docker compose exec tests composer run test` passes green (MySQL default). `composer
   run test` uses `--fail-on-skipped`, so **no test may skip**.
2. Run the suite against Postgres and SQLite to confirm the collapsed sequence branch did
   not regress:
   `docker compose exec tests vendor/bin/phpunit --adapter pgsql`
   `docker compose exec tests vendor/bin/phpunit --adapter sqlite`
3. `grep -riE 'oci|OciAdapter' lib test phpunit.xml` returns only the legitimate
   `PDO::ATTR_ORACLE_NULLS` line in `lib/Connection.php`.
4. A connection string `oci://...` throws the new `DatabaseException` with the removal
   message (quick manual/unit check).
5. `docker compose exec tests composer run check-compatibility` still passes.

## Risk

Low. The single behavioral edit is the `Model.php` sequence branch, verified to leave the
MySQL and Postgres paths intact. Everything else is deletion of code guarded by
`instanceof OciAdapter`, which becomes unreachable once the class is removed.
