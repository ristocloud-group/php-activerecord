# DB-version CI matrix, PHP-floor cleanup & expanded examples

**Date:** 2026-08-05
**Status:** Approved design — pending implementation. Aligned to `master` after
the `phpstan/level-8` merge (`cbb4d48`); no cross-effort dependency remains.
**Author:** Claude (paired with maintainer)

## Context

Six low-risk maintenance improvements, bundled into a single PR (they are
cohesive: "broaden DB coverage + modernize + document"). Work happens on a
**new branch cut from `master`**. This PR touches `.github/workflows/ci.yml`,
`README.md`, `CLAUDE.md`, `ActiveRecord.php`, `examples/`, and `phpstan.neon`.

### Status: the `phpstan/level-8` effort is merged (dependency cleared)

The parallel PHPStan climb has **landed on `master`** (commit `cbb4d48`,
"PHPStan level 7 → 8 (max)"). master is now `level: 8`, `paths: [lib]`, baseline
still 37 lines. This removes the earlier merge-ordering dependency entirely:

- **Task 6a** (add `examples` to `paths`) is **no longer gated** — master is
  already at level 8; it is now a plain one-line `phpstan.neon` edit.
- The level-8 merge touched **only** `lib/*.php`, `phpstan.neon` (the `level:`
  bump), and `test/ExpressionsTest.php`. It did **not** touch `ci.yml`,
  `README.md`, `ActiveRecord.php`, `CLAUDE.md`, or `examples/` — so **none of
  this PR's other tasks overlap** with it, and `phpstan.neon` is no longer a
  contended file (this PR's `paths`/`phpVersion` edits stand alone).
- **It did leave `CLAUDE.md` stale:** the "PHPStan (level 5)" text (lines 31 &
  47) was not updated to level 8. This PR now **owns that correction** (folded
  into Task 6 / Task 3's CLAUDE.md edits) — previously the spec deferred it to
  the level-8 branch, which didn't do it.
- **`phpVersion` (Task 6b) was not added** by the level-8 work — still this
  PR's to do.

### Working tree is clean; branch straight off `master`

The workspace is already on `master` at `cbb4d48` with a clean tree (only this
untracked spec present); the parallel agent's former WIP is now merged. So the
earlier "don't switch branches while WIP is uncommitted" caution is resolved.
Note git worktrees remain unusable here (the `tests` container mounts `.:/code`,
so an external worktree is invisible to Docker — project memory
`docker-tests-mount-blocks-worktrees`); use an **in-place branch**. First
implementation step: `git checkout -b <branch> master` → commit this spec (it
rides along the branch creation as an untracked file).

## Goals

1. Add CI coverage for MariaDB 10.11, MariaDB 11.8, MariaDB 12.3, MySQL 8.4,
   PostgreSQL 15, 16 and 17 (18 already covered), and document SQLite 3.
2. Publish a **master line-coverage badge** in the README, self-generated in CI
   from the existing clover `coverage.xml` (no external coverage service).
3. Update `README.md` (and `CLAUDE.md`) to document the DB versions covered.
4. Remove the obsolete PHP 5.3 runtime guard in `ActiveRecord.php` (the `^8.3`
   floor is already enforced by `composer.json`).
5. Expand `examples/` to demonstrate the full ActiveRecord feature surface.
6. Harden the PHPStan config: (a) bring `examples/` under the **level-8**
   analysis (add it to `phpstan.neon` `paths`) so example code is held to the
   same clean-code bar as `lib/` and cannot rot; (b) pin the analyzed
   **`phpVersion`** to the supported range (8.3 → 8.5) so version-incompatible
   code is caught statically.

## Non-goals

- No changes to `lib/` behavior, adapters, or the schema-introspection paths.
- No new database-version constraint in `composer.json` (policy minimums stay
  documentation-only, per CLAUDE.md).
- Examples are **not** wired into the test suite / CI (they remain demos); they
  are verified by being run manually during implementation.

## Verified facts

All requested Docker image tags exist on Docker Hub (checked 2026-08-05):
`mysql:8.4`, `mariadb:10.11`, `mariadb:11.8`, `mariadb:12.3`, `postgres:15`,
`postgres:16`, `postgres:17` (and the current `postgres:18`).

**SQLite** is not a service container: `pdo_sqlite` links the SQLite library
compiled into the PHP runtime image, so its version is tied to the PHP build,
not independently pinnable via a matrix. SQLite 3.x is therefore exercised on
**every** job already (there is no SQLite 4). "SQLite 3" is a documentation
item, not a new matrix dimension.

---

## Task 1 — CI: expand the main `test` matrix (PHP × DB version)

**Approach:** cartesian expansion of the existing `test` job (chosen over a
separate `redis-compat`-style compat job). The `mysql`, `mariadb` **and
`postgres`** services all stay up in every job because the suite exercises every
adapter in one run, so a "DB target" is a **triple** of images (mysql +
mariadb + postgres). The `db` matrix axis carries the triple; folding postgres
in this way covers all four postgres versions without adding any jobs.

### Matrix

`matrix.php-version: ['8.3', '8.4', '8.5']` × `matrix.db` (4 triples) = **12
jobs** (was 3). The two MySQL versions are balanced across the four MariaDB
versions, and each postgres major (15/16/17/18) lands on one row, so every
requested version gets real coverage:

| db target | mysql image | mariadb image | postgres image | rationale |
|---|---|---|---|---|
| `baseline` | `mysql:9.7` | `mariadb:11.4` | `postgres:18` | current CI baseline (preserved) |
| `min` | `mysql:8.4` | `mariadb:10.11` | `postgres:15` | documented supported minimums (MySQL 8.4 LTS, MariaDB 10.11 LTS) + oldest Postgres |
| `lts` | `mysql:8.4` | `mariadb:11.8` | `postgres:16` | MariaDB 11.8 LTS |
| `rolling` | `mysql:9.7` | `mariadb:12.3` | `postgres:17` | MariaDB 12.x rolling |

SQLite 3.x is exercised on every one of these jobs via the PHP runtime's bundled
`pdo_sqlite` (no service; see "Verified facts").

### Implementation shape

- Add the `db` axis as a list of objects, e.g.
  `- { name: baseline, mysql: 'mysql:9.7', mariadb: 'mariadb:11.4', postgres: 'postgres:18' }` …
- Service `image:` fields become `${{ matrix.db.mysql }}`,
  `${{ matrix.db.mariadb }}` and `${{ matrix.db.postgres }}`. Ports, env, and
  health-checks are unchanged and work across all four MariaDB versions (the
  `healthcheck.sh` script and `MARIADB_*` env aliases are present in
  mariadb ≥ 10.4), MySQL 8.4/9.7, and Postgres 15–18 (`pg_isready` health-check
  unchanged).
- `memcached`, `redis` services stay fixed.
- **Tighten the once-only steps.** `Static analysis` and `Coding style`
  currently gate on `matrix.php-version == '8.3'`; with the new axis that would
  fire on every 8.3 row (4×). Change the guard to
  `matrix.php-version == '8.3' && matrix.db.name == 'baseline'` so each runs
  exactly once.
- Coverage-artifact `name:` must stay unique across matrix rows (currently
  `coverage-php${{ matrix.php-version }}`); append `-${{ matrix.db.name }}` to
  avoid the "artifact already exists" upload error.
- `redis-compat` job: untouched.

### Risks / open items

- A specific DB version may surface a genuine SQL incompatibility in
  `test/sql/mysql.sql` or a query path. That is exactly what this coverage is
  for; if a real incompatibility appears, surface it to the maintainer rather
  than silently narrowing the matrix (per backward-compat gate).

---

## Task 2 — CI + README: master line-coverage badge

Self-generated badge (chosen over Codecov/Coveralls/shields-gist): no external
account or coverage service, at the cost of a scoped `contents: write` grant and
one bot commit per master push. Design goal: keep that write permission and the
commit noise **off master history** and **off the existing jobs**.

### Where the number comes from

The `test` job already emits clover `coverage.xml` and uploads it per matrix
cell. The badge is computed from a **single canonical cell** — PHP 8.3 +
`baseline` DB (artifact `coverage-php8.3-baseline`, per Task 1's naming) — so
there is exactly one authoritative number and no cross-cell race. Clover's
project-level `<metrics …>` carries `elements`/`coveredelements` (and
`statements`/`coveredstatements`); line-coverage % =
`coveredstatements / statements * 100`, computed by a tiny inline PHP snippet
(PHP is already on the runner — no new dependency).

### New `coverage-badge` job (isolates the write permission)

Rather than granting the matrix `test` job write access, add a small dedicated
job:

- `needs: test`, and gated `if: github.event_name == 'push' && github.ref ==
  'refs/heads/master'` — never runs on pull requests (where the token is
  read-only and, from forks, cannot push anyway).
- Top-level workflow `permissions:` stays `contents: read`; this job alone
  declares `permissions: { contents: write }`. Blast radius = one job that only
  runs on master pushes.
- Steps: `actions/download-artifact` (the `coverage-php8.3-baseline` clover) →
  compute % → generate an SVG → publish it.

### Generating + publishing the SVG

- **Generate locally, no network at render time:** either a ~10-line inline
  writer that fills a static SVG template with the % and a threshold color
  (`< 50` red, `< 80` yellow, `>= 80` brightgreen), or a self-contained action
  such as `emibcn/badge-action`. Avoid depending on `img.shields.io` at README
  render time by committing a static SVG.
- **Publish to a dedicated orphan `badges` branch** (not master), so master
  history stays free of bot commits and the commit cannot retrigger CI. Commit
  message includes `[skip ci]`. The branch is created on first run and holds
  only `coverage.svg`. (A commit-to-branch action like `EndBug/add-and-commit`
  with a target `branch:` handles orphan creation + push.)

### README badge

Add a second badge line beside the existing CI badge (README line 3):

```md
[![Coverage](https://raw.githubusercontent.com/ristocloud-group/php-activerecord/badges/coverage.svg)](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml)
```

(GitHub proxies the raw SVG through Camo; the badge updates whenever the
`badges` branch is refreshed by a master build.)

### Notes / risks

- The badge reflects **one cell's** line coverage (PHP 8.3 / baseline DB), not a
  merged figure across the 12 cells. That is the conventional meaning of a repo
  coverage badge and is deterministic; documented here so the number isn't
  mistaken for a matrix-wide aggregate.
- `contents: write` on a CI job is a deliberate, scoped exception to the
  repo's least-privilege `contents: read` default — called out for the security
  reviewer. No new long-lived secret is introduced (uses the built-in
  `GITHUB_TOKEN`).
- First master run after merge creates the `badges` branch; until then the
  README badge shows a broken image. Acceptable one-time transient.

---

## Task 3 — Docs: README + CLAUDE.md

**`README.md`**, "Supported Databases" (currently line ~44): replace the
"MySQL 9.7, MariaDB 11.4, PostgreSQL 18 and SQLite" CI sentence with the actual
matrix now covered:

> Continuous integration runs the full test suite across PHP 8.3, 8.4 and 8.5
> against MySQL 8.4 & 9.7, MariaDB 10.11, 11.4, 11.8 & 12.3, PostgreSQL 15, 16,
> 17 & 18, and SQLite 3.

Keep the existing policy-minimum lines (MySQL 8+, MariaDB 10.11+) — now
explicitly exercised by the `min` matrix row.

**`CLAUDE.md`**: the architecture paragraph's one-line CI description (line 47:
"runs a matrix per push: PHP 8.3, 8.4, 8.5, each with MySQL, MariaDB, …") gets a
parenthetical noting the MySQL/MariaDB/Postgres version matrix, to stay
accurate. Note this is the **same paragraph** Task 6 updates for the PHPStan
level/scope correction — make both edits coherently in one pass (line 47
mentions both the DB services and the `analyse` step).

---

## Task 4 — `ActiveRecord.php`: drop the PHP 5.3 guard

Delete:

```php
if (!defined('PHP_VERSION_ID') || PHP_VERSION_ID < 50300) {
    die('PHP ActiveRecord requires PHP 5.3 or higher');
}
```

No replacement guard is added — `composer.json`'s `"php": "^8.3"` enforces the
floor at install time. `PHP_ACTIVERECORD_VERSION_ID` and the `require` manifest
are untouched.

---

## Task 5 — Examples: focused, runnable, SQLite-backed dirs

**Decision:** multiple focused dirs (one per feature area), each **runnable with
zero setup** via SQLite (`sqlite://<path>` DSN). Departs from the two existing
`mysql://` examples on purpose — a reader can run any example with
`php examples/<x>/<x>.php` and no DB server. Each dir follows the established
grain (`<x>.php` entry script + `<x>.sql` schema + `models/`), is heavily
commented, and **self-creates** its schema (loads the `.sql` at startup so the
script is idempotent). Every example is **run during implementation** to confirm
correct output before commit.

Modern PHP 8.3 idioms throughout (short arrays, typed signatures where they fit,
constructor property promotion in non-model helper code). Public ActiveRecord
API stays snake_case (`find_by_*`, `save`, `is_dirty`, static `$has_many` etc.),
per the library contract.

**Each example must pass PHPStan level 8 with no new baseline entries** (see
Task 6) — this is an authoring constraint, not an afterthought. ActiveRecord's
magic (`__get`/`__set`, `__callStatic` finders) is level-8-tolerated because the
`Model` base declares those magic methods, so `$post->title` and
`Post::find_by_name(...)` analyse cleanly (as `mixed`). Where a `mixed` return
would otherwise trip level 8 (e.g. `count($model->some_relation)`), prefer a
minimal, *instructive* type hint — a local `@var`, an `assert()`, or an explicit
cast — over cluttering the teaching code; keep such annotations rare and
readable.

### Dirs

1. **`examples/validations/`** — `$validates_presence_of`,
   `$validates_uniqueness_of`, `$validates_length_of`, `$validates_format_of`,
   `$validates_numericality_of`, `$validates_inclusion_of` /
   `$validates_exclusion_of`, a custom `validate()` method; inspecting the
   `Errors` object (`$model->errors`, `full_messages()`, `on()`), and
   `is_valid()`.
2. **`examples/relationships/`** — `$belongs_to`, `$has_many`, `$has_one`,
   `$has_many` `through`, `$has_and_belongs_to_many`; eager loading via
   `include`; association builders (`create_*` / `build_*`); a relationship
   option (`conditions`/`order`) shown in **positional** form (project memory
   `bug-relationship-hash-conditions-ignored`: the hash form is silently
   dropped — do not demonstrate the broken form).
3. **`examples/callbacks/`** — the full lifecycle in order:
   `before_validation` → `after_validation` → `before_save` → `before_create`
   → `after_create` → `after_save` (and the update/destroy equivalents);
   halting persistence by returning `false` from a `before_*` hook.
4. **`examples/attributes/`** — custom getters/setters
   (`get_*`/`set_*` + `assign_attribute`/`read_attribute`), `$alias_attribute`,
   `$attr_accessible` / `$attr_protected` (mass-assignment guarding), dirty
   tracking (`is_dirty`, `dirty_attributes`, `changes`), and `delegate`.
5. **`examples/serialization/`** — `to_json`, `to_xml`, `to_array`, each with
   `only` / `except` / `methods` / `include` options.
6. **`examples/finders/`** — dynamic finders (`find_by_*`,
   `find_all_by_*`, `find_by_*_and_*`), the `conditions` / `order` / `limit` /
   `offset` / `group` / `having` / `select` option set, `find_by_sql`, and a
   static scope helper.

### `examples/README.md`

New index listing each example, the feature(s) it covers, and the one-line run
command. Notes that these examples use SQLite for zero-setup and that the older
`simple/` and `orders/` examples target MySQL.

---

## Task 6 — PHPStan: examples under level-8 + pin the analyzed PHP version

Two `phpstan.neon` hardening edits. master is **already `level: 8`** (the
level-8 climb merged — see Context), so neither is gated on anything: both are
plain additions to the config this PR owns outright.

- **6a — `examples/` path**: add `examples` to `paths`.
- **6b — `phpVersion` pin**: analyse against the supported PHP range.

### 6a — Add `examples` to `paths`

Add `examples` to `phpstan.neon` `paths` (master is level 8, so this alone puts
examples under the same level-8 analysis as `lib/`):

```neon
parameters:
    level: 8
    paths:
        - lib
        - examples
    bootstrapFiles:
        - ActiveRecord.php
```

The `examples/simple/simple.php` etc. already `require` `ActiveRecord.php`, and
the bootstrap loads the library, so the example model classes (which
`extends ActiveRecord\Model`) resolve. PHPStan parses the whole `examples`
tree together, so a script referencing a model defined under
`examples/<x>/models/` is seen. `composer run analyse` needs no flag change —
it picks up the extended `paths`.

#### Pre-existing examples must also comply

Adding the path analyses the **existing** `examples/simple/`, `examples/orders/`
and `examples/upsert/` too, not just the new dirs. Expect some level-8 findings
there (they predate this bar). Resolve them the same way as the new examples —
minimal type hints / asserts, **no new baseline suppressions** (per CLAUDE.md's
frozen-baseline policy: "new code must not add suppressions"). If a legacy
example needs non-trivial reworking to pass, prefer a small, behavior-preserving
edit; if it would require ugly contortions, surface it to the maintainer rather
than bloat the baseline.

### 6b — Pin the analyzed PHP version

Tell PHPStan to analyse against the **supported PHP range** rather than only the
version the analyser happens to run on (8.3 in CI). PHPStan 2.x (the pinned
`^2.0`) supports a `phpVersion` **range**:

```neon
parameters:
    phpVersion:
        min: 80300   # composer floor: ^8.3
        max: 80599   # top of the tested matrix: PHP 8.5.x
```

- **`min: 80300`** — flags any use of an 8.4/8.5-only symbol or syntax that
  would fatal on the 8.3 floor (`composer.json` requires `^8.3`).
- **`max: 80599`** — flags things removed/deprecated by PHP 8.5, complementing
  the runtime `--fail-on-deprecation` in CI (which only sees executed paths;
  PHPStan sees unexecuted ones too).
- Keep `max` in lockstep with the CI PHP matrix — when a newer PHP is added to
  the matrix, bump `max`.

**Risk / scope:** enabling the range (specifically the `max` side) may surface
**new** findings against existing `lib/` code — e.g. a symbol deprecated in 8.4
or 8.5. These are real portability issues and must be **fixed**, not baselined
(frozen-baseline policy). If the volume is non-trivial or a fix is behavior-
affecting, surface it to the maintainer before proceeding — do not silently
widen the baseline or narrow the range to make it pass.

#### CI (both 6a and 6b)

No new CI step — the existing 8.3 `Static analysis` step (`composer run
analyse`) picks up the extended `paths` (6a) and the `phpVersion` pin (6b)
automatically. Its once-only gate (Task 1: `8.3 && baseline`) is unchanged.

#### CLAUDE.md

The level-8 merge left CLAUDE.md's PHPStan text stale — it still reads
"**level 5**" in two places: the commands table (line 31,
"PHPStan (level 5) static analysis") and the architecture paragraph (line 47,
"`composer run analyse` (PHPStan, level 5, …)"). This PR now **owns those
corrections** (the level-8 branch didn't make them). Update both to reflect
reality after this PR:

- level **5 → 8**,
- analysis now covers `lib/` **and** `examples/`,
- and it analyses against the **8.3–8.5** `phpVersion` range.

(This is the same CLAUDE.md paragraph Task 3 edits for the DB-version matrix —
one coherent edit in the same PR.)

---

## Verification plan (implementation)

- **CI:** validate `ci.yml` YAML (parse), confirm 12 test jobs render and each
  service `image` resolves; the true gate is a green Actions run on the PR.
- **Coverage badge:** verify the `coverage-badge` job is skipped on the PR
  (push-to-master gate) and that its `contents: write` grant is job-scoped;
  locally dry-run the %-extraction snippet against a real `coverage.xml` to
  confirm it parses clover and yields a sane number. Full validation is the
  first post-merge master build creating the `badges` branch + rendering badge.
- **Examples:** run every `examples/*/*.php` in the `tests` Docker container
  (PHP 8.3 + pdo_sqlite) and confirm each prints sensible, error-free output.
- **PHPStan (examples):** after adding the path, run `composer run analyse`
  (master is already level 8) and confirm **zero** errors over `lib` +
  `examples` and **no new** entries in `phpstan-baseline.neon` (diff the
  baseline — master's is 37 lines).
- **PHPStan (`phpVersion`):** add the range, run `composer run analyse`, and
  triage any newly-surfaced findings (fix, don't baseline). Confirm the config
  parses (`phpVersion.min`/`max` accepted by PHPStan 2.x) and the baseline is
  unchanged.
- **`ActiveRecord.php`:** run the existing suite (`composer run test`) — the
  guard removal must not change any behavior.
- **Docs:** proofread; ensure README/CLAUDE version lists match the matrix.
- Full `composer run test` + `composer run cs` on the branch before opening PR.

## Rollout

Single PR off `master` (which is already at PHPStan level 8 — no ordering
dependency remains). Title e.g.
`ci: DB-version matrix + coverage badge; drop PHP5.3 guard; expand examples (phpstan examples + phpVersion)`.
