<?php

use ActiveRecord\RecordNotFound;

// a primary key passed together with conditions is ANDed with them (#149)
class PkWithConditionsTest extends DatabaseTest
{
    private function assert_not_found(string $message, callable $find): void
    {
        try {
            $find();
            $this->fail('RecordNotFound expected');
        } catch (RecordNotFound $e) {
            $this->assert_same($message, $e->getMessage());
        }
    }

    public function test_find_pk_with_conditions_finds_it_only_when_they_match()
    {
        $this->assert_equals(1, Author::find(1, ['conditions' => ['name' => 'Tito']])->id);
        $this->assert_not_found(
            "Couldn't find Author with ID=1",
            fn() => Author::find(1, ['conditions' => ['name' => 'George W. Bush']]),
        );
    }

    public function test_find_pk_with_a_bare_conditions_hash()
    {
        $this->assert_equals(1, Author::find(1, ['name' => 'Tito'])->id);
        $this->assert_not_found("Couldn't find Author with ID=1", fn() => Author::find(1, ['name' => 'George W. Bush']));
    }

    public function test_find_several_pks_with_conditions()
    {
        $authors = Author::find(1, 2, ['conditions' => ['parent_author_id' => [2, 3]], 'order' => 'author_id']);
        $this->assert_equals([1, 2], array_map(fn($a) => $a->id, $authors));

        $this->assert_not_found(
            "Couldn't find all Author with IDs (1,2) (found 1, but was looking for 2)",
            fn() => Author::find(1, 2, ['conditions' => ['name' => 'Tito']]),
        );
        $this->assert_not_found(
            "Couldn't find all Author with IDs (1,2) (found 1, but was looking for 2)",
            fn() => Author::find([1, 2], ['name' => 'Tito']),
        );
    }

    public function test_find_first_and_last_with_a_pk_and_conditions()
    {
        // the pks are ANDed with the conditions, as by find($pk, ...): they were dropped (#169)
        $this->assert_equals(3, Author::find('first', 3, ['conditions' => ['name' => 'Bill Clinton']])->id);
        $this->assert_not_found("Couldn't find Author with ID=3", fn() => Author::find('first', 3, ['conditions' => ['name' => 'Tito']]));
        $this->assert_equals(3, Author::last(3, ['name' => 'Bill Clinton'])->id);
        $this->assert_not_found("Couldn't find Author with ID=3", fn() => Author::find('last', 3, ['name' => 'Tito']));
        $this->assert_equals(3, Author::find('first', [2, 3], ['conditions' => ['name' => 'Bill Clinton']])->id);
        $this->assert_equals(3, Author::first(2, 3, ['name' => 'Bill Clinton'])->id);
        $this->assert_not_found("Couldn't find Author with ID=2,3", fn() => Author::find('first', 2, 3, ['conditions' => ['name' => 'Tito']]));

        // an OR of theirs stays scoped to the pks; conditions rendering no WHERE leave the pk
        $this->assert_equals(2, Author::find('first', [2, 3], ['conditions' => "name = 'Tito' OR name = 'George W. Bush'"])->id);
        $this->assert_equals(2, Author::find('first', 2, ['conditions' => ''])->id);
        $this->assert_equals(2, Author::find('last', 2, ['conditions' => null])->id);
    }

    public function test_find_all_with_pks_and_conditions()
    {
        // as find(<pk>..., $options) (#169)
        $this->assert_equals(3, Author::find('all', 3, ['conditions' => ['name' => 'Bill Clinton']])->id);
        $this->assert_not_found("Couldn't find Author with ID=3", fn() => Author::find('all', 3, ['conditions' => ['name' => 'Tito']]));
        $this->assert_not_found(
            "Couldn't find all Author with IDs (2,3) (found 1, but was looking for 2)",
            fn() => Author::find('all', [2, 3], ['conditions' => ['name' => 'Bill Clinton']]),
        );
        $this->assert_not_found("Couldn't find Author with ID=3", fn() => Author::all(3, ['name' => 'Tito']));

        $authors = Author::all(1, 2, ['conditions' => ['parent_author_id' => [2, 3]], 'order' => 'author_id']);
        $this->assert_equals([1, 2], array_map(fn($a) => $a->id, $authors));
        $this->assert_same([], Author::find('all', [], ['conditions' => ['name' => 'Tito']]));
        $this->assert_equals(2, Author::find('all', 2, ['conditions' => ''])->id);
    }

    public function test_find_first_last_and_all_with_a_pk_map_an_alias_attribute_key()
    {
        $this->assert_equals(2, Venue::find('first', 2, ['conditions' => ['marquee' => 'Warner Theatre']])->id);
        $this->assert_not_found("Couldn't find Venue with ID=1", fn() => Venue::find('last', 1, ['conditions' => ['marquee' => 'Warner Theatre']]));
        $this->assert_equals(2, Venue::find('all', 2, ['marquee' => 'Warner Theatre'])->id);
    }

    public function test_a_hash_before_an_options_hash_is_no_conditions_for_find_first_and_all()
    {
        // as for find() alone, such a hash is still read as a pk value
        foreach ([
            fn() => Author::find('first', ['name' => 'George W. Bush'], ['order' => 'author_id']),
            fn() => Author::find('last', 1, ['name' => 'Tito'], ['order' => 'author_id']),
            fn() => Author::find('all', ['name' => 'George W. Bush'], ['order' => 'author_id']),
        ] as $find) {
            try {
                // @: the RecordNotFound message joins the hash as the string 'Array'
                @$find();
                $this->fail('an exception expected');
            } catch (RecordNotFound|ActiveRecord\DatabaseException $e) {
                // RecordNotFound, or on Postgres the failed query
                $this->assert_instance_of(ActiveRecord\ActiveRecordException::class, $e);
            }
        }
    }

    public function test_find_by_pk_with_conditions()
    {
        $this->assert_equals(1, Author::find_by_pk(1, ['conditions' => ['name' => 'Tito']])->id);
        $this->assert_equals(2, count(Author::find_by_pk([1, 2], ['conditions' => 'name IS NOT NULL'])));
        $this->assert_not_found(
            "Couldn't find Author with ID=1",
            fn() => Author::find_by_pk(1, ['conditions' => ['name' => 'George W. Bush']]),
        );
    }

    public function test_a_string_condition_with_or_stays_scoped_to_the_pk()
    {
        // unparenthesized, "author_id=1 AND name='George W. Bush' OR name='Bill Clinton'" would find author 3
        $this->assert_not_found(
            "Couldn't find Author with ID=1",
            fn() => Author::find(1, ['conditions' => "name = 'George W. Bush' OR name = 'Bill Clinton'"]),
        );
        $this->assert_equals(1, Author::find(1, ['conditions' => "name = 'Bill Clinton' OR name = 'Tito'"])->id);
    }

    public function test_a_positional_condition_with_or_stays_scoped_to_the_pk()
    {
        $this->assert_not_found(
            "Couldn't find Author with ID=1",
            fn() => Author::find(1, ['conditions' => ['name = ? OR name = ?', 'George W. Bush', 'Bill Clinton']]),
        );
        $this->assert_equals(1, Author::find(1, ['conditions' => ['name = ? OR name = ?', 'Bill Clinton', 'Tito']])->id);
        $this->assert_equals(1, Author::find(1, ['conditions' => ['name IN(?)', ['Tito', 'Uncle Bob']]])->id);
        $this->assert_not_found(
            "Couldn't find Author with ID=2",
            fn() => Author::find(2, ['conditions' => ['name IN(?)', ['Tito', 'Uncle Bob']]]),
        );
    }

    public function test_hash_conditions_with_null_and_list_values()
    {
        $this->assert_equals(1, Author::find(1, ['conditions' => ['some_text' => null]])->id);
        $this->assert_equals(1, Author::find(1, ['conditions' => ['parent_author_id' => [3, 4]]])->id);
        $this->assert_not_found(
            "Couldn't find Author with ID=1",
            fn() => Author::find(1, ['conditions' => ['parent_author_id' => [1, 2]]]),
        );
        $this->assert_not_found(
            "Couldn't find Author with ID=1",
            fn() => Author::find(1, ['conditions' => ['parent_author_id' => null]]),
        );
    }

    public function test_an_alias_attribute_key()
    {
        $this->assert_equals(2, Venue::find(2, ['conditions' => ['marquee' => 'Warner Theatre']])->id);
        $this->assert_equals(2, Venue::find_by_pk(2, ['conditions' => ['marquee' => 'Warner Theatre']])->id);
        $this->assert_not_found(
            "Couldn't find Venue with ID=1",
            fn() => Venue::find(1, ['conditions' => ['marquee' => 'Warner Theatre']]),
        );
    }

    public function test_table_qualified_keys_and_joins()
    {
        $this->assert_equals(1, Author::find(1, ['conditions' => ['authors.name' => 'Tito']])->id);

        // with joins both the pk and a bare key name the model's table (books has author_id and name too)
        $author = Author::find(1, ['joins' => ['books'], 'conditions' => ['name' => 'Tito']]);
        $this->assert_equals(1, $author->id);
        $author = Author::find(1, ['joins' => ['books'], 'conditions' => ['books.name' => 'Ancient Art of Main Tanking']]);
        $this->assert_equals(1, $author->id);
        $this->assert_not_found(
            "Couldn't find Author with ID=1",
            fn() => Author::find(1, ['joins' => ['books'], 'conditions' => ['books.name' => 'Another Book']]),
        );
    }

    public function test_a_date_time_condition_value()
    {
        $at = new DateTime('2026-01-02 03:04:05');
        Author::find(1)->update_attribute('created_at', $at);

        $this->assert_equals(1, Author::find(1, ['conditions' => ['created_at' => $at]])->id);
        $this->assert_equals(1, Author::find(1, ['conditions' => ['created_at = ?', new DateTimeImmutable('2026-01-02 03:04:05')]])->id);
        $this->assert_not_found("Couldn't find Author with ID=2", fn() => Author::find(2, ['conditions' => ['created_at' => $at]]));
        $this->assert_same(1, (int) Author::count(1, ['created_at' => $at]));
        $this->assert_false(Author::exists(2, ['conditions' => ['created_at' => $at]]));
    }

    public function test_count_and_exists_with_a_pk_and_conditions()
    {
        $this->assert_same(0, (int) Author::count(1, ['name' => 'George W. Bush']));
        $this->assert_same(1, (int) Author::count(1, ['name' => 'Tito']));
        $this->assert_same(1, (int) Author::count(1, 2, ['conditions' => ['name' => 'Tito']]));
        $this->assert_same(1, (int) Author::count([1, 2], ['conditions' => "name = 'Tito' OR name = 'Bill Clinton'"]));

        $this->assert_false(Author::exists(1, ['conditions' => ['name' => 'George W. Bush']]));
        $this->assert_true(Author::exists(1, ['conditions' => ['name' => 'Tito']]));
        $this->assert_false(Author::exists(2, ['conditions' => ['name = ? OR name = ?', 'Bill Clinton', 'Tito']]));
        $this->assert_true(Author::exists(9999999, 1, ['name' => 'Tito']));
    }

    public function test_conditions_rendering_no_where_leave_the_pk()
    {
        $this->assert_equals(2, Author::find(2, ['conditions' => ''])->id);
        $this->assert_equals(2, Author::find(2, ['conditions' => []])->id);
        $this->assert_equals(2, Author::find(2, ['conditions' => '0'])->id);
        $this->assert_same(1, (int) Author::count(2, ['conditions' => '']));
    }

    public function test_a_zero_string_condition_renders_no_where_and_leaves_the_pk()
    {
        // SQLBuilder renders no WHERE for a falsy '0' fragment (#150): the pk alone, as before
        $this->assert_equals(1, Author::find(1, ['conditions' => '0'])->id);
        $this->assert_equals(1, Author::find(1, ['conditions' => ['0']])->id);
        $this->assert_same(1, (int) Author::count(1, ['conditions' => '0']));
        $this->assert_true(Author::exists(1, ['conditions' => '0']));
    }

    public function test_an_empty_pk_with_conditions_finds_nothing()
    {
        // like find(null) / find('') / find([]) alone: the conditions do not widen an empty pk
        $this->assert_not_found("Couldn't find Author with ID=", fn() => Author::find(null, ['conditions' => ['name' => 'Tito']]));
        $this->assert_not_found("Couldn't find Author with ID=", fn() => Author::find('', ['conditions' => ['name' => 'Tito']]));
        $this->assert_same([], Author::find([], ['conditions' => ['name' => 'Tito']]));
    }

    public function test_a_hash_before_an_options_hash_is_no_conditions_for_find()
    {
        // only a trailing hash of unknown keys is conditions; one before an options hash
        // is still read as a pk value, as it always was
        $this->assert_not_found(
            "Couldn't find Author with ID=George W. Bush",
            fn() => Author::find(['name' => 'George W. Bush'], ['order' => 'author_id']),
        );

        try {
            // @: the RecordNotFound message joins the hash as the string 'Array'
            @Author::find(1, ['name' => 'Tito'], ['order' => 'author_id']);
            $this->fail('an exception expected');
        } catch (RecordNotFound|ActiveRecord\DatabaseException $e) {
            // RecordNotFound, or on Postgres the failed query
            $this->assert_instance_of(ActiveRecord\ActiveRecordException::class, $e);
        }
    }

    public function test_a_hash_before_an_options_hash_is_no_conditions_for_count_and_exists()
    {
        // as always, only the first argument is the pk then
        $this->assert_same(1, (int) Author::count(1, ['name' => 'George W. Bush'], ['readonly' => true]));
        $this->assert_true(Author::exists(1, ['name' => 'George W. Bush'], ['readonly' => true]));
    }

    public function test_count_and_exists_do_not_map_alias_keys()
    {
        // as for conditions-only count()/exists() (#144): an alias is no column there
        $this->expect_exception(ActiveRecord\DatabaseException::class);
        Venue::count(2, ['marquee' => 'Warner Theatre']);
    }

    public function test_the_callers_options_are_not_changed()
    {
        $options = ['conditions' => ['name' => 'Tito'], 'readonly' => true];
        $copy = $options;

        Author::find(1, $options);
        Author::find('first', 1, $options);
        Author::find('all', [1], $options);
        Author::find_by_pk([1], $options);
        Author::count(1, $options);
        Author::exists(1, $options);

        $this->assert_same($copy, $options);
    }

    public function test_a_non_integer_pk_with_conditions_is_not_found()
    {
        // on Postgres the INTEGER pk is not queried with 'abc' (#92), conditions or not
        $ret = Author::transaction(function () {
            $this->assert_not_found("Couldn't find Author with ID=abc", fn() => Author::find('abc', ['conditions' => ['name' => 'Tito']]));
            $this->assert_not_found(
                "Couldn't find all Author with IDs (1,abc) (found 1, but was looking for 2)",
                fn() => Author::find(1, 'abc', ['conditions' => ['name' => 'Tito']]),
            );
            $this->assert_equals('Tito', Author::find(1)->name);

            return true;
        });

        $this->assert_true($ret);
    }
}
