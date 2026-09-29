<?php

use ActiveRecord\Table;

/*
 * Fixture models for a lazily loaded has_many with COMPOSITE keys plus declared
 * `conditions` (prerequisite for the GH #52 fix).
 *
 * The owners reuse the `authors` table and key the relationship on the pair
 * (author_id, parent_author_id); the children live in `composite_items`
 * (author_ref, parent_ref). Only the declared condition differs between them.
 * Fixture data:
 *   authors:         1 -> (1, 3), 3 -> (3, 1)
 *   composite_items: 1 (1, 3) "keep"    2 (1, 3) "x"
 *                    3 (1, 4) "other"   4 (2, 3) "orphan"
 *                    5 (3, 1) "mirror"  6 (3, 1) "x"
 * Rows 3 and 4 each match only ONE of author 1's key columns and rows 5/6 carry
 * author 1's key values swapped, so a result is only right when BOTH keys are
 * bound, in order, one value per placeholder.
 */

class CompositeItem extends ActiveRecord\Model
{
    public static $table_name = 'composite_items';
}

// control: no declared condition (the shape that already worked)
class CompositeKeyAuthor extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['items', 'class_name' => 'CompositeItem', 'foreign_key' => ['author_ref', 'parent_ref'],
        'primary_key' => ['author_id', 'parent_author_id'], 'order' => 'id asc']];
}

// (a) positional fragment without bind values
class CompositeKeyAuthorPositionalNoBind extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['items', 'class_name' => 'CompositeItem', 'foreign_key' => ['author_ref', 'parent_ref'],
        'primary_key' => ['author_id', 'parent_author_id'], 'order' => 'id asc', 'conditions' => ["title <> 'x'"]]];
}

// (b) positional fragment with a scalar bind value
class CompositeKeyAuthorPositionalBind extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['items', 'class_name' => 'CompositeItem', 'foreign_key' => ['author_ref', 'parent_ref'],
        'primary_key' => ['author_id', 'parent_author_id'], 'order' => 'id asc', 'conditions' => ['title <> ?', 'x']]];
}

// (b) positional fragment with an array bind value (expanded to IN(?,?,?))
class CompositeKeyAuthorPositionalArrayBind extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['items', 'class_name' => 'CompositeItem', 'foreign_key' => ['author_ref', 'parent_ref'],
        'primary_key' => ['author_id', 'parent_author_id'], 'order' => 'id asc', 'conditions' => ['title IN(?)', ['keep', 'other', 'mirror']]]];
}

// (c) fragment that BEGINS with a string literal (the GH #52 shape)
class CompositeKeyAuthorLeadingLiteral extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['items', 'class_name' => 'CompositeItem', 'foreign_key' => ['author_ref', 'parent_ref'],
        'primary_key' => ['author_id', 'parent_author_id'], 'order' => 'id asc', 'conditions' => ["'x' <> title"]]];
}

// (d) hash condition (normalized to positional, GH #13)
class CompositeKeyAuthorHash extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['items', 'class_name' => 'CompositeItem', 'foreign_key' => ['author_ref', 'parent_ref'],
        'primary_key' => ['author_id', 'parent_author_id'], 'order' => 'id asc', 'conditions' => ['title' => 'keep']]];
}

class RelationshipCompositeKeyConditionsTest extends DatabaseTest
{
    /**
     * @param list<ActiveRecord\Model> $items
     * @return list<int>
     */
    private function ids(array $items): array
    {
        return array_map(fn($item) => (int) $item->id, $items);
    }

    public function test_composite_keys_without_conditions_bind_every_key()
    {
        $this->assert_equals([1, 2], $this->ids(CompositeKeyAuthor::find(1)->items));
        $this->assert_equals([5, 6], $this->ids(CompositeKeyAuthor::find(3)->items));
        $this->assert_sql_has('WHERE author_ref=? AND parent_ref=?', Table::load('CompositeItem')->last_sql);
    }

    public function test_composite_keys_with_positional_condition_without_binds()
    {
        $this->assert_equals([1], $this->ids(CompositeKeyAuthorPositionalNoBind::find(1)->items));
        $this->assert_equals([5], $this->ids(CompositeKeyAuthorPositionalNoBind::find(3)->items));
        $this->assert_sql_has("WHERE (title <> 'x') AND author_ref=? AND parent_ref=?", Table::load('CompositeItem')->last_sql);
    }

    public function test_composite_keys_with_positional_condition_with_a_bind()
    {
        $this->assert_equals([1], $this->ids(CompositeKeyAuthorPositionalBind::find(1)->items));
        $this->assert_equals([5], $this->ids(CompositeKeyAuthorPositionalBind::find(3)->items));
        $this->assert_sql_has('WHERE (title <> ?) AND author_ref=? AND parent_ref=?', Table::load('CompositeItem')->last_sql);
    }

    public function test_composite_keys_with_positional_condition_with_an_array_bind()
    {
        $this->assert_equals([1], $this->ids(CompositeKeyAuthorPositionalArrayBind::find(1)->items));
        $this->assert_equals([5], $this->ids(CompositeKeyAuthorPositionalArrayBind::find(3)->items));
        $this->assert_sql_has('WHERE (title IN(?,?,?)) AND author_ref=? AND parent_ref=?', Table::load('CompositeItem')->last_sql);
    }

    public function test_composite_keys_with_condition_starting_with_a_string_literal()
    {
        $this->assert_equals([1], $this->ids(CompositeKeyAuthorLeadingLiteral::find(1)->items));
        $this->assert_equals([5], $this->ids(CompositeKeyAuthorLeadingLiteral::find(3)->items));
        $this->assert_sql_has("WHERE ('x' <> title) AND author_ref=? AND parent_ref=?", Table::load('CompositeItem')->last_sql);
    }

    public function test_composite_keys_with_hash_condition()
    {
        $this->assert_equals([1], $this->ids(CompositeKeyAuthorHash::find(1)->items));
        $this->assert_equals([], $this->ids(CompositeKeyAuthorHash::find(3)->items));
        $this->assert_sql_has('WHERE (title=?) AND author_ref=? AND parent_ref=?', Table::load('CompositeItem')->last_sql);
    }
}
