# Examples

Runnable demonstrations of php-activerecord features. The examples below use
**SQLite** and create their own database on the fly, so each runs with no database server to configure:

```sh
php examples/validations/validations.php
```

(In this repo's Docker setup: `docker compose exec tests php examples/validations/validations.php`.)

| Example | Demonstrates |
|---|---|
| [`validations/`](validations/) | `$validates_*` macros, a custom `validate()`, the `Errors` object (`full_messages()`, `on()`), `is_valid()` |
| [`relationships/`](relationships/) | `belongs_to` (incl. `class_name`/`foreign_key` options and the unknown-option error), `has_many`, `has_one`, `has_many … through` (incl. many-to-many), eager `include`, `create_*` builders, composite-key `has_many` (`foreign_key`/`primary_key` lists) with declared `conditions`, an `OR` in declared `conditions` stays scoped to the owner, a `has_many` keyed by a declared `primary_key` (lazy load, eager `include` and `create_*` agree, a declared `limit` applies per owner) |
| [`callbacks/`](callbacks/) | Lifecycle hooks (`before_validation`, `before_save`, `after_create`, `before_update`, `before_destroy`) and halting a save |
| [`attributes/`](attributes/) | Custom `get_*`/`set_*`, `$alias_attribute`, `$attr_accessible` (and `Config::set_strict_mass_assignment()`, which throws instead of dropping), `$delegate`, dirty tracking (`is_dirty`, `dirty_attributes`), integer columns (a value beyond `PHP_INT_MAX` keeps its exact string), columns whose names are not attribute names (`"first name"` is `first_name`: `save`, `find_by_first_name` and `count` name the real column; `lastName` is `lastname`, renamed only on Postgres, where the case of a quoted name matters) |
| [`serialization/`](serialization/) | `to_json` / `to_xml` / `to_array` with `only` / `except` / `methods` / `include` |
| [`finders/`](finders/) | Dynamic finders, the `conditions`/`order`/`limit`/`offset`/`group`/`having`/`select` option set, `last()` (reversed `order`), `find_by_sql`, static scopes, `find()` by a non-integer pk value (`'abc'`) → `RecordNotFound` (on Postgres without querying, so an open transaction stays usable), `delete_all()`/`update_all()` with `order` + `limit` (native `ORDER BY … LIMIT` on SQLite/MySQL; on Postgres the rows are picked by primary key in a subquery, or by `(tableoid, ctid)` on a table without one — that half runs only when `PHPAR_PGSQL` points at a reachable server) |
| [`conditions/`](conditions/) | Condition semantics: `null` renders `IS NULL`, empty arrays return no rows (`1=0` / `IN(NULL)`), arrays containing `null` also match NULL rows, the user-authored-fragment boundary, and table-qualified hash keys with `joins` (hash keys are always quoted as identifiers) |
| [`simple/`](simple/) | The minimal model (`class Book extends Model {}`), the primary key `create()` fills in (an int, `===` to `find()`'s, also in `to_json()`), a model on a table without a primary key (`create()` + read back), the exception for updating/deleting a never-saved or deleted record (undone by a rolled-back `transaction()`), and convention overrides (`$table_name`, `$primary_key`) |
| [`orders/`](orders/) | A fuller app: `$validates_*`, a `before_validation_on_create` callback (applies tax), `belongs_to`/`has_many`, `has_many … through` with `select`/`conditions`, dynamic finders |
| [`upsert/`](upsert/) | `Model::upsert()` — bulk insert-or-update with `unique_by`/`update` and automatically-managed timestamps |
| [`sequences/`](sequences/) | Primary-key sequences: the Postgres `{table}_{pk}_seq` convention, no sequence for a table without a primary key, explicit `static $sequence`, `supports_sequences()`, why the declaration is harmlessly ignored on MySQL/SQLite, and which SQLite primary keys are generated (only the rowid alias: a lone `INTEGER PRIMARY KEY` on an ordinary rowid table, not declared `DESC` inline; an `INT PRIMARY KEY` keeps the id given to `create()`). The Postgres half runs only when `PHPAR_PGSQL` points at a reachable server (this repo's Docker setup does) and is skipped otherwise |
| [`inflection/`](inflection/) | Table names derived from class names (`Inflector::tableize()`): `Human` → `humans` (a listed regular look-alike of an irregular word skips the irregular rules; compounds such as `Fireman` → `firemen` keep them), `Complex` → `complexes` (only a name ending in `matrix`/`vertex`/`index` takes `-ices`; a `matrix` earlier in the name is left alone: `MatrixRow` → `matrix_rows`), the `has_many` class and `build_*`/`create_*` builders that follow, and `$table_name` as the escape hatch |
