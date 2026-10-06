<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\SQLBuilder;
use ActiveRecord\Table;

/*
 * Column names that contain the dynamic-finder separators _and_ / _or_ (GH #53).
 *
 * `swatches` has the columns black, white, black_and_white, black_or_white,
 * Shade_and_Tone (attribute shade_and_tone) and title. Fixture data:
 *   black white black_and_white black_or_white shade_and_tone title
 *   7     9     99              0              0              "A bogus"
 *   5     0     7               0              3              "B match"
 *   7     0     1               7              0              "C pair"
 *   1     1     7               0              0              "E match"
 * Relationships key on venues: 1 "Blender Theater at Gramercy" and
 * 7 "The National", both with tier 5.
 */

class Swatch extends ActiveRecord\Model
{
    public static $table_name = 'swatches';
}

class SwatchAliased extends ActiveRecord\Model
{
    public static $table_name = 'swatches';
    public static $alias_attribute = ['tone_and_shade' => 'black_and_white', 'shade' => 'white', 'white_and_title' => 'title'];
}

// the target's primary key contains _and_ (belongs_to keys on it)
class SwatchByShade extends ActiveRecord\Model
{
    public static $table_name = 'swatches';
    public static $primary_key = 'black_and_white';
}

class SwatchVenue extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $has_many = [['swatches', 'class_name' => 'Swatch', 'foreign_key' => 'black_and_white', 'order' => 'title']];
    public static $has_one = [['swatch', 'class_name' => 'Swatch', 'foreign_key' => 'black_and_white', 'order' => 'title']];
}

class SwatchVenueComposite extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $has_many = [['swatches', 'class_name' => 'Swatch', 'foreign_key' => ['black_and_white', 'black'],
        'primary_key' => ['id', 'tier'], 'order' => 'title']];
}

class SwatchVenueConditions extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $has_many = [['swatches', 'class_name' => 'Swatch', 'foreign_key' => 'black_and_white', 'order' => 'title',
        'conditions' => ['title <> ?', 'E match']]];
}

class SwatchVenueBelongsTo extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $belongs_to = [['swatch', 'class_name' => 'SwatchByShade', 'foreign_key' => 'id']];
}

class AndOrInColumnNamesTest extends DatabaseTest
{
    /**
     * @param list<ActiveRecord\Model>|ActiveRecord\Model|null $records
     * @return list<string>|string|null
     */
    private function titles($records)
    {
        if (is_array($records)) {
            return array_map(fn($record) => $record->title, $records);
        }

        return $records?->title;
    }

    private function last_sql(): string
    {
        return Table::load('Swatch')->last_sql ?? '';
    }

    // --- dynamic finders -----------------------------------------------------

    public function test_find_by_a_column_whose_name_contains_and()
    {
        $this->assert_equals('B match', $this->titles(Swatch::find_by_black_and_white(7, ['order' => 'title'])));
        $this->assert_sql_has('WHERE black_and_white=?', $this->last_sql());
    }

    public function test_find_by_still_splits_when_the_pieces_match_the_argument_count()
    {
        $this->assert_equals('C pair', $this->titles(Swatch::find_by_black_and_white(7, 0)));
        $this->assert_sql_has('WHERE black=? AND white=?', $this->last_sql());
    }

    public function test_find_all_by_and_count_by_a_column_whose_name_contains_and()
    {
        $this->assert_equals(['B match', 'E match'], $this->titles(Swatch::find_all_by_black_and_white(7, ['order' => 'title'])));
        $this->assert_equals(2, Swatch::count_by_black_and_white(7));
        $this->assert_sql_has('WHERE black_and_white=?', $this->last_sql());
    }

    public function test_find_all_by_a_column_whose_name_contains_or()
    {
        $this->assert_equals(['C pair'], $this->titles(Swatch::find_all_by_black_or_white(7)));
        $this->assert_sql_has('WHERE black_or_white=?', $this->last_sql());
    }

    public function test_or_between_two_real_columns_is_still_an_or()
    {
        $this->assert_equals(['A bogus', 'C pair', 'E match'], $this->titles(Swatch::find_all_by_black_or_white(7, 1, ['order' => 'title'])));
        $this->assert_sql_has('WHERE black=? OR white=?', $this->last_sql());
    }

    public function test_a_joined_column_next_to_another_column()
    {
        $this->assert_equals(['B match'], $this->titles(Swatch::find_all_by_black_and_white_and_title(7, 'B match')));
        $this->assert_sql_has('WHERE black_and_white=? AND title=?', $this->last_sql());

        $this->assert_equals(['C pair'], $this->titles(Swatch::find_all_by_black_and_white_or_title(1, 'nope')));
        $this->assert_sql_has('WHERE black_and_white=? OR title=?', $this->last_sql());
    }

    public function test_three_values_keep_the_split_at_every_separator()
    {
        $this->assert_equals(['C pair'], $this->titles(Swatch::find_all_by_black_and_white_and_title(7, 0, 'C pair')));
        $this->assert_sql_has('WHERE black=? AND white=? AND title=?', $this->last_sql());
    }

    public function test_with_no_split_matching_the_argument_count_the_fewest_names_win()
    {
        // black_and_white_and_title is no column: (black_and_white, title) beats (black, white, title)
        $this->assert_null(Swatch::find_by_black_and_white_and_title(7));
        $this->assert_sql_has('WHERE black_and_white=? AND title IS NULL', $this->last_sql());
    }

    public function test_array_and_null_values_on_a_joined_column()
    {
        Swatch::create(['black' => 3, 'white' => 3, 'black_and_white' => null, 'title' => 'D null']);

        $this->assert_equals(['B match', 'C pair', 'E match'], $this->titles(Swatch::find_all_by_black_and_white([1, 7], ['order' => 'title'])));
        $this->assert_sql_has('WHERE black_and_white IN(?,?)', $this->last_sql());

        $this->assert_equals(['D null'], $this->titles(Swatch::find_all_by_black_and_white(null)));
        $this->assert_sql_has('WHERE black_and_white IS NULL', $this->last_sql());

        $this->assert_equals(['C pair', 'D null'], $this->titles(Swatch::find_all_by_black_and_white([1, null], ['order' => 'title'])));
        $this->assert_sql_has('WHERE (black_and_white IN(?) OR black_and_white IS NULL)', $this->last_sql());
    }

    public function test_the_attribute_name_of_a_column_matches()
    {
        // the column is Shade_and_Tone on MySQL/SQLite: its attribute is shade_and_tone
        $this->assert_equals(['B match'], $this->titles(Swatch::find_all_by_shade_and_tone(3)));
        $this->assert_sql_has('WHERE shade_and_tone=?', $this->last_sql());
    }

    public function test_separators_stay_case_insensitive()
    {
        $this->assert_equals(['C pair'], $this->titles(Swatch::find_all_by_black_AND_white(7, 0)));
        $this->assert_sql_has('WHERE black=? AND white=?', $this->last_sql());
    }

    public function test_a_joined_name_matches_a_column_in_exact_case_only()
    {
        // black_AND_white is not black_and_white (names match exactly, like attributes): even
        // with one value the name keeps the split at every separator, as before #53
        $this->assert_equals([], $this->titles(Swatch::find_all_by_black_AND_white(7)));
        $this->assert_sql_has('WHERE black=? AND white IS NULL', $this->last_sql());

        try {
            $this->assert_equals([], $this->titles(Swatch::find_all_by_BLACK_AND_WHITE(7)));
        } catch (ActiveRecord\DatabaseException $e) {
            // Postgres: the quoted "BLACK" is not the column black (as before #53)
            $this->assert_equals('pgsql', $this->conn->protocol);
        }
        $this->assert_sql_has('WHERE BLACK=? AND WHITE IS NULL', $this->last_sql());
    }

    public function test_an_alias_attribute_whose_name_contains_and()
    {
        $this->assert_equals(['B match', 'E match'], $this->titles(SwatchAliased::find_all_by_tone_and_shade(7, ['order' => 'title'])));
        $this->assert_sql_has('WHERE black_and_white=?', Table::load('SwatchAliased')->last_sql);

        // a plain alias still maps inside the split
        $this->assert_equals(['C pair'], $this->titles(SwatchAliased::find_all_by_black_and_shade(7, 0)));
        $this->assert_sql_has('WHERE black=? AND white=?', Table::load('SwatchAliased')->last_sql);
    }

    public function test_between_splits_with_as_many_names_the_longest_names_first_win()
    {
        // (black_and_white, title) and (black, white_and_title) both name two real attributes
        $this->assert_equals(['B match'], $this->titles(SwatchAliased::find_all_by_black_and_white_and_title(7, 'B match')));
        $this->assert_sql_has('WHERE black_and_white=? AND title=?', Table::load('SwatchAliased')->last_sql);
    }

    public function test_find_or_create_by_finds_on_a_column_whose_name_contains_or()
    {
        $this->assert_equals('C pair', $this->titles(Swatch::find_or_create_by_black_or_white(7)));
        $this->assert_equals(4, Swatch::count());
    }

    public function test_find_or_create_by_creates_on_columns_whose_names_contain_and_or_or()
    {
        $created = Swatch::find_or_create_by_black_or_white(42);
        $this->assert_false($created->is_new_record());
        $this->assert_equals(42, $created->black_or_white);
        $this->assert_null($created->black);

        $created = Swatch::find_or_create_by_black_and_white_and_title(42, 'new');
        $this->assert_equals(['black' => null, 'white' => null, 'black_and_white' => 42, 'title' => 'new'], [
            'black' => $created->black, 'white' => $created->white,
            'black_and_white' => $created->black_and_white, 'title' => $created->title,
        ]);
        $reloaded = Swatch::find($created->id);
        $this->assert_equals(42, $reloaded->black_and_white);
        $this->assert_null($reloaded->black);
        $this->assert_equals(6, Swatch::count());
    }

    public function test_find_or_create_by_still_rejects_a_real_or()
    {
        $this->assert_exception_message_contains("Cannot use OR'd attributes", function () {
            Swatch::find_or_create_by_black_or_white(7, 0);
        }, ActiveRecordException::class);
        $this->assert_exception_message_contains("Cannot use OR'd attributes", function () {
            Swatch::find_or_create_by_black_and_white_or_title(7, 'x');
        }, ActiveRecordException::class);
        $this->assert_equals(4, Swatch::count());
    }

    // --- relationship key conditions ---------------------------------------

    public function test_has_many_on_a_foreign_key_whose_name_contains_and()
    {
        $this->assert_equals(['B match', 'E match'], $this->titles(SwatchVenue::find(7)->swatches));
        $this->assert_sql_has('WHERE black_and_white=?', $this->last_sql());
        $this->assert_equals(['C pair'], $this->titles(SwatchVenue::find(1)->swatches));
    }

    public function test_has_many_eager_on_a_foreign_key_whose_name_contains_and()
    {
        $venues = SwatchVenue::find('all', ['include' => 'swatches', 'conditions' => ['id IN(?)', [1, 7]], 'order' => 'id']);
        $this->assert_sql_has('WHERE black_and_white IN(?,?)', $this->last_sql());
        $this->assert_equals(['C pair'], $this->titles($venues[0]->swatches));
        $this->assert_equals(['B match', 'E match'], $this->titles($venues[1]->swatches));
    }

    public function test_has_one_lazy_and_eager_on_a_foreign_key_whose_name_contains_and()
    {
        $this->assert_equals('B match', $this->titles(SwatchVenue::find(7)->swatch));

        $venues = SwatchVenue::find('all', ['include' => 'swatch', 'conditions' => ['id IN(?)', [1]]]);
        $this->assert_equals('C pair', $this->titles($venues[0]->swatch));
    }

    public function test_composite_keys_with_a_column_whose_name_contains_and()
    {
        $this->assert_equals(['B match'], $this->titles(SwatchVenueComposite::find(7)->swatches));
        $this->assert_sql_has('WHERE black_and_white=? AND black=?', $this->last_sql());
    }

    public function test_declared_conditions_merge_with_a_key_whose_name_contains_and()
    {
        $this->assert_equals(['B match'], $this->titles(SwatchVenueConditions::find(7)->swatches));
        $this->assert_sql_has('WHERE (title <> ?) AND black_and_white=?', $this->last_sql());
    }

    public function test_belongs_to_a_primary_key_whose_name_contains_and()
    {
        $this->assert_equals('C pair', $this->titles(SwatchVenueBelongsTo::find(1)->swatch));
        $this->assert_sql_has('WHERE black_and_white=?', Table::load('SwatchByShade')->last_sql);

        $venues = SwatchVenueBelongsTo::find('all', ['include' => 'swatch', 'conditions' => ['id IN(?)', [1]]]);
        $this->assert_sql_has('WHERE black_and_white IN(?)', Table::load('SwatchByShade')->last_sql);
        $this->assert_equals('C pair', $this->titles($venues[0]->swatch));
    }

    // --- SQLBuilder::create_conditions_from_columns() ----------------------

    public function test_create_conditions_from_columns_keeps_each_name_whole()
    {
        $this->assert_equals(
            [$this->conn->quote_name('black_and_white') . '=?', 5],
            SQLBuilder::create_conditions_from_columns($this->conn, ['black_and_white'], [5]),
        );
        $this->assert_equals(
            [$this->conn->quote_name('a_or_b') . '=? OR ' . $this->conn->quote_name('c') . ' IS NULL', 1],
            SQLBuilder::create_conditions_from_columns($this->conn, ['a_or_b', 'c'], [1], null, ['_OR_']),
        );
    }

    public function test_create_conditions_from_columns_renders_like_the_underscored_string()
    {
        $map = ['my_name' => 'name'];
        $cases = [
            ['id', [], [1]],
            ['id', [], [[1, 2]]],
            ['id', [], [[]]],
            ['id', [], [null]],
            ['id', [], [[1, null]]],
            ['id', [], [[null, null]]],
            ['id', [], []],
            ['id_and_name_or_z', ['_and_', '_or_'], [1, 'Tito', 'X']],
            ['id_AND_name_Or_z', ['_AND_', '_Or_'], [1, null]],
            ['id_and_name_or_z', ['_and_', '_or_'], [[1, null], '', [2, 3], 'extra']],
            ['id_and_my_name', ['_and_'], [1, 'Tito']],
        ];

        foreach ($cases as [$name, $separators, $values]) {
            $columns = preg_split('/_and_|_or_/i', $name);
            $mapped = $map;
            $this->assert_equals(
                SQLBuilder::create_conditions_from_underscored_string($this->conn, $name, $values, $mapped),
                SQLBuilder::create_conditions_from_columns($this->conn, $columns, $values, $map, $separators),
            );
        }

        $this->assert_null(SQLBuilder::create_conditions_from_columns($this->conn, []));
        $this->assert_null(SQLBuilder::create_conditions_from_columns($this->conn, [''], [1]));
    }

    public function test_create_conditions_from_columns_accepts_only_underscored_separators()
    {
        foreach (['OR', 'or', ' OR ', 'and', '', '_xor_', '_or_x'] as $separator) {
            $this->assert_exception_message_contains("Invalid separator '$separator'", function () use ($separator) {
                SQLBuilder::create_conditions_from_columns($this->conn, ['a', 'b'], [1, 2], null, [$separator]);
            }, ActiveRecordException::class);
        }
    }

    // --- hash conditions (#64/#35 follow-ups) --------------------------------

    public function test_hash_conditions_on_and_or_column_names()
    {
        // a key whose name contains _and_/_or_ is one column
        $this->assert_equals(['B match', 'E match'], $this->titles(Swatch::all(['conditions' => ['black_and_white' => 7], 'order' => 'title'])));
        $this->assert_equals(['C pair'], $this->titles(Swatch::all(['conditions' => ['black_or_white' => 7, 'shade_and_tone' => 0]])));
        $this->assert_equals('C pair', SwatchByShade::find(1)->title);

        // an alias and its column are both kept (the alias used to replace the column's condition)
        $this->assert_equals([], SwatchAliased::all(['conditions' => ['black_and_white' => 7, 'tone_and_shade' => 1]]));
        $this->assert_equals(['B match', 'E match'], $this->titles(SwatchAliased::all(['conditions' => ['tone_and_shade' => 7, 'black_and_white' => 7], 'order' => 'title'])));
        $this->assert_sql_has('WHERE black_and_white=? AND black_and_white=?', Table::load('SwatchAliased')->last_sql);
    }

    public function test_create_hash_from_columns()
    {
        $map = ['my_name' => 'name'];
        $this->assert_equals(
            ['black_and_white' => 1, 'name' => 'Tito'],
            SQLBuilder::create_hash_from_columns(['black_and_white', 'my_name'], [1, 'Tito'], $map),
        );
    }
}
