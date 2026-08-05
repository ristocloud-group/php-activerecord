# DB-version CI matrix, coverage badge, PHP-floor cleanup, examples & PHPStan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Broaden CI to a MySQL/MariaDB/Postgres version matrix, publish a self-generated master coverage badge, drop the obsolete PHP 5.3 guard, add six focused runnable SQLite examples, and bring `examples/` under PHPStan level 8 with a pinned PHP-version range.

**Architecture:** Pure maintenance PR off `master` (branch `ci/db-matrix-coverage-examples`). CI changes live in `.github/workflows/ci.yml`; docs in `README.md` + `CLAUDE.md`; the guard in `ActiveRecord.php`; new example dirs under `examples/`; PHPStan config in `phpstan.neon`. No `lib/` behavior changes.

**Tech Stack:** GitHub Actions, PHP 8.3–8.5, PDO SQLite, PHPUnit 12 (clover coverage), PHPStan 2.x (level 8), Docker Compose (`tests` service).

## Global Constraints

- **Branch:** `ci/db-matrix-coverage-examples`, already cut from `master` (which is at PHPStan **level 8**). The spec is already committed here.
- **PHP floor:** `^8.3`. Write modern PHP 8.3 idioms (short arrays `[]`, typed signatures where they fit, `??`/`?->`). Applies to example code too.
- **Public API is snake_case** (`find_by_*`, `save`, `is_dirty`, static `$has_many`, …) — never rename.
- **Coding style:** PER-CS 3.0 (4-space indent) + PHP 8.3 migration set, enforced by `composer run cs`. Run `composer run cs-fix` before committing PHP.
- **Frozen PHPStan baseline** (`phpstan-baseline.neon`, 37 lines on master): **no new suppressions may be added.** New/edited code must pass level 8 outright.
- **This fork has NO `$has_and_belongs_to_many`** and **NO `Model::changes()`** — do not use them. Many-to-many is `has_many … through`; dirty tracking is `is_dirty()` + `dirty_attributes()`.
- **Everything runs in Docker:** prefix commands with `docker compose exec tests …`. The `tests` container has PHP 8.3 + all PDO drivers. Ensure `docker compose up -d` first.
- **Repo slug** for URLs: `ristocloud-group/php-activerecord`.
- **Skipped tests fail the build** — never introduce a test that skips in the Docker env.

---

## Task 1: Expand the CI `test` matrix over DB versions

**Files:**
- Modify: `.github/workflows/ci.yml` (the `test` job: `strategy.matrix`, the `mysql`/`mariadb`/`postgres` service `image:` fields, the two `if:` guards, the coverage-upload artifact name)

**Interfaces:**
- Produces: matrix context `matrix.db.{name,mysql,mariadb,postgres}` and the per-cell coverage artifact name `coverage-php<ver>-<dbname>`. Task 2 consumes the artifact `coverage-php8.3-baseline`.

Current `test` job matrix is only `php-version: ['8.3','8.4','8.5']` with fixed service images `mysql:9.7`, `mariadb:11.4`, `postgres:18`. We add a `db` axis (4 image triples) → 12 jobs.

- [ ] **Step 1: Replace the `strategy` block of the `test` job**

Find:
```yaml
    strategy:
      fail-fast: false
      matrix:
        php-version: ['8.3', '8.4', '8.5']
```
Replace with:
```yaml
    strategy:
      fail-fast: false
      matrix:
        php-version: ['8.3', '8.4', '8.5']
        db:
          - { name: baseline, mysql: 'mysql:9.7', mariadb: 'mariadb:11.4', postgres: 'postgres:18' }
          - { name: min,      mysql: 'mysql:8.4', mariadb: 'mariadb:10.11', postgres: 'postgres:15' }
          - { name: lts,      mysql: 'mysql:8.4', mariadb: 'mariadb:11.8', postgres: 'postgres:16' }
          - { name: rolling,  mysql: 'mysql:9.7', mariadb: 'mariadb:12.3', postgres: 'postgres:17' }
```

- [ ] **Step 2: Parameterize the three DB service images**

In the `test` job's `services:`, change the three `image:` lines (leave ports/env/health-checks untouched):
- `image: mysql:9.7` → `image: ${{ matrix.db.mysql }}`
- `image: mariadb:11.4` → `image: ${{ matrix.db.mariadb }}`
- `image: postgres:18` → `image: ${{ matrix.db.postgres }}`

- [ ] **Step 3: Gate the once-only steps to a single cell**

The `Static analysis` and `Coding style` steps currently guard on `if: matrix.php-version == '8.3'`. With 4 db cells that would fire 4×. Change **both** guards to:
```yaml
        if: matrix.php-version == '8.3' && matrix.db.name == 'baseline'
```

- [ ] **Step 4: Make the coverage artifact name unique per cell**

In the `Upload coverage` step, change:
```yaml
          name: coverage-php${{ matrix.php-version }}
```
to:
```yaml
          name: coverage-php${{ matrix.php-version }}-${{ matrix.db.name }}
```

- [ ] **Step 5: Validate the YAML parses and the matrix is well-formed**

Run (host):
```bash
python3 - <<'PY'
import yaml
d = yaml.safe_load(open('.github/workflows/ci.yml'))
m = d['jobs']['test']['strategy']['matrix']
cells = len(m['php-version']) * len(m['db'])
print('test cells:', cells)
assert cells == 12, cells
svc = d['jobs']['test']['services']
for k, key in (('mysql','mysql'),('mariadb','mariadb'),('postgres','postgres')):
    assert '${{ matrix.db.' in svc[k]['image'], svc[k]['image']
names = {c['name'] for c in m['db']}
assert names == {'baseline','min','lts','rolling'}, names
print('OK')
PY
```
Expected: `test cells: 12` then `OK`. (If `python3`/pyyaml is unavailable, eyeball the diff; the authoritative gate is the green Actions run once the branch is pushed.)

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: matrix the test job over MySQL/MariaDB/Postgres versions"
```

---

## Task 2: Self-generated master coverage badge

**Files:**
- Modify: `.github/workflows/ci.yml` (add a new `coverage-badge` job)

**Interfaces:**
- Consumes: artifact `coverage-php8.3-baseline` (clover `coverage.xml`) produced by Task 1's `test` job.
- Produces: an orphan `badges` branch containing `coverage.svg`, referenced by the README badge in Task 3.

The job runs only on pushes to `master`, computes line-coverage % from clover, renders an SVG locally, and force-publishes it to an orphan `badges` branch. Write access is scoped to this one job (workflow default stays `contents: read`).

- [ ] **Step 1: Add the `coverage-badge` job at the end of `ci.yml`**

Fully first-party: no third-party badge/publish actions (only `actions/*` + `shivammathur/setup-php`). The SVG is generated inline with PHP and pushed to an orphan `badges` branch with a plain git script using the built-in `GITHUB_TOKEN`. Append (after the `redis-compat` job, at the same indentation as other jobs under `jobs:`):
```yaml
  coverage-badge:
    runs-on: ubuntu-latest
    needs: test
    if: github.event_name == 'push' && github.ref == 'refs/heads/master'
    permissions:
      contents: write
    steps:
      - uses: actions/checkout@v4

      - name: Download baseline coverage
        uses: actions/download-artifact@v4
        with:
          name: coverage-php8.3-baseline

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Generate coverage badge SVG
        run: |
          php -r '
          $x = simplexml_load_file("coverage.xml");
          $n = $x->xpath("//project/metrics");
          $m = $n[0];
          $s = (int) $m["statements"];
          $c = (int) $m["coveredstatements"];
          $pct = $s > 0 ? (int) round($c / $s * 100) : 0;
          $color = $pct >= 90 ? "#4c1" : ($pct >= 80 ? "#97ca00" : ($pct >= 50 ? "#dfb317" : "#e05d44"));
          $label = "coverage"; $value = $pct . "%";
          $lw = 6 * strlen($label) + 10; $vw = 7 * strlen($value) + 10; $w = $lw + $vw;
          $svg = sprintf(
            "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"%d\" height=\"20\" role=\"img\" aria-label=\"%s: %s\">".
            "<rect rx=\"3\" width=\"%d\" height=\"20\" fill=\"#555\"/>".
            "<rect rx=\"3\" x=\"%d\" width=\"%d\" height=\"20\" fill=\"%s\"/>".
            "<g fill=\"#fff\" text-anchor=\"middle\" font-family=\"Verdana,Geneva,sans-serif\" font-size=\"11\">".
            "<text x=\"%d\" y=\"14\">%s</text><text x=\"%d\" y=\"14\">%s</text></g></svg>",
            $w, $label, $value, $w, $lw, $vw, $color, (int) ($lw / 2), $label, (int) ($lw + $vw / 2), $value
          );
          @mkdir("out");
          file_put_contents("out/coverage.svg", $svg);
          fwrite(STDERR, "coverage={$pct}%\n");
          '

      - name: Publish to orphan badges branch
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        run: |
          cd out
          git init -q
          git checkout -q -b badges
          git add coverage.svg
          git -c user.name="github-actions[bot]" \
              -c user.email="github-actions[bot]@users.noreply.github.com" \
              commit -q -m "chore: update coverage badge [skip ci]"
          git push -q -f "https://x-access-token:${GITHUB_TOKEN}@github.com/${GITHUB_REPOSITORY}.git" badges
```

- [ ] **Step 2: Verify the coverage-extraction snippet against a real clover file (local)**

Generate a coverage report and run the extraction logic (no `GITHUB_OUTPUT`, prints to stderr):
```bash
docker compose exec tests vendor/bin/phpunit --coverage-clover coverage.xml >/dev/null 2>&1
docker compose exec tests php -r '
  $x = simplexml_load_file("coverage.xml");
  $m = $x->xpath("//project/metrics")[0];
  $s = (int)$m["statements"]; $c = (int)$m["coveredstatements"];
  $pct = $s>0 ? (int) round($c/$s*100) : 0;
  echo "statements=$s covered=$c pct=$pct%\n";
'
```
Expected: a line like `statements=… covered=… pct=NN%` with a plausible non-zero percentage. Then clean up: `rm -f coverage.xml`.

- [ ] **Step 3: Validate the YAML still parses and the job is push/master-gated**

```bash
python3 - <<'PY'
import yaml
d = yaml.safe_load(open('.github/workflows/ci.yml'))
j = d['jobs']['coverage-badge']
assert j['needs'] == 'test'
assert j['permissions'] == {'contents': 'write'}
assert "refs/heads/master" in j['if'] and "push" in j['if']
assert d.get('permissions') == {'contents': 'read'}, "workflow default must stay read"
print('OK')
PY
```
Expected: `OK`. (The workflow-level `permissions: contents: read` at the top of the file must remain unchanged — this job's write grant is job-scoped.)

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: add master coverage badge job (self-generated SVG on orphan branch)"
```

---

## Task 3: Docs — README badge + DB versions + CLAUDE.md corrections

**Files:**
- Modify: `README.md` (add coverage badge near line 3; update the "Supported Databases" CI sentence)
- Modify: `CLAUDE.md` (correct stale "level 5" → "level 8"; note version matrix + examples-in-analysis)

**Interfaces:**
- Consumes: the `badges/coverage.svg` published by Task 2.

- [ ] **Step 1: Add the coverage badge to README**

In `README.md`, the CI badge is line 3. Immediately after it, add the coverage badge line. Find:
```md
[![CI](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml/badge.svg)](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml)
```
Replace with (append a second line):
```md
[![CI](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml/badge.svg)](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml)
[![Coverage](https://raw.githubusercontent.com/ristocloud-group/php-activerecord/badges/coverage.svg)](https://github.com/ristocloud-group/php-activerecord/actions/workflows/ci.yml)
```

- [ ] **Step 2: Update the "Supported Databases" CI sentence in README**

Find:
```md
These are policy minimums; `composer.json` carries no database-version constraint. Continuous integration runs the full test suite across PHP 8.3, 8.4 and 8.5 against MySQL 9.7, MariaDB 11.4, PostgreSQL 18 and SQLite. The Oracle (`oci`) adapter was removed in v1.8.0.
```
Replace with:
```md
These are policy minimums; `composer.json` carries no database-version constraint. Continuous integration runs the full test suite across PHP 8.3, 8.4 and 8.5 against MySQL 8.4 & 9.7, MariaDB 10.11, 11.4, 11.8 & 12.3, PostgreSQL 15, 16, 17 & 18, and SQLite 3. The Oracle (`oci`) adapter was removed in v1.8.0.
```

- [ ] **Step 3: Correct the stale "level 5" in CLAUDE.md commands table**

Find (line ~31):
```md
docker compose exec tests composer run analyse                         # PHPStan (level 5) static analysis
```
Replace with:
```md
docker compose exec tests composer run analyse                         # PHPStan (level 8) static analysis (lib + examples)
```

- [ ] **Step 4: Correct the CI/analyse paragraph in CLAUDE.md**

Find (line ~47):
```md
CI (`.github/workflows/ci.yml`, GitHub Actions) runs a matrix per push: PHP 8.3, 8.4, 8.5, each with MySQL, MariaDB, Postgres, and memcached as service containers (SQLite needs no service). The 8.3 job also runs `composer run analyse` (PHPStan, level 5, with a frozen baseline in `phpstan-baseline.neon` — new code must not add suppressions to it). `compose.yaml`'s `tests` service defaults its build arg `PHP_VERSION` to `8.3`.
```
Replace with:
```md
CI (`.github/workflows/ci.yml`, GitHub Actions) runs a matrix per push: PHP 8.3, 8.4, 8.5, each crossed with a DB-version set (MySQL 8.4/9.7, MariaDB 10.11/11.4/11.8/12.3, Postgres 15/16/17/18) as service containers, plus memcached and redis (SQLite needs no service). The baseline 8.3 cell also runs `composer run analyse` (PHPStan level 8 over `lib` and `examples`, analysing against the 8.3–8.5 `phpVersion` range, with a frozen baseline in `phpstan-baseline.neon` — new code must not add suppressions to it). `compose.yaml`'s `tests` service defaults its build arg `PHP_VERSION` to `8.3`.
```

- [ ] **Step 5: Sanity-check the edits**

```bash
grep -n "Coverage" README.md
grep -n "MySQL 8.4 & 9.7" README.md
grep -c "level 5" CLAUDE.md    # expect 0
grep -n "level 8" CLAUDE.md    # expect the two updated lines
```
Expected: badge + DB sentence present in README; `level 5` count is `0` in CLAUDE.md.

- [ ] **Step 6: Commit**

```bash
git add README.md CLAUDE.md
git commit -m "docs: coverage badge + DB-version matrix; correct PHPStan level to 8"
```

---

## Task 4: Drop the PHP 5.3 runtime guard

**Files:**
- Modify: `ActiveRecord.php` (remove lines 3–5)

- [ ] **Step 1: Remove the guard**

In `ActiveRecord.php`, delete these four lines (the `if` block and the blank line after it):
```php
if (!defined('PHP_VERSION_ID') || PHP_VERSION_ID < 50300) {
    die('PHP ActiveRecord requires PHP 5.3 or higher');
}

```
The file must now begin:
```php
<?php

define('PHP_ACTIVERECORD_VERSION_ID', '2.0.0');

require __DIR__ . '/lib/Singleton.php';
```

- [ ] **Step 2: Confirm the library still bootstraps and the suite is green**

```bash
docker compose exec tests php -r "require 'ActiveRecord.php'; echo PHP_ACTIVERECORD_VERSION_ID, PHP_EOL;"
docker compose exec tests composer run test
```
Expected: prints `2.0.0`; full suite passes (no skips/risky/warnings — the CI gate flags).

- [ ] **Step 3: Commit**

```bash
git add ActiveRecord.php
git commit -m "chore: drop obsolete PHP 5.3 guard (composer enforces ^8.3)"
```

---

## Task 5: Six focused, runnable SQLite examples

**Files (create):**
- `examples/validations/{validations.sql,validations.php,models/User.php}`
- `examples/relationships/{relationships.sql,relationships.php,models/{Author,Profile,Post,Comment,Tag,Tagging}.php}`
- `examples/callbacks/{callbacks.sql,callbacks.php,models/Article.php}`
- `examples/attributes/{attributes.sql,attributes.php,models/{Member,Company}.php}`
- `examples/serialization/{serialization.sql,serialization.php,models/{Product,Category}.php}`
- `examples/finders/{finders.sql,finders.php,models/Widget.php}`
- `examples/README.md`

**Key constraints (apply to every example):**
- **SQLite adapter throws if the DB file is absent** (`SqliteAdapter.php:33`), so each script must create the file + schema via **raw PDO before** `Config::initialize`. Skeleton (adjust the `<name>`):
  ```php
  require_once __DIR__ . '/../../ActiveRecord.php';
  require_once __DIR__ . '/models/User.php'; // + other models

  $db = __DIR__ . '/<name>.db';
  @unlink($db);                                   // fresh + idempotent
  $pdo = new PDO('sqlite:' . $db);
  $pdo->exec((string) file_get_contents(__DIR__ . '/<name>.sql'));
  $pdo = null;

  ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
      $cfg->set_connections(['development' => 'sqlite://' . $db]);
  });
  ```
  (`'sqlite://' . '/abs/path.db'` → `sqlite:///abs/path.db`, which the adapter reads as host `/abs/path.db`.)
- **Level-8 cleanliness:** give every model `@property`/`@property-read` PHPDoc for its columns and relationships (types the magic `__get`), and `@method` for dynamic finders used. This is both the level-8 device and good documentation — no inline casts needed in scripts.
- **No `strict_types`** (matches existing examples; avoids TypeErrors across the AR boundary).
- Print with a tiny helper `function out(string $s): void { echo $s . "\n"; }`.

### 5a — validations

- [ ] **Step 1: `examples/validations/validations.sql`**
```sql
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  email TEXT,
  age INTEGER,
  role TEXT
);
INSERT INTO users (name, email, age, role) VALUES ('Existing', 'taken@example.com', 30, 'member');
```

- [ ] **Step 2: `examples/validations/models/User.php`**
```php
<?php

/**
 * @property int    $id
 * @property string $name
 * @property string $email
 * @property int    $age
 * @property string $role
 * @property-read \ActiveRecord\Errors $errors
 */
class User extends ActiveRecord\Model
{
    public static $validates_presence_of = [
        ['name'], ['email'],
    ];

    public static $validates_length_of = [
        ['name', 'minimum' => 2, 'maximum' => 50],
    ];

    public static $validates_uniqueness_of = [
        ['email'],
    ];

    public static $validates_format_of = [
        ['email', 'with' => '/\A[^@\s]+@[^@\s]+\.[^@\s]+\z/'],
    ];

    public static $validates_numericality_of = [
        ['age', 'greater_than' => 0, 'less_than' => 150, 'allow_null' => true],
    ];

    public static $validates_inclusion_of = [
        ['role', 'in' => ['admin', 'member', 'guest']],
    ];

    // Custom validation: runs on every save; add errors via $this->errors->add().
    public function validate(): void
    {
        if (strtolower((string) $this->name) === 'admin') {
            $this->errors->add('name', 'cannot be the reserved word "admin"');
        }
    }
}
```

- [ ] **Step 3: `examples/validations/validations.php`**
```php
<?php

require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/User.php';

$db = __DIR__ . '/validations.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/validations.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://' . $db]);
});

function out(string $s): void
{
    echo $s . "\n";
}

// A valid record saves.
$ok = new User(['name' => 'Ada', 'email' => 'ada@example.com', 'age' => 36, 'role' => 'member']);
out('valid saved? ' . ($ok->save() ? 'yes' : 'no'));

// An invalid record: fails presence, format, uniqueness, inclusion, and the custom rule.
$bad = new User(['name' => 'a', 'email' => 'not-an-email', 'age' => 999, 'role' => 'wizard']);
out('invalid saved? ' . ($bad->save() ? 'yes' : 'no'));
out('is_valid()? ' . ($bad->is_valid() ? 'yes' : 'no'));
out('errors:');
foreach ($bad->errors->full_messages() as $msg) {
    out('  - ' . $msg);
}

// Reserved-word custom rule + uniqueness on an existing email.
$dup = new User(['name' => 'admin', 'email' => 'taken@example.com', 'age' => 20, 'role' => 'guest']);
$dup->save();
out('errors on name: ' . implode(', ', $dup->errors->on('name') ?: []));
out('errors on email: ' . implode(', ', $dup->errors->on('email') ?: []));
```

- [ ] **Step 4: Run it**

Run: `docker compose exec tests php examples/validations/validations.php`
Expected: `valid saved? yes`, `invalid saved? no`, `is_valid()? no`, a bulleted error list, and non-empty name/email error lines. No PHP warnings/errors.

- [ ] **Step 5: Commit**
```bash
git add examples/validations
git commit -m "examples: validations (macros, custom validate, Errors object)"
```

### 5b — relationships

- [ ] **Step 1: `examples/relationships/relationships.sql`**
```sql
CREATE TABLE authors  (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT);
CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER, bio TEXT);
CREATE TABLE posts    (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER, title TEXT);
CREATE TABLE comments (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, body TEXT);
CREATE TABLE tags     (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT);
CREATE TABLE taggings (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, tag_id INTEGER);
```

- [ ] **Step 2: Model files under `examples/relationships/models/`**

`Author.php`:
```php
<?php

/**
 * @property int    $id
 * @property string $name
 * @property-read array<int, Post>    $posts
 * @property-read Profile             $profile
 * @property-read array<int, Comment> $comments
 */
class Author extends ActiveRecord\Model
{
    public static $has_many = [
        ['posts'],
        ['comments', 'through' => 'posts'],
    ];

    public static $has_one = [
        ['profile'],
    ];
}
```
`Profile.php`:
```php
<?php

/**
 * @property int    $id
 * @property int    $author_id
 * @property string $bio
 * @property-read Author $author
 */
class Profile extends ActiveRecord\Model
{
    public static $belongs_to = [['author']];
}
```
`Post.php`:
```php
<?php

/**
 * @property int    $id
 * @property int    $author_id
 * @property string $title
 * @property-read Author              $author
 * @property-read array<int, Comment> $comments
 * @property-read array<int, Tagging> $taggings
 * @property-read array<int, Tag>     $tags
 */
class Post extends ActiveRecord\Model
{
    public static $belongs_to = [['author']];

    public static $has_many = [
        ['comments'],
        ['taggings'],                          // the intermediate assoc that `through` walks
        ['tags', 'through' => 'taggings'],
    ];
}
```
`Comment.php`:
```php
<?php

/**
 * @property int    $id
 * @property int    $post_id
 * @property string $body
 * @property-read Post $post
 */
class Comment extends ActiveRecord\Model
{
    public static $belongs_to = [['post']];
}
```
`Tag.php`:
```php
<?php

/**
 * @property int    $id
 * @property string $name
 * @property-read array<int, Tagging> $taggings
 * @property-read array<int, Post>    $posts
 */
class Tag extends ActiveRecord\Model
{
    public static $has_many = [
        ['taggings'],                          // intermediate assoc for `through`
        ['posts', 'through' => 'taggings'],
    ];
}
```
`Tagging.php`:
```php
<?php

/**
 * @property int $id
 * @property int $post_id
 * @property int $tag_id
 * @property-read Post $post
 * @property-read Tag  $tag
 */
class Tagging extends ActiveRecord\Model
{
    public static $belongs_to = [['post'], ['tag']];
}
```

- [ ] **Step 3: `examples/relationships/relationships.php`**
```php
<?php

require_once __DIR__ . '/../../ActiveRecord.php';
foreach (['Author', 'Profile', 'Post', 'Comment', 'Tag', 'Tagging'] as $m) {
    require_once __DIR__ . '/models/' . $m . '.php';
}

$db = __DIR__ . '/relationships.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/relationships.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://' . $db]);
});

function out(string $s): void
{
    echo $s . "\n";
}

// Seed: an author with a profile (has_one), two posts (has_many),
// comments on a post, and tags via a join table (has_many through).
$ada = Author::create(['name' => 'Ada']);
$ada->create_profile(['bio' => 'Mathematician']);                 // has_one builder
$p1 = $ada->create_posts(['title' => 'On Engines']);             // has_many builder
$ada->create_posts(['title' => 'On Notes']);
$p1->create_comments(['body' => 'Fascinating']);
$p1->create_comments(['body' => 'Agreed']);

$php = Tag::create(['name' => 'php']);
Tagging::create(['post_id' => $p1->id, 'tag_id' => $php->id]);

// belongs_to + has_one
out('post author: ' . $p1->author->name);
out('author bio (has_one): ' . $ada->profile->bio);

// has_many
out('post count: ' . count($ada->posts));

// has_many :through (author -> comments through posts)
out('author comment count (through): ' . count($ada->comments));

// has_many :through many-to-many (post <-> tags via taggings)
out('first post tags: ' . implode(', ', ActiveRecord\collect($p1->tags, 'name')));

// Eager loading with include (avoids N+1); iterate the loaded graph.
$authors = Author::all(['include' => ['posts', 'profile']]);
foreach ($authors as $author) {
    out($author->name . ' has ' . count($author->posts) . ' posts');
}
```

- [ ] **Step 4: Run it**

Run: `docker compose exec tests php examples/relationships/relationships.php`
Expected: `post author: Ada`, bio line, `post count: 2`, `author comment count (through): 2`, `first post tags: php`, and an eager-load line. No errors.

- [ ] **Step 5: Commit**
```bash
git add examples/relationships
git commit -m "examples: relationships (belongs_to/has_many/has_one/through, include, builders)"
```

### 5c — callbacks

- [ ] **Step 1: `examples/callbacks/callbacks.sql`**
```sql
CREATE TABLE articles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT,
  slug TEXT,
  body TEXT,
  word_count INTEGER
);
```

- [ ] **Step 2: `examples/callbacks/models/Article.php`**
```php
<?php

/**
 * @property int    $id
 * @property string $title
 * @property string $slug
 * @property string $body
 * @property int    $word_count
 */
class Article extends ActiveRecord\Model
{
    public static $before_validation = ['make_slug'];
    public static $before_save = ['count_words'];
    public static $after_create = ['log_created'];
    public static $before_update = ['log_updating'];
    public static $before_destroy = ['log_destroying'];

    public function make_slug(): void
    {
        $this->slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim((string) $this->title)) ?? '');
    }

    // Returning false from a before_ hook halts the save.
    public function count_words(): bool
    {
        $body = trim((string) $this->body);
        if ($body === '') {
            echo "  [before_save] empty body -> halting save\n";
            return false;
        }
        $this->word_count = count(preg_split('/\s+/', $body) ?: []);
        return true;
    }

    public function log_created(): void
    {
        echo "  [after_create] #{$this->id} '{$this->slug}'\n";
    }

    public function log_updating(): void
    {
        echo "  [before_update] #{$this->id}\n";
    }

    public function log_destroying(): void
    {
        echo "  [before_destroy] #{$this->id}\n";
    }
}
```

- [ ] **Step 3: `examples/callbacks/callbacks.php`**
```php
<?php

require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Article.php';

$db = __DIR__ . '/callbacks.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/callbacks.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://' . $db]);
});

function out(string $s): void
{
    echo $s . "\n";
}

out('create (fires before_validation -> before_save -> after_create):');
$a = Article::create(['title' => 'Hello World', 'body' => 'one two three']);
out("  slug='{$a->slug}' word_count={$a->word_count}");

out('update (fires before_update):');
$a->body = 'now four words here';
$a->save();
out("  word_count={$a->word_count}");

out('halted save (before_save returns false on empty body):');
$b = Article::create(['title' => 'Empty', 'body' => '']);
out('  persisted? ' . ($b->id ? 'yes' : 'no'));

out('destroy (fires before_destroy):');
$a->delete();
```

- [ ] **Step 4: Run it**

Run: `docker compose exec tests php examples/callbacks/callbacks.php`
Expected: interleaved hook log lines showing order; `slug='hello-world' word_count=3`; updated `word_count=4`; halted save `persisted? no`; a `before_destroy` line. No errors.

- [ ] **Step 5: Commit**
```bash
git add examples/callbacks
git commit -m "examples: callbacks (lifecycle order + halting a save)"
```

### 5d — attributes

- [ ] **Step 1: `examples/attributes/attributes.sql`**
```sql
CREATE TABLE companies (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, country TEXT);
CREATE TABLE members (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  first_name TEXT,
  last_name TEXT,
  email TEXT,
  password_hash TEXT,
  is_admin INTEGER
);
INSERT INTO companies (name, country) VALUES ('Acme', 'IT');
```

- [ ] **Step 2: models under `examples/attributes/models/`**

`Company.php`:
```php
<?php

/**
 * @property int    $id
 * @property string $name
 * @property string $country
 */
class Company extends ActiveRecord\Model
{
    public static $has_many = [['members']];
}
```
`Member.php`:
```php
<?php

/**
 * @property int    $id
 * @property int    $company_id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string $password_hash
 * @property int    $is_admin
 * @property-read string  $full_name
 * @property string  $email_address  alias of email
 * @property-read Company $company
 * @property-read string  $country    delegated from company
 */
class Member extends ActiveRecord\Model
{
    public static $belongs_to = [['company']];

    // Mass-assignment whitelist: only these are set from an array; is_admin is ignored.
    public static $attr_accessible = ['first_name', 'last_name', 'email', 'company_id'];

    // Expose email under a second name.
    public static $alias_attribute = ['email_address' => 'email'];

    // Read country straight off the associated company.
    public static $delegate = [['country', 'to' => 'company']];

    // Computed read-only attribute (get_ prefix -> $member->full_name).
    public function get_full_name(): string
    {
        return trim((string) $this->read_attribute('first_name') . ' ' . (string) $this->read_attribute('last_name'));
    }

    // Custom writer (set_ prefix -> $member->password = ...): never store plaintext.
    public function set_password(string $plaintext): void
    {
        $this->assign_attribute('password_hash', hash('sha256', $plaintext));
    }
}
```

- [ ] **Step 3: `examples/attributes/attributes.php`**
```php
<?php

require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Company.php';
require_once __DIR__ . '/models/Member.php';

$db = __DIR__ . '/attributes.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/attributes.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://' . $db]);
});

function out(string $s): void
{
    echo $s . "\n";
}

// Mass assignment respects $attr_accessible: is_admin is dropped.
$m = new Member([
    'first_name' => 'Grace',
    'last_name'  => 'Hopper',
    'email'      => 'grace@example.com',
    'company_id' => 1,
    'is_admin'   => 1,   // ignored (not in $attr_accessible)
]);
$m->password = 's3cret';           // custom setter -> password_hash
$m->save();

out('full_name (custom getter): ' . $m->full_name);
out('is_admin after mass-assign (protected): ' . (int) $m->is_admin);   // 0
out('password stored as hash: ' . $m->password_hash);
out('alias_attribute email_address: ' . $m->email_address);

// Delegation: read company.country through the member.
out('delegated country: ' . $m->country);

// Dirty tracking.
$m->first_name = 'Grace B.';
out('is_dirty()? ' . ($m->is_dirty() ? 'yes' : 'no'));
out('dirty_attributes: ' . implode(', ', array_keys($m->dirty_attributes())));
$m->save();
out('is_dirty() after save? ' . ($m->is_dirty() ? 'yes' : 'no'));
```

- [ ] **Step 4: Run it**

Run: `docker compose exec tests php examples/attributes/attributes.php`
Expected: `full_name (custom getter): Grace Hopper`; `is_admin ... : 0`; a 64-char hex hash; `email_address: grace@example.com`; `delegated country: IT`; `is_dirty()? yes`; `dirty_attributes: first_name`; `is_dirty() after save? no`. No errors.

- [ ] **Step 5: Commit**
```bash
git add examples/attributes
git commit -m "examples: attributes (getters/setters, alias, accessible, delegate, dirty)"
```

### 5e — serialization

- [ ] **Step 1: `examples/serialization/serialization.sql`**
```sql
CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT);
CREATE TABLE products (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  category_id INTEGER,
  name TEXT,
  price REAL,
  secret_cost REAL
);
INSERT INTO categories (name) VALUES ('Tools');
```

- [ ] **Step 2: models under `examples/serialization/models/`**

`Category.php`:
```php
<?php

/**
 * @property int    $id
 * @property string $name
 * @property-read array<int, Product> $products
 */
class Category extends ActiveRecord\Model
{
    public static $has_many = [['products']];
}
```
`Product.php`:
```php
<?php

/**
 * @property int    $id
 * @property int    $category_id
 * @property string $name
 * @property float  $price
 * @property float  $secret_cost
 * @property-read Category $category
 * @property-read float    $discounted_price
 */
class Product extends ActiveRecord\Model
{
    public static $belongs_to = [['category']];

    // Exposed to serializers via the 'methods' option.
    public function discounted_price(): float
    {
        return round((float) $this->price * 0.9, 2);
    }
}
```

- [ ] **Step 3: `examples/serialization/serialization.php`**
```php
<?php

require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Category.php';
require_once __DIR__ . '/models/Product.php';

$db = __DIR__ . '/serialization.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/serialization.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://' . $db]);
});

function out(string $s): void
{
    echo $s . "\n";
}

$p = Product::create([
    'category_id' => 1,
    'name'        => 'Hammer',
    'price'       => 20.0,
    'secret_cost' => 7.5,
]);

// only / except pick or drop columns.
out('to_json only: '   . $p->to_json(['only' => ['name', 'price']]));
out('to_json except: ' . $p->to_json(['except' => ['secret_cost', 'category_id']]));

// methods adds computed values from model methods.
out('to_json methods: ' . $p->to_json(['only' => ['name'], 'methods' => ['discounted_price']]));

// include pulls in an association.
out('to_json include: ' . $p->to_json(['only' => ['name'], 'include' => ['category']]));

// to_array mirrors the same options; to_xml renders XML.
$arr = $p->to_array(['only' => ['name', 'price']]);
out('to_array keys: ' . implode(', ', array_keys($arr)));
out('to_xml except secret_cost:');
out($p->to_xml(['except' => ['secret_cost']]));
```

- [ ] **Step 4: Run it**

Run: `docker compose exec tests php examples/serialization/serialization.php`
Expected: JSON lines honoring `only`/`except`/`methods`/`include` (e.g. `discounted_price` = `18`), `to_array keys: name, price`, and an XML block without `secret_cost`. No errors.

- [ ] **Step 5: Commit**
```bash
git add examples/serialization
git commit -m "examples: serialization (to_json/to_xml/to_array with only/except/methods/include)"
```

### 5f — finders

- [ ] **Step 1: `examples/finders/finders.sql`**
```sql
CREATE TABLE widgets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  category TEXT,
  price REAL,
  in_stock INTEGER
);
INSERT INTO widgets (name, category, price, in_stock) VALUES
  ('Alpha', 'gadgets', 9.99,  1),
  ('Beta',  'gadgets', 19.99, 0),
  ('Gamma', 'gizmos',  4.99,  1),
  ('Delta', 'gizmos',  49.99, 1);
```

- [ ] **Step 2: `examples/finders/models/Widget.php`**
```php
<?php

/**
 * @property int    $id
 * @property string $name
 * @property string $category
 * @property float  $price
 * @property int    $in_stock
 *
 * @method static Widget|null        find_by_name(string $name)
 * @method static array<int, Widget> find_all_by_category(string $category)
 * @method static Widget|null        find_by_category_and_in_stock(string $category, int $in_stock)
 */
class Widget extends ActiveRecord\Model
{
    // A reusable static scope.
    /** @return array<int, Widget> */
    public static function cheap(): array
    {
        return static::all(['conditions' => ['price < ?', 10.0], 'order' => 'price asc']);
    }
}
```

- [ ] **Step 3: `examples/finders/finders.php`**
```php
<?php

require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Widget.php';

$db = __DIR__ . '/finders.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/finders.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://' . $db]);
});

function out(string $s): void
{
    echo $s . "\n";
}

// Dynamic finders (they return a model or null -> use nullsafe access).
out('find_by_name: ' . (Widget::find_by_name('Alpha')?->name ?? '(none)'));
out('find_all_by_category(gizmos): ' . count(Widget::find_all_by_category('gizmos')));
out('find_by_category_and_in_stock: ' . (Widget::find_by_category_and_in_stock('gadgets', 1)?->name ?? '(none)'));

// Option set: conditions / order / limit / offset / select.
$page = Widget::all([
    'select'     => 'name, price',
    'conditions' => ['price > ?', 5.0],
    'order'      => 'price desc',
    'limit'      => 2,
    'offset'     => 1,
]);
out('page names: ' . implode(', ', ActiveRecord\collect($page, 'name')));

// group / having (aggregate).
$rows = Widget::all([
    'select' => 'category, COUNT(*) AS n',
    'group'  => 'category',
    'having' => 'COUNT(*) > 1',
]);
out('categories with >1 widget: ' . implode(', ', ActiveRecord\collect($rows, 'category')));

// Raw SQL escape hatch.
$raw = Widget::find_by_sql('SELECT * FROM widgets WHERE in_stock = 1 ORDER BY price');
out('in-stock via find_by_sql: ' . count($raw));

// Static scope.
out('cheap(): ' . implode(', ', ActiveRecord\collect(Widget::cheap(), 'name')));
```

- [ ] **Step 4: Run it**

Run: `docker compose exec tests php examples/finders/finders.php`
Expected: `find_by_name: Alpha`; `find_all_by_category(gizmos): 2`; `find_by_category_and_in_stock: Alpha`; a page line; `categories with >1 widget: gadgets, gizmos`; `in-stock via find_by_sql: 3`; `cheap(): Gamma, Alpha`. No errors.

- [ ] **Step 5: Commit**
```bash
git add examples/finders
git commit -m "examples: finders (dynamic finders, option set, find_by_sql, scope)"
```

### 5g — examples index

- [ ] **Step 1: `examples/README.md`**
```md
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
```

- [ ] **Step 2: Verify every example still runs end-to-end**
```bash
for e in validations relationships callbacks attributes serialization finders; do
  echo "== $e =="
  docker compose exec tests php examples/$e/$e.php || { echo "FAILED: $e"; break; }
done
```
Expected: each prints its output block with no PHP error/warning.

- [ ] **Step 3: Ignore the generated `.db` files**

Append to `.gitignore`:
```
examples/**/*.db
```
Confirm none were staged: `git status --short examples | grep -c '\.db$'` → `0`.

- [ ] **Step 4: Commit**
```bash
git add examples/README.md .gitignore
git commit -m "examples: index README + ignore generated sqlite db files"
```

---

## Task 6: PHPStan — analyse `examples/` at level 8 + pin `phpVersion`

**Files:**
- Modify: `phpstan.neon` (add `examples` to `paths`; add `phpVersion` range)

**Interfaces:**
- Consumes: the example files from Task 5 (must already exist for the path to analyse anything meaningful).

master is already `level: 8`. This task must run **after** Task 5.

- [ ] **Step 1: Add the `examples` path and `phpVersion` range to `phpstan.neon`**

Replace the whole `parameters:` block:
```neon
parameters:
    level: 8
    paths:
        - lib
    bootstrapFiles:
        - ActiveRecord.php
```
with:
```neon
parameters:
    level: 8
    phpVersion:
        min: 80300
        max: 80599
    paths:
        - lib
        - examples
    bootstrapFiles:
        - ActiveRecord.php
```

- [ ] **Step 2: Run the analysis**

Run: `docker compose exec tests composer run analyse`
Expected: `[OK] No errors`. 

If findings appear, resolve them **without** touching `phpstan-baseline.neon`:
- **In the new examples** — the usual cause is a missing `@property`/`@method` annotation on a model (add the column/relationship as `@property`, the finder as `@method`), or an unguarded `mixed`. For a genuinely `mixed` value, add a narrow `/** @var Type $x */` or an explicit cast at the use site — keep it minimal.
- **In the pre-existing `examples/simple/`, `examples/orders/`, `examples/upsert/`** — these predate this bar and **will** produce findings once the path is analysed (e.g. `Book::first()->attributes()` in `simple.php` is a nullsafe/`mixed` level-8 error; `orders.php` reads many `mixed` attributes). Fix them the same way: add `@property` PHPDoc to `examples/*/models/*.php`, guard the `Model::first()`/`find()` nullable with a check or `?->`, cast at use sites. These are behavior-preserving edits. If a legacy example would need ugly contortions or a behavior change to pass, **STOP and report to the maintainer** rather than bloating the baseline.
- **In `lib` from the `phpVersion` pin** — a symbol deprecated/removed across 8.3–8.5. These are real portability issues: fix them (or, if the fix is behavior-affecting or the volume is non-trivial, STOP and report to the maintainer). Do **not** add baseline entries or narrow the range to force a pass.

- [ ] **Step 3: Confirm the baseline is unchanged**

Run: `git diff --stat phpstan-baseline.neon`
Expected: **no output** (the baseline file is untouched — still 37 lines).

- [ ] **Step 4: Run the style gate over new code**

```bash
docker compose exec tests composer run cs
```
Expected: no style diffs. If any, run `docker compose exec tests composer run cs-fix`, re-inspect, and amend the relevant example commit.

- [ ] **Step 5: Commit**
```bash
git add phpstan.neon
git commit -m "phpstan: analyse examples at level 8 + pin phpVersion to 8.3-8.5"
```

---

## Final verification (whole PR)

- [ ] **Run the full suite** (guard removal must not change behavior):
  `docker compose exec tests composer run test` → all green, no skips/risky/warnings.
- [ ] **Run static analysis:** `docker compose exec tests composer run analyse` → no errors.
- [ ] **Run the style gate:** `docker compose exec tests composer run cs` → clean.
- [ ] **Run every example once more** (Task 5g loop) → all print clean output.
- [ ] **Push the branch and open the PR.** The authoritative gate for the CI-config tasks (1 & 2) is a green Actions run: confirm the `test` job fans out to 12 cells across the DB versions, the once-only `analyse`/`cs` steps run exactly once, and (post-merge to master) the `coverage-badge` job creates the `badges` branch and the README badge renders.

---

## Notes for the implementer

- **Coverage badge is only fully verifiable post-merge:** the `coverage-badge` job is gated to `push` on `master`, so it does not run on the PR. Until the first master build, the README coverage badge shows a broken image — expected.
- **`ActiveRecord\collect($array, 'field')`** is a library helper (used in the existing `orders` example) that plucks a field from a list of models — reused in the examples to keep output one-liners level-8-clean.
- **Do not add `strict_types`** to example files; AR's loosely-typed internal calls can raise `TypeError` under caller-side strict mode.
- If any `docker compose exec tests` command errors with "no such service", run `docker compose up -d` first.
