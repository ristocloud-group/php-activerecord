<?php

/*
 * Fixture models for a has_many / has_one that declares `primary_key` (GH #40):
 * lazy load() keys off the declared column, and so must the eager `include` and
 * the build_* / create_* builders.
 *
 * The owners reuse `authors` and key their books on parent_author_id instead of
 * the table pk (author_id). Fixture data:
 *   authors: 1 (parent 3), 2 (parent 2), 3 (parent 1), 4 (parent 2)
 *   books:   1 (author_id 1), 2 (author_id 2)
 * Keyed by parent_author_id: author 1 -> [], 2 -> [2], 3 -> [1], 4 -> [2];
 * keyed by the table pk (the bug) it is 1 -> [1], 2 -> [2], 3 -> [], 4 -> [].
 */
class ParentKeyedAuthor extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['books', 'class_name' => 'Book', 'foreign_key' => 'author_id',
        'primary_key' => 'parent_author_id', 'order' => 'book_id asc']];
    public static $has_one = [['book', 'class_name' => 'Book', 'foreign_key' => 'author_id',
        'primary_key' => 'parent_author_id']];
}

// declared conditions + order on top of the declared primary_key
class ParentKeyedAuthorWithOptions extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['books', 'class_name' => 'Book', 'foreign_key' => 'author_id',
        'primary_key' => 'parent_author_id', 'conditions' => ['name <> ?', 'x'], 'order' => 'book_id desc']];
}

// a string primary_key value (books.numeric_test VARCHAR) keying an int foreign key (book_reviews.book_id)
class CodeKeyedBook extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $has_many = [['reviews', 'class_name' => 'BookReview', 'foreign_key' => 'book_id',
        'primary_key' => 'numeric_test', 'order' => 'id asc']];
}

// composite keys: (author_ref, parent_ref) <- (author_id, parent_author_id)
class PrimaryKeyCompositeItem extends ActiveRecord\Model
{
    public static $table_name = 'composite_items';
}

class ParentKeyedCompositeAuthor extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['items', 'class_name' => 'PrimaryKeyCompositeItem', 'foreign_key' => ['author_ref', 'parent_ref'],
        'primary_key' => ['author_id', 'parent_author_id'], 'order' => 'id asc']];
}

class RelationshipPrimaryKeyTest extends DatabaseTest
{
    /**
     * @param list<ActiveRecord\Model>|ActiveRecord\Model|null $models
     * @return list<int>|int|null
     */
    private function ids($models, string $pk = 'book_id')
    {
        if (null === $models) {
            return null;
        }
        if ($models instanceof ActiveRecord\Model) {
            return (int) $models->$pk;
        }

        return array_map(fn($model) => (int) $model->$pk, $models);
    }

    /**
     * @param class-string<ActiveRecord\Model> $class
     * @return array<int, mixed> owner pk => ids of the related models
     */
    private function lazy(string $class, string $relationship, string $pk = 'book_id'): array
    {
        $out = [];
        foreach ($class::find('all', ['order' => 'author_id asc']) as $owner) {
            $out[$owner->author_id] = $this->ids($owner->$relationship, $pk);
        }

        return $out;
    }

    /**
     * @param class-string<ActiveRecord\Model> $class
     * @return array<int, mixed> owner pk => ids of the related models
     */
    private function eager(string $class, string $relationship, string $pk = 'book_id'): array
    {
        $out = [];
        foreach ($class::find('all', ['order' => 'author_id asc', 'include' => $relationship]) as $owner) {
            $out[$owner->author_id] = $this->ids($owner->$relationship, $pk);
        }

        return $out;
    }

    public function test_eager_has_many_keys_off_the_declared_primary_key()
    {
        $expected = [1 => [], 2 => [2], 3 => [1], 4 => [2]];

        $this->assert_same($expected, $this->lazy('ParentKeyedAuthor', 'books'));
        $this->assert_same($expected, $this->eager('ParentKeyedAuthor', 'books'));
        $this->assert_sql_has('WHERE author_id IN(?,?,?,?) ORDER BY book_id asc', Book::table()->last_sql);
    }

    public function test_eager_has_one_keys_off_the_declared_primary_key()
    {
        $expected = [1 => null, 2 => 2, 3 => 1, 4 => 2];

        $this->assert_same($expected, $this->lazy('ParentKeyedAuthor', 'book'));
        $this->assert_same($expected, $this->eager('ParentKeyedAuthor', 'book'));
    }

    public function test_eager_load_keeps_declared_conditions_and_order()
    {
        Book::create(['author_id' => 1, 'name' => 'x']);
        $second = Book::create(['author_id' => 1, 'name' => 'second']);
        $expected = [1 => [], 2 => [2], 3 => [(int) $second->book_id, 1], 4 => [2]];

        $this->assert_same($expected, $this->lazy('ParentKeyedAuthorWithOptions', 'books'));
        $this->assert_same($expected, $this->eager('ParentKeyedAuthorWithOptions', 'books'));
        $this->assert_sql_has('WHERE (name <> ?) AND author_id IN(?,?,?,?) ORDER BY book_id desc', Book::table()->last_sql);
    }

    public function test_eager_load_matches_a_string_primary_key_to_an_int_foreign_key()
    {
        CodeKeyedBook::find(1)->update_attribute('numeric_test', '2');
        CodeKeyedBook::find(2)->update_attribute('numeric_test', '1');

        // reviews: 1 (book_id 1), 2 (book_id 1), 3 (book_id 2)
        $this->assert_same([3], $this->ids(CodeKeyedBook::find(1)->reviews, 'id'));
        $this->assert_same([1, 2], $this->ids(CodeKeyedBook::find(2)->reviews, 'id'));

        $books = CodeKeyedBook::find('all', ['order' => 'book_id asc', 'include' => 'reviews']);
        $this->assert_same('2', $books[0]->numeric_test);
        $this->assert_same([3], $this->ids($books[0]->reviews, 'id'));
        $this->assert_same([1, 2], $this->ids($books[1]->reviews, 'id'));
    }

    public function test_eager_load_gives_nothing_to_an_owner_whose_primary_key_is_null()
    {
        // a child with a NULL foreign key must not be matched to an owner with a NULL key
        $orphan = Book::create(['name' => 'no author']);
        $this->assert_null($orphan->author_id);
        $owner = ParentKeyedAuthor::create(['name' => 'no parent']);
        $this->assert_null($owner->parent_author_id);

        $this->assert_empty(ParentKeyedAuthor::find($owner->author_id)->books);
        $this->assert_null(ParentKeyedAuthor::find($owner->author_id)->book);

        $eager = $this->eager('ParentKeyedAuthor', 'books');
        $this->assert_same([], $eager[$owner->author_id]);
        $this->assert_same([3 => [1], 4 => [2]], array_intersect_key($eager, [3 => 0, 4 => 0]));
        $this->assert_null($this->eager('ParentKeyedAuthor', 'book')[$owner->author_id]);
    }

    public function test_eager_load_runs_no_query_when_every_owner_key_is_null()
    {
        Book::create(['name' => 'no author']);
        ParentKeyedAuthor::create(['name' => 'no parent']);

        $owners = ParentKeyedAuthor::find('all', ['conditions' => 'parent_author_id IS NULL', 'include' => ['books', 'book']]);

        $this->assert_equals(1, count($owners));
        $this->assert_same([], $owners[0]->books);
        $this->assert_null($owners[0]->book);
        $this->assert_sql_doesnt_has('books', $this->conn->last_query);
    }

    public function test_build_and_create_inject_the_foreign_key_from_the_declared_primary_key()
    {
        $author = ParentKeyedAuthor::find(1); // parent_author_id 3

        $this->assert_equals(3, $author->build_books(['name' => 'built'])->author_id);
        $this->assert_equals(3, $author->build_book(['name' => 'built one'])->author_id);

        $created = $author->create_books(['name' => 'created']);
        $this->assert_equals(3, $created->author_id);
        $this->assert_contains((int) $created->book_id, $this->ids(ParentKeyedAuthor::find(1)->books));
    }

    public function test_explicit_foreign_key_passed_to_the_builder_wins()
    {
        $author = ParentKeyedAuthor::find(1);

        $this->assert_equals(4, $author->build_books(['author_id' => 4])->author_id);
        $this->assert_equals(4, $author->create_books(['author_id' => 4, 'name' => 'explicit'])->author_id);
    }

    public function test_composite_builders_take_each_foreign_key_from_its_primary_key_column()
    {
        $author = ParentKeyedCompositeAuthor::find(1); // (author_id 1, parent_author_id 3)

        $built = $author->build_items(['title' => 'built']);
        $this->assert_equals([1, 3], [$built->author_ref, $built->parent_ref]);

        $created = $author->create_items(['title' => 'created']);
        $this->assert_equals([1, 3], [$created->author_ref, $created->parent_ref]);
        $this->assert_contains((int) $created->id, $this->ids(ParentKeyedCompositeAuthor::find(1)->items, 'id'));
    }
}
