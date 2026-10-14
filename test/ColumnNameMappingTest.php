<?php

/*
 * Attribute names that differ from their column names (#92).
 *
 * An attribute is the inflected column name (lowercased, '-' and ' ' => '_'), so
 * odd_columns has the attributes camelcase ("camelCase"), space_name ("space name"),
 * dash_name ("dash-name") and some_day ("some day", a DATE). No adapter knows
 * "space_name", "dash_name" or "some_day": every query uses the real column name. A
 * name differing only by case (camelcase) is mapped on Postgres only, which matches a
 * quoted name's case; MySQL/SQLite match it as is, and their SQL stays as it was.
 * (No column here has both a capital and a space or dash, "some Day": PDO::CASE_LOWER
 * returns its value under 'some day', which names no column nor attribute, so a found
 * record would not read it. That predates #92.)
 */
class ColumnNameMappingTest extends DatabaseTest
{
    private function quote(string $name): string
    {
        return $this->conn->quote_name($name);
    }

    private function case_sensitive(): bool
    {
        return 'pgsql' === $this->conn->protocol;
    }

    /**
     * @param list<ActiveRecord\Model> $records
     * @return list<mixed>
     */
    private function ids(array $records): array
    {
        return array_map(fn($record) => $record->id, $records);
    }

    public function test_create_find_update_reload_delete_with_odd_column_names()
    {
        $r = OddColumn::create(['camelcase' => 'a', 'space_name' => 'b', 'dash_name' => 'c']);
        $r = OddColumn::find($r->id);
        $this->assert_equals(['a', 'b', 'c'], [$r->camelcase, $r->space_name, $r->dash_name]);
        $r->space_name = 'B';
        $r->save();
        $r->reload();
        $this->assert_equals('B', $r->space_name);
        $this->assert_false($r->is_dirty());
        $r->delete();
        $this->assert_equals(0, OddColumn::count());
    }

    public function test_flag_dirty_then_save_inserts_odd_column()
    {
        $r = new OddColumn();
        $r->flag_dirty('camelcase');
        $r->flag_dirty('dash_name');
        $r->save();
        $this->assert_false($r->attribute_is_dirty('camelcase'));
        $this->assert_not_null($r->id);
        $this->assert_equals(1, OddColumn::count());
    }

    public function test_finders_by_attribute_name()
    {
        $r = OddColumn::create(['camelcase' => 'a', 'space_name' => 'b', 'dash_name' => 'c']);
        OddColumn::create(['camelcase' => 'x', 'space_name' => 'y', 'dash_name' => 'z']);

        $this->assert_equals($r->id, OddColumn::find_by_space_name('b')->id);
        $this->assert_equals([$r->id], $this->ids(OddColumn::all(['conditions' => ['dash_name' => 'c']])));
        $this->assert_equals($r->id, OddColumn::first(['conditions' => ['camelcase' => 'a']])->id);
        $this->assert_equals($r->id, OddColumn::find_by_camelcase_and_dash_name('a', 'c')->id);
        $this->assert_equals([$r->id], $this->ids(OddColumn::find_all_by_space_name('b')));
    }

    public function test_alias_of_an_odd_column_attribute()
    {
        $r = OddColumnAliased::create(['nick' => 'b']);
        OddColumnAliased::create(['nick' => 'y']);

        $this->assert_equals('b', OddColumnAliased::find($r->id)->space_name);
        $this->assert_equals($r->id, OddColumnAliased::find_by_nick('b')->id);
        $this->assert_equals([$r->id], $this->ids(OddColumnAliased::all(['conditions' => ['nick' => 'b']])));
    }

    public function test_count_and_exists_by_attribute_name()
    {
        OddColumn::create(['camelcase' => 'a', 'space_name' => 'b', 'dash_name' => 'c']);

        $this->assert_equals(1, OddColumn::count(['conditions' => ['space_name' => 'b']]));
        $this->assert_equals(1, OddColumn::count_by_dash_name('c'));
        $this->assert_true(OddColumn::exists(['conditions' => ['camelcase' => 'a']]));
        $this->assert_false(OddColumn::exists(['conditions' => ['dash_name' => 'nope']]));
    }

    public function test_attribute_and_real_column_keys_are_both_kept()
    {
        OddColumn::create(['space_name' => 'a', 'camelcase' => 'a']);

        // both name the column "space name": two conditions, ANDed, none dropped
        $this->assert_equals([], OddColumn::all(['conditions' => ['space_name' => 'a', 'space name' => 'zzz']]));
        $this->assert_equals(1, count(OddColumn::all(['conditions' => ['space_name' => 'a', 'space name' => 'a']])));

        $sql = OddColumn::table()->options_to_sql(['conditions' => ['space_name' => 'a', 'space name' => 'zzz']]);
        $column = $this->quote('space name');
        $this->assert_equals('SELECT * FROM ' . $this->quote('odd_columns') . " WHERE $column=? AND $column=?", $sql->to_s());
        $this->assert_equals(['a', 'zzz'], $sql->get_where_values());

        // a name differing only by case: mapped on Postgres, matched as is by MySQL/SQLite;
        // either way both conditions stay
        $this->assert_equals([], OddColumn::all(['conditions' => ['camelcase' => 'a', 'camelCase' => 'zzz']]));
        $this->assert_equals(1, count(OddColumn::all(['conditions' => ['camelcase' => 'a', 'camelCase' => 'a']])));
        $first = $this->quote($this->case_sensitive() ? 'camelCase' : 'camelcase');
        $this->assert_string_contains_string(" WHERE $first=? AND " . $this->quote('camelCase') . '=?', OddColumn::table()->last_sql);

        // the WHERE hash of a record update/delete keeps both conditions too (the later
        // one alone would match the row): nothing matches
        $table = OddColumn::table();
        $set = ['dash_name' => 'z'];
        $this->assert_equals(0, $table->update($set, ['space name' => 'zzz', 'space_name' => 'a'])->rowCount());
        $this->assert_equals(0, $table->delete(['space name' => 'zzz', 'space_name' => 'a'])->rowCount());
        $this->assert_null(OddColumn::first()->dash_name);
    }

    public function test_qualified_and_quoted_keys_are_not_remapped()
    {
        $table = Author::table();
        $this->assert_same('authors.some_date', $table->real_column_name('authors.some_date'));
        $this->assert_same('nope', $table->real_column_name('nope'));
        $this->assert_same('some_Date', $table->real_column_name('some_Date'));
        $this->assert_same('"some_Date"', $table->real_column_name('"some_Date"'));
        $this->assert_same('`some_Date`', $table->real_column_name('`some_Date`'));
        $this->assert_same('some_date IS NULL', $table->real_column_name('some_date IS NULL'));

        // a qualified, a pre-quoted and an unknown key reach the quoting of #129 unchanged
        $sql = OddColumn::table()->options_to_sql(['conditions' => [
            'odd_columns.camelcase' => 'a',
            $this->quote('space_name') => 'b',
            'nope' => 'c',
        ]]);
        $this->assert_equals(
            'SELECT * FROM ' . $this->quote('odd_columns') . ' WHERE ' . $this->quote('odd_columns') . '.' . $this->quote('camelcase')
            . '=? AND ' . $this->quote('space_name') . '=? AND ' . $this->quote('nope') . '=?',
            $sql->to_s()
        );
    }

    public function test_case_only_names_are_mapped_where_column_names_are_case_sensitive()
    {
        $case_sensitive = $this->case_sensitive();
        $this->assert_same($case_sensitive, $this->conn->column_names_are_case_sensitive());

        // only the case differs: mapped on Postgres, unchanged on MySQL/SQLite
        $this->assert_same($case_sensitive ? 'some_Date' : 'some_date', Author::table()->real_column_name('some_date'));
        $this->assert_same($case_sensitive ? 'camelCase' : 'camelcase', OddColumn::table()->real_column_name('camelcase'));

        // more than the case differs: mapped on every adapter
        $this->assert_same('space name', OddColumn::table()->real_column_name('space_name'));
        $this->assert_same('dash-name', OddColumn::table()->real_column_name('dash_name'));
        $this->assert_same('some day', OddColumn::table()->real_column_name('some_day'));
    }

    public function test_real_column_keys_maps_string_keys_and_keeps_the_later_value()
    {
        $table = OddColumn::table();

        $this->assert_same(
            ['space name' => 'b', 'dash-name' => 'c', 'id' => 1, 0 => 'x'],
            $table->real_column_keys(['space_name' => 'a', 'dash_name' => 'c', 'space name' => 'b', 'id' => 1, 0 => 'x'])
        );
        $this->assert_same([['space name' => 'a'], ['space name' => 'b', 'dash-name' => 'c']], $table->condition_hashes(['space_name' => 'a', 'space name' => 'b', 'dash_name' => 'c']));
    }

    public function test_datetime_for_a_case_only_date_column_binds_as_before_on_mysql_and_sqlite()
    {
        $date = new DateTime('2026-01-02 15:00:00');

        // some_date is the column some_Date: on MySQL/SQLite it is not mapped, so a DateTime
        // binds in the datetime format as it always has; Postgres maps it to the DATE format
        $expected = $this->case_sensitive() ? $this->conn->date_to_string($date) : $this->conn->datetime_to_string($date);
        $this->assert_same(['some_date' => $expected], Author::table()->format_date_bind_values(['some_date' => $date]));

        // SQLite stores the text it is given; MySQL/MariaDB and Postgres store the date
        $author = Author::create(['name' => 'dated', 'some_date' => $date]);
        $values = [$author->id];
        $stored = Author::connection()->query_and_fetch_one(
            'SELECT ' . $this->quote('some_Date') . ' FROM ' . $this->quote('authors') . ' WHERE ' . $this->quote('author_id') . '=?',
            $values
        );
        $this->assert_same('sqlite' === $this->conn->protocol ? $this->conn->datetime_to_string($date) : '2026-01-02', $stored);
    }

    public function test_date_value_for_odd_date_column_is_bound_as_date()
    {
        $r = OddColumn::create(['some_day' => new DateTime('2026-01-02 15:00:00')]);

        $found = OddColumn::find_by_some_day('2026-01-02');
        $this->assert_not_null($found);
        $this->assert_equals($r->id, $found->id);
        $this->assert_equals('2026-01-02', $found->some_day->format('Y-m-d'));
    }

    public function test_a_save_with_nothing_to_write_throws_as_before()
    {
        // dirty but nothing to write (books has no timestamps to touch): the library's
        // exception, not a TypeError from the mapping
        $book = Book::first();
        $book->flag_dirty('not_a_column');
        $this->assert_exception_message_contains('Updating requires a hash or string.', fn() => $book->save(), ActiveRecord\ActiveRecordException::class);

        $data = null;
        $this->assert_exception_message_contains('Inserting requires a hash.', fn() => Author::table()->insert($data), ActiveRecord\ActiveRecordException::class);
    }

    public function test_validates_uniqueness_of_on_odd_column()
    {
        $this->assert_true(OddColumnUnique::create(['space_name' => 'b'])->is_valid());

        $duplicate = new OddColumnUnique(['space_name' => 'b']);
        $this->assert_false($duplicate->save());
        $this->assert_not_null($duplicate->errors->on('space_name'));

        $this->assert_true((new OddColumnUnique(['space_name' => 'c']))->save());
    }

    public function test_validates_uniqueness_of_with_an_odd_primary_key_column()
    {
        $first = OddColumnUniqueBySpace::create(['space_name' => 'a', 'dash_name' => 'c']);
        $this->assert_true($first->is_valid());

        // another row has dash_name c: the check excludes this record by its pk column
        $duplicate = new OddColumnUniqueBySpace(['space_name' => 'b', 'dash_name' => 'c']);
        $this->assert_false($duplicate->save());
        $this->assert_not_null($duplicate->errors->on('dash_name'));
        $this->assert_string_contains_string($this->quote('space name') . ' != ? AND ' . $this->quote('dash-name') . '=?', OddColumnUniqueBySpace::table()->last_sql);

        // the record itself is not its own duplicate
        $first->camelcase = 'x';
        $this->assert_true($first->save());
        $this->assert_equals(1, OddColumnUniqueBySpace::count());
    }

    public function test_last_default_order_sql_is_unchanged_for_a_plain_pk()
    {
        Author::last();
        $this->assert_string_contains_string('ORDER BY author_id DESC', Author::table()->last_sql);
    }

    public function test_odd_primary_key_column()
    {
        OddColumnBySpace::create(['space_name' => 'a', 'camelcase' => 'x']);
        OddColumnBySpace::create(['space_name' => 'k', 'camelcase' => 'x']);

        $last = OddColumnBySpace::last();
        $this->assert_equals('k', $last->space_name);
        $this->assert_string_contains_string('ORDER BY ' . $this->quote('space name') . ' DESC', OddColumnBySpace::table()->last_sql);

        $r = OddColumnBySpace::find('k');
        $r->camelcase = 'y';
        $r->save();
        $r->reload();
        $this->assert_equals('y', $r->camelcase);
        $this->assert_equals('x', OddColumnBySpace::find('a')->camelcase);

        $r->delete();
        $this->assert_equals(['a'], array_map(fn($record) => $record->space_name, OddColumnBySpace::all()));
    }

    public function test_update_all_set_and_conditions_by_attribute_name()
    {
        OddColumn::create(['space_name' => 'x', 'dash_name' => 'k']);
        OddColumn::create(['space_name' => 'y', 'dash_name' => 'k']);
        $this->assert_equals(1, OddColumn::update_all(['set' => ['camelcase' => 'Z'], 'conditions' => ['space_name' => 'x']]));
        $this->assert_equals(1, OddColumn::count(['conditions' => ['camelcase' => 'Z']]));

        // a 'set' hash is data: two keys naming one column leave the later value
        $this->assert_equals(2, OddColumn::update_all(['set' => ['dash_name' => 'p', 'dash-name' => 'q']]));
        $this->assert_equals(2, OddColumn::count_by_dash_name('q'));
    }

    public function test_delete_all_conditions_by_attribute_name()
    {
        OddColumn::create(['space_name' => 'x', 'dash_name' => 'k']);
        OddColumn::create(['space_name' => 'y', 'dash_name' => 'k']);
        $this->assert_equals(1, OddColumn::delete_all(['conditions' => ['dash_name' => 'k', 'space_name' => 'y']]));
        $this->assert_equals(['x'], array_map(fn($record) => $record->space_name, OddColumn::all()));
    }

    public function test_delete_all_keeps_both_attribute_and_real_column_conditions()
    {
        OddColumn::create(['space_name' => 'x', 'dash_name' => 'k']);

        // both name the column "space name": two conditions, ANDed, none dropped (the
        // later one alone would match the row in the second call)
        $this->assert_equals(0, OddColumn::delete_all(['conditions' => ['space_name' => 'x', 'space name' => 'nope']]));
        $this->assert_equals(0, OddColumn::delete_all(['conditions' => ['space name' => 'nope', 'space_name' => 'x']]));
        $column = $this->quote('space name');
        $this->assert_equals('DELETE FROM ' . $this->quote('odd_columns') . " WHERE $column=? AND $column=?", OddColumn::table()->last_sql);
        $this->assert_equals(1, OddColumn::count());

        $this->assert_equals(1, OddColumn::delete_all(['conditions' => ['space name' => 'x', 'space_name' => 'x']]));
        $this->assert_equals(0, OddColumn::count());
    }

    public function test_update_all_keeps_both_attribute_and_real_column_conditions()
    {
        OddColumn::create(['space_name' => 'x', 'dash_name' => 'k']);

        // the SET value binds first, then both conditions on "space name", ANDed
        $this->assert_equals(0, OddColumn::update_all(['set' => ['dash_name' => 'z'], 'conditions' => ['space name' => 'nope', 'space_name' => 'x']]));
        $column = $this->quote('space name');
        $this->assert_equals(
            'UPDATE ' . $this->quote('odd_columns') . ' SET ' . $this->quote('dash-name') . "=? WHERE $column=? AND $column=?",
            OddColumn::table()->last_sql
        );
        $this->assert_equals('k', OddColumn::first()->dash_name);

        $this->assert_equals(1, OddColumn::update_all(['set' => ['dash_name' => 'z'], 'conditions' => ['space name' => 'x', 'space_name' => 'x']]));
        $this->assert_equals('z', OddColumn::first()->dash_name);
    }

    public function test_update_all_set_date_on_odd_date_column()
    {
        OddColumn::create(['space_name' => 'x']);
        OddColumn::create(['space_name' => 'y']);

        // some_day is the DATE column "some day": the DateTime binds in the date format
        $this->assert_equals(2, OddColumn::update_all(['set' => ['some_day' => new DateTime('2026-03-04 10:00:00')]]));
        $this->assert_not_null(OddColumn::find_by_some_day('2026-03-04'));
        $this->assert_equals(2, OddColumn::count_by_some_day('2026-03-04'));
    }

    public function test_update_all_and_delete_all_sql_is_unchanged_for_plain_columns()
    {
        $this->assert_equals(1, Author::update_all(['set' => ['name' => 'x'], 'conditions' => ['author_id' => 1, 'parent_author_id' => 3]]));
        $this->assert_equals(
            'UPDATE ' . $this->quote('authors') . ' SET ' . $this->quote('name') . '=? WHERE ' . $this->quote('author_id') . '=? AND ' . $this->quote('parent_author_id') . '=?',
            Author::table()->last_sql
        );

        $this->assert_equals(1, Author::delete_all(['conditions' => ['author_id' => 1, 'name' => 'x']]));
        $this->assert_equals(
            'DELETE FROM ' . $this->quote('authors') . ' WHERE ' . $this->quote('author_id') . '=? AND ' . $this->quote('name') . '=?',
            Author::table()->last_sql
        );
    }

    public function test_upsert_by_attribute_names()
    {
        OddColumn::upsert([['id' => 1, 'space_name' => 'u']], 'id');
        OddColumn::upsert([['id' => 1, 'space_name' => 'u2']], 'id');

        $this->assert_equals(['u2'], array_map(fn($record) => $record->space_name, OddColumn::all()));
    }

    public function test_upsert_unique_by_and_update_by_attribute_names()
    {
        // ON CONFLICT on Postgres/SQLite needs a unique index on "dash-name"; dropped below
        $index = $this->quote('odd_columns_dash_name');
        $table = $this->quote('odd_columns');
        $this->conn->query("CREATE UNIQUE INDEX $index ON $table (" . $this->quote('dash-name') . ')');

        try {
            OddColumn::upsert([['dash_name' => 'k', 'space_name' => 'a', 'camelcase' => 'c']], 'dash_name');
            OddColumn::upsert([['dash_name' => 'k', 'space_name' => 'b', 'camelcase' => 'd']], ['dash_name'], ['space_name']);

            // one row: space_name overwritten, camelcase (not in the update list) kept
            $r = OddColumn::first();
            $this->assert_equals([1, 'b', 'c'], [OddColumn::count(), $r->space_name, $r->camelcase]);
        } finally {
            $this->conn->query('mysql' === $this->conn->protocol ? "DROP INDEX $index ON $table" : "DROP INDEX $index");
        }
    }
}
