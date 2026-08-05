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

// belongs_to, a single hash mixing every operator the hash form supports:
//   list  -> `name` IN(?, ?)      scalar -> `parent_author_id` = ?
//   null  -> `some_text` IS ?
// Author 1 (Tito) satisfies all three (name in list, parent_author_id 3,
// some_text NULL); author 2 (George W. Bush) fails on name.
class BookComboHashAuthor extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $belongs_to = [['author', 'conditions' => [
        'name' => ['Tito', 'Bill Clinton'],
        'parent_author_id' => 3,
        'some_text' => null,
    ]]];
}

// belongs_to, same combined hash but the equality clause is deliberately made
// to exclude Tito (his parent_author_id is 3, not 1). Proves the equality term
// is really ANDed in — if it were dropped, Tito would still match on name.
class BookComboHashExcludingAuthor extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $belongs_to = [['author', 'conditions' => [
        'name' => ['Tito', 'Bill Clinton'],
        'parent_author_id' => 1,
    ]]];
}

// has_many, hash mixing a list (IN) and a scalar (equality).
class AuthorComboHashHasManyBooks extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['books', 'foreign_key' => 'author_id', 'conditions' => [
        'name' => ['Another Book', 'Does Not Exist'],
        'special' => 0,
    ]]];
}

// belongs_to, IS NOT NULL — NOT expressible in the hash form (the builder only
// emits IN / = / IS), so it must be written as a positional fragment. Combined
// here with an IN so the test also covers a mixed fragment.
class BookNotNullPositionalAuthor extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $belongs_to = [['author', 'conditions' => [
        'parent_author_id IS NOT NULL AND name IN(?)', ['Tito', 'Bill Clinton'],
    ]]];
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

    // ---- combined hashes: several operators AND-ed together --------------

    public function test_belongs_to_combined_hash_mixes_in_equality_and_is_null()
    {
        $books = BookComboHashAuthor::all(['include' => ['author'], 'order' => 'book_id asc']);

        // Only author 1 (Tito) satisfies name IN + parent_author_id=3 + some_text IS NULL
        $this->assert_equals('Tito', $books[0]->author->name);
        $this->assert_null($books[1]->author);

        // every operator of the hash made it into the WHERE clause, alongside the FK
        $sql = Table::load('Author')->last_sql;
        $this->assert_sql_has('name IN(?,?)', $sql);
        $this->assert_sql_has('parent_author_id=?', $sql);
        $this->assert_sql_has('some_text IS ?', $sql);
        $this->assert_sql_has('author_id IN(?,?)', $sql);
    }

    public function test_belongs_to_combined_hash_works_lazily_too()
    {
        // same combined hash, but through the lazy (create_conditions_from_keys) path
        $this->assert_equals('Tito', BookComboHashAuthor::find(1)->author->name);
        $this->assert_null(BookComboHashAuthor::find(2)->author);

        $sql = Table::load('Author')->last_sql;
        $this->assert_sql_has('name IN(?,?)', $sql);
        $this->assert_sql_has('some_text IS ?', $sql);
    }

    public function test_belongs_to_combined_hash_equality_clause_actually_filters()
    {
        // Tito matches the name IN list but his parent_author_id is 3, not 1, so
        // the equality clause must exclude him. If that clause were dropped, book 1
        // would wrongly load Tito.
        $this->assert_null(BookComboHashExcludingAuthor::find(1)->author);
        $this->assert_null(BookComboHashExcludingAuthor::find(2)->author);
    }

    public function test_has_many_combined_hash_mixes_in_and_equality()
    {
        // George (author 2): book "Another Book" is in the list AND special = 0 -> matches
        $george = AuthorComboHashHasManyBooks::find(2);
        $this->assert_equals(1, count($george->books));
        $this->assert_equals('Another Book', $george->books[0]->name);

        // Tito (author 1): his only book isn't in the name list -> filtered out
        $this->assert_equals(0, count(AuthorComboHashHasManyBooks::find(1)->books));

        $sql = Table::load('Book')->last_sql;
        $this->assert_sql_has('name IN(?,?)', $sql);
        $this->assert_sql_has('special=?', $sql);
    }

    public function test_is_not_null_is_expressed_via_positional_fragment()
    {
        // The hash form has no IS NOT NULL operator (it only emits IN / = / IS),
        // matching the finder's hash semantics; IS NOT NULL is written as a
        // positional fragment. This confirms that combined fragment still works.
        $books = BookNotNullPositionalAuthor::all(['include' => ['author'], 'order' => 'book_id asc']);

        $this->assert_equals('Tito', $books[0]->author->name);
        $this->assert_null($books[1]->author);

        $sql = Table::load('Author')->last_sql;
        $this->assert_sql_has('parent_author_id IS NOT NULL', $sql);
        $this->assert_sql_has('name IN(?,?)', $sql);
    }
}
