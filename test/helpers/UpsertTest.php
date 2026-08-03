<?php

abstract class UpsertTest extends DatabaseTest
{
    // Cross-adapter upsert tests. Concrete subclasses select the connection.
    // Uses the venues fixture (UNIQUE(name,address), PK Id) and authors
    // fixture (created_at/updated_at, PK author_id).

    public function test_upsert_inserts_new_rows()
    {
        $before = count(Venue::all());

        Venue::upsert([
            ['name' => 'Fresh Hall',   'address' => '1 New St', 'city' => 'Austin'],
            ['name' => 'Second Stage', 'address' => '2 New St', 'city' => 'Dallas'],
        ], ['name', 'address']);

        $this->assert_equals($before + 2, count(Venue::all()));
        $this->assert_equals('Austin', Venue::find_by_name('Fresh Hall')->city);
    }

    public function test_upsert_updates_matching_row_only_for_listed_columns()
    {
        // Fixture row 1: name "Blender Theater at Gramercy", address "127 East 23rd Street".
        $original = Venue::find(1);

        Venue::upsert([
            ['name' => 'Blender Theater at Gramercy', 'address' => '127 East 23rd Street', 'city' => 'Gotham'],
        ], ['name', 'address'], ['city']);

        $reloaded = Venue::find(1);
        $this->assert_equals('Gotham', $reloaded->city);       // updated
        $this->assert_equals($original->state, $reloaded->state); // untouched
        $this->assert_equals(6, count(Venue::all()));           // no new row
    }

    public function test_upsert_mixed_insert_and_update()
    {
        Venue::upsert([
            ['name' => 'Blender Theater at Gramercy', 'address' => '127 East 23rd Street', 'city' => 'Edited'],
            ['name' => 'Brand New Venue',             'address' => '99 New St',            'city' => 'Reno'],
        ], ['name', 'address'], ['city']);

        $this->assert_equals('Edited', Venue::find(1)->city);
        $this->assert_equals('Reno', Venue::find_by_name('Brand New Venue')->city);
        $this->assert_equals(7, count(Venue::all()));
    }

    public function test_upsert_default_update_overwrites_all_provided_columns()
    {
        Venue::upsert([
            ['name' => 'Blender Theater at Gramercy', 'address' => '127 East 23rd Street', 'city' => 'C2', 'state' => 'XX'],
        ], ['name', 'address']); // no $update -> all columns

        $v = Venue::find(1);
        $this->assert_equals('C2', $v->city);
        $this->assert_equals('XX', $v->state);
    }

    public function test_upsert_composite_unique_by()
    {
        // Same name, different address -> a NEW row (composite key), not an update.
        Venue::upsert([
            ['name' => 'Blender Theater at Gramercy', 'address' => 'DIFFERENT ADDRESS', 'city' => 'Nowhere'],
        ], ['name', 'address'], ['city']);

        $this->assert_equals(7, count(Venue::all()));
    }

    public function test_upsert_string_unique_by_is_normalized()
    {
        // Conflict on the primary key via a string arg.
        Author::upsert([
            ['author_id' => 1, 'name' => 'renamed-via-upsert'],
        ], 'author_id', ['name']);

        $this->assert_equals('renamed-via-upsert', Author::find(1)->name);
    }

    public function test_upsert_sets_timestamps_on_insert()
    {
        Author::upsert([
            ['author_id' => 999, 'name' => 'Timestamped'],
        ], 'author_id');

        $a = Author::find(999);
        $this->assert_not_null($a->created_at);
        $this->assert_not_null($a->updated_at);
    }

    public function test_upsert_bumps_updated_at_but_preserves_created_at_on_update()
    {
        Author::upsert([['author_id' => 500, 'name' => 'First']], 'author_id');
        $created = Author::find(500)->created_at->format('Y-m-d H:i:s');

        Author::upsert([['author_id' => 500, 'name' => 'Second']], 'author_id', ['name']);
        $a = Author::find(500);

        $this->assert_equals($created, $a->created_at->format('Y-m-d H:i:s')); // preserved
        $this->assert_not_null($a->updated_at);                                // bumped
        $this->assert_equals('Second', $a->name);
    }

    public function test_upsert_does_not_overwrite_caller_supplied_timestamps()
    {
        Author::upsert([
            ['author_id' => 777, 'name' => 'X', 'created_at' => '2001-01-01 00:00:00'],
        ], 'author_id');

        $this->assert_equals('2001-01-01', Author::find(777)->created_at->format('Y-m-d'));
    }

    public function test_upsert_converts_datetime_values()
    {
        // A DateTime is converted to the adapter's string form by process_data().
        // updated_at is used (not some_Date) because Postgres quotes "some_Date"
        // case-sensitively, whereas updated_at is lowercase on every adapter.
        Author::upsert([
            ['author_id' => 888, 'name' => 'D', 'updated_at' => new DateTime('2010-05-06 07:08:09')],
        ], 'author_id', ['updated_at']);

        $this->assert_equals('2010-05-06 07:08:09', Author::find(888)->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_upsert_single_row_batch()
    {
        Venue::upsert([['name' => 'Solo', 'address' => 'Solo St', 'city' => 'One']], ['name', 'address']);
        $this->assert_equals('One', Venue::find_by_name('Solo')->city);
    }

    public function test_upsert_bypasses_setters_and_validations()
    {
        // Author::set_name() upper-cases via the model layer; upsert must store raw text.
        Author::upsert([['author_id' => 321, 'name' => 'lowercase']], 'author_id');
        $stored = $this->conn->query_and_fetch_one(
            'SELECT name FROM authors WHERE author_id = 321'
        );
        $this->assert_equals('lowercase', $stored);
    }

    public function test_upsert_empty_values_returns_zero()
    {
        $this->assert_equals(0, Venue::upsert([], ['name', 'address']));
    }

    public function test_upsert_empty_unique_by_throws()
    {
        $this->expectException(ActiveRecord\ActiveRecordException::class);
        Venue::upsert([['name' => 'A', 'address' => 'B']], []);
    }

    public function test_upsert_non_uniform_row_keys_throws()
    {
        $this->expectException(ActiveRecord\ActiveRecordException::class);
        Venue::upsert([
            ['name' => 'A', 'address' => 'B', 'city' => 'C'],
            ['name' => 'A', 'address' => 'B'], // missing city
        ], ['name', 'address']);
    }

    public function test_upsert_returns_positive_affected_count()
    {
        $n = Venue::upsert([['name' => 'Counted', 'address' => 'C St', 'city' => 'X']], ['name', 'address']);
        $this->assert_true($n > 0);
    }

    public function test_upsert_emits_correct_dialect_sql()
    {
        Venue::upsert([['name' => 'S', 'address' => 'A', 'city' => 'C']], ['name', 'address'], ['city']);
        $sql = $this->conn->last_query;

        if ($this->conn instanceof ActiveRecord\MysqlAdapter) {
            $this->assert_true(str_contains($sql, 'ON DUPLICATE KEY UPDATE'));
            $this->assert_true(str_contains($sql, 'VALUES('));
        } else {
            $this->assert_true(str_contains($sql, 'ON CONFLICT ('));
            $this->assert_true(str_contains($sql, 'EXCLUDED.'));
        }
    }

    public function test_upsert_chunks_large_batch_and_sums_affected()
    {
        $cls = get_class($this->conn);
        $prev = $cls::$MAX_BIND_PARAMS;
        $cls::$MAX_BIND_PARAMS = 6; // 3 columns -> chunk_size 2

        try {
            $rows = [];
            for ($i = 0; $i < 5; $i++) {
                $rows[] = ['name' => "Chunked $i", 'address' => "Addr $i", 'city' => "City $i"];
            }
            $affected = Venue::upsert($rows, ['name', 'address']);

            $this->assert_equals(5, count(Venue::find('all', ['conditions' => "name LIKE 'Chunked %'"])));
            $this->assert_true($affected >= 5);
        } finally {
            $cls::$MAX_BIND_PARAMS = $prev;
        }
    }

    public function test_upsert_rolls_back_all_chunks_on_failure()
    {
        // authors rows carry author_id,name,created_at,updated_at = 4 columns after
        // timestamp injection, so a limit of 8 gives chunk_size 2 -> 2 chunks for 3 rows.
        $cls = get_class($this->conn);
        $prev = $cls::$MAX_BIND_PARAMS;
        $cls::$MAX_BIND_PARAMS = 8;

        try {
            $original = Author::find(1)->name; // fixture 'Tito'

            // Chunk 1 updates author 1 and inserts author 950; chunk 2 fails because
            // authors.name is NOT NULL on every adapter.
            $threw = false;
            try {
                Author::upsert([
                    ['author_id' => 1,   'name' => 'CHANGED'],
                    ['author_id' => 950, 'name' => 'Ok'],
                    ['author_id' => 951, 'name' => null], // NOT NULL violation -> error
                ], 'author_id', ['name']);
            } catch (ActiveRecord\DatabaseException $e) {
                $threw = true;
            }

            $this->assert_true($threw);
            $this->assert_equals($original, Author::find(1)->name); // chunk 1 rolled back
            // Model::find($pk) throws RecordNotFound rather than returning null;
            // find('first', ...) is the codebase's convention for a nullable lookup.
            $this->assert_null(Author::find('first', ['conditions' => 'author_id = 950'])); // chunk 1 rolled back
        } finally {
            $cls::$MAX_BIND_PARAMS = $prev;
        }
    }

    public function test_upsert_joins_caller_transaction_and_rolls_back()
    {
        $cls = get_class($this->conn);
        $prev = $cls::$MAX_BIND_PARAMS;
        $cls::$MAX_BIND_PARAMS = 6;

        try {
            $this->conn->transaction();
            Venue::upsert([
                ['name' => 'Txn A', 'address' => 'TA', 'city' => 'x'],
                ['name' => 'Txn B', 'address' => 'TB', 'city' => 'y'],
                ['name' => 'Txn C', 'address' => 'TC', 'city' => 'z'],
            ], ['name', 'address']);
            $this->conn->rollback();

            $this->assert_null(Venue::find_by_name('Txn A'));
        } finally {
            $cls::$MAX_BIND_PARAMS = $prev;
        }
    }

    public function test_upsert_throws_when_columns_exceed_limit()
    {
        $cls = get_class($this->conn);
        $prev = $cls::$MAX_BIND_PARAMS;
        $cls::$MAX_BIND_PARAMS = 2; // fewer than the 3 columns

        try {
            $this->expectException(ActiveRecord\ActiveRecordException::class);
            Venue::upsert([['name' => 'A', 'address' => 'B', 'city' => 'C']], ['name', 'address']);
        } finally {
            $cls::$MAX_BIND_PARAMS = $prev;
        }
    }
}
