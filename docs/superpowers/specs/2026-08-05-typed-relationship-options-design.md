# Design: Typed relationship definitions (validation + static-analysis layer)

**Date:** 2026-08-05
**Status:** Implemented on PR #24. **The shipped architecture differs from the DTO design in
§4/§8 below — see "Final implementation" first.**
**Scope:** Give the `$has_many` / `$has_one` / `$belongs_to` / `$has_and_belongs_to_many`
model-configuration arrays clear runtime validation and static-analysis/IDE support. One
**maintainer-authorized breaking change** shipped (unknown option keys now throw — see
Final implementation); everything else preserves existing consumer models and the public API.

## Final implementation (what shipped in PR #24 — supersedes §4 and §8)

The design below explored a `RelationshipOptions` **value object** as the linchpin. It was
built, then progressively simplified and ultimately **removed** (maintainer rulings of
2026-08-05/06). What actually shipped:

- **No DTO class.** Relationship declarations are validated **inline in the constructors**
  (`AbstractRelationship`, `HasMany`, `HasAndBelongsToMany`) via two reusable free functions
  in `lib/Utils.php`:
  - `relationship_option_string(mixed $v, string $rel, string $opt): ?string` — must be a
    non-empty string when present, else `RelationshipException`.
  - `relationship_option_key_list(mixed $v, string $rel, string $opt): list<string>` — a
    non-empty string or list of non-empty strings; absent / `null` / `[]` → `[]`.
  These cover the five consumed fields (`class`/`class_name`, `foreign_key`, `primary_key`,
  `through`, `source`) plus the positional name at `[0]`.
- **Unknown option keys now throw** (the breaking change). `merge_association_options()`
  rejects any key not in the relationship's `$valid_association_options` (positional integer
  keys excepted) with a clear `RelationshipException`. This **reverses the library's
  previously-tested "silently ignore unknown options" contract** and was explicitly
  authorized by the maintainer. The documenting test was rewritten
  (`test_belongs_to_with_an_invalid_option` → `test_belongs_to_with_an_unknown_option_throws`).
- **Static-analysis / IDE layer unchanged** (§4.3): the `@phpstan-type Relationship` alias,
  the untyped-PHPDoc base `Model` properties, and the shipped stub remain — *they*, not the
  DTO, deliver the analysis/IDE goals. The alias is the human-readable companion to the now
  runtime-authoritative `$valid_association_options`.
- **Validation helpers are unit-tested directly** (`test/UtilsTest.php`) **and** via the
  constructors (`test/RelationshipOptionsWiringTest.php`).

Why the DTO was dropped: it was built, read only within the constructor, then discarded — it
persisted no state used after construction (the real state lives in `$this->class_name` /
`foreign_key` / `through` / `primary_key`). Inline validation via shared helpers delivers the
same guarantees with less machinery, matching the legacy constructor's existing inline style.
Verified green on the full CI matrix (PHP 8.3–8.5 × mysql/mariadb/postgres, + PHPStan level 8).

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

> **Superseded — see "Final implementation" at the top.** §4 and §8 describe the original
> `RelationshipOptions` value-object approach; the DTO was ultimately removed in favor of
> inline validation via `lib/Utils.php` helpers. Kept below for design-decision history.

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
  This is backward compatible for the way consumer models actually declare these arrays:
  an untyped base property coexists with all three existing consumer shapes (`['books']`,
  `[['author']]`, `public static $has_one;`) with no fatal, and `null` defaults are still
  skipped by `set_associations()`. Native typing is **not** added (that is the rejected
  break of §2).

  **One-directional caveat (maintainer-acknowledged, 2026-08-05).** PHP property-type
  compatibility cuts both ways: just as a *typed* base fatals an *untyped* child (the §2
  rejection), an *untyped* base fatals a child that declares the property with a *native*
  type — e.g. a consumer writing `public static array $has_many = [...]` would now fatal
  with *"Type of … must be omitted to match the parent definition"*. This is a genuine (if
  narrow) new constraint. It was accepted because it is **consistent with the pre-existing
  contract**: `Model` already declares `$alias_attribute`, `$attr_accessible`,
  `$attr_protected`, and `$delegate` untyped, so "do not natively-type a `Model` static
  config array" is an established rule this change merely extends to four more properties.
  No in-repo model (test, example, or library) natively-types these, so the practical blast
  radius is nil.
- A **stub file** shipped with the library (referenced from `composer.json`) so consumer
  projects' PHPStan learns the property shapes. Honest limitation: the library's own
  level-8 pass gains little from the stub (it reads via reflection → `mixed`); the real
  internal typing win comes from §4.2's migrated `$this->def->x` reads. IDE autocomplete of
  *inner keys* stays partial (array-shape support is IDE-dependent) — full key autocomplete
  is not achievable without leaving plain arrays, which was explicitly out of scope.

### 4.4 Validation policy (goal 3)

`CLAUDE.md` classifies *tightening input handling* as a breaking change, so the DTO factory's
validation was originally scoped to only clarify existing failures. During implementation the
maintainer **explicitly authorized** a bounded tightening (ruling of 2026-08-05, recorded in
the SDD ledger); the policy is therefore:

- **Missing name** (`$options[0]` unset/empty/non-string) → clear `RelationshipException`
  (was a confusing undefined-key error).
- **Present-but-malformed values on the five *consumed* fields** — `class`/`class_name`,
  `foreign_key`, `primary_key`, `through`, `source` — throw a clear `RelationshipException`
  when the value is not a non-empty string (or, for the key fields, a list of non-empty
  strings). These inputs already failed today (SQL error on an empty/`null` key column, or a
  `TypeError` in `set_class_name()` on a non-string); the swap in §4.2 made the DTO's earlier
  silent coercion observable, and the maintainer chose an explicit error over silent coercion.
  **No previously-*valid* declaration is affected** — the full suite stayed green (946 → 952
  with the new tests), confirming no fixture relied on the old lenient behavior.

Then, in a later ruling (2026-08-06), the maintainer went further:

- **Unknown option keys now throw** `RelationshipException` (was: silently ignored). Enforced
  in `merge_association_options()`, excluding the positional integer key(s). This is the one
  **breaking change** in the change set — see "Final implementation".

Still lenient:

- **Pass-through finder metadata values** (`select`, `order`, `group`, `having`, `limit`,
  `offset`, `readonly`) are not *type*-validated — only their *keys* must be known. Their
  values still flow to the finder untouched, so e.g. `'limit' => '10'` is accepted as before.

## 5. Backward compatibility

The implementation steps in §8 are all non-breaking, verified against the constraints above:

- Public constructor signature preserved (`array` still accepted).
- No native property types added; base declarations are untyped + PHPDoc only.
- No previously-*valid* input is rejected. The bounded tightening in §4.4 (clear errors on
  present-but-malformed values for the five consumed fields) was **explicitly approved by the
  maintainer** during implementation, per `CLAUDE.md`'s hard gate; it only turns already-failing
  malformed inputs into clear errors (suite stayed green).
- New file added to the `ActiveRecord.php` manifest.

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

- **Rejecting invalid option/type *combinations*** (e.g. `through`/`source` on `belongs_to`)
  is still deferred. (Present-but-malformed *values* on the five consumed fields, and
  *unknown option keys*, were both pulled into scope by maintainer rulings — see §4.4 and
  "Final implementation".)
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
