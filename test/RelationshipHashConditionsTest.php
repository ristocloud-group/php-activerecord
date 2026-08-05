<?php

use ActiveRecord\Table;

/*
 * Fixture models for GH #13 — relationship `conditions` in the hash form.
 *
 * They reuse the existing `books`/`authors` tables (and their key-inference),
 * so only the relationship declaration differs between them. Fixture data:
 *   authors: 1=Tito, 2="George W. Bush", 3="Bill Clinton", 4="Uncle Bob"
 *   books:   1 -> author 1 (Tito), 2 -> author 2 (George W. Bush)
 */

// belongs_to, hash condition with a list value -> `name` IN(?, ?)
class BookHashInAuthor extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $belongs_to = [['author', 'conditions' => ['name' => ['Tito', 'Bill Clinton']]]];
}

// belongs_to, hash condition with a scalar value -> `name` = ?
class BookHashScalarAuthor extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $belongs_to = [['author', 'conditions' => ['name' => 'Tito']]];
}

// belongs_to, positional/fragment condition — the form that already worked
class BookPositionalAuthor extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $belongs_to = [['author', 'conditions' => ['name IN(?)', ['Tito', 'Bill Clinton']]]];
}

// has_many, hash condition with a scalar value. The foreign key is stated
// explicitly because has_many infers it from the owning model's class name,
// which for this fixture is not the conventional "author_id".
class AuthorHashHasManyBooks extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['books', 'foreign_key' => 'author_id', 'conditions' => ['name' => 'Another Book']]];
}

class RelationshipHashConditionsTest extends DatabaseTest
{
    // ---- belongs_to, hash with a list value (IN) -------------------------

    public function test_belongs_to_hash_list_condition_is_applied_eagerly()
    {
        $books = BookHashInAuthor::all(['include' => ['author'], 'order' => 'book_id asc']);

        // Book 1's author (Tito) is in the list -> loaded; Book 2's (George W.
        // Bush) is filtered out -> null. Before the fix, both authors loaded.
        $this->assert_equals('Tito', $books[0]->author->name);
        $this->assert_null($books[1]->author);

        // the declared name condition is merged alongside the FK condition
        $sql = Table::load('Author')->last_sql;
        $this->assert_sql_has('name IN(?,?)', $sql);
        $this->assert_sql_has('author_id IN(?,?)', $sql);
    }

    public function test_belongs_to_hash_list_condition_is_applied_lazily()
    {
        // lazy load goes through a different path (create_conditions_from_keys)
        $this->assert_equals('Tito', BookHashInAuthor::find(1)->author->name);
        $this->assert_null(BookHashInAuthor::find(2)->author);

        $this->assert_sql_has('name IN(?,?)', Table::load('Author')->last_sql);
    }

    // ---- belongs_to, hash with a scalar value (equality) -----------------

    public function test_belongs_to_hash_scalar_condition_uses_equality()
    {
        $this->assert_equals('Tito', BookHashScalarAuthor::find(1)->author->name);
        $this->assert_null(BookHashScalarAuthor::find(2)->author);

        // a scalar hash value becomes `name` = ?, not an IN(...) list
        $sql = Table::load('Author')->last_sql;
        $this->assert_sql_has('name=?', $sql);
        $this->assert_sql_doesnt_has('name IN', $sql);
    }

    // ---- regression: the positional form must still work -----------------

    public function test_belongs_to_positional_condition_still_works()
    {
        $books = BookPositionalAuthor::all(['include' => ['author'], 'order' => 'book_id asc']);

        $this->assert_equals('Tito', $books[0]->author->name);
        $this->assert_null($books[1]->author);
    }

    // ---- parity: hash and positional forms behave identically ------------

    public function test_hash_and_positional_forms_are_equivalent()
    {
        $hash = BookHashInAuthor::all(['include' => ['author'], 'order' => 'book_id asc']);
        $positional = BookPositionalAuthor::all(['include' => ['author'], 'order' => 'book_id asc']);

        $this->assert_equals($positional[0]->author->name, $hash[0]->author->name);
        $this->assert_null($hash[1]->author);
        $this->assert_null($positional[1]->author);
    }

    // ---- has_many, hash condition ----------------------------------------

    public function test_has_many_hash_condition_filters_the_collection()
    {
        // George W. Bush (author 2) wrote "Another Book" -> matches the condition
        $george = AuthorHashHasManyBooks::find(2);
        $this->assert_equals(1, count($george->books));
        $this->assert_equals('Another Book', $george->books[0]->name);

        // Tito (author 1) wrote only "Ancient Art of Main Tanking" -> filtered out
        $tito = AuthorHashHasManyBooks::find(1);
        $this->assert_equals(0, count($tito->books));

        $this->assert_sql_has('name=?', Table::load('Book')->last_sql);
    }

    public function test_has_many_hash_condition_is_applied_eagerly()
    {
        $authors = AuthorHashHasManyBooks::all([
            'include' => ['books'],
            'conditions' => ['author_id IN(?)', [1, 2]],
            'order' => 'author_id asc',
        ]);

        // author 1 (Tito) -> no "Another Book"; author 2 (George) -> exactly one
        $this->assert_equals(0, count($authors[0]->books));
        $this->assert_equals(1, count($authors[1]->books));
        $this->assert_equals('Another Book', $authors[1]->books[0]->name);
    }
}
