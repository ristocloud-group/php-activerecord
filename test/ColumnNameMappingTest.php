<?php

/*
 * Attribute names that differ from their column names (#92).
 *
 * An attribute is the inflected column name (lowercased, '-' and ' ' => '_'), so
 * odd_columns has the attributes camelcase ("camelCase"), space_name ("space name"),
 * dash_name ("dash-name") and some_day ("some_Day", a DATE). Postgres folds nothing
 * in a quoted name and MySQL/SQLite match any case, but no adapter knows "space_name"
 * or "dash_name": every query must use the real column name.
 */
class ColumnNameMappingTest extends DatabaseTest
{
    private function quote(string $name): string
    {
        return $this->conn->quote_name($name);
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
        OddColumn::create(['camelcase' => 'a']);

        // both name the column "camelCase": two conditions, ANDed, none dropped
        $this->assert_equals([], OddColumn::all(['conditions' => ['camelcase' => 'a', 'camelCase' => 'zzz']]));
        $this->assert_equals(1, count(OddColumn::all(['conditions' => ['camelcase' => 'a', 'camelCase' => 'a']])));

        $sql = OddColumn::table()->options_to_sql(['conditions' => ['camelcase' => 'a', 'camelCase' => 'zzz']]);
        $column = $this->quote('camelCase');
        $this->assert_equals('SELECT * FROM ' . $this->quote('odd_columns') . " WHERE $column=? AND $column=?", $sql->to_s());
        $this->assert_equals(['a', 'zzz'], $sql->get_where_values());

        // the WHERE hash of a record update/delete keeps both conditions too (the later
        // one alone would match the row): nothing matches
        $table = OddColumn::table();
        $set = ['dash_name' => 'z'];
        $this->assert_equals(0, $table->update($set, ['camelCase' => 'zzz', 'camelcase' => 'a'])->rowCount());
        $this->assert_equals(0, $table->delete(['camelCase' => 'zzz', 'camelcase' => 'a'])->rowCount());
        $this->assert_null(OddColumn::first()->dash_name);
    }

    public function test_qualified_and_quoted_keys_are_not_remapped()
    {
        $table = Author::table();
        $this->assert_same('authors.some_date', $table->real_column_name('authors.some_date'));
        $this->assert_same('nope', $table->real_column_name('nope'));
        $this->assert_same('some_Date', $table->real_column_name('some_date'));
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

    public function test_real_column_keys_maps_string_keys_and_keeps_the_later_value()
    {
        $table = OddColumn::table();

        $this->assert_same(
            ['camelCase' => 'b', 'space name' => 'c', 'id' => 1, 0 => 'x'],
            $table->real_column_keys(['camelcase' => 'a', 'space_name' => 'c', 'camelCase' => 'b', 'id' => 1, 0 => 'x'])
        );
        $this->assert_same([['camelCase' => 'a'], ['camelCase' => 'b', 'dash-name' => 'c']], $table->condition_hashes(['camelcase' => 'a', 'camelCase' => 'b', 'dash_name' => 'c']));
    }

    public function test_date_value_for_odd_date_column_is_bound_as_date()
    {
        $r = OddColumn::create(['some_day' => new DateTime('2026-01-02 15:00:00')]);

        $found = OddColumn::find_by_some_day('2026-01-02');
        $this->assert_not_null($found);
        $this->assert_equals($r->id, $found->id);
        $this->assert_equals('2026-01-02', $found->some_day->format('Y-m-d'));
    }

    public function test_validates_uniqueness_of_on_odd_column()
    {
        $this->assert_true(OddColumnUnique::create(['space_name' => 'b'])->is_valid());

        $duplicate = new OddColumnUnique(['space_name' => 'b']);
        $this->assert_false($duplicate->save());
        $this->assert_not_null($duplicate->errors->on('space_name'));

        $this->assert_true((new OddColumnUnique(['space_name' => 'c']))->save());
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
}
