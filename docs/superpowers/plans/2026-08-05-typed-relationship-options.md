# Typed Relationship Options Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the `$has_many` / `$has_one` / `$belongs_to` model-config arrays a typed internal representation (`RelationshipOptions`), clear runtime validation of already-failing input, and PHPStan/IDE support — with zero break to any consumer model or public API.

**Architecture:** A new `final` readonly value object `ActiveRecord\RelationshipOptions` is the single linchpin. `AbstractRelationship::__construct()` builds one (`$this->def`) from the raw definition array — this is the runtime-validation point. The existing mutable `$this->options` array is **kept** (it is a finder-options scratchpad, mutated in `HasMany::load()`); only constructor-time *declaration* reads migrate onto `$this->def`. A PHPStan `@phpstan-type Relationship` alias + untyped PHPDoc'd base properties on `Model` + a shipped stub deliver static analysis.

**Tech Stack:** PHP 8.3+, PHPUnit (snake_case wrapper base class), PHPStan level 8, php-cs-fixer (PER-CS 3.0), Docker (`tests` service).

## Global Constraints

- **PHP floor ≥ 8.3**; modern idioms (short arrays `[]`, typed properties, promoted readonly params, `??`, `match`). Applies to all code and docs.
- **snake_case public API** — do not rename existing methods/options/properties to camelCase.
- **Backward compatibility is a hard gate.** No public method signature / return-type / thrown-exception-type / observable-behavior change; no tightening of input handling. The one clarified failure (missing relationship name) is pre-approved in the spec (§4.4); anything stricter is out of scope.
- **PHPStan baseline (`phpstan-baseline.neon`) is frozen** — achieve green by fixing code, **never add a new baseline entry**.
- **Skipped tests fail the build** (`--fail-on-skipped`) — no test may skip in Docker.
- **New `lib/` files must be added by hand to `ActiveRecord.php`** (no PSR-4 autoloader for the library).
- **Coding style** enforced by `composer run cs`; apply with `composer run cs-fix`. Minimal, behavior-preserving edits in `lib/Relationship.php` (documented bug hot-spot) — no drive-by refactoring (YAGNI).
- All tooling runs through Docker: `docker compose exec tests …` (DB/memcached/redis containers must be up: `docker compose up -d`).

### Design notes locked during planning (read before starting)

- **`$this->options` is NOT removed.** Grep confirmed it is a mutable finder-options bag: `HasMany::load()` writes `$this->options['joins']` (`lib/Relationship.php:645`) and passes it through `unset_non_finder_options()` into `find()`. The DTO holds the *declared* definition; the scratchpad stays. Task 3 migrates only the declaration reads listed there and deliberately leaves `conditions`/`namespace`/`joins`/finder reads on `$this->options`.
- **`RelationshipOptions` is internal.** The relationship constructors keep accepting a raw `array` (the `InterfaceRelationship::__construct($options = [])` param is untyped — `lib/Relationship.php:19`). We do **not** add an `array|RelationshipOptions` union: `set_associations()` only ever passes arrays, and `$this->options` is still built from that array, so accepting a DTO would buy nothing (YAGNI). This is a deliberate, documented narrowing of spec §4.2.
- **One documented normalization:** scalar-typed options whose value is the wrong PHP type (e.g. a non-string `class_name`) are coerced to "absent" rather than throwing. These are already-broken inputs that fail today; we neither newly-reject valid input nor newly-accept invalid input. This keeps us PHPStan-clean without tightening.
- **`HasAndBelongsToMany` is an unimplemented stub** (`@todo implement me`, empty constructor that does not call `parent::__construct`). Task 2 adds one line so its `$def` is initialized (keeps the typed property always-initialized); no other HABTM behavior changes.

---

## File Structure

- **Create** `lib/RelationshipOptions.php` — the value object + `from_array()` factory + validation. One responsibility: normalize & validate a single relationship definition.
- **Create** `test/RelationshipOptionsTest.php` — pure unit tests (extends `SnakeCase_PHPUnit_Framework_TestCase`, no DB → cannot skip).
- **Create** `stubs/relationship-properties.stub` — PHPStan stub documenting the four `Model` properties for consumer projects.
- **Modify** `ActiveRecord.php` — register the new lib file in the require-manifest.
- **Modify** `lib/Relationship.php` — hold `$this->def` (Task 2); migrate declaration reads (Task 3); host the `@phpstan-type Relationship` alias (Task 4).
- **Modify** `lib/Model.php` — declare the four relationship properties untyped + PHPDoc (Task 4).
- **Modify** `composer.json` — wire the stub into the shipped `extra.phpstan` / `autoload` files list (Task 4).

---

## Task 1: `RelationshipOptions` value object + factory + validation

**Files:**
- Create: `lib/RelationshipOptions.php`
- Modify: `ActiveRecord.php` (add require after line 17)
- Test: `test/RelationshipOptionsTest.php`

**Interfaces:**
- Consumes: `ActiveRecord\RelationshipException` (already defined, `lib/Exceptions.php:141`).
- Produces:
  - `final class ActiveRecord\RelationshipOptions` with `public readonly` fields: `string $name`, `?string $class_name`, `list<string> $foreign_key`, `list<string> $primary_key`, `mixed $conditions`, `?string $select`, `?bool $readonly`, `?string $namespace`, `?string $order`, `?string $group`, `?string $having`, `?int $limit`, `?int $offset`, `?string $through`, `?string $source`.
  - `public static function from_array(array $definition): self` — throws `RelationshipException` when the name (index `0`) is missing/empty/non-string.

- [ ] **Step 1: Write the failing tests**

Create `test/RelationshipOptionsTest.php`:

```php
<?php

use ActiveRecord\RelationshipOptions;
use ActiveRecord\RelationshipException;

class RelationshipOptionsTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    public function test_name_is_read_from_index_zero()
    {
        $opts = RelationshipOptions::from_array(['books']);
        $this->assert_equals('books', $opts->name);
    }

    public function test_class_key_takes_precedence_over_class_name()
    {
        $opts = RelationshipOptions::from_array(['books', 'class' => 'Book', 'class_name' => 'Ignored']);
        $this->assert_equals('Book', $opts->class_name);
    }

    public function test_class_name_used_when_class_absent()
    {
        $opts = RelationshipOptions::from_array(['books', 'class_name' => 'Book']);
        $this->assert_equals('Book', $opts->class_name);
    }

    public function test_scalar_foreign_key_is_wrapped_to_list()
    {
        $opts = RelationshipOptions::from_array(['books', 'foreign_key' => 'author_id']);
        $this->assert_equals(['author_id'], $opts->foreign_key);
    }

    public function test_array_foreign_key_is_reindexed()
    {
        $opts = RelationshipOptions::from_array(['books', 'foreign_key' => [3 => 'a', 7 => 'b']]);
        $this->assert_equals(['a', 'b'], $opts->foreign_key);
    }

    public function test_absent_keys_default_to_empty_or_null()
    {
        $opts = RelationshipOptions::from_array(['books']);
        $this->assert_equals([], $opts->foreign_key);
        $this->assert_equals([], $opts->primary_key);
        $this->assert_null($opts->class_name);
        $this->assert_null($opts->through);
    }

    public function test_has_many_options_are_captured()
    {
        $opts = RelationshipOptions::from_array(['payments', 'through' => 'orders', 'source' => 'payment', 'primary_key' => 'user_id']);
        $this->assert_equals('orders', $opts->through);
        $this->assert_equals('payment', $opts->source);
        $this->assert_equals(['user_id'], $opts->primary_key);
    }

    public function test_missing_name_throws_relationship_exception()
    {
        $this->expectException(RelationshipException::class);
        RelationshipOptions::from_array(['class_name' => 'Book']); // no index 0
    }

    public function test_empty_string_name_throws()
    {
        $this->expectException(RelationshipException::class);
        RelationshipOptions::from_array(['']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec tests vendor/bin/phpunit --filter RelationshipOptionsTest`
Expected: FAIL — `Class "ActiveRecord\RelationshipOptions" not found`.

- [ ] **Step 3: Create the value object**

Create `lib/RelationshipOptions.php`:

```php
<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Typed, normalized representation of a single relationship definition — one element of a
 * model's $has_many / $has_one / $belongs_to array.
 *
 * Built once per relationship per model class by {@see Table::set_associations()}, behind the
 * per-class Table cache; never constructed on a per-row or per-query hot path.
 *
 * `$class_name` is the *raw declared* class (from the `class` or `class_name` key) before any
 * namespace resolution — {@see AbstractRelationship::set_class_name()} still performs that.
 *
 * @package ActiveRecord
 */
final class RelationshipOptions
{
    /**
     * @param list<string> $foreign_key
     * @param list<string> $primary_key
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $class_name = null,
        public readonly array $foreign_key = [],
        public readonly array $primary_key = [],
        public readonly mixed $conditions = null,
        public readonly ?string $select = null,
        public readonly ?bool $readonly = null,
        public readonly ?string $namespace = null,
        public readonly ?string $order = null,
        public readonly ?string $group = null,
        public readonly ?string $having = null,
        public readonly ?int $limit = null,
        public readonly ?int $offset = null,
        public readonly ?string $through = null,
        public readonly ?string $source = null,
    ) {
    }

    /**
     * Build a validated, normalized instance from a raw definition array (as produced by
     * {@see wrap_strings_in_arrays()} in {@see Table::set_associations()}).
     *
     * @param array<int|string, mixed> $definition
     *
     * @throws RelationshipException when the relationship name (index 0) is missing/empty
     */
    public static function from_array(array $definition): self
    {
        $name = $definition[0] ?? null;
        if (!is_string($name) || '' === $name) {
            throw new RelationshipException('Relationship definition is missing its name (expected a non-empty string at index 0).');
        }

        $class = $definition['class'] ?? $definition['class_name'] ?? null;

        return new self(
            name: $name,
            class_name: is_string($class) ? $class : null,
            foreign_key: self::to_list($definition['foreign_key'] ?? null),
            primary_key: self::to_list($definition['primary_key'] ?? null),
            conditions: $definition['conditions'] ?? null,
            select: self::as_string($definition['select'] ?? null),
            readonly: self::as_bool($definition['readonly'] ?? null),
            namespace: self::as_string($definition['namespace'] ?? null),
            order: self::as_string($definition['order'] ?? null),
            group: self::as_string($definition['group'] ?? null),
            having: self::as_string($definition['having'] ?? null),
            limit: self::as_int($definition['limit'] ?? null),
            offset: self::as_int($definition['offset'] ?? null),
            through: self::as_string($definition['through'] ?? null),
            source: self::as_string($definition['source'] ?? null),
        );
    }

    private static function as_string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function as_int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private static function as_bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * Normalize a scalar or array option into a reindexed list of strings, mirroring the
     * relationship constructors' historical `is_array(...) ? array_values(...) : [scalar]`.
     * Non-scalar members of an array are dropped (already-malformed input).
     *
     * @return list<string>
     */
    private static function to_list(mixed $value): array
    {
        if (null === $value || '' === $value || [] === $value) {
            return [];
        }

        $items = is_array($value) ? array_values($value) : [$value];
        $out = [];
        foreach ($items as $item) {
            if (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }

        return $out;
    }
}
```

- [ ] **Step 4: Register the file in the manifest**

Modify `ActiveRecord.php` — add after line 17 (`require __DIR__ . '/lib/Exceptions.php';`):

```php
require __DIR__ . '/lib/RelationshipOptions.php';
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker compose exec tests vendor/bin/phpunit --filter RelationshipOptionsTest`
Expected: PASS (9 tests).

- [ ] **Step 6: Style + static analysis**

Run: `docker compose exec tests composer run cs-fix`
Run: `docker compose exec tests composer run analyse`
Expected: cs-fix clean; PHPStan green with **no new baseline entries**.

- [ ] **Step 7: Commit**

```bash
git add lib/RelationshipOptions.php test/RelationshipOptionsTest.php ActiveRecord.php
git commit -m "feat: add RelationshipOptions value object with validation

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Wire `$this->def` into the relationship constructors (validation goes live)

**Files:**
- Modify: `lib/Relationship.php` (add `$def` property + build it in `AbstractRelationship::__construct`; init in `HasAndBelongsToMany::__construct`)
- Test: `test/RelationshipOptionsWiringTest.php` (create)

**Interfaces:**
- Consumes: `RelationshipOptions::from_array()` (Task 1).
- Produces: `protected RelationshipOptions $def;` available to all `AbstractRelationship` subclasses (`HasMany`, `HasOne`, `BelongsTo`). Read by Task 3.

- [ ] **Step 1: Write the failing test**

Create `test/RelationshipOptionsWiringTest.php` (unit-level: the missing-name case throws inside the constructor, before any DB access):

```php
<?php

use ActiveRecord\HasMany;
use ActiveRecord\BelongsTo;
use ActiveRecord\RelationshipException;

class RelationshipOptionsWiringTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    public function test_has_many_without_name_throws_relationship_exception()
    {
        $this->expectException(RelationshipException::class);
        new HasMany(['class_name' => 'Book']); // no index 0
    }

    public function test_belongs_to_without_name_throws_relationship_exception()
    {
        $this->expectException(RelationshipException::class);
        new BelongsTo(['class_name' => 'Author']); // no index 0
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec tests vendor/bin/phpunit --filter RelationshipOptionsWiringTest`
Expected: FAIL — today a missing index `0` yields a PHP "Undefined array key 0" warning / downstream `TypeError`, not a `RelationshipException`.

- [ ] **Step 3: Add the `$def` property**

Modify `lib/Relationship.php`, in `AbstractRelationship`, immediately after the `$attribute_name` property declaration (near line 57):

```php
    /**
     * Typed, validated view of the declared relationship definition.
     */
    protected RelationshipOptions $def;
```

- [ ] **Step 4: Build `$def` as the first statement of the constructor**

Modify `AbstractRelationship::__construct` (`lib/Relationship.php:100`) — insert as the **first** line of the body, before `$this->attribute_name = $options[0];`:

```php
        $this->def = RelationshipOptions::from_array($options);
```

(Leave every other line of the constructor unchanged in this task — validation is now active; reads migrate in Task 3.)

- [ ] **Step 5: Initialize `$def` on the HABTM stub**

Modify `HasAndBelongsToMany::__construct` (`lib/Relationship.php:750`) — add as the first line inside the currently-empty body (keeps the typed property initialized for the stub; no other behavior change):

```php
        $this->def = RelationshipOptions::from_array($options);
```

- [ ] **Step 6: Run the new test + the full relationship suite**

Run: `docker compose exec tests vendor/bin/phpunit --filter RelationshipOptionsWiringTest`
Expected: PASS.
Run: `docker compose exec tests vendor/bin/phpunit test/RelationshipTest.php`
Expected: PASS (no regressions — existing Author/Venue/Event fixtures still construct).

- [ ] **Step 7: Full suite + static analysis**

Run: `docker compose exec tests composer run test`
Run: `docker compose exec tests composer run analyse`
Expected: suite green, no skips; PHPStan green, no new baseline entries.

- [ ] **Step 8: Commit**

```bash
git add lib/Relationship.php test/RelationshipOptionsWiringTest.php
git commit -m "feat: build validated RelationshipOptions in relationship constructors

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Migrate constructor-time declaration reads onto `$this->def`

Scope: **only** the declaration reads below. `$this->options` remains the finder-options scratchpad (conditions/namespace/joins and everything consumed by `HasMany::load()` stay on it, untouched).

**Files:**
- Modify: `lib/Relationship.php` (`AbstractRelationship::__construct`, `HasMany::__construct`)
- Test: reuse `test/RelationshipTest.php` + Task 1/2 tests (behavior must be identical).

**Interfaces:**
- Consumes: `$this->def` (Task 2). Fields used here: `class_name`, `foreign_key`, `through`, `source`, `primary_key`.
- Produces: no new public surface; internal reads are now typed (`string|null` / `list<string>` instead of `mixed` array lookups).

- [ ] **Step 1: Confirm the safety net is green before editing the hot-spot**

Run: `docker compose exec tests vendor/bin/phpunit test/RelationshipTest.php test/RelationshipHashConditionsTest.php`
Expected: PASS. (These are the regression oracle for this task.)

- [ ] **Step 2: Migrate `class_name` + `foreign_key` reads in `AbstractRelationship::__construct`**

In `lib/Relationship.php`, replace the `class`/`class_name` branch (currently lines 115-118):

```php
        if (isset($this->options['class'])) {
            $this->set_class_name($this->options['class']);
        } elseif (isset($this->options['class_name'])) {
            $this->set_class_name($this->options['class_name']);
        }
```

with:

```php
        if (null !== $this->def->class_name) {
            $this->set_class_name($this->def->class_name);
        }
```

Then replace the `foreign_key` block (currently lines 123-124):

```php
        if (!$this->foreign_key && isset($this->options['foreign_key'])) {
            $this->foreign_key = is_array($this->options['foreign_key']) ? array_values($this->options['foreign_key']) : [$this->options['foreign_key']];
        }
```

with:

```php
        if (!$this->foreign_key && $this->def->foreign_key) {
            $this->foreign_key = $this->def->foreign_key;
        }
```

Leave the `conditions` normalization (lines 111-112) and everything else on `$this->options` as-is.

- [ ] **Step 3: Migrate `through` / `source` / `primary_key` reads in `HasMany::__construct`**

In `lib/Relationship.php`, replace the block (currently lines 582-591):

```php
        if (isset($this->options['through'])) {
            $this->through = $this->options['through'];

            if (isset($this->options['source'])) {
                $this->set_class_name($this->options['source']);
            }
        }

        if (!$this->primary_key && isset($this->options['primary_key'])) {
            $this->primary_key = is_array($this->options['primary_key']) ? array_values($this->options['primary_key']) : [$this->options['primary_key']];
        }
```

with:

```php
        if (null !== $this->def->through) {
            $this->through = $this->def->through;

            if (null !== $this->def->source) {
                $this->set_class_name($this->def->source);
            }
        }

        if (!$this->primary_key && $this->def->primary_key) {
            $this->primary_key = $this->def->primary_key;
        }
```

- [ ] **Step 4: Run the regression oracle**

Run: `docker compose exec tests vendor/bin/phpunit test/RelationshipTest.php test/RelationshipHashConditionsTest.php`
Expected: PASS (identical behavior — this is a pure representation swap).

- [ ] **Step 5: Full suite + static analysis**

Run: `docker compose exec tests composer run test`
Run: `docker compose exec tests composer run analyse`
Expected: suite green, no skips; PHPStan green (reads are now `string|null`/`list<string>`, strictly better-typed than the previous `mixed` lookups) — **no new baseline entries**.

- [ ] **Step 6: Commit**

```bash
git add lib/Relationship.php
git commit -m "refactor: read relationship declaration options from typed RelationshipOptions

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Static-analysis + IDE layer (type alias, base PHPDoc, shipped stub)

**Files:**
- Modify: `lib/Relationship.php` (declare the `@phpstan-type Relationship` alias on `AbstractRelationship`)
- Modify: `lib/Model.php` (declare the four relationship properties untyped + PHPDoc)
- Create: `stubs/relationship-properties.stub`
- Modify: `composer.json` (ship the stub for consumer PHPStan)

**Interfaces:**
- Consumes: nothing at runtime.
- Produces: a documented array-shape `Relationship`; property existence + shape hints on `Model` for IDEs and consumer PHPStan.

- [ ] **Step 1: Declare the type alias**

In `lib/Relationship.php`, add to the `AbstractRelationship` class docblock (the block ending at line 49, above `abstract class AbstractRelationship`):

```php
 * @phpstan-type Relationship array{
 *     0: string, class_name?: string, class?: string,
 *     foreign_key?: string|list<string>, primary_key?: string|list<string>,
 *     conditions?: mixed, select?: string, readonly?: bool,
 *     order?: string, group?: string, having?: string, limit?: int, offset?: int,
 *     through?: string, source?: string
 * }
```

- [ ] **Step 2: Declare the base properties on `Model` (untyped + PHPDoc)**

In `lib/Model.php`, add these four static properties to the `Model` class body (place them near the top of the class, after the existing static config docblock). They are **untyped** — proven backward compatible with existing untyped consumer declarations (a native type would fatal them):

```php
    /**
     * @var array<int, Relationship|string>|string|null
     * @phpstan-var array<int, \ActiveRecord\Relationship>|string|null
     */
    public static $has_many;

    /**
     * @var array<int, Relationship|string>|string|null
     * @phpstan-var array<int, \ActiveRecord\Relationship>|string|null
     */
    public static $has_one;

    /**
     * @var array<int, Relationship|string>|string|null
     * @phpstan-var array<int, \ActiveRecord\Relationship>|string|null
     */
    public static $belongs_to;

    /**
     * @var array<int, Relationship|string>|string|null
     * @phpstan-var array<int, \ActiveRecord\Relationship>|string|null
     */
    public static $has_and_belongs_to_many;
```

Add the alias import to the `Model` class docblock so `Relationship` resolves inside `Model`:

```php
 * @phpstan-import-type Relationship from AbstractRelationship
```

- [ ] **Step 3: Verify backward compatibility empirically**

Run: `docker compose exec tests vendor/bin/phpunit test/RelationshipTest.php test/ActiveRecordTest.php`
Expected: PASS. In particular, models that declare `public static $has_one;` untyped (e.g. `test/models/Venue.php:14`) must still load with **no** `Type of ... must be array` fatal — the base declarations are untyped precisely to avoid that.

- [ ] **Step 4: Create the consumer stub**

Create `stubs/relationship-properties.stub`:

```php
<?php

namespace ActiveRecord;

/**
 * @phpstan-type Relationship array{
 *     0: string, class_name?: string, class?: string,
 *     foreign_key?: string|list<string>, primary_key?: string|list<string>,
 *     conditions?: mixed, select?: string, readonly?: bool,
 *     order?: string, group?: string, having?: string, limit?: int, offset?: int,
 *     through?: string, source?: string
 * }
 */
class Model
{
    /** @var array<int, Relationship|string>|string|null */
    public static $has_many;

    /** @var array<int, Relationship|string>|string|null */
    public static $has_one;

    /** @var array<int, Relationship|string>|string|null */
    public static $belongs_to;

    /** @var array<int, Relationship|string>|string|null */
    public static $has_and_belongs_to_many;
}
```

- [ ] **Step 5: Ship the stub via composer**

Read `composer.json` first to match the existing structure, then add the stub under `extra.phpstan.includes` (create the keys if absent) so consumer projects using `phpstan/extension-installer` pick it up:

```json
    "extra": {
        "phpstan": {
            "includes": [
                "stubs/relationship-properties.stub"
            ]
        }
    }
```

Also confirm the library's own `phpstan.neon`/`phpstan.dist.neon` references the stub via `parameters.stubFiles` (add `stubs/relationship-properties.stub` if the project analyses itself against it). If adding it triggers duplicate-symbol issues against `lib/Model.php`, prefer shipping the stub for consumers only (composer `extra`) and rely on the in-source PHPDoc for the library's own analysis — document whichever choice you make in the commit message.

- [ ] **Step 6: Full suite + static analysis + style**

Run: `docker compose exec tests composer run cs-fix`
Run: `docker compose exec tests composer run test`
Run: `docker compose exec tests composer run analyse`
Expected: all green, no skips, **no new baseline entries**.

- [ ] **Step 7: Commit**

```bash
git add lib/Relationship.php lib/Model.php stubs/relationship-properties.stub composer.json
git commit -m "feat: PHPStan type alias + base property PHPDoc + consumer stub for relationships

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Amendment (2026-08-05 maintainer ruling, applied in Task 3 fix round 1)

Task 3's read-swap made the DTO's silent coercion of malformed input observable. The maintainer
ruled for **explicit validation** instead of silent coercion. `RelationshipOptions::from_array()`
therefore **throws `RelationshipException`** on present-but-malformed values (non-string, or
empty string; for the key fields, a non-empty-string list) for the five *consumed* fields —
`class`/`class_name`, `foreign_key`, `primary_key`, `through`, `source`. Pass-through finder
metadata (`select`, `order`, `group`, `having`, `namespace`, `limit`, `offset`, `readonly`) stays
lenient. This only turns already-failing malformed inputs into clear errors; the full suite went
946 → 952 (6 new throw-tests) with no pre-existing failures. See spec §4.4 and commit `4e25aa1`.
The Task 1/2 code blocks above show the *originally planned* coercing form; the shipped `from_array`
is the stricter form described here.

## Out of scope (require separate maintainer approval — do NOT implement here)

- **Further** strict validation: rejecting *unknown* option keys, or invalid option/type *combinations* (e.g. `through` on `belongs_to`). Still a breaking tightening. (Present-but-malformed-value checks on the five consumed fields were pulled into scope by the ruling above.)
- Native property typing on `Model` (e.g. `public static array $has_many`) — fatals existing untyped consumer declarations.
- Replacing the array API with attributes/DTO-on-model.

## Self-review (completed during planning)

- **Spec coverage:** goal 1 (static analysis) → Task 4; goal 2 (IDE) → Task 4; goal 3 (runtime validation) → Tasks 1–2; idea "C" (normalization/internal typing) → Tasks 1–3. §2 rejection of native `array` → "Out of scope" + Task 4 Step 3 verifies the untyped choice. §6 performance is a spec property, not an implementation step (nothing to build). §7 follow-ups → "Out of scope".
- **Placeholder scan:** every code/test step contains complete code; commands are exact Docker invocations.
- **Type consistency:** `RelationshipOptions::from_array()` and the `$def` field name/type are identical across Tasks 1–3; the `Relationship` alias shape is identical in `lib/Relationship.php` (Task 4 Step 1) and the stub (Task 4 Step 4).
- **Deviation from spec (documented):** the constructors accept only `array` (not `array|RelationshipOptions`) — justified in "Design notes locked during planning."
