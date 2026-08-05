<?php

use ActiveRecord as AR;

class UtilsTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    /** @var array */
    private $object_array;

    /** @var array */
    private $array_hash;

    public function set_up()
    {
        $this->object_array = [null,null];
        $this->object_array[0] = new stdClass();
        $this->object_array[0]->a = "0a";
        $this->object_array[0]->b = "0b";
        $this->object_array[1] = new stdClass();
        $this->object_array[1]->a = "1a";
        $this->object_array[1]->b = "1b";

        $this->array_hash = [
            ["a" => "0a", "b" => "0b"],
            ["a" => "1a", "b" => "1b"],
        ];
    }

    public function test_collect_with_array_of_objects_using_closure()
    {
        $this->assert_equals(["0a","1a"], AR\collect($this->object_array, function ($obj) {
            return $obj->a;
        }));
    }

    public function test_collect_with_array_of_objects_using_string()
    {
        $this->assert_equals(["0a","1a"], AR\collect($this->object_array, "a"));
    }

    public function test_collect_with_array_hash_using_closure()
    {
        $this->assert_equals(["0a","1a"], AR\collect($this->array_hash, function ($item) {
            return $item["a"];
        }));
    }

    public function test_collect_with_array_hash_using_string()
    {
        $this->assert_equals(["0a","1a"], AR\collect($this->array_hash, "a"));
    }

    public function test_array_flatten()
    {
        $this->assert_equals([], AR\array_flatten([]));
        $this->assert_equals([1], AR\array_flatten([1]));
        $this->assert_equals([1], AR\array_flatten([[1]]));
        $this->assert_equals([1, 2], AR\array_flatten([[1, 2]]));
        $this->assert_equals([1, 2], AR\array_flatten([[1], 2]));
        $this->assert_equals([1, 2], AR\array_flatten([1, [2]]));
        $this->assert_equals([1, 2, 3], AR\array_flatten([1, [2], 3]));
        $this->assert_equals([1, 2, 3, 4], AR\array_flatten([1, [2, 3], 4]));
        $this->assert_equals([1, 2, 3, 4, 5, 6], AR\array_flatten([1, [2, 3], 4, [5, 6]]));
    }

    public function test_all()
    {
        $this->assert_true(AR\all(null, [null,null]));
        $this->assert_true(AR\all(1, [1,1]));
        $this->assert_false(AR\all(1, [1,'1']));
        $this->assert_false(AR\all(null, ['',null]));
    }

    public function test_classify()
    {
        $bad_class_names = ['ubuntu_rox', 'stop_the_Snake_Case', 'CamelCased', 'camelCased'];
        $good_class_names = ['UbuntuRox', 'StopTheSnakeCase', 'CamelCased', 'CamelCased'];

        $class_names = [];
        foreach ($bad_class_names as $s) {
            $class_names[] = AR\classify($s);
        }

        $this->assert_equals($class_names, $good_class_names);
    }

    public function test_classify_singularize()
    {
        $bad_class_names = ['events', 'stop_the_Snake_Cases', 'angry_boxes', 'Mad_Sheep_herders', 'happy_People'];
        $good_class_names = ['Event', 'StopTheSnakeCase', 'AngryBox', 'MadSheepHerder', 'HappyPerson'];

        $class_names = [];
        foreach ($bad_class_names as $s) {
            $class_names[] = AR\classify($s, true);
        }

        $this->assert_equals($class_names, $good_class_names);
    }

    public function test_pluralize()
    {
        // exercises the regex-transform branches whose preg_replace result is
        // now coalesced to a guaranteed string (never null)
        $this->assert_equals('order_statuses', AR\Utils::pluralize('order_status'));
        $this->assert_equals('os_types', AR\Utils::pluralize('os_type'));
        $this->assert_equals('photos', AR\Utils::pluralize('photo'));
        $this->assert_equals('passes', AR\Utils::pluralize('pass'));
        // uncountable words are returned unchanged
        $this->assert_equals('information', AR\Utils::pluralize('information'));
        // contract: always a string, never null
        $this->assert_true(is_string(AR\Utils::pluralize('photo')));
    }

    public function test_singularize()
    {
        $this->assert_equals('order_status', AR\Utils::singularize('order_status'));
        $this->assert_equals('order_status', AR\Utils::singularize('order_statuses'));
        $this->assert_equals('os_type', AR\Utils::singularize('os_type'));
        $this->assert_equals('os_type', AR\Utils::singularize('os_types'));
        $this->assert_equals('photo', AR\Utils::singularize('photos'));
        $this->assert_equals('pass', AR\Utils::singularize('pass'));
        $this->assert_equals('pass', AR\Utils::singularize('passes'));
    }

    public function test_is_odd_returns_bool()
    {
        // is_odd() is a boolean predicate: it must return a real bool,
        // so strict comparisons (=== true / === false) behave as the name promises.
        $this->assert_same(true, AR\Utils::is_odd(3));
        $this->assert_same(false, AR\Utils::is_odd(4));
        $this->assert_same(true, AR\Utils::is_odd(-1));
        $this->assert_same(false, AR\Utils::is_odd(0));
    }

    public function test_wrap_strings_in_arrays()
    {
        $x = ['1',['2']];
        $this->assert_equals([['1'],['2']], ActiveRecord\wrap_strings_in_arrays($x));

        $x = '1';
        $this->assert_equals([['1']], ActiveRecord\wrap_strings_in_arrays($x));
    }

    public function test_extract_options()
    {
        // the trailing element is an options hash -> returned as-is
        $this->assert_equals(['limit' => 1], AR\Utils::extract_options(['foo', ['limit' => 1]]));
        // no trailing array -> empty options
        $this->assert_equals([], AR\Utils::extract_options(['foo', 'bar']));
        $this->assert_equals([], AR\Utils::extract_options([]));
    }

    public function test_add_condition()
    {
        // seeding an empty condition set with an array condition
        $conditions = [];
        AR\Utils::add_condition($conditions, ['name = ?', 'Bill']);
        $this->assert_equals(['name = ?', 'Bill'], $conditions);

        // appending a string condition ANDs onto the SQL fragment (index 0)
        $conditions = ['name = ?', 'Bill'];
        AR\Utils::add_condition($conditions, 'age > 30');
        $this->assert_equals(['name = ? AND age > 30', 'Bill'], $conditions);

        // a custom conjunction is honored
        $conditions = ['a = 1'];
        AR\Utils::add_condition($conditions, 'b = 2', 'OR');
        $this->assert_equals(['a = 1 OR b = 2'], $conditions);
    }

    public function test_is_a_range()
    {
        $this->assert_true(AR\Utils::is_a('range', [1, 5]));
        $this->assert_false(AR\Utils::is_a('range', [5, 1]));
        $this->assert_false(AR\Utils::is_a('range', 'not-an-array'));
        // unknown type falls through to false
        $this->assert_false(AR\Utils::is_a('bogus', [1, 5]));
    }

    public function test_is_blank()
    {
        $this->assert_true(AR\Utils::is_blank(null));
        $this->assert_true(AR\Utils::is_blank(''));
        $this->assert_false(AR\Utils::is_blank('x'));
        // "0" is a real value, not blank
        $this->assert_false(AR\Utils::is_blank('0'));
    }

    public function test_pluralize_if()
    {
        $this->assert_equals('dog', AR\Utils::pluralize_if(1, 'dog'));
        $this->assert_equals('dogs', AR\Utils::pluralize_if(2, 'dog'));
        $this->assert_equals('dogs', AR\Utils::pluralize_if(0, 'dog'));
    }

    public function test_squeeze()
    {
        $this->assert_equals('a b', AR\Utils::squeeze(' ', 'a   b'));
        $this->assert_equals('abbb', AR\Utils::squeeze('a', 'aaabbb'));
        // nothing to collapse -> unchanged
        $this->assert_equals('abc', AR\Utils::squeeze(' ', 'abc'));
    }
};
