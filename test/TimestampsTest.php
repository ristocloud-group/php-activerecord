<?php

use ActiveRecord\Timestamps;

/**
 * The timestamp policy (ActiveRecord\Timestamps): the one "now", which columns of a table are its
 * timestamps, the rule that fills them in an INSERT or an UPDATE, and the per-class suspension
 * behind Model::without_timestamps().
 */
class TimestampsTest extends DatabaseTest
{
    private const string FROZEN = '2026-10-21 10:00:00';

    private const string GIVEN = '2001-02-03 04:05:06';

    public function tear_down()
    {
        // the clock is process-wide state: no test leaves it frozen
        Timestamps::freeze(null);

        parent::tear_down();
    }

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
     * Inserts $row into odd_timestamps as Table::insert() sends it, and returns the stored row.
     *
     * @param array<string, mixed> $row
     */
    private function store_odd_timestamp(array $row): OddTimestamp
    {
        $table = OddTimestamp::table();
        $table->insert($row);

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
}
