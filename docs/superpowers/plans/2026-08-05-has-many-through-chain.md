# has_many / has_one `:through` reverse-FK chain — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `has_many … 'through'` (and the inherited `has_one … 'through'`) work for the has_many→has_many chain, where the intermediate model `has_many` the target (reverse FK) — e.g. `Author has_many book_reviews, through: books` with `Book has_many book_reviews`.

**Architecture:** Approach B (additive). At through-setup time, resolve the *source association* on the middle model (the association pointing at the target). If it is a `BelongsTo`, keep the historical join-table code path **byte-for-byte unchanged**. If it is a `HasMany`, take a new reverse-FK branch that builds `INNER JOIN <middle> ON(<target>.<source_fk> = <middle>.<source_pk>)` and filters by the through model's own owner FK. Two new helpers (`resolve_source_relationship`, `construct_through_reverse_join_sql`) live on `AbstractRelationship`; the existing `construct_inner_join_sql` is not touched.

**Tech Stack:** PHP 8.3+, this repo's bespoke ActiveRecord ORM (`lib/*.php`, manual `require` manifest in `ActiveRecord.php`), PHPUnit via `DatabaseTest`, Docker Compose test harness, PHPStan level 8, php-cs-fixer (PER-CS 3.0).

## Global Constraints

- **Backward compatibility is a hard gate — additive only.** The `BelongsTo`/join-table `through` path must stay byte-for-byte unchanged; no public method, option key, return type, thrown-exception type, or observable behavior of an existing working case may change. (CLAUDE.md)
- **snake_case public API** — methods and options stay snake_case (`through`, `has_many`, `find_by_pk`). New *private/protected* helper names may be lowerCamel-free snake too; match surrounding style in `lib/Relationship.php` (existing privates are snake_case, e.g. `create_conditions_from_keys`).
- **Modern PHP ≥ 8.3** — short arrays `[]`, type declarations on new params/returns, `??`, typed where the surrounding code is typed.
- **PHPStan level 8, frozen baseline** — new code must not add entries to `phpstan-baseline.neon`. Achieve green by fixing code. (CLAUDE.md)
- **No skipped tests** — the suite runs `--fail-on-skipped`; new fixtures must load on **every** adapter (MySQL, MariaDB, Postgres, SQLite). (CLAUDE.md)
- **MySQL is the primary target**; Postgres and SQLite must stay green.
- **Coding style** — run `docker compose exec tests composer run cs-fix` before each commit; `composer run cs` must be clean.

### Docker test caveat (this worktree)

We are in the worktree `/Users/nicolasvaccari/dev/ristocloudgroup/php-activerecord-issue22`. The `tests` service mounts `.:/code` (`docker-compose.override.yml:5`), where `.` is the directory `docker compose` is invoked from. **Run all test commands from inside this worktree** so the container mounts *this* checkout:

```sh
cd /Users/nicolasvaccari/dev/ristocloudgroup/php-activerecord-issue22
docker compose up -d           # brings up an isolated compose project (dir-named); no host ports published, so it won't collide with the main checkout's stack
docker compose exec tests composer run test
docker compose exec tests composer run analyse
docker compose exec tests composer run cs
```

Single test while iterating:
`docker compose exec tests vendor/bin/phpunit --filter test_gh22_has_many_through_has_many_chain`

---

## File Structure

- `test/models/BookReview.php` **(create)** — minimal model mapping `book_reviews` (pk `id`). The middle→target `has_many` (`Book has_many book_reviews`) is declared per-test via static mutation, matching the existing `Venue` pattern.
- `test/sql/mysql.sql`, `test/sql/sqlite.sql`, `test/sql/pgsql.sql` **(modify)** — add the `book_reviews` grandchild table.
- `test/sql/pgsql-after-fixtures.sql` **(modify)** — reset the `book_reviews_id_seq` sequence after fixtures load.
- `test/fixtures/book_reviews.csv` **(create)** — deterministic rows spanning two authors' books.
- `lib/Relationship.php` **(modify)** — add `resolve_source_relationship()` and `construct_through_reverse_join_sql()` on `AbstractRelationship`; add the reverse-FK branch in `HasMany::load()` and in `AbstractRelationship::query_and_attach_related_models_eagerly()`.
- `test/RelationshipTest.php` **(modify)** — normalize `Author`/`Book` statics in `set_up()`; add four `test_gh22_*` tests.
- `RELEASES.md` **(modify)** — changelog entry.

---

## Task 1: `book_reviews` fixture, model, and load guard

**Files:**
- Create: `test/models/BookReview.php`
- Modify: `test/sql/mysql.sql` (after the `books` table, ~line 22), `test/sql/sqlite.sql`, `test/sql/pgsql.sql`, `test/sql/pgsql-after-fixtures.sql`
- Create: `test/fixtures/book_reviews.csv`
- Test: `test/RelationshipTest.php`

**Interfaces:**
- Produces: table `book_reviews(id, book_id, rating)`; model `BookReview` (`$table_name = 'book_reviews'`, pk `id`); fixture rows — review `1`→book `1`, review `2`→book `1`, review `3`→book `2`. Given the existing `books` fixture (book `1`→author `1`, book `2`→author `2`), this means author `1` reaches reviews `[1,2]` and author `2` reaches review `[3]`.

- [ ] **Step 1: Write the failing fixture-load test**

In `test/RelationshipTest.php`, add:

```php
    public function test_gh22_book_reviews_fixture_loads()
    {
        $this->assert_equals(3, count(BookReview::all()));
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_gh22_book_reviews_fixture_loads`
Expected: FAIL — no `book_reviews` table / no `BookReview` model.

- [ ] **Step 3: Create the model**

`test/models/BookReview.php`:

```php
<?php

class BookReview extends ActiveRecord\Model
{
    public static $table_name = 'book_reviews';
}
```

- [ ] **Step 4: Add the schema to all three adapters**

`test/sql/mysql.sql` — after the `books` table:

```sql
CREATE TABLE book_reviews(
	id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
	book_id INT,
	rating INT
);
```

`test/sql/sqlite.sql` — after the `books` table:

```sql
CREATE TABLE book_reviews(
	id INTEGER NOT NULL PRIMARY KEY,
	book_id INT,
	rating INT
);
```

`test/sql/pgsql.sql` — after the `books` table:

```sql
CREATE TABLE book_reviews(
	id SERIAL PRIMARY KEY,
	book_id INT,
	rating INT
);
```

- [ ] **Step 5: Reset the Postgres sequence after fixtures**

`test/sql/pgsql-after-fixtures.sql` — add a line (near the `books` one):

```sql
SELECT setval('book_reviews_id_seq', max(id)) FROM book_reviews;
```

- [ ] **Step 6: Create the fixture CSV**

`test/fixtures/book_reviews.csv`:

```
id,book_id,rating
1,1,5
2,1,3
3,2,4
```

- [ ] **Step 7: Run the test to verify it passes (all adapters)**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_gh22_book_reviews_fixture_loads`
Expected: PASS (default `mysql`). Then confirm Postgres and SQLite load too:
`docker compose exec tests env PHPAR_CONNECTION=pgsql vendor/bin/phpunit --filter test_gh22_book_reviews_fixture_loads`
`docker compose exec tests env PHPAR_CONNECTION=sqlite vendor/bin/phpunit --filter test_gh22_book_reviews_fixture_loads`
Expected: PASS on each (guards `--fail-on-skipped`). If `PHPAR_CONNECTION` is not the switch used by `test/helpers/config.php`, read that file and use the mechanism it defines to select the adapter.

- [ ] **Step 8: Commit**

```bash
git add test/models/BookReview.php test/sql/mysql.sql test/sql/sqlite.sql test/sql/pgsql.sql test/sql/pgsql-after-fixtures.sql test/fixtures/book_reviews.csv test/RelationshipTest.php
git commit -m "test: add book_reviews grandchild fixture for the through chain (#22)"
```

---

## Task 2: Source resolution + reverse-FK helper + lazy `has_many` chain

**Files:**
- Modify: `lib/Relationship.php` — add two protected helpers on `AbstractRelationship`; add the reverse branch in `HasMany::load()` (the `through` init block, ~lines 628–650)
- Modify: `test/RelationshipTest.php` — normalize `Author`/`Book` statics in `set_up()`; add the lazy chain test

**Interfaces:**
- Produces:
  - `protected function resolve_source_relationship(AbstractRelationship $through): ?AbstractRelationship` — returns the middle model's association pointing at this relationship's target, or `null`.
  - `protected function construct_through_reverse_join_sql(Table $middle_table, HasMany $source): string` — returns `INNER JOIN <middle> ON(<target>.<source_fk> = <middle>.<source_pk>)`.
- Consumes: `Utils::singularize()`, `Utils::pluralize()` (static); `Table::get_relationship()`, `Table::get_fully_qualified_table_name()`; `HasMany::set_keys()`, public `$foreign_key`, protected `$primary_key`.

- [ ] **Step 1: Normalize `Author`/`Book` statics in `set_up()` so per-test mutation can't leak**

In `test/RelationshipTest.php::set_up()`, after the existing `Host::$has_many = …;` line, add:

```php
        Author::$has_many = [['books']];
        Author::$has_one = [
            ['awesome_person', 'foreign_key' => 'author_id', 'primary_key' => 'author_id'],
            ['parent_author', 'class_name' => 'Author', 'foreign_key' => 'parent_author_id']];
        Book::$has_many = [];
        Book::$belongs_to = [['author']];
```

These restore the values already declared in `test/models/Author.php` / `Book.php`, so behavior for existing tests is unchanged; they only guarantee a clean slate before each test.

- [ ] **Step 2: Write the failing lazy chain test**

In `test/RelationshipTest.php`, add:

```php
    public function test_gh22_has_many_through_has_many_chain()
    {
        Book::$has_many = [['book_reviews']];
        Author::$has_many = [['books'], ['book_reviews', 'through' => 'books']];

        $reviews = Author::find(1)->book_reviews;

        $this->assert_equals(2, count($reviews));
        $ids = [$reviews[0]->id, $reviews[1]->id];
        sort($ids);
        $this->assert_equals([1, 2], $ids);
        $this->assert_true($reviews[0] instanceof BookReview);
    }
```

- [ ] **Step 3: Run it to verify it fails**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_gh22_has_many_through_has_many_chain`
Expected: FAIL with a `DatabaseException` — `no such column: author_id` / mangled join (the current bug).

- [ ] **Step 4: Add the two helpers on `AbstractRelationship`**

In `lib/Relationship.php`, inside `class AbstractRelationship`, add (place them next to `construct_inner_join_sql`, right after it):

```php
    /**
     * Resolves the association on the *through* (middle) model that points at
     * this relationship's target — the equivalent of Rails' "source".
     *
     * The middle model is the target of the `through` relationship. We probe its
     * declared associations by name, trying (in order): the singular of our
     * attribute name, that singular re-pluralized, and the attribute name
     * itself. The first that resolves wins; `null` means none matched (callers
     * then keep the historical join-table behavior).
     *
     * @param AbstractRelationship $through the owner's `through` relationship
     * @return AbstractRelationship|null
     */
    protected function resolve_source_relationship(AbstractRelationship $through): ?AbstractRelationship
    {
        $middle_table = $through->get_table();
        $singular = Utils::singularize($this->attribute_name);

        foreach ([$singular, Utils::pluralize($singular), $this->attribute_name] as $name) {
            $source = $middle_table->get_relationship($name);
            if (null !== $source) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Builds the INNER JOIN that hops from this relationship's target table to
     * the `through` (middle) table for the reverse-FK (has_many→has_many) chain:
     *
     *     INNER JOIN <middle> ON(<target>.<source_fk> = <middle>.<source_pk>)
     *
     * The keys come from the middle model's own `has_many` to the target
     * ($source). Kept separate from {@see construct_inner_join_sql()} so the
     * historical join-table path is left untouched (issue #22, Approach B).
     *
     * @param Table $middle_table the `through` model's table
     * @param HasMany $source the middle→target has_many association
     * @return string
     */
    protected function construct_through_reverse_join_sql(Table $middle_table, HasMany $source): string
    {
        $source->set_keys($middle_table->class->getName());
        if (null === $source->primary_key) {
            throw new RelationshipException("Could not determine source primary key for relationship '{$this->attribute_name}'");
        }

        $target_name = $this->get_table()->get_fully_qualified_table_name();
        $middle_name = $middle_table->get_fully_qualified_table_name();
        $target_fk = $source->foreign_key[0]; // FK on the target table (e.g. book_id)
        $middle_pk = $source->primary_key[0]; // middle PK (e.g. book_id)

        return "INNER JOIN $middle_name ON($target_name.$target_fk = $middle_name.$middle_pk)";
    }
```

Note: `$source->set_keys(...)` and `$source->primary_key` are protected `HasMany` members accessed from an `AbstractRelationship` (parent) method — this is exactly what the existing eager path already does at `Relationship.php:201`, so it is valid and PHPStan-clean.

- [ ] **Step 5: Add the reverse branch in `HasMany::load()`**

In `lib/Relationship.php`, the current `through` init block reads:

```php
                // save old keys as we will be reseting them below for inner join convenience
                $pk = $this->primary_key;
                $fk = $this->foreign_key;

                $this->set_keys($this->get_table()->class->getName(), true);

                $through_table = $through_relationship->get_table();
                $this->options['joins'] = $this->construct_inner_join_sql($through_table, true);

                // reset keys
                $this->primary_key = $pk;
                $this->foreign_key = $fk;
```

Replace that block with:

```php
                $source = $this->resolve_source_relationship($through_relationship);

                if ($source instanceof HasMany && $through_relationship instanceof HasMany) {
                    // Reverse-FK chain (issue #22): the middle model has_many the
                    // target, so hop target.<source_fk> = middle.<source_pk> and
                    // filter by the through model's own owner FK on the middle
                    // table. The owner FK column (e.g. books.author_id) is left
                    // unqualified in the condition: it is unambiguous because the
                    // target table does not carry it, and qualifying it would be
                    // mangled by quote_name().
                    $through_table = $through_relationship->get_table();
                    $this->options['joins'] = $this->construct_through_reverse_join_sql($through_table, $source);

                    $through_relationship->set_keys($model::table()->class->getName());
                    if (null === $through_relationship->primary_key) {
                        throw new RelationshipException("Could not determine primary key for relationship '{$this->attribute_name}'");
                    }
                    $this->foreign_key = [$through_relationship->foreign_key[0]];
                    $this->primary_key = $through_relationship->primary_key;
                } else {
                    // save old keys as we will be reseting them below for inner join convenience
                    $pk = $this->primary_key;
                    $fk = $this->foreign_key;

                    $this->set_keys($this->get_table()->class->getName(), true);

                    $through_table = $through_relationship->get_table();
                    $this->options['joins'] = $this->construct_inner_join_sql($through_table, true);

                    // reset keys
                    $this->primary_key = $pk;
                    $this->foreign_key = $fk;
                }
```

- [ ] **Step 6: Run the lazy chain test — verify it passes**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_gh22_has_many_through_has_many_chain`
Expected: PASS. If it fails with a bind/column error, inspect `Author::table()->last_sql` — the expected SQL is
`SELECT book_reviews.* FROM book_reviews INNER JOIN books ON(book_reviews.book_id = books.book_id) WHERE author_id = ?`.

- [ ] **Step 7: Run the existing `through` regression tests — verify still green**

Run: `docker compose exec tests vendor/bin/phpunit --filter has_many_through`
Expected: PASS — including `test_has_many_through`, `test_has_many_through_with_select`, `test_has_many_through_with_conditions`, `test_has_many_through_using_source`, `test_gh27_has_many_through_with_explicit_keys` (the untouched `BelongsTo` path).

- [ ] **Step 8: PHPStan + style**

Run: `docker compose exec tests composer run analyse && docker compose exec tests composer run cs`
Expected: level 8 clean, no baseline changes; style clean (run `cs-fix` first if needed).

- [ ] **Step 9: Commit**

```bash
git add lib/Relationship.php test/RelationshipTest.php
git commit -m "fix: has_many :through supports the has_many->has_many chain (#22)"
```

---

## Task 3: Lazy `has_one` chain (inherited — test-only)

**Files:**
- Test: `test/RelationshipTest.php`

**Interfaces:**
- Consumes: the Task 2 branch. `HasOne extends HasMany` with `poly_relationship = false`, so `HasOne::load()` is the inherited `HasMany::load()` and returns `find('first')`. No production code change is expected — this task proves the inheritance.

- [ ] **Step 1: Write the failing (expected-passing-after-Task-2) has_one test**

In `test/RelationshipTest.php`, add:

```php
    public function test_gh22_has_one_through_has_many_chain()
    {
        Book::$has_many = [['book_reviews']];
        Author::$has_one = [['book_review', 'through' => 'books']];

        $review = Author::find(1)->book_review;

        $this->assert_true($review instanceof BookReview);
        $this->assert_equals(1, $review->book_id);
    }
```

Resolution note: attribute `book_review` → `singularize` = `book_review` (no match on `Book`), `pluralize` = `book_reviews` (matches `Book has_many book_reviews`). Target class = `classify('book_review', singularize: true)` = `BookReview`. `find('first')` returns one review whose `book_id` is 1 (author 1 owns book 1; both reviews 1 and 2 have `book_id` 1, so the assertion is deterministic).

- [ ] **Step 2: Run it — verify it passes with no production change**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_gh22_has_one_through_has_many_chain`
Expected: PASS. If it fails, do **not** special-case `HasOne`; debug the shared `load()` branch from Task 2 (the fix must be in the inherited path).

- [ ] **Step 3: Commit**

```bash
git add test/RelationshipTest.php
git commit -m "test: has_one :through has_many chain returns a single record (#22)"
```

---

## Task 4: Eager (`include`) `has_many` chain

**Files:**
- Modify: `lib/Relationship.php` — the `through` branch in `AbstractRelationship::query_and_attach_related_models_eagerly()` (~lines 196–216)
- Test: `test/RelationshipTest.php`

**Interfaces:**
- Consumes: `resolve_source_relationship()`, `construct_through_reverse_join_sql()` (Task 2). At entry, `$query_key` = the owner FK (`author_id`, from `HasMany::load_eagerly()` calling `set_keys` → `keyify('Author')`), and the conditions built earlier in the method are already `author_id IN (…)` — correct for the reverse chain. The reverse branch must (a) build the reverse join, (b) expose the middle's owner FK in `select` so rows can be partitioned per owner, and (c) **not** null `$query_key`.

- [ ] **Step 1: Write the failing eager chain test**

In `test/RelationshipTest.php`, add:

```php
    public function test_gh22_eager_has_many_through_chain()
    {
        Book::$has_many = [['book_reviews']];
        Author::$has_many = [['books'], ['book_reviews', 'through' => 'books', 'order' => 'book_reviews.id asc']];

        $authors = Author::find('all', ['include' => ['book_reviews'], 'order' => 'author_id asc']);

        $by_id = [];
        foreach ($authors as $a) {
            $by_id[$a->author_id] = $a;
        }

        // author 1 -> reviews via book 1 ; author 2 -> review via book 2 ; authors 3,4 -> none
        $this->assert_equals([1, 2], array_map(fn ($r) => $r->id, $by_id[1]->book_reviews));
        $this->assert_equals([3], array_map(fn ($r) => $r->id, $by_id[2]->book_reviews));
        $this->assert_equals(0, count($by_id[3]->book_reviews ?? []));
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_gh22_eager_has_many_through_chain`
Expected: FAIL — today the eager `through` path builds the belongs_to-shaped join and nulls the match key, so results are wrong (mangled column error, or every review attached to every author).

- [ ] **Step 3: Add the reverse branch to the eager `through` block**

In `lib/Relationship.php`, the current block reads:

```php
        if (!empty($options['through'])) {
            // save old keys as we will be reseting them below for inner join convenience
            $pk = $this->primary_key;
            $fk = $this->foreign_key;

            $this->set_keys($this->get_table()->class->getName(), true);

            $through_relationship = $table->get_relationship($options['through'], true);
            if (null === $through_relationship) {
                throw new RelationshipException("Relationship named {$options['through']} has not been declared for class: {$table->class->getName()}");
            }
            $through_table = $through_relationship->get_table();

            $options['joins'] = $this->construct_inner_join_sql($through_table, true);

            $query_key = null;

            // reset keys
            $this->primary_key = $pk;
            $this->foreign_key = $fk;
        }
```

Replace it with:

```php
        if (!empty($options['through'])) {
            $through_relationship = $table->get_relationship($options['through'], true);
            if (null === $through_relationship) {
                throw new RelationshipException("Relationship named {$options['through']} has not been declared for class: {$table->class->getName()}");
            }
            $through_table = $through_relationship->get_table();
            $source = $this->resolve_source_relationship($through_relationship);

            if ($source instanceof HasMany) {
                // Reverse-FK chain (issue #22): join the middle table and expose
                // its owner FK (e.g. books.author_id) aliased onto every target
                // row so the matching loop below can partition per owner. The
                // owner FK stays as $query_key (already the owner FK here).
                $options['joins'] = $this->construct_through_reverse_join_sql($through_table, $source);
                $target_name = $this->get_table()->get_fully_qualified_table_name();
                $middle_name = $through_table->get_fully_qualified_table_name();
                $options['select'] = "$target_name.*, $middle_name.$query_key AS $query_key";
            } else {
                // Historical join-table / belongs_to shape — unchanged.
                $pk = $this->primary_key;
                $fk = $this->foreign_key;

                $this->set_keys($this->get_table()->class->getName(), true);
                $options['joins'] = $this->construct_inner_join_sql($through_table, true);

                $query_key = null;

                // reset keys
                $this->primary_key = $pk;
                $this->foreign_key = $fk;
            }
        }
```

- [ ] **Step 4: Run the eager chain test — verify it passes**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_gh22_eager_has_many_through_chain`
Expected: PASS. If `$by_id[3]->book_reviews` throws instead of being empty, check `Model::set_relationship_from_eager_load()` for the poly no-match case and adjust the assertion to the shape it produces (array vs null) — the `?? []` guard already tolerates null.

- [ ] **Step 5: Regression + PHPStan + style**

Run:
```
docker compose exec tests vendor/bin/phpunit --filter has_many_through
docker compose exec tests vendor/bin/phpunit --filter eager_loading
docker compose exec tests composer run analyse
docker compose exec tests composer run cs
```
Expected: all green; level 8 clean, no baseline changes; style clean.

- [ ] **Step 6: Commit**

```bash
git add lib/Relationship.php test/RelationshipTest.php
git commit -m "fix: eager-load has_many :through the has_many->has_many chain (#22)"
```

---

## Task 5: Eager `has_one` chain — documented last-wins (test-only)

**Files:**
- Test: `test/RelationshipTest.php`

**Interfaces:**
- Consumes: the Task 4 eager branch (inherited by `HasOne`). For a non-poly relationship the attach loop overwrites on each match, so when multiple targets match one owner the **last row wins**. This is pre-existing `has_one` eager behavior across the whole library — this task pins it down, it does not fix it.

- [ ] **Step 1: Write the last-wins test**

In `test/RelationshipTest.php`, add:

```php
    public function test_gh22_eager_has_one_through_chain_is_last_wins()
    {
        Book::$has_many = [['book_reviews']];
        Author::$has_one = [['book_review', 'through' => 'books', 'order' => 'book_reviews.id asc']];

        $authors = Author::find('all', ['include' => ['book_review'], 'order' => 'author_id asc']);

        $by_id = [];
        foreach ($authors as $a) {
            $by_id[$a->author_id] = $a;
        }

        // Author 1 matches reviews 1 and 2; eager has_one attaches sequentially
        // and overwrites, so the LAST row in our asc order (id 2) wins. This is
        // pre-existing has_one eager-load semantics, not a bug introduced here —
        // a first-wins implementation would have returned id 1.
        $this->assert_true($by_id[1]->book_review instanceof BookReview);
        $this->assert_equals(2, $by_id[1]->book_review->id);
    }
```

- [ ] **Step 2: Run it — verify it passes**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_gh22_eager_has_one_through_chain_is_last_wins`
Expected: PASS (single `BookReview`, id 2). If the row order is not honored, confirm the association `order` is carried into the eager finder; do not add production code to force "first".

- [ ] **Step 3: Commit**

```bash
git add test/RelationshipTest.php
git commit -m "test: document last-wins for eager has_one :through chain (#22)"
```

---

## Task 6: Full suite, changelog, finish

**Files:**
- Modify: `RELEASES.md`

- [ ] **Step 1: Full suite on the primary adapter**

Run: `docker compose exec tests composer run test`
Expected: entire suite green, **0 skipped** (`--fail-on-skipped`).

- [ ] **Step 2: Cross-adapter confirmation**

Run the suite against Postgres and SQLite using the adapter-selection mechanism in `test/helpers/config.php` (read it for the exact env var). Expected: green on both. This is the real guard that the reverse-FK join + unqualified `author_id` condition are portable.

- [ ] **Step 3: Add a changelog entry**

In `RELEASES.md`, under the current unreleased section, add:

```markdown
- Fixed `has_many … 'through'` (and the inherited `has_one … 'through'`) for the
  has_many→has_many chain, where the intermediate model `has_many` the target
  (reverse FK) — e.g. `Author has_many book_reviews, through: books`. Both lazy
  access and eager `include` are supported. The existing join-table / `belongs_to`
  `through` shape is unchanged. (#22)
```

- [ ] **Step 4: Final PHPStan + style gate**

Run: `docker compose exec tests composer run analyse && docker compose exec tests composer run cs`
Expected: both clean.

- [ ] **Step 5: Commit**

```bash
git add RELEASES.md
git commit -m "docs: RELEASES note for has_many/has_one :through chain fix (#22)"
```

- [ ] **Step 6: Hand off for branch finish**

The branch `fix/has-many-through-chain` now carries the design doc, the fix, and tests. Use the `finishing-a-development-branch` skill (or open a PR referencing issue #22) — do not merge without maintainer review, per the backward-compat gate.

---

## Self-Review

**Spec coverage:**
- Lazy `has_many` chain → Task 2. ✔
- Eager `has_many` chain (partitioned per owner) → Task 4. ✔
- `has_one … through` reverse-FK, lazy → Task 3; eager last-wins documented → Task 5. ✔
- Source-association resolution (singular → plural → attribute) → Task 2 `resolve_source_relationship`. ✔
- `BelongsTo` path byte-for-byte unchanged → Task 2/Task 4 keep the original lines in the `else`; `construct_inner_join_sql` untouched. ✔
- Fixtures reuse `authors→books` + one `book_reviews` grandchild across all adapters + Postgres sequence → Task 1. ✔
- Non-goals (write-side, `source_type`, nested through) → not implemented, by design. ✔
- Verification: full suite, cross-adapter, PHPStan level 8 no baseline, style → Task 6 (+ per-task gates). ✔

**Placeholder scan:** No TBD/TODO; every code step has literal code. The only conditional instructions are debug fallbacks (Task 4 Step 4, Task 6 Step 2) that point at a specific file to read — not deferred work.

**Type consistency:** `resolve_source_relationship(AbstractRelationship): ?AbstractRelationship` and `construct_through_reverse_join_sql(Table, HasMany): string` are used with matching signatures at both call sites; `$source instanceof HasMany` narrows before `construct_through_reverse_join_sql` receives it; `$query_key` is the same variable the surrounding eager method already defines. Test method names are unique and referenced consistently in their `--filter` runs.
