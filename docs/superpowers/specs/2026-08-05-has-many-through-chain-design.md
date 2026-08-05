# Design: fix `has_many … through` for the has_many→has_many chain (issue #22)

- **Issue:** #22 — `has_many … through` unsupported for has_many→has_many chains (only the join-table/HABTM shape works)
- **Date:** 2026-08-05
- **Target:** `master` @ 29c5367 (`PHP_ACTIVERECORD_VERSION_ID` 2.0.0)
- **Adapters:** the fix is adapter-independent (key/join derivation only); tests cover MySQL (primary), Postgres, SQLite.

## Problem

`has_many … 'through' => …` only works for the **join-table / HABTM shape**, where the
intermediate model `belongs_to` the target:

- ✅ **Works:** `Venue has_many hosts, through: events`, where `Event belongs_to host`
  (`events.host_id`). Covered by `test/RelationshipTest.php`.
- ❌ **Broken:** `Author has_many comments, through: posts`, where `Post has_many comments`
  (`Comment belongs_to Post`, a reverse-FK chain). Throws
  `DatabaseException: … no such column: <owner>_id`.

### Root cause (traced through `lib/Relationship.php`)

The `through` machinery derives its join and condition keys from **class-name inflection**
(`Inflector::keyify`) instead of from the actual associations. That happens to produce the
right columns for the belongs_to shape purely by naming coincidence, and produces invented
columns for the reverse-FK chain.

Tracing the broken case — `Author has_many comments through posts`, `Post has_many comments`:

- `HasMany::load()` calls `set_keys(get_class($model))` → `foreign_key = keyify('RAuthor') = 'rauthor_id'`.
- The through init block calls `set_keys($this->get_table()->class->getName(), true)` →
  `foreign_key = keyify('RComment') = 'rcomment_id'`, then builds the join via
  `construct_inner_join_sql($through_table, true)`.
- Result SQL:
  `SELECT comments.* FROM comments INNER JOIN posts ON(comments.id = posts.rcomment_id) WHERE rauthor_id = ?`
  — both `posts.rcomment_id` and `rauthor_id` are invented → `no such column: rauthor_id`.

**Correct** SQL for the chain:
`SELECT comments.* FROM comments INNER JOIN posts ON(comments.post_id = posts.id) WHERE posts.author_id = ?`

Eager loading (`include`) routes through the **same** `construct_inner_join_sql(…, true)` in
`AbstractRelationship::query_and_attach_related_models_eagerly()`, and additionally sets
`query_key = null` for `through`, which attaches every fetched row to every owner (only
incidentally correct when there is a single owner).

## Scope

**In scope**

- Lazy load of `has_many … through` for the has_many→has_many (reverse-FK) chain.
- Eager load (`include`) of the same, partitioned correctly across multiple owners.
- `has_one … through` for the reverse-FK chain — see "Why has_one is included" below.

**Non-goals (YAGNI)**

- Write-side `build_*` / `create_*` through a chain (semantically murky: which intermediate
  row would own the new record?).
- Polymorphic `source_type`.
- Nested / two-level `through` (`through` pointing at another `through`).
- A full Rails rewrite of the `through` subsystem.

## Chosen approach — Approach B: additive branch keyed off the source association

Both viable approaches resolve the *source association* to pick the join direction. Approach A
would rework the shared join builder so **both** shapes derive keys from the real associations
(cleaner end state, single path) but modifies the currently-passing belongs_to path — higher
regression risk on the primary MySQL shape. **Approach B** (chosen) adds a **new branch** for
the reverse-FK chain and leaves the belongs_to/join-table path **byte-for-byte unchanged**:
smallest blast radius, zero regression risk to the working shape, at the cost of two parallel
branches.

### Core mechanism

For any `Owner has_many :targets, through: :middle`, two associations fully determine the SQL;
we look them both up instead of inflecting column names:

1. **through association** — `Owner`'s relationship named by `through` (e.g. `Author has_many books`).
   Supplies the column on the *middle* table that references the owner (`books.author_id`).
2. **source association** — the relationship on the *middle* model pointing at the target,
   resolved Rails-style: try the **singular** of the attribute name, then the **plural/original**
   (the explicit `source` option overrides). Its **kind** selects the branch:
   - `BelongsTo` (`Event belongs_to host`) → **existing** join-table path, untouched.
   - `HasMany` (`Book has_many book_reviews`) → **new** reverse-FK branch.

### New reverse-FK branch builds

Using `Author has_many book_reviews through books`, `Book has_many book_reviews` as the running example:

- **Join** (query is `FROM` the target, joining the middle) — keys from the source `HasMany`:
  `INNER JOIN books ON (book_reviews.book_id = books.book_id)`
  i.e. `target.<source.foreign_key> = middle.<source.primary_key>`.
- **Owner filter** — key from the through `HasMany`, qualified with the middle table to stay
  unambiguous:
  `books.author_id = <owner.author_id>`
  i.e. `middle.<through.foreign_key> = <owner pk value>`.

## Implementation (all in `lib/Relationship.php`)

### Lazy — `HasMany::load()`

In the `through` init block, after resolving the source association:

- If the source is a `BelongsTo` → keep the current lines verbatim (existing behavior).
- If the source is a `HasMany` → set the join and condition keys from the two real
  associations (join from the source `HasMany`, condition column from the through `HasMany`),
  instead of `set_keys(…, true)` / `keyify`. Qualify the condition column with the middle
  table name.

### Join builder — `AbstractRelationship::construct_inner_join_sql(…, using_through: true)`

Teach it the reverse-FK direction for the **new branch only**. The existing branch stays
byte-for-byte identical.

### Eager — `HasMany::load_eagerly` / `query_and_attach_related_models_eagerly`

Target rows (`book_reviews`) do not carry `author_id`, so per-owner partitioning requires the
through key on each row. In the **new branch only**:

- Augment `select` to expose the through key: `book_reviews.*, books.author_id`.
- Set `query_key = author_id` and `model_values_key = <owner pk>` so the existing matching loop
  partitions correctly.

The belongs_to path keeps its current `query_key = null` behavior unchanged; any pre-existing
multi-owner quirk there is out of scope.

### Why `has_one … through` is included (no extra mechanism)

`HasOne extends HasMany {}` with no overrides, and `poly_relationship` is only set true for
`hasmany`/`habtm`. So a `HasOne` is a `HasMany` with `poly = false`:

- `HasOne::load()` is the inherited `HasMany::load()` — same `through` block, same join builder,
  same conditions. The only difference is `find('first')` vs `'all'`, already driven by
  `poly_relationship`.
- `HasOne::load_eagerly()` is inherited; the attach step (`Model::set_relationship_from_eager_load`)
  already branches on `is_poly()` to assign a single model vs. an array.

Therefore the new reverse-FK branch is inherited by `HasOne` unchanged; no separate code is
written. The only cost is test coverage.

**Documented pre-existing quirk:** for **eager** `has_one` through, when several targets match
one owner, the attach loop calls `set_relationship_from_eager_load` repeatedly and — being
non-poly — each call overwrites, so the behavior is **last-row-wins** rather than a
deterministic "first". This is true of *all* `has_one` eager loading in the library today; this
change does not introduce it. Lazy `has_one` through uses `find('first')` and is well-defined.

## Testing

Reuse the existing `authors` → `books` relationship and add **one** grandchild table.

**New fixtures**

- Table `book_reviews (id, book_id, rating)` added to `test/sql/mysql.sql`, `test/sql/pgsql.sql`,
  `test/sql/sqlite.sql` (+ `test/sql/pgsql-after-fixtures.sql` sequence reset if needed).
- `test/fixtures/book_reviews.csv` with rows spanning at least two books belonging to at least
  two different authors (so eager partitioning is actually exercised).
- `test/models/BookReview.php` (`belongs_to book`).

**Tests** (in `test/RelationshipTest.php`; set `Author::$has_many` in-test per the existing
`Venue` pattern — no permanent edits to `test/models/Author.php`):

1. **Lazy has_many chain** — `$author->book_reviews` returns exactly the reviews reachable
   through that author's books (the issue #22 repro, now green).
2. **Eager has_many chain** — `Author::find('all', ['include' => ['book_reviews']])` attaches
   each review to the correct author across multiple authors (guards the select-augmentation
   partitioning).
3. **Lazy has_one chain** — `Author has_one … through books` with an explicit `source` (the
   singular/plural name won't match `book_reviews`), asserting a single `BookReview` is returned.
4. **Eager has_one chain** — asserts a single `BookReview` is attached and explicitly documents
   the last-row-wins semantics (asserts the last matching row, not a guessed "first").
5. **Regression** — the existing `Venue has_many hosts through events` (belongs_to) tests remain
   green.

Constraint: the Docker suite runs with `--fail-on-skipped`, so nothing may skip — the new
fixtures must load on every adapter in the matrix.

## Backward compatibility

Purely additive: a currently-throwing case starts working; the belongs_to/join-table path is
untouched. No public method, option key, return type, or observable behavior of an existing
working case changes. Satisfies the CLAUDE.md backward-compatibility gate — no consumer break.

## Verification

- `docker compose exec tests composer run test` — full suite green across the PHP × DB matrix
  (the CI gate), including the new tests and the untouched belongs_to tests.
- `docker compose exec tests composer run analyse` — PHPStan level 8 clean, no new
  `phpstan-baseline.neon` entries.
- `docker compose exec tests composer run cs` — coding-style clean.
- Empirically confirm the issue #22 reproduction now returns the expected rows.
