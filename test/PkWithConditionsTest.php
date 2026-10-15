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
        $this->assert_same(1, (int) Author::count(2, ['conditions' => '']));
    }

    public function test_the_callers_options_are_not_changed()
    {
        $options = ['conditions' => ['name' => 'Tito'], 'readonly' => true];
        $copy = $options;

        Author::find(1, $options);
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
