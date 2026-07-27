# Remove the OCI/Oracle Adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Completely remove the incomplete, untested OCI/Oracle adapter from php-activerecord without affecting the MySQL, Postgres, or SQLite adapters.

**Architecture:** Delete the adapter file, its dedicated test, and its SQL fixtures; strip every code branch guarded by `instanceof OciAdapter` / `protocol == 'oci'`, collapsing the one behavioral conditional (an insert/sequence path in `Model.php`) to its non-Oracle body; add an explicit, readable error for any residual `oci://` connection string; and update docs + version to v1.8.0.

**Tech Stack:** PHP 7.4–8.x, PHPUnit 9, PDO, Docker Compose (MySQL 5.6, Postgres 9.6, memcached). Tests run inside the `tests` container.

## Global Constraints

- Everything runs in Docker: `docker compose exec tests <cmd>`. The DB containers must be up (`docker compose up -d`).
- The test command is `composer run test` = `phpunit --fail-on-risky --fail-on-warning --fail-on-skipped`. **No test may skip** — a skip is a red build.
- Public API is **snake_case** (`set_up`, `assert_equals`, `test_*`). Do not rename to camelCase. Tests extend `DatabaseTest` (DB-backed) or `SnakeCase_PHPUnit_Framework_TestCase` (pure).
- **MySQL is the primary target.** Postgres and SQLite must remain green.
- **Do NOT touch:** `PDO::ATTR_ORACLE_NULLS` in `lib/Connection.php` (a standard PDO option, not the OCI adapter); the sequence machinery (`supports_sequences()`, `$table->sequence`, `insert_id($sequence)`) which Postgres depends on; the `RmBldg` model / `rm-bldg` fixture (a general quoting test).
- Match the surrounding legacy style in each file (`array()` literals, tabs, no strict-types header). This is a removal, not a modernization.
- Commit after each task with the exact message given.

---

### Task 1: Explicit error for the removed `oci` protocol

Add a guard so a leftover `oci://...` connection string fails with a readable message instead of "class ActiveRecord\OciAdapter not found". This task is done first and independently: the guard short-circuits `load_adapter_class()` before the (still-present) adapter file is loaded.

**Files:**
- Modify: `lib/Connection.php` (method `load_adapter_class`, ~lines 125-136)
- Test: `test/ConnectionTest.php`

**Interfaces:**
- Consumes: `ActiveRecord\Connection::instance(string $connection_string)` (public; internally calls the private `load_adapter_class`), `ActiveRecord\DatabaseException` (already used in this file).
- Produces: a `DatabaseException` thrown for any `oci` protocol with message `The OCI/Oracle adapter was removed in php-activerecord v1.8.0.`

- [ ] **Step 1: Write the failing test**

Add to `test/ConnectionTest.php` (before the closing `}`):

```php
	public function test_oci_protocol_throws_removed_exception()
	{
		$this->expectException(ActiveRecord\DatabaseException::class);
		$this->expectExceptionMessage('The OCI/Oracle adapter was removed in php-activerecord v1.8.0.');

		ActiveRecord\Connection::instance('oci://test:test@127.0.0.1/dev');
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_oci_protocol_throws_removed_exception`
Expected: FAIL — no exception (or a different one), because the OCI adapter still loads.

- [ ] **Step 3: Add the guard**

In `lib/Connection.php`, change the start of `load_adapter_class` from:

```php
	private static function load_adapter_class($adapter)
	{
		$class = ucwords($adapter) . 'Adapter';
```

to:

```php
	private static function load_adapter_class($adapter)
	{
		if (strtolower($adapter) === 'oci')
			throw new DatabaseException('The OCI/Oracle adapter was removed in php-activerecord v1.8.0.');

		$class = ucwords($adapter) . 'Adapter';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec tests vendor/bin/phpunit --filter test_oci_protocol_throws_removed_exception`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add lib/Connection.php test/ConnectionTest.php
git commit -m "$(printf 'Rejects the removed oci protocol with a clear error\n\nCo-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>')"
```

---

### Task 2: Collapse the Oracle branch in the insert/sequence path

Remove the two `Model.php` branches that only existed for OCI. The retained `else` body is exactly the path Postgres already uses, so behavior for MySQL/Postgres/SQLite is unchanged. Verification is the existing write suite run against Postgres (which exercises the sequence path).

**Files:**
- Modify: `lib/Model.php` (insert path ~lines 820-841; `ar_rnum__` skip ~lines 1211-1214)

**Interfaces:**
- Consumes: `$table->sequence`, `$table->insert($attributes, $pk, $sequence)`, `static::connection()->insert_id($sequence)` — all unchanged.
- Produces: no new interface; removes the last runtime reference to the `OciAdapter` class inside `lib/`.

- [ ] **Step 1: Collapse the sequence branch**

In `lib/Model.php`, replace:

```php
		if ($table->sequence && !isset($attributes[$pk]))
		{
			if (($conn = static::connection()) instanceof OciAdapter)
			{
				// terrible oracle makes us select the nextval first
				$attributes[$pk] = $conn->get_next_sequence_value($table->sequence);
				$table->insert($attributes);
				$this->attributes[$pk] = $attributes[$pk];
			}
			else
			{
				// unset pk that was set to null
				if (array_key_exists($pk,$attributes))
					unset($attributes[$pk]);

				$table->insert($attributes,$pk,$table->sequence);
				$use_sequence = true;
			}
		}
		else
			$table->insert($attributes);
```

with:

```php
		if ($table->sequence && !isset($attributes[$pk]))
		{
			// unset pk that was set to null
			if (array_key_exists($pk,$attributes))
				unset($attributes[$pk]);

			$table->insert($attributes,$pk,$table->sequence);
			$use_sequence = true;
		}
		else
			$table->insert($attributes);
```

- [ ] **Step 2: Remove the `ar_rnum__` skip**

In `lib/Model.php`, replace:

```php
			else
			{
				// ignore OciAdapter's limit() stuff
				if ($name == 'ar_rnum__')
					continue;

				// set arbitrary data
				$this->assign_attribute($name,$value);
			}
```

with:

```php
			else
			{
				// set arbitrary data
				$this->assign_attribute($name,$value);
			}
```

- [ ] **Step 3: Verify no `OciAdapter` references remain in lib**

Run: `grep -rn "OciAdapter" lib/Model.php`
Expected: no output.

- [ ] **Step 4: Run the write suite on MySQL, Postgres, and SQLite**

```bash
docker compose exec tests vendor/bin/phpunit test/ActiveRecordWriteTest.php
docker compose exec tests vendor/bin/phpunit --adapter pgsql test/ActiveRecordWriteTest.php
docker compose exec tests vendor/bin/phpunit --adapter sqlite test/ActiveRecordWriteTest.php
```
Expected: PASS on all three (Postgres exercises the retained sequence path).

- [ ] **Step 5: Commit**

```bash
git add lib/Model.php
git commit -m "$(printf 'Removes the Oracle-only branch from the insert path\n\nCo-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>')"
```

---

### Task 3: Strip OCI branches from the test helpers

Remove OCI handling from the shared test infrastructure. After this, no helper references the `oci` protocol or the `OciAdapter` class.

**Files:**
- Modify: `test/helpers/config.php` (remove the `oci` connection, ~line 48)
- Modify: `test/helpers/DatabaseLoader.php` (remove 4 `protocol == 'oci'` branches)
- Modify: `test/helpers/AdapterTest.php` (remove the OCI branch in `test_columnsx`, ~lines 262-263)

**Interfaces:**
- Consumes: nothing new.
- Produces: `DatabaseLoader` and `AdapterTest` with no Oracle-specific code paths; `Config` with only `mysql`, `pgsql`, `sqlite` connections.

- [ ] **Step 1: Remove the `oci` connection from config**

In `test/helpers/config.php`, delete this line from the `set_connections(array(...))` call:

```php
		'oci'    => getenv('PHPAR_OCI')    ?: 'oci://test:test@127.0.0.1/dev',
```

The remaining array keeps `mysql`, `pgsql`, and `sqlite`.

- [ ] **Step 2: Remove the `rm-bldg` skip in `reset_table_data`**

In `test/helpers/DatabaseLoader.php`, delete these lines (the loop body keeps the `DELETE` + `load_fixture_data` calls):

```php
			if ($this->db->protocol == 'oci' && $table == 'rm-bldg')
				continue;

```

- [ ] **Step 3: Remove the two OCI blocks in `drop_tables`**

In `test/helpers/DatabaseLoader.php`, replace the whole `foreach` body:

```php
		foreach ($this->get_fixture_tables() as $table)
		{
			if ($this->db->protocol == 'oci')
			{
				$table = strtoupper($table);

				if ($table == 'RM-BLDG')
					continue;
			}

			if (in_array($table,$tables))
				$this->db->query('DROP TABLE ' . $this->quote_name($table));

			if ($this->db->protocol == 'oci')
			{
				try {
					$this->db->query("DROP SEQUENCE {$table}_seq");
				} catch (ActiveRecord\DatabaseException $e) {
					// ignore
				}
			}
		}
```

with:

```php
		foreach ($this->get_fixture_tables() as $table)
		{
			if (in_array($table,$tables))
				$this->db->query('DROP TABLE ' . $this->quote_name($table));
		}
```

- [ ] **Step 4: Remove the OCI branch in `quote_name`**

In `test/helpers/DatabaseLoader.php`, replace:

```php
	public function quote_name($name)
	{
		if ($this->db->protocol == 'oci')
			$name = strtoupper($name);

		return $this->db->quote_name($name);
	}
```

with:

```php
	public function quote_name($name)
	{
		return $this->db->quote_name($name);
	}
```

- [ ] **Step 5: Remove the OCI branch in `AdapterTest::test_columnsx`**

In `test/helpers/AdapterTest.php`, delete these two lines:

```php
		if ($this->conn instanceof ActiveRecord\OciAdapter)
			$names = array_filter(array_map('strtolower',$names),function($s) { $s !== 'some_time'; });
```

- [ ] **Step 6: Run the full suite (MySQL) and confirm helpers are clean**

```bash
docker compose exec tests composer run test
grep -rn "oci\|OciAdapter" test/helpers
```
Expected: suite PASS (green, no skips); grep returns no output.

- [ ] **Step 7: Commit**

```bash
git add test/helpers/config.php test/helpers/DatabaseLoader.php test/helpers/AdapterTest.php
git commit -m "$(printf 'Removes OCI handling from the test helpers\n\nCo-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>')"
```

---

### Task 4: Remove OCI guard blocks from the behavioral tests

Three DB-backed tests carried `instanceof ActiveRecord\OciAdapter` branches. Remove the Oracle paths; keep the standard-SQL assertions that MySQL/Postgres/SQLite run.

**Files:**
- Modify: `test/ActiveRecordWriteTest.php` (`test_save_blank_value`, ~lines 179-181)
- Modify: `test/ActiveRecordFindTest.php` (`test_having`, ~lines 360-378)
- Modify: `test/ActiveRecordTest.php` (`test_hyphenated_column_names_to_underscore` and `test_column_names_with_spaces`, ~lines 115-131)

**Interfaces:**
- Consumes: nothing new.
- Produces: three test files with no `OciAdapter` references.

- [ ] **Step 1: `test_save_blank_value` — drop the Oracle early-return**

In `test/ActiveRecordWriteTest.php`, delete:

```php
		// oracle doesn't do blanks. probably an option to enable?
		if ($this->conn instanceof ActiveRecord\OciAdapter)
			return;

```

so the method body starts directly at `$book = Book::find(1);`.

- [ ] **Step 2: `test_having` — collapse to the non-Oracle body**

In `test/ActiveRecordFindTest.php`, replace the whole method:

```php
	public function test_having()
	{
		if ($this->conn instanceof ActiveRecord\OciAdapter)
		{
			$author = Author::first(array(
				'select' => 'to_char(created_at,\'YYYY-MM-DD\') as created_at',
				'group'  => 'to_char(created_at,\'YYYY-MM-DD\')',
				'having' => "to_char(created_at,'YYYY-MM-DD') > '2009-01-01'"));
			$this->assert_sql_has("GROUP BY to_char(created_at,'YYYY-MM-DD') HAVING to_char(created_at,'YYYY-MM-DD') > '2009-01-01'",Author::table()->last_sql);
		}
		else
		{
			$author = Author::first(array(
				'select' => 'date(created_at) as created_at',
				'group'  => 'date(created_at)',
				'having' => "date(created_at) > '2009-01-01'"));
			$this->assert_sql_has("GROUP BY date(created_at) HAVING date(created_at) > '2009-01-01'",Author::table()->last_sql);
		}
	}
```

with:

```php
	public function test_having()
	{
		$author = Author::first(array(
			'select' => 'date(created_at) as created_at',
			'group'  => 'date(created_at)',
			'having' => "date(created_at) > '2009-01-01'"));
		$this->assert_sql_has("GROUP BY date(created_at) HAVING date(created_at) > '2009-01-01'",Author::table()->last_sql);
	}
```

- [ ] **Step 3: `ActiveRecordTest` — drop the two Oracle early-returns**

In `test/ActiveRecordTest.php`, in `test_hyphenated_column_names_to_underscore`, delete:

```php
		if ($this->conn instanceof ActiveRecord\OciAdapter)
			return;

```

and in `test_column_names_with_spaces`, delete the identical block:

```php
		if ($this->conn instanceof ActiveRecord\OciAdapter)
			return;

```

- [ ] **Step 4: Run the three files on MySQL and confirm no references remain**

```bash
docker compose exec tests vendor/bin/phpunit test/ActiveRecordWriteTest.php test/ActiveRecordFindTest.php test/ActiveRecordTest.php
grep -rn "OciAdapter" test/ActiveRecordWriteTest.php test/ActiveRecordFindTest.php test/ActiveRecordTest.php
```
Expected: PASS; grep returns no output.

- [ ] **Step 5: Commit**

```bash
git add test/ActiveRecordWriteTest.php test/ActiveRecordFindTest.php test/ActiveRecordTest.php
git commit -m "$(printf 'Removes OCI guard blocks from the behavioral tests\n\nCo-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>')"
```

---

### Task 5: Delete the adapter, its test, its SQL, and the phpunit exclude

With all references gone, delete the OCI files themselves and remove the now-dead `oci` group exclusion from `phpunit.xml`.

**Files:**
- Delete: `lib/adapters/OciAdapter.php`
- Delete: `test/OciAdapterTest.php`
- Delete: `test/sql/oci.sql`
- Delete: `test/sql/oci-after-fixtures.sql`
- Modify: `phpunit.xml` (remove the `<groups>` block)

**Interfaces:**
- Consumes: nothing.
- Produces: a tree with no OCI adapter, test, SQL, or group filter.

- [ ] **Step 1: Delete the four files**

```bash
git rm lib/adapters/OciAdapter.php test/OciAdapterTest.php test/sql/oci.sql test/sql/oci-after-fixtures.sql
```

- [ ] **Step 2: Remove the `<groups>` block from `phpunit.xml`**

Delete these lines (the only reason they existed was to skip `@group oci`, now gone):

```xml
    <groups>
        <exclude>
            <!-- Zamzar don't use the OCI adapter, and it was never complete anyway -->
            <group>oci</group>
        </exclude>
    </groups>
```

- [ ] **Step 3: Full-tree grep — only the legitimate PDO option should remain**

Run: `grep -rniE "oci|OciAdapter" lib test phpunit.xml`
Expected: a single hit — `PDO::ATTR_ORACLE_NULLS` in `lib/Connection.php`. Nothing else.

- [ ] **Step 4: Run the full suite on all three adapters**

```bash
docker compose exec tests composer run test
docker compose exec tests vendor/bin/phpunit --adapter pgsql
docker compose exec tests vendor/bin/phpunit --adapter sqlite
```
Expected: green on all three, no skips.

- [ ] **Step 5: Confirm language compatibility still passes**

Run: `docker compose exec tests composer run check-compatibility`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "$(printf 'Deletes the OCI adapter, its test, SQL, and phpunit exclude\n\nCo-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>')"
```

---

### Task 6: Docs and version bump to v1.8.0

Record the removal and drop Oracle from user-facing docs.

**Files:**
- Modify: `RELEASES.md` (new top entry)
- Modify: `ActiveRecord.php` (version constant)
- Modify: `README.md` (Supported Databases list)
- Modify: `CLAUDE.md` (adapter/priorities section)

**Interfaces:**
- Consumes: nothing.
- Produces: docs consistent with an OCI-free, v1.8.0 codebase.

- [ ] **Step 1: Add the RELEASES.md entry**

In `RELEASES.md`, insert directly under the `# Release Notes` heading, above the `## v1.7.1` entry:

```markdown
## v1.8.0 (27 July 2026)
* Removes the incomplete, untested OCI/Oracle adapter

```

- [ ] **Step 2: Bump the version constant**

In `ActiveRecord.php`, change:

```php
define('PHP_ACTIVERECORD_VERSION_ID','1.0');
```

to:

```php
define('PHP_ACTIVERECORD_VERSION_ID','1.8.0');
```

(Note: this constant was stale at `1.0` while releases were tracked only in `RELEASES.md`; this aligns it with the release version.)

- [ ] **Step 3: Drop Oracle from README's supported databases**

In `README.md`, under `## Supported Databases ##`, delete the line:

```markdown
- Oracle
```

The list keeps MySQL, SQLite, and PostgreSQL.

- [ ] **Step 4: Update CLAUDE.md**

In `CLAUDE.md`, in the "Adapter support / priorities" list, replace the Oracle bullet:

```markdown
- **Oracle (`oci`)** — **to be removed.** It is incomplete and never used (`phpunit.xml` already excludes `<group>oci</group>` with the note "Zamzar don't use the OCI adapter, and it was never complete anyway"). Removal means deleting `lib/adapters/OciAdapter.php`, its `test/*Oci*` tests, `test/sql/oci*.sql`, oci branches in test helpers (`DatabaseLoader`), the `oci` connection in `test/helpers/config.php`, and the now-dead `oci` exclude in `phpunit.xml`. Dropping it also removes a chunk of untested code that would otherwise drag down the 90% coverage target.
```

with:

```markdown
- **Oracle (`oci`)** — **removed in v1.8.0.** The adapter was incomplete and never used. A residual `oci://` connection string now throws a clear `DatabaseException` from `Connection::load_adapter_class()`. Do not reintroduce it.
```

Also, in the architecture section, change the adapters line:

```markdown
Adapters (`MysqlAdapter`, `PgsqlAdapter`, `SqliteAdapter`; `OciAdapter` slated for removal) supply the DB-specific bits: quoting, `LIMIT` syntax, and schema-introspection queries (`columns()`, `tables()`). Put MySQL-specific behavior in `MysqlAdapter`.
```

to:

```markdown
Adapters (`MysqlAdapter`, `PgsqlAdapter`, `SqliteAdapter`) supply the DB-specific bits: quoting, `LIMIT` syntax, and schema-introspection queries (`columns()`, `tables()`). Put MySQL-specific behavior in `MysqlAdapter`.
```

- [ ] **Step 5: Sanity-check the docs**

Run: `grep -rniE "oracle|oci" README.md CLAUDE.md RELEASES.md ActiveRecord.php`
Expected: only the new `RELEASES.md` v1.8.0 line and the `CLAUDE.md` "removed in v1.8.0 / `oci://`" mentions. No "Supported ... Oracle", no live adapter references.

- [ ] **Step 6: Commit**

```bash
git add RELEASES.md ActiveRecord.php README.md CLAUDE.md
git commit -m "$(printf 'Documents OCI removal and bumps version to 1.8.0\n\nCo-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>')"
```

---

## Self-Review

**Spec coverage:**
- Delete adapter/test/SQL → Task 5. ✓
- Collapse `Model.php` sequence branch + remove `ar_rnum__` → Task 2. ✓
- Explicit `oci://` guard → Task 1. ✓
- Test helper branches (config, DatabaseLoader, AdapterTest) → Task 3. ✓
- Behavioral test guards (Write/Find/Test) → Task 4. ✓
- `phpunit.xml` exclude → Task 5. ✓
- Docs + version (RELEASES, ActiveRecord.php, README, CLAUDE.md) → Task 6. ✓
- "Not touched" invariants (ATTR_ORACLE_NULLS, sequence machinery, RmBldg) → enforced via Global Constraints and the Task 5 Step 3 grep that expects exactly the one `ATTR_ORACLE_NULLS` hit. ✓
- Dependency check (no composer/Docker/CI change) → confirmed in spec; no task needed. ✓

**Placeholder scan:** No TBD/TODO/"handle edge cases"/vague steps — every code step shows exact before/after. ✓

**Type/name consistency:** The exception message string is identical in Task 1 Step 1 (test), Task 1 Step 3 (impl), and Task 6 Step 4 (CLAUDE.md). The retained `Model.php` `else` body matches the pre-existing Postgres path verbatim. ✓

**Ordering keeps the suite green at every commit:** guard (T1) → lib collapse removes last `lib` reference (T2) → helper references (T3) → test references (T4) → only then delete files + exclude (T5) → docs (T6). No commit leaves a dangling `OciAdapter` reference that would fatal. ✓
