# Design: Typed relationship definitions (`RelationshipOptions` DTO + static-analysis layer)

**Date:** 2026-08-05
**Status:** Draft (pending user review → implementation plan)
**Scope:** Give the `$has_many` / `$has_one` / `$belongs_to` / `$has_and_belongs_to_many`
model-configuration arrays a typed internal representation, clear runtime validation, and
static-analysis/IDE support — **without breaking any existing consumer model or public API.**

## 1. Goal

Three concrete outcomes the maintainer asked for, all as a **technical benefit** (not a
policy break):

1. **Static analysis** — PHPStan (level 8) and consumer PHPStan know the *shape* of a
   relationship definition.
2. **IDE support** — models get property existence + shape hints for the four relationship
   arrays.
3. **Runtime validation** — malformed definitions raise a clear `RelationshipException`
   instead of failing silently or with a confusing PHP error.

This is achieved by combining two originally-separate ideas into **one shared linchpin**: a
typed, readonly value object (`RelationshipOptions`) that is simultaneously the internal
normalization target (idea "C"), the runtime-validation point (goal 3), and the source of
the documented array-shape used for static analysis (goals 1–2, idea "A").

## 2. Why the alternative (native `array` type) was rejected

Declaring `public static array $has_many = []` on `Model` was considered and **rejected on
technical merit** (independently of the backward-compat break it also causes):

- PHP has no *shaped-array* type; native typing tops out at bare `array`, which validates
  only "is an array" — not the name, keys, `foreign_key` format, or option combinations.
- A bare native `array` is **weaker** for PHPStan than a PHPDoc `@var` shape (PHPStan treats
  it as `array<mixed,mixed>`); the shape has to be expressed in PHPDoc regardless — and the
  PHPDoc can be added *without* breaking anything.
- It delivers no internal framework typing (the framework reads definitions via reflection)
  and no IDE key autocomplete.

Empirically, typing the property `array` on the base is also a hard backward-compat break:
an existing consumer model that declares `public static $has_one;` (untyped — as
`test/models/Venue.php:14` does) triggers a fatal
`Type of Child::$has_one must be array (as in class Base)`. So the native-type route pays
the full break cost while delivering the *weakest* slice of goal 3. The DTO route below
delivers all three goals with no break.

## 3. Background: how relationships are wired today (grounded in the code)

- `Table::load()` caches one `Table` per model class (`lib/Table.php:81–87`) and calls
  `set_associations()` **exactly once per class per process**.
- `set_associations()` (`lib/Table.php:706–743`) iterates `getStaticProperties()`, skips
  empty/`null` values (`if (!$definitions) continue;`), and `switch`es on the property
  **name** — so relationships are discovered by reflection on the name, not via late static
  binding. This is why the base class does *not* declare these properties.
- Each definition is `wrap_strings_in_arrays()`-normalized, then passed as a raw
  `array $options` to a relationship constructor (`HasMany`/`HasOne`/`BelongsTo`/
  `HasAndBelongsToMany`).
- The relationship constructors read `$options[0]` as the relationship name and pull option
  keys out of the array (`lib/Relationship.php:100–128`, `546–600`, `812+`).
- `merge_association_options()` (`lib/Relationship.php:302–312`) keeps only known keys via
  `array_intersect_key` — i.e. **unknown keys are silently dropped today** (a `class_nane`
  typo produces no error and a silently-broken relationship).

### Option vocabulary (the canonical shape)

Read directly from the constructors and `$valid_association_options`:

- **Positional:** `[0]` → relationship name (**required**).
- **Common** (`AbstractRelationship`): `class_name`, `class`, `foreign_key`, `conditions`,
  `select`, `readonly`, `namespace`.
- **HasMany / HasOne** (extra): `primary_key`, `order`, `group`, `having`, `limit`,
  `offset`, `through`, `source`.
- **BelongsTo:** `primary_key` (plus the common set).

## 4. Design

### 4.1 The linchpin: `RelationshipOptions` (new `lib/RelationshipOptions.php`)

A `final` readonly value object holding the normalized definition. Fields mirror §3's
vocabulary; heterogeneous inputs are normalized on the way in:

- `name: string` (from `[0]`; required)
- `class_name: ?string` (from `class_name`, falling back to `class`)
- `foreign_key: list<string>` / `primary_key: list<string>` (scalars wrapped to lists)
- `conditions: mixed`, `select: ?string`, `readonly: bool`, `namespace: ?string`
- `order: ?string`, `group: ?string`, `having: ?string`, `limit: ?int`, `offset: ?int`
- `through: ?string`, `source: ?string`

Construction goes through a static factory:

```php
public static function from_array(array $definition): self
```

The factory performs **validation that only clarifies failures that already occur today**
(see §4.4), then normalizes and returns the DTO.

> New file reminder: `lib/RelationshipOptions.php` must be added by hand to the
> `ActiveRecord.php` require-manifest (there is no PSR-4 autoloader for the library).

### 4.2 Wiring into the relationship constructors (idea "C") — non-breaking

The public constructor contract (`InterfaceRelationship::__construct(array $options)`) is
**preserved**. Constructors accept `array|RelationshipOptions`; when given an array they
build the DTO internally:

```php
$this->def = $options instanceof RelationshipOptions
    ? $options
    : RelationshipOptions::from_array($options);
```

Internal reads then migrate, field by field, from `$this->options['through']` (array hash
lookup) to `$this->def->through` (typed property). The old `$options`/`merge_association_options`
path is removed only once every read for that relationship class has moved over, with the
test suite green at each step.

### 4.3 Static-analysis + IDE layer (idea "A") — non-breaking

- A single canonical PHPStan type alias, declared once (on `AbstractRelationship`) and
  imported where needed:
  ```
  @phpstan-type Relationship = array{
      0: string, class_name?: string, class?: string,
      foreign_key?: string|list<string>, primary_key?: string|list<string>,
      conditions?: mixed, select?: string, readonly?: bool,
      order?: string, group?: string, having?: string, limit?: int, offset?: int,
      through?: string, source?: string
  }
  ```
- The four relationship properties are declared on `Model` **untyped** with a PHPDoc
  `@var` referencing the alias, e.g.:
  ```php
  /** @var array<int, Relationship|string>|null */
  public static $has_many;
  ```
  This is proven backward compatible: an untyped base property coexists with all three
  existing consumer shapes (`['books']`, `[['author']]`, `public static $has_one;`) with no
  fatal, and `null` defaults are still skipped by `set_associations()`. Native typing is
  **not** added (that is the rejected break of §2).
- A **stub file** shipped with the library (referenced from `composer.json`) so consumer
  projects' PHPStan learns the property shapes. Honest limitation: the library's own
  level-8 pass gains little from the stub (it reads via reflection → `mixed`); the real
  internal typing win comes from §4.2's migrated `$this->def->x` reads. IDE autocomplete of
  *inner keys* stays partial (array-shape support is IDE-dependent) — full key autocomplete
  is not achievable without leaving plain arrays, which was explicitly out of scope.

### 4.4 Validation policy (goal 3) — clarify, do not tighten

`CLAUDE.md` classifies *tightening input handling* as a breaking change. Therefore the DTO
factory, in this spec's scope, throws **only** for inputs that already fail today:

- **Missing name** (`$options[0]` unset) → today produces a confusing undefined-key error;
  becomes a clear `RelationshipException` ("relationship definition is missing its name").

Everything else that "works" today keeps working unchanged — in particular **unknown keys
are still tolerated** (silently ignored, matching current `array_intersect_key` behavior).

Stricter validation — rejecting unknown keys, or rejecting invalid option/type combinations
(e.g. `through`/`source` on `belongs_to`) — is **explicitly out of scope for this spec** and
listed as a follow-up for the maintainer to approve (§7), optionally behind an opt-in flag
or preceded by a deprecation notice.

## 5. Backward compatibility

The implementation steps in §8 are all non-breaking, verified against the constraints above:

- Public constructor signature preserved (`array` still accepted).
- No native property types added; base declarations are untyped + PHPDoc only.
- No previously-accepted input is rejected (validation only clarifies existing failures).
- New file added to the `ActiveRecord.php` manifest.

The one genuinely-breaking item (strict validation) is quarantined in §7 and gated on
explicit maintainer approval, per `CLAUDE.md`.

## 6. Performance

Measured on PHP 8.3 (this environment):

| Operation | Cost | Frequency |
|---|---|---|
| `RelationshipOptions::from_array()` (construct + validate) | ~190 ns / relationship | **once per model class per process** (behind `Table::$cache`) |
| read `$this->def->through` (typed property) | ~5.3 ns | per query / eager-load |
| read `$arr['through']` (today's array hash lookup) | ~11.8 ns | per query / eager-load |

Conclusion: **net-neutral to slightly positive.** DTO construction is a one-time,
per-class cost (tens/hundreds of µs total across a large app), dwarfed by a single query.
The per-query hot-path read is ~2× *faster* as a typed property than an array lookup, so the
§4.2 migration is not a cost. Per-row hydration is untouched (the DTO lives on the
`Relationship` object, built upstream of hydration).

## 7. Out of scope / follow-ups (require maintainer approval)

- **Strict validation** (reject unknown keys; reject invalid option/type combinations),
  possibly opt-in or behind a deprecation-warning phase.
- **Native property typing** / any 2.0-style standardization of consumer model declarations.
- Replacing the array API with attributes/DTO-on-model (explicitly excluded by the user).

## 8. Implementation sequence (each step independently shippable, suite green throughout)

1. Add `lib/RelationshipOptions.php` (DTO + `from_array` + missing-name validation) and
   register it in `ActiveRecord.php`; unit tests. **No wiring yet.**
2. Wire the DTO into the relationship constructors (`array|RelationshipOptions`), reading in
   parallel from both the DTO and the legacy `$this->options` with an equivalence assertion.
3. Migrate internal reads to the DTO field by field, deleting the legacy array reads; suite
   green after each field.
4. Add the `@phpstan-type Relationship` alias, the untyped-`+`-PHPDoc base properties on
   `Model`, and the shipped PHPStan stub.

## 9. Testing

- Unit tests for `RelationshipOptions::from_array()`: each existing input shape
  (`'books'`, `['books']`, `[['author'], 'class_name' => ...]`), scalar-vs-list
  `foreign_key`/`primary_key` normalization, `class`→`class_name` fallback, and the
  missing-name `RelationshipException`.
- Regression coverage through existing model fixtures (`Author`, `Venue`, `Event`,
  through-relationships) exercising `Model`/`Table`/`Relationship`, per the repo's
  fixture-driven convention — not private-helper unit tests.
- PHPStan level 8 stays green with **no new baseline entries**.
- Suite must not skip (skips fail the build).
