# Examples

Runnable demonstrations of php-activerecord features. The examples below use
**SQLite** and create their own database on the fly, so each runs with no setup:

```sh
php examples/validations/validations.php
```

(In this repo's Docker setup: `docker compose exec tests php examples/validations/validations.php`.)

| Example | Demonstrates |
|---|---|
| [`validations/`](validations/) | `$validates_*` macros, a custom `validate()`, the `Errors` object (`full_messages()`, `on()`), `is_valid()` |
| [`relationships/`](relationships/) | `belongs_to`, `has_many`, `has_one`, `has_many … through` (incl. many-to-many), eager `include`, `create_*` builders |
| [`callbacks/`](callbacks/) | Lifecycle hooks (`before_validation`, `before_save`, `after_create`, `before_update`, `before_destroy`) and halting a save |
| [`attributes/`](attributes/) | Custom `get_*`/`set_*`, `$alias_attribute`, `$attr_accessible`, `$delegate`, dirty tracking (`is_dirty`, `dirty_attributes`) |
| [`serialization/`](serialization/) | `to_json` / `to_xml` / `to_array` with `only` / `except` / `methods` / `include` |
| [`finders/`](finders/) | Dynamic finders, the `conditions`/`order`/`limit`/`offset`/`group`/`having`/`select` option set, `find_by_sql`, static scopes |

The older [`simple/`](simple/), [`orders/`](orders/) and [`upsert/`](upsert/)
examples target **MySQL** and need a running server + database.
