<?php

use ActiveRecord\Query;
use ActiveRecord\Table;
use ActiveRecord\Timestamps;

/**
 * The timestamp policy (ActiveRecord\Timestamps): the one "now", which columns of a table are its
 * timestamps, the rule that fills them in an INSERT, an UPDATE or an UPSERT, and the per-class
 * suspension behind Model::without_timestamps().
 */
class TimestampsTest extends DatabaseTest
{
    private const string FROZEN = '2026-10-21 10:00:00';

    private const string GIVEN = '2001-02-03 04:05:06';

    /**
     * The timestamps an authors row holds, as strings (null where the column is).
     *
     * @return array{created_at: ?string, updated_at: ?string}
     */
    private function stamps(int $author_id): array
    {
        $author = Author::find($author_id);

        return [
            'created_at' => $author->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $author->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Inserts $row into odd_timestamps exactly as it is, and returns the stored row. The engine's own
     * timestamps are off: a row that apply_to_insert() got wrong must not be put right on the way in.
     *
     * @param array<string, mixed> $row
     */
    private function store_odd_timestamp(array $row): OddTimestamp
    {
        Query::from_options(OddTimestamp::table(), [])->execute_insert($row, timestamps: false);

        return OddTimestamp::first();
    }

    // -- now() and freeze()

    public function test_now_is_the_current_time_in_the_database_format(): void
    {
        $before = date('Y-m-d H:i:s');
        $now = Timestamps::now();
        $after = date('Y-m-d H:i:s');

        $this->assert_matches_regular_expression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now);
        $this->assert_true($before <= $now && $now <= $after, "$now is not between $before and $after");
    }

    public function test_freeze_fixes_now_until_it_is_unfrozen(): void
    {
        $before = date('Y-m-d H:i:s');

        Timestamps::freeze(self::GIVEN);
        $this->assert_same(self::GIVEN, Timestamps::now());

        Timestamps::freeze(null);
        $this->assert_true($before <= Timestamps::now());
    }

    // The clock is process-wide state. Every DatabaseTest releases it when it ends, so that a test
    // whose assertion fails before it unfreezes the clock cannot leave it frozen for the next ones.
    public function test_the_base_tear_down_releases_a_frozen_clock(): void
    {
        Timestamps::freeze(self::FROZEN);

        $this->tear_down();

        $this->assert_not_same(self::FROZEN, Timestamps::now());
    }

    // -- the suspension behind Model::without_timestamps()

    public function test_without_timestamps_skips_save_timestamps(): void
    {
        $a = Author::without_timestamps(fn() => Author::create(['name' => 'Quiet']));

        $this->assert_null(Author::find($a->author_id)->created_at);
        $this->assert_null(Author::find($a->author_id)->updated_at);
    }

    public function test_without_timestamps_skips_the_updated_at_of_an_update(): void
    {
        Timestamps::freeze(self::FROZEN);
        $author = Author::create(['name' => 'Loud']);

        Timestamps::freeze('2026-10-21 11:00:00');
        Author::without_timestamps(function () use ($author): void {
            $author->name = 'Quiet';
            $author->save();
        });
        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => self::FROZEN], $this->stamps($author->author_id));

        // outside the suspension the same save touches updated_at again
        $author->name = 'Loud again';
        $author->save();
        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => '2026-10-21 11:00:00'], $this->stamps($author->author_id));
    }

    public function test_without_timestamps_returns_the_result_of_the_callback(): void
    {
        $this->assert_same(42, Author::without_timestamps(fn() => 42));
        $this->assert_same(['a' => 1], Author::without_timestamps(fn() => ['a' => 1]));
        $this->assert_null(Author::without_timestamps(fn() => null));
        $this->assert_false(Author::without_timestamps(fn() => false));
    }

    public function test_suspension_is_restored_after_an_exception(): void
    {
        $thrown = new RuntimeException('boom');
        $caught = null;

        try {
            Author::without_timestamps(function () use ($thrown): void {
                throw $thrown;
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        // the callback's own exception reaches the caller, and the class saves timestamps again
        $this->assert_same($thrown, $caught);
        $this->assert_false(Timestamps::suspended(Author::class));
        $this->assert_not_null(Author::find(Author::create(['name' => 'After'])->author_id)->created_at);
    }

    public function test_suspension_is_restored_after_an_error(): void
    {
        try {
            Author::without_timestamps(function (): void {
                throw new Error('not an Exception');
            });
        } catch (Error) {
        }

        $this->assert_false(Timestamps::suspended(Author::class));
    }

    public function test_suspension_is_per_class_and_nests(): void
    {
        Author::without_timestamps(function () {
            Author::without_timestamps(fn() => null);
            $this->assert_true(Timestamps::suspended(Author::class));
            $this->assert_false(Timestamps::suspended(Book::class));
        });

        $this->assert_false(Timestamps::suspended(Author::class));
    }

    public function test_a_nested_call_does_not_end_the_outer_suspension(): void
    {
        [$inner, $outer] = Author::without_timestamps(function (): array {
            $inner = Author::without_timestamps(fn() => Author::create(['name' => 'Inner']));
            $outer = Author::create(['name' => 'Outer']);

            return [$inner->author_id, $outer->author_id];
        });

        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps($inner));
        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps($outer));
    }

    // Two models nested: each call ends only its own suspension
    public function test_nested_calls_on_two_models_restore_one_class_at_a_time(): void
    {
        Author::without_timestamps(function (): void {
            OddTimestamp::without_timestamps(function (): void {
                $this->assert_true(Timestamps::suspended(Author::class));
                $this->assert_true(Timestamps::suspended(OddTimestamp::class));
            });

            // the inner call returned: only the outer one is still running
            $this->assert_true(Timestamps::suspended(Author::class));
            $this->assert_false(Timestamps::suspended(OddTimestamp::class));
        });

        $this->assert_false(Timestamps::suspended(Author::class));
        $this->assert_false(Timestamps::suspended(OddTimestamp::class));
    }

    public function test_nested_calls_on_two_models_save_without_timestamps_while_they_run(): void
    {
        Timestamps::freeze(self::FROZEN);

        [$both, $outer_only] = Author::without_timestamps(function (): array {
            $both = OddTimestamp::without_timestamps(fn() => [Author::create(['name' => 'Both']), OddTimestamp::create(['name' => 'Both'])]);
            $outer_only = [Author::create(['name' => 'Outer']), OddTimestamp::create(['name' => 'Outer'])];

            return [$both, $outer_only];
        });

        // inside the inner call both models save without timestamps
        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps($both[0]->author_id));
        $this->assert_null(OddTimestamp::find($both[1]->id)->updated_at);

        // after it only the outer model does
        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps($outer_only[0]->author_id));
        $this->assert_same(self::FROZEN, OddTimestamp::find($outer_only[1]->id)->updated_at->format('Y-m-d H:i:s'));
    }

    // The inner call throws and the outer one catches it: the inner suspension is over, the outer is not
    public function test_a_throwing_inner_call_on_another_model_leaves_the_outer_suspension_running(): void
    {
        Author::without_timestamps(function (): void {
            try {
                OddTimestamp::without_timestamps(function (): void {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException) {
            }

            $this->assert_true(Timestamps::suspended(Author::class));
            $this->assert_false(Timestamps::suspended(OddTimestamp::class));
        });

        $this->assert_false(Timestamps::suspended(Author::class));
    }

    public function test_the_suspension_of_a_class_leaves_another_model_of_its_table_saving_timestamps(): void
    {
        Timestamps::freeze(self::FROZEN);

        // JoinAuthor and Author are two classes over the authors table
        $other = Author::without_timestamps(fn() => JoinAuthor::create(['name' => 'Other']));

        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => self::FROZEN], $this->stamps($other->author_id));
    }

    public function test_the_suspension_of_a_class_is_that_class_only_not_its_subclasses_nor_its_parent(): void
    {
        Timestamps::freeze(self::FROZEN);
        $stamped = ['created_at' => self::FROZEN, 'updated_at' => self::FROZEN];

        $sub = Author::without_timestamps(fn() => OverridingAuthor::create(['name' => 'Sub']));
        $this->assert_same($stamped, $this->stamps($sub->author_id));

        $parent = OverridingAuthor::without_timestamps(fn() => Author::create(['name' => 'Parent']));
        $this->assert_same($stamped, $this->stamps($parent->author_id));
    }

    // -- set_timestamps() takes its "now" from the policy

    public function test_frozen_now_feeds_set_timestamps(): void
    {
        Timestamps::freeze('2026-10-21 10:00:00');
        $a = Author::create(['name' => 'Frozen']);
        $this->assert_equals('2026-10-21 10:00:00', Author::find($a->author_id)->created_at->format('Y-m-d H:i:s'));
        $this->assert_equals('2026-10-21 10:00:00', Author::find($a->author_id)->updated_at->format('Y-m-d H:i:s'));
        Timestamps::freeze(null);
    }

    public function test_set_timestamps_sets_created_at_for_a_new_record_only(): void
    {
        Timestamps::freeze(self::FROZEN);
        $author = Author::create(['name' => 'First']);

        Timestamps::freeze('2026-10-21 11:00:00');
        $author->name = 'Second';
        $author->save();

        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => '2026-10-21 11:00:00'], $this->stamps($author->author_id));
    }

    // -- columns()

    public function test_columns_names_the_timestamp_columns_of_a_table(): void
    {
        $this->assert_same(['created_at' => 'created_at', 'updated_at' => 'updated_at'], Timestamps::columns(Author::table()));
        $this->assert_same(['created_at' => null, 'updated_at' => null], Timestamps::columns(Book::table()));
    }

    public function test_columns_finds_a_timestamp_by_its_attribute_name(): void
    {
        // the columns "created at" and UPDATED_AT are the attributes created_at and updated_at (#92)
        $this->assert_same(['created_at' => 'created at', 'updated_at' => 'UPDATED_AT'], Timestamps::columns(OddTimestamp::table()));
    }

    public function test_columns_takes_the_first_column_of_an_attribute_name(): void
    {
        // "created at" and created_at are the attribute created_at, and so are "updated-at" and UPDATED_AT:
        // the first of them is the timestamp, as Table::get_column_by_inflected_name() finds a column
        $table = clone Author::table();
        $table->columns = [];

        foreach (['created at', 'created_at', 'updated-at', 'UPDATED_AT'] as $name) {
            $column = new ActiveRecord\Column();
            $column->name = $name;
            $column->inflected_name = ActiveRecord\Inflector::instance()->variablize($name);
            $table->columns[$name] = $column;
        }

        $this->assert_same(['created_at' => 'created at', 'updated_at' => 'updated-at'], Timestamps::columns($table));
    }

    // -- apply_to_insert()

    public function test_apply_to_insert_adds_the_timestamps_the_row_lacks(): void
    {
        Timestamps::freeze(self::FROZEN);

        $row = Timestamps::apply_to_insert(Author::table(), Author::class, ['name' => 'Tito']);

        // created_at before updated_at, after the row's own columns
        $this->assert_same(['name' => 'Tito', 'created_at' => self::FROZEN, 'updated_at' => self::FROZEN], $row);
    }

    public function test_apply_to_insert_keeps_a_timestamp_the_row_has(): void
    {
        Timestamps::freeze(self::FROZEN);

        $this->assert_same(
            ['created_at' => self::GIVEN, 'name' => 'x', 'updated_at' => self::FROZEN],
            Timestamps::apply_to_insert(Author::table(), Author::class, ['created_at' => self::GIVEN, 'name' => 'x'])
        );
        $this->assert_same(
            ['updated_at' => self::GIVEN, 'created_at' => self::FROZEN],
            Timestamps::apply_to_insert(Author::table(), Author::class, ['updated_at' => self::GIVEN])
        );
        // a null the caller gave is a value too
        $this->assert_same(
            ['created_at' => null, 'updated_at' => null],
            Timestamps::apply_to_insert(Author::table(), Author::class, ['created_at' => null, 'updated_at' => null])
        );
    }

    public function test_apply_to_insert_leaves_a_table_without_timestamps_alone(): void
    {
        Timestamps::freeze(self::FROZEN);

        $this->assert_same(['name' => 'x'], Timestamps::apply_to_insert(Book::table(), Book::class, ['name' => 'x']));
    }

    public function test_apply_to_insert_leaves_a_suspended_class_alone(): void
    {
        Timestamps::freeze(self::FROZEN);

        Author::without_timestamps(function (): void {
            $this->assert_same(['name' => 'x'], Timestamps::apply_to_insert(Author::table(), Author::class, ['name' => 'x']));

            // it is the class named that is suspended, not the table
            $this->assert_same(
                ['name' => 'x', 'created_at' => self::FROZEN, 'updated_at' => self::FROZEN],
                Timestamps::apply_to_insert(Author::table(), JoinAuthor::class, ['name' => 'x'])
            );
        });
    }

    public function test_apply_to_insert_reads_a_key_as_the_column_or_its_attribute_name(): void
    {
        Timestamps::freeze(self::FROZEN);
        $table = OddTimestamp::table();

        // "created at" and created_at are one column. Its name is what is added
        foreach (['created at', 'created_at'] as $key) {
            $this->assert_same(
                ['name' => 'x', $key => self::GIVEN, 'UPDATED_AT' => self::FROZEN],
                Timestamps::apply_to_insert($table, OddTimestamp::class, ['name' => 'x', $key => self::GIVEN])
            );
        }

        // so are UPDATED_AT and updated_at, which differ by case only: a database that matches names
        // regardless of case (MySQL, SQLite) names the column by either
        foreach (['UPDATED_AT', 'updated_at'] as $key) {
            $this->assert_same(
                ['name' => 'x', $key => self::GIVEN, 'created at' => self::FROZEN],
                Timestamps::apply_to_insert($table, OddTimestamp::class, ['name' => 'x', $key => self::GIVEN])
            );
        }

        $this->assert_same(
            ['name' => 'x', 'created at' => self::FROZEN, 'UPDATED_AT' => self::FROZEN],
            Timestamps::apply_to_insert($table, OddTimestamp::class, ['name' => 'x'])
        );
    }

    public function test_apply_to_insert_gives_a_row_the_database_takes(): void
    {
        Timestamps::freeze(self::FROZEN);
        $table = OddTimestamp::table();

        $odd = $this->store_odd_timestamp(Timestamps::apply_to_insert($table, OddTimestamp::class, ['name' => 'none']));
        $this->assert_same(self::FROZEN, $odd->created_at->format('Y-m-d H:i:s'));
        $this->assert_same(self::FROZEN, $odd->updated_at->format('Y-m-d H:i:s'));
        $odd->delete();

        // a row that has updated_at (and not UPDATED_AT): the column must not be named twice, which
        // MySQL refuses. Table does not map the attribute to the column where the case alone differs
        $odd = $this->store_odd_timestamp(Timestamps::apply_to_insert($table, OddTimestamp::class, ['name' => 'attribute', 'updated_at' => self::GIVEN]));
        $this->assert_same(self::FROZEN, $odd->created_at->format('Y-m-d H:i:s'));
        $this->assert_same(self::GIVEN, $odd->updated_at->format('Y-m-d H:i:s'));
        $odd->delete();

        $odd = $this->store_odd_timestamp(Timestamps::apply_to_insert($table, OddTimestamp::class, ['name' => 'column', 'created at' => self::GIVEN]));
        $this->assert_same(self::GIVEN, $odd->created_at->format('Y-m-d H:i:s'));
        $this->assert_same(self::FROZEN, $odd->updated_at->format('Y-m-d H:i:s'));
    }

    // -- apply_to_update()

    public function test_apply_to_update_adds_updated_at_only(): void
    {
        Timestamps::freeze(self::FROZEN);

        $set = Timestamps::apply_to_update(Author::table(), Author::class, ['name' => 'Tito']);

        $this->assert_same(['name' => 'Tito', 'updated_at' => self::FROZEN], $set);
    }

    public function test_apply_to_update_keeps_the_updated_at_it_has(): void
    {
        Timestamps::freeze(self::FROZEN);

        $this->assert_same(['updated_at' => self::GIVEN], Timestamps::apply_to_update(Author::table(), Author::class, ['updated_at' => self::GIVEN]));
        $this->assert_same(['updated_at' => null], Timestamps::apply_to_update(Author::table(), Author::class, ['updated_at' => null]));
        // created_at is the caller's to set, and is no reason to leave updated_at alone
        $this->assert_same(
            ['created_at' => self::GIVEN, 'updated_at' => self::FROZEN],
            Timestamps::apply_to_update(Author::table(), Author::class, ['created_at' => self::GIVEN])
        );
    }

    public function test_apply_to_update_leaves_a_table_without_timestamps_alone(): void
    {
        Timestamps::freeze(self::FROZEN);

        $this->assert_same(['name' => 'x'], Timestamps::apply_to_update(Book::table(), Book::class, ['name' => 'x']));
    }

    public function test_apply_to_update_leaves_a_suspended_class_alone(): void
    {
        Timestamps::freeze(self::FROZEN);

        Author::without_timestamps(function (): void {
            $this->assert_same(['name' => 'x'], Timestamps::apply_to_update(Author::table(), Author::class, ['name' => 'x']));
            $this->assert_same(['name' => 'x', 'updated_at' => self::FROZEN], Timestamps::apply_to_update(Author::table(), JoinAuthor::class, ['name' => 'x']));
        });
    }

    public function test_apply_to_update_reads_a_key_as_the_column_or_its_attribute_name(): void
    {
        Timestamps::freeze(self::FROZEN);
        $table = OddTimestamp::table();

        foreach (['UPDATED_AT', 'updated_at'] as $key) {
            $this->assert_same(['name' => 'x', $key => self::GIVEN], Timestamps::apply_to_update($table, OddTimestamp::class, ['name' => 'x', $key => self::GIVEN]));
        }

        $this->assert_same(['name' => 'x', 'UPDATED_AT' => self::FROZEN], Timestamps::apply_to_update($table, OddTimestamp::class, ['name' => 'x']));
    }

    public function test_apply_to_update_gives_a_set_the_database_takes(): void
    {
        $table = OddTimestamp::table();
        $odd = OddTimestamp::create(['name' => 'before']);

        Timestamps::freeze(self::FROZEN);
        $set = Timestamps::apply_to_update($table, OddTimestamp::class, ['name' => 'after']);
        $table->update($set, ['id' => $odd->id]);

        $odd = OddTimestamp::find($odd->id);
        $this->assert_same('after', $odd->name);
        $this->assert_same(self::FROZEN, $odd->updated_at->format('Y-m-d H:i:s'));
    }

    // -- the time of a statement: a statement of several rows reads the clock once, and gives it to each

    public function test_apply_to_insert_takes_the_time_it_is_given(): void
    {
        Timestamps::freeze(self::FROZEN);

        $row = Timestamps::apply_to_insert(Author::table(), Author::class, ['name' => 'x'], self::GIVEN);

        $this->assert_same(['name' => 'x', 'created_at' => self::GIVEN, 'updated_at' => self::GIVEN], $row);
    }

    public function test_apply_to_insert_reads_the_clock_when_it_is_given_no_time(): void
    {
        Timestamps::freeze(self::FROZEN);
        $expected = ['name' => 'x', 'created_at' => self::FROZEN, 'updated_at' => self::FROZEN];

        $this->assert_same($expected, Timestamps::apply_to_insert(Author::table(), Author::class, ['name' => 'x']));
        $this->assert_same($expected, Timestamps::apply_to_insert(Author::table(), Author::class, ['name' => 'x'], null));
    }

    public function test_apply_to_insert_with_a_time_keeps_what_the_row_has_and_the_suspension(): void
    {
        $other = '2030-01-02 03:04:05';

        $this->assert_same(
            ['created_at' => self::FROZEN, 'name' => 'x', 'updated_at' => $other],
            Timestamps::apply_to_insert(Author::table(), Author::class, ['created_at' => self::FROZEN, 'name' => 'x'], $other)
        );
        $this->assert_same(
            ['created_at' => null, 'updated_at' => $other],
            Timestamps::apply_to_insert(Author::table(), Author::class, ['created_at' => null], $other)
        );
        // a table without the columns, and a suspended class, get nothing whatever the time
        $this->assert_same(['name' => 'x'], Timestamps::apply_to_insert(Book::table(), Book::class, ['name' => 'x'], $other));
        Author::without_timestamps(function () use ($other): void {
            $this->assert_same(['name' => 'x'], Timestamps::apply_to_insert(Author::table(), Author::class, ['name' => 'x'], $other));
        });
    }

    // the use of the time: a statement computes it once, and every row of it takes that one,
    // even where the clock has moved on between two of them
    public function test_the_rows_of_a_statement_take_the_one_time_they_are_given(): void
    {
        Timestamps::freeze(self::GIVEN);
        $now = Timestamps::now();
        Timestamps::freeze(self::FROZEN);

        $rows = array_map(
            fn(array $row): array => Timestamps::apply_to_insert(Author::table(), Author::class, $row, $now),
            [['name' => 'a'], ['name' => 'b']]
        );

        $this->assert_same(
            [['name' => 'a', 'created_at' => self::GIVEN, 'updated_at' => self::GIVEN], ['name' => 'b', 'created_at' => self::GIVEN, 'updated_at' => self::GIVEN]],
            $rows
        );
    }

    public function test_apply_to_update_takes_the_time_it_is_given(): void
    {
        Timestamps::freeze(self::FROZEN);

        $this->assert_same(['name' => 'x', 'updated_at' => self::GIVEN], Timestamps::apply_to_update(Author::table(), Author::class, ['name' => 'x'], self::GIVEN));
        $this->assert_same(['name' => 'x', 'updated_at' => self::FROZEN], Timestamps::apply_to_update(Author::table(), Author::class, ['name' => 'x'], null));
    }

    public function test_apply_to_update_with_a_time_keeps_what_the_set_has_and_the_suspension(): void
    {
        $other = '2030-01-02 03:04:05';

        $this->assert_same(['updated_at' => self::GIVEN], Timestamps::apply_to_update(Author::table(), Author::class, ['updated_at' => self::GIVEN], $other));
        $this->assert_same(['name' => 'x'], Timestamps::apply_to_update(Book::table(), Book::class, ['name' => 'x'], $other));
        Author::without_timestamps(function () use ($other): void {
            $this->assert_same(['name' => 'x'], Timestamps::apply_to_update(Author::table(), Author::class, ['name' => 'x'], $other));
        });
    }

    // -- upsert(): the clock, the columns and the suspension of every other write

    public function test_upsert_honors_without_timestamps(): void
    {
        Author::without_timestamps(fn() => Author::upsert([['author_id' => 1, 'name' => 'U']], 'author_id', ['name']));

        $this->assert_null(Author::find(1)->updated_at);
    }

    // The conflict clause is part of the rule. With updated_at in it, the clause would read the column from a row
    // that does not insert it (NULL on MySQL, the column's default on the others) and wipe the stamp the row has
    public function test_a_suspended_upsert_leaves_the_stamps_of_the_row_it_updates(): void
    {
        Timestamps::freeze(self::FROZEN);
        $author = Author::create(['name' => 'Stamped']);
        Timestamps::freeze('2026-10-21 11:00:00');

        Author::without_timestamps(fn() => Author::upsert([['author_id' => $author->author_id, 'name' => 'Quiet']], 'author_id', ['name']));
        $sql = Author::table()->last_sql;

        $this->assert_same('Quiet', Author::find($author->author_id)->name);
        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => self::FROZEN], $this->stamps($author->author_id));
        $this->assert_sql_doesnt_has('created_at', $sql);
        $this->assert_sql_doesnt_has('updated_at', $sql);
    }

    public function test_a_suspended_upsert_stamps_the_rows_it_inserts_with_nothing(): void
    {
        Timestamps::freeze(self::FROZEN);

        Author::without_timestamps(fn() => Author::upsert([['author_id' => 70, 'name' => 'New'], ['author_id' => 71, 'name' => 'Newer']], 'author_id'));

        foreach ([70, 71] as $id) {
            $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps($id));
        }

        // the suspension ended with the call
        Author::upsert([['author_id' => 72, 'name' => 'After']], 'author_id');
        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => self::FROZEN], $this->stamps(72));
    }

    // What the caller gives is the caller's, and so is the conflict clause: updated_at joins it of the library's own
    // accord only where the class is not suspended
    public function test_a_suspended_upsert_keeps_the_timestamps_the_caller_gives_and_adds_none_to_the_conflict_clause(): void
    {
        Timestamps::freeze(self::FROZEN);
        $author = Author::create(['name' => 'Stamped']);
        $rows = [
            ['author_id' => $author->author_id, 'name' => 'Given', 'updated_at' => self::GIVEN],
            ['author_id' => 90, 'name' => 'Fresh', 'updated_at' => self::GIVEN],
        ];

        Author::without_timestamps(fn() => Author::upsert($rows, 'author_id', ['name']));

        // the row that existed was asked for its name and nothing else; the new one has what it came with, and no created_at
        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => self::FROZEN], $this->stamps($author->author_id));
        $this->assert_same(['created_at' => null, 'updated_at' => self::GIVEN], $this->stamps(90));

        // outside the suspension the same call overwrites updated_at too, with the value its row has
        Author::upsert($rows, 'author_id', ['name']);
        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => self::GIVEN], $this->stamps($author->author_id));
    }

    // The default list is every column the rows insert: a suspended class inserts the timestamps the caller gave, and no more
    public function test_a_suspended_upsert_with_the_default_list_overwrites_the_columns_it_inserts(): void
    {
        Timestamps::freeze(self::FROZEN);
        $author = Author::create(['name' => 'Stamped', 'parent_author_id' => 1]);

        Author::without_timestamps(fn() => Author::upsert([['author_id' => $author->author_id, 'name' => 'Quiet', 'updated_at' => self::GIVEN]], 'author_id'));

        $found = Author::find($author->author_id);
        $this->assert_same(['Quiet', 1], [$found->name, $found->parent_author_id]);
        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => self::GIVEN], $this->stamps($author->author_id));
    }

    public function test_the_suspension_of_a_class_leaves_the_upsert_of_another_model_stamping(): void
    {
        Timestamps::freeze(self::FROZEN);
        $stamped = ['created_at' => self::FROZEN, 'updated_at' => self::FROZEN];

        // JoinAuthor is another model of the authors table, OverridingAuthor a subclass of Author
        Author::without_timestamps(function (): void {
            JoinAuthor::upsert([['author_id' => 60, 'name' => 'Other']], 'author_id');
            OverridingAuthor::upsert([['author_id' => 61, 'name' => 'Sub']], 'author_id');
        });
        $this->assert_same($stamped, $this->stamps(60));
        $this->assert_same($stamped, $this->stamps(61));

        // nor does a subclass suspend its parent
        OverridingAuthor::without_timestamps(fn() => Author::upsert([['author_id' => 62, 'name' => 'Parent']], 'author_id'));
        $this->assert_same($stamped, $this->stamps(62));
    }

    public function test_upsert_stamps_with_the_clock_of_the_policy(): void
    {
        Timestamps::freeze(self::FROZEN);

        Author::upsert([['author_id' => 80, 'name' => 'A'], ['author_id' => 81, 'name' => 'B']], 'author_id', ['name']);

        $stamped = ['created_at' => self::FROZEN, 'updated_at' => self::FROZEN];
        $this->assert_same($stamped, $this->stamps(80));
        $this->assert_same($stamped, $this->stamps(81));
    }

    // One time for the whole call. This Table moves the frozen clock a second on at every connection() call, which the
    // stamping of a row makes before it would read the clock for itself: a row that did read it would get a later time than
    // the row before. Chunked, the rows of the later statements must not read a later one either
    public function test_the_rows_of_an_upsert_all_take_the_one_time_of_the_call(): void
    {
        $table = new class (Author::class) extends Table {
            public bool $ticking = false;

            public function connection(): ActiveRecord\Connection
            {
                if ($this->ticking) {
                    Timestamps::freeze(date('Y-m-d H:i:s', strtotime(Timestamps::now()) + 1));
                }

                return parent::connection();
            }
        };
        $class = get_class($this->conn);
        $previous = $class::$MAX_BIND_PARAMS;

        try {
            // five rows in one statement, then in three (two rows of four columns each)
            foreach ([[100, $previous], [200, 8]] as [$first_id, $max_bind_params]) {
                $class::$MAX_BIND_PARAMS = $max_bind_params;
                $ids = range($first_id, $first_id + 4);
                $rows = array_map(fn(int $id): array => ['author_id' => $id, 'name' => "T$id"], $ids);

                Timestamps::freeze(self::FROZEN);
                $table->ticking = true;
                Query::for_table($table)->upsert($rows, 'author_id', ['name']);
                $table->ticking = false;

                // the probe was live: the clock moved on while the rows were stamped
                $this->assert_not_same(self::FROZEN, Timestamps::now());

                $stamps = array_map($this->stamps(...), $ids);
                $this->assert_not_null($stamps[0]['created_at']);
                $this->assert_same($stamps[0]['created_at'], $stamps[0]['updated_at']);
                $this->assert_same(array_fill(0, 5, $stamps[0]), $stamps);
            }
        } finally {
            $class::$MAX_BIND_PARAMS = $previous;
        }
    }

    public function test_upsert_on_a_conflict_sets_updated_at_to_the_clock_and_keeps_created_at(): void
    {
        Timestamps::freeze(self::FROZEN);
        $author = Author::create(['name' => 'Old']);

        // with the default list, then with one of its own
        foreach ([['2026-10-21 11:00:00', null], ['2026-10-21 12:00:00', ['name']]] as [$later, $update]) {
            Timestamps::freeze($later);
            Author::upsert([['author_id' => $author->author_id, 'name' => "At $later"]], 'author_id', $update);

            $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => $later], $this->stamps($author->author_id));
        }
    }

    public function test_upsert_with_an_empty_update_list_stamps_its_rows_and_has_no_conflict_clause(): void
    {
        Timestamps::freeze(self::FROZEN);

        Author::upsert([['author_id' => 85, 'name' => 'Plain']], 'author_id', []);
        $sql = Author::table()->last_sql;

        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => self::FROZEN], $this->stamps(85));
        $this->assert_sql_doesnt_has('ON CONFLICT', $sql);
        $this->assert_sql_doesnt_has('ON DUPLICATE', $sql);
    }

    public function test_upsert_keeps_a_null_timestamp_the_caller_gives(): void
    {
        Timestamps::freeze(self::FROZEN);

        Author::upsert([['author_id' => 86, 'name' => 'Nulls', 'created_at' => null, 'updated_at' => null]], 'author_id', []);

        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps(86));
    }

    // "created at" and UPDATED_AT are the attributes created_at and updated_at (#92), and the policy names them.
    // Upsert used to stamp only the columns that are named created_at and updated_at themselves
    public function test_upsert_stamps_the_timestamp_columns_the_policy_names(): void
    {
        Timestamps::freeze(self::FROZEN);

        OddTimestamp::upsert([['id' => 5, 'name' => 'odd']], 'id');

        $odd = OddTimestamp::find(5);
        $this->assert_same([self::FROZEN, self::FROZEN], [$odd->created_at->format('Y-m-d H:i:s'), $odd->updated_at->format('Y-m-d H:i:s')]);
    }

    public function test_upsert_on_a_conflict_bumps_the_updated_at_column_the_policy_names(): void
    {
        Timestamps::freeze(self::FROZEN);
        OddTimestamp::upsert([['id' => 5, 'name' => 'odd']], 'id');

        // the default list leaves "created at" out and keeps UPDATED_AT, then a list of its own gets UPDATED_AT added
        foreach ([['default', '2026-10-21 11:00:00', null], ['listed', '2026-10-21 12:00:00', ['name']]] as [$name, $later, $update]) {
            Timestamps::freeze($later);
            OddTimestamp::upsert([['id' => 5, 'name' => $name]], 'id', $update);

            $odd = OddTimestamp::find(5);
            $this->assert_same($name, $odd->name);
            $this->assert_same([self::FROZEN, $later], [$odd->created_at->format('Y-m-d H:i:s'), $odd->updated_at->format('Y-m-d H:i:s')]);
        }
    }

    // A table with one of the two columns touches that one only. authors, seen through a Table that knows one of them
    public function test_upsert_on_a_table_with_only_created_at(): void
    {
        Timestamps::freeze(self::FROZEN);
        $table = clone Author::table();
        unset($table->columns['updated_at']);

        // author 1 exists: created_at is not overwritten, and updated_at is none of this table's business
        $table->upsert([['author_id' => 1, 'name' => 'C1'], ['author_id' => 50, 'name' => 'C2']], 'author_id');

        $this->assert_same('C1', Author::find(1)->name);
        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps(1));
        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => null], $this->stamps(50));
        $this->assert_sql_doesnt_has('updated_at', (string) $table->last_sql);
    }

    public function test_upsert_on_a_table_with_only_updated_at(): void
    {
        Timestamps::freeze(self::FROZEN);
        $table = clone Author::table();
        unset($table->columns['created_at']);

        $table->upsert([['author_id' => 1, 'name' => 'U1'], ['author_id' => 51, 'name' => 'U2']], 'author_id', ['name']);

        $this->assert_same('U1', Author::find(1)->name);
        $this->assert_same(['created_at' => null, 'updated_at' => self::FROZEN], $this->stamps(1));
        $this->assert_same(['created_at' => null, 'updated_at' => self::FROZEN], $this->stamps(51));
        $this->assert_sql_doesnt_has('created_at', (string) $table->last_sql);
    }
}
