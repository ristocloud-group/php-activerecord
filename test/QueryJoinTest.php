<?php

use ActiveRecord\AbstractRelationship;
use ActiveRecord\ActiveRecordException;
use ActiveRecord\JoinClause;
use ActiveRecord\Model;
use ActiveRecord\Query;
use ActiveRecord\RelationshipException;
use ActiveRecord\SQLBuilder;
use ActiveRecord\Table;

/**
 * The joins of the query builder (spec §4.2): join(), left_join(), right_join() and cross_join(), whose ON a
 * closure can write on a JoinClause, and joins() and left_joins() by relationship, joins() taking the
 * hand-written JOIN fragments of the legacy 'joins' option too.
 *
 * Fixture data: books 1 Ancient Art of Main Tanking (author 1, secondary author 2) and 2 Another Book (author 2,
 * secondary author 2); authors 1 Tito, 2 George W. Bush, 3 Bill Clinton and 4 Uncle Bob, none with some_text;
 * events 1, 2, 3, 5, 6 and 7 at venues 1, 2, 2, 6, 500 and 9 (there is no venue 500), by hosts 1, 2, 3, 4, 4
 * and 4; venue 2 is the Warner Theatre; four hosts.
 */
class QueryJoinTest extends DatabaseTest
{
    /**
     * $sql with each backquote in the quote character of the connection.
     */
    private function sql(string $sql): string
    {
        return str_replace('`', $this->conn::$QUOTE_CHARACTER, $sql);
    }

    /**
     * The values of an attribute of the models, sorted.
     *
     * @param list<Model> $models
     * @return list<mixed>
     */
    private function values(array $models, string $attribute): array
    {
        $values = array_map(fn(Model $model): mixed => $model->$attribute, $models);
        sort($values);

        return $values;
    }

    public function test_join_forms(): void
    {
        $q = Book::join('authors', 'authors.author_id', '=', 'books.Author_Id');
        $this->assert_sql_has('SELECT books.* FROM books INNER JOIN authors ON authors.author_id = books.Author_Id', $q->to_sql());
        $q = Book::left_join('authors', fn(ActiveRecord\JoinClause $j) => $j->on('authors.author_id', '=', 'books.Author_Id')->where('authors.name', 'Tito'))->where('book_id', '>', 0);
        $this->assert_sql_has('LEFT JOIN authors ON authors.author_id = books.Author_Id AND authors.name = ?', $q->to_sql());
        $this->assert_same(['Tito', 0], $q->get_bindings());
    }

    // The relationship's join of the legacy option, of which left_joins() changes only the keyword: the ON is
    // that of Relationship::construct_inner_join_sql(), which writes the foreign key by the relationship's name
    public function test_relationship_joins_match_the_legacy_option(): void
    {
        $this->assert_same(Book::table()->options_to_sql(['joins' => ['author']])->to_s(), Book::joins('author')->to_sql());
        $this->assert_same(str_replace('INNER JOIN', 'LEFT JOIN', Book::joins('author')->to_sql()), Book::left_joins('author')->to_sql());
    }

    public function test_left_joins_keep_rows_without_a_match(): void
    {
        // event 6 points at venue 500, which does not exist
        $this->assert_same(Event::count(), count(Event::left_joins('venue')->get()));
        $this->assert_same(Event::count() - 1, count(Event::joins('venue')->get()));
    }

    // Review Focus 4: with joins only the model's columns are qualified. A hash is rendered as a legacy
    // conditions hash is (spec §5.2), with no spaces around its = (see QueryWhereTest)
    public function test_hash_where_with_joins_qualifies_only_model_columns(): void
    {
        $q = Book::joins('author')->where(['name' => 'Ancient Art of Main Tanking', 'authors.name' => 'Tito', 'parent_author_id' => 3]);
        $this->assert_sql_has('books.name=? AND authors.name=? AND parent_author_id=?', $q->to_sql());
        $this->assert_same([1], array_map(fn($b) => $b->book_id, $q->get()));
    }

    public function test_undeclared_relationship_throws(): void
    {
        $this->assert_exception_message_contains('nope', fn() => Book::left_joins('nope')->to_sql(), ActiveRecord\RelationshipException::class);
    }

    // -- join, left_join, right_join, cross_join

    // The table of a join is read as from() reads its own (QueryCompiler::table_reference()): "table as alias"
    // gives it an alias. Two columns without an operator are compared with =
    public function test_a_join_names_its_table_as_from_does_and_two_columns_compare_with_equal(): void
    {
        $query = Book::join('authors as a', 'a.author_id', '=', 'books.author_id');

        $this->assert_same($this->sql('SELECT `books`.* FROM `books` INNER JOIN `authors` AS `a` ON `a`.`author_id` = `books`.`author_id`'), $query->to_sql());
        $this->assert_same([1, 2], $this->values($query->get(), 'book_id'));
        $this->assert_same(
            $this->sql('SELECT `books`.* FROM `books` INNER JOIN `authors` ON `authors`.`author_id` = `books`.`author_id`'),
            Book::join('authors', 'authors.author_id', 'books.author_id')->to_sql()
        );
    }

    // RIGHT JOIN keeps the authors without a book (3 and 4), whose rows hold no book; CROSS JOIN pairs every
    // book with every author, without an ON
    public function test_left_right_and_cross_joins(): void
    {
        $on = 'ON `authors`.`author_id` = `books`.`author_id`';
        $left = Book::left_join('authors', 'authors.author_id', '=', 'books.author_id');
        $right = Book::right_join('authors', 'authors.author_id', '=', 'books.author_id');
        $cross = Book::cross_join('authors');

        $this->assert_same($this->sql("SELECT `books`.* FROM `books` LEFT JOIN `authors` $on"), $left->to_sql());
        $this->assert_same($this->sql("SELECT `books`.* FROM `books` RIGHT JOIN `authors` $on"), $right->to_sql());
        $this->assert_same($this->sql('SELECT `books`.* FROM `books` CROSS JOIN `authors`'), $cross->to_sql());

        $this->assert_same([1, 2], $this->values($left->get(), 'book_id'));
        $this->assert_same([null, null, 1, 2], $this->values($right->get(), 'book_id'));
        $this->assert_same(8, count($cross->get()));
    }

    // Spec §5.1: the conditions of a join name their columns by the rule of every clause. A column of the
    // model, by its alias or attribute name too, is its real column with the name the FROM gives the model's
    // rows (its table, or the from() alias); any other name is as written
    public function test_the_conditions_of_a_join_name_their_columns_by_the_rule(): void
    {
        $query = Venue::join('events', fn(JoinClause $join) => $join->on('events.venue_id', '=', 'id')->where('marquee', 'Warner Theatre'));

        $this->assert_same($this->sql('SELECT `venues`.* FROM `venues` INNER JOIN `events` ON `events`.`venue_id` = `venues`.`id` AND `venues`.`name` = ?'), $query->to_sql());
        $this->assert_same(['Warner Theatre'], $query->get_bindings());
        // events 2 and 3 are at the Warner Theatre: its row comes once for each
        $this->assert_same([2, 2], $this->values($query->get(), 'id'));

        $query = Book::from('books', 'b')->join('authors', 'authors.author_id', '=', 'author_id');

        $this->assert_same($this->sql('SELECT `b`.* FROM `books` AS `b` INNER JOIN `authors` ON `authors`.`author_id` = `b`.`author_id`'), $query->to_sql());
        $this->assert_same([1, 2], $this->values($query->get(), 'book_id'));
    }

    // The conditions of a JoinClause: on() and or_on() compare two columns, where() and or_where() a column with
    // a value, and where_null(), where_not_null(), where_in() and where_not_in() are those of the where family.
    // They are joined as written, each by its AND or OR, and their values are bound in order
    public function test_the_conditions_of_a_join_clause(): void
    {
        $query = Book::left_join('authors', fn(JoinClause $join) => $join
            ->on('authors.author_id', '=', 'books.author_id')
            ->or_on('authors.author_id', 'books.secondary_author_id')
            ->where('authors.name', '!=', 'Bill Clinton')
            ->or_where('authors.author_id', 4)
            ->where_null('authors.some_text')
            ->where_not_null('authors.name')
            ->where_in('authors.parent_author_id', [1, 2, 3])
            ->where_not_in('authors.author_id', [3]));

        $this->assert_same(
            $this->sql(
                'SELECT `books`.* FROM `books` LEFT JOIN `authors` ON `authors`.`author_id` = `books`.`author_id` OR `authors`.`author_id` = `books`.`secondary_author_id`'
                . ' AND `authors`.`name` != ? OR `authors`.`author_id` = ? AND `authors`.`some_text` IS NULL AND `authors`.`name` IS NOT NULL'
                . ' AND `authors`.`parent_author_id` IN(?,?,?) AND `authors`.`author_id` NOT IN(?)'
            ),
            $query->to_sql()
        );
        $this->assert_same(['Bill Clinton', 4, 1, 2, 3, 3], $query->get_bindings());

        // book 1 has its author 1 and its secondary author 2, book 2 has author 2 by both keys: one row
        $either = fn(JoinClause $join): JoinClause => $join->on('authors.author_id', '=', 'books.author_id')->or_on('authors.author_id', '=', 'books.secondary_author_id');
        $this->assert_same([1, 1, 2], $this->values(Book::join('authors', $either)->get(), 'book_id'));

        // on() after another condition ANDs: book 2 is the only book whose author is its secondary author
        $query = Book::join('authors', fn(JoinClause $join) => $join->on('authors.author_id', 'books.author_id')->on('authors.author_id', '=', 'books.secondary_author_id'));
        $this->assert_same(
            $this->sql('SELECT `books`.* FROM `books` INNER JOIN `authors` ON `authors`.`author_id` = `books`.`author_id` AND `authors`.`author_id` = `books`.`secondary_author_id`'),
            $query->to_sql()
        );
        $this->assert_same([2], $this->values($query->get(), 'book_id'));

        $by_author = fn(JoinClause $join): JoinClause => $join->on('authors.author_id', '=', 'books.author_id');
        $this->assert_same([1], $this->values(Book::join('authors', fn(JoinClause $join) => $by_author($join)->where_in('authors.name', ['Tito', 'Uncle Bob'])->where_null('authors.some_text'))->get(), 'book_id'));
        $this->assert_same([2], $this->values(Book::join('authors', fn(JoinClause $join) => $by_author($join)->where_not_null('authors.parent_author_id')->where_not_in('authors.author_id', [1]))->get(), 'book_id'));
        $this->assert_same([2], $this->values(Book::join('authors', fn(JoinClause $join) => $by_author($join)->where('authors.name', 'George W. Bush'))->get(), 'book_id'));
    }

    // A JoinClause reads its conditions as the where family reads its own (Conditions): an operator of the LIKE
    // family compares the column as the adapter compares it in a LIKE, which Postgres casts to text
    public function test_a_like_in_a_join_compares_the_column_as_the_adapter_does(): void
    {
        $query = Book::join('authors', fn(JoinClause $join) => $join->on('authors.author_id', '=', 'books.author_id')->where('authors.name', 'like', 'T%'));

        $this->assert_same(
            $this->sql('SELECT `books`.* FROM `books` INNER JOIN `authors` ON `authors`.`author_id` = `books`.`author_id` AND ')
                . $this->conn->like_column($this->sql('`authors`.`name`')) . ' LIKE ?',
            $query->to_sql()
        );
        $this->assert_same(['T%'], $query->get_bindings());
        $this->assert_same([1], $this->values($query->get(), 'book_id'));
    }

    // Spec §5.7: what the where family refuses, a join refuses at the call, naming the method, the model and
    // the input, and the query is left as it was
    public function test_a_join_refuses_at_the_call_what_the_where_family_refuses(): void
    {
        $query = Book::new_query();
        $on = fn(JoinClause $join): JoinClause => $join->on('authors.author_id', '=', 'books.author_id');

        foreach ([
            ["Unknown operator 'nope' in on() on Book", fn(JoinClause $join) => $join->on('authors.author_id', 'nope', 'books.author_id')],
            ["No second column for 'authors.author_id' in or_on() on Book", fn(JoinClause $join) => $on($join)->or_on('authors.author_id')],
            ["Unknown operator 'nope' in where() on Book", fn(JoinClause $join) => $on($join)->where('authors.name', 'nope', 'x')],
            ["Illegal operator and value combination: '>' with null in or_where() on Book", fn(JoinClause $join) => $on($join)->or_where('authors.name', '>', null)],
            ["An array is no value for '=' in where() on Book", fn(JoinClause $join) => $on($join)->where('authors.name', ['x'])],
            ["Empty column name: '' in where_null() on Book", fn(JoinClause $join) => $on($join)->where_null('')],
            ["Empty column name: ' ' in where_not_null() on Book", fn(JoinClause $join) => $on($join)->where_not_null(' ')],
            ['An array is no value in the list given to where_in() on Book, at key 0', fn(JoinClause $join) => $on($join)->where_in('authors.author_id', [[1]])],
            ["An array is no value in the list given to where_not_in() on Book, at key 'a'", fn(JoinClause $join) => $on($join)->where_not_in('authors.author_id', ['a' => [1]])],
        ] as [$message, $closure]) {
            $this->assert_exception_message_contains($message, fn() => $query->join('authors', $closure), ActiveRecordException::class);
        }

        foreach ([
            ["Unknown operator 'nope' in join() on Book", fn() => $query->join('authors', 'authors.author_id', 'nope', 'books.author_id')],
            ["No second column for 'authors.author_id' in left_join() on Book", fn() => $query->left_join('authors', 'authors.author_id')],
            ["Empty column name: '' in right_join() on Book", fn() => $query->right_join('authors', '', '=', 'books.author_id')],
            ["Empty table name: '' in join() on Book", fn() => $query->join('', 'authors.author_id', '=', 'books.author_id')],
            ["Empty alias: 'authors as ' in left_join() on Book", fn() => $query->left_join('authors as ', 'authors.author_id', '=', 'books.author_id')],
            ["Empty table name: ' as a' in cross_join() on Book", fn() => $query->cross_join(' as a')],
        ] as [$message, $call]) {
            $this->assert_exception_message_contains($message, $call, ActiveRecordException::class);
        }

        $this->assert_same($this->sql('SELECT * FROM `books`'), $query->to_sql());
    }

    // A closure that adds no condition would join every row of the table to every row (MySQL, MariaDB and
    // SQLite take an INNER JOIN without ON as a CROSS JOIN): refused at the call, cross_join() says so
    public function test_a_join_closure_that_adds_no_condition_is_refused(): void
    {
        $query = Book::new_query();

        $this->assert_exception_message_contains(
            "The closure given to join() on Book adds no condition to the join of 'authors': cross_join() joins without one",
            fn() => $query->join('authors', fn(JoinClause $join) => $join),
            ActiveRecordException::class
        );
        $this->assert_same($this->sql('SELECT * FROM `books`'), $query->to_sql());
    }

    // The conditions of a closure are taken when it returns: a later change to its JoinClause changes nothing
    public function test_the_conditions_of_a_join_closure_are_taken_when_it_returns(): void
    {
        $kept = null;
        $query = Book::join('authors', function (JoinClause $join) use (&$kept): void {
            $kept = $join->on('authors.author_id', '=', 'books.author_id');
        });
        $sql = $query->to_sql();

        $kept->where('authors.name', 'Tito');

        $this->assert_same($sql, $query->to_sql());
        $this->assert_same([], $query->get_bindings());
    }

    // A JoinClause reads its conditions for the model of a query: the one a closure is given. Made by hand, it
    // holds its type and table, and takes no condition
    public function test_a_join_clause_made_by_hand_takes_no_condition(): void
    {
        $join = new JoinClause('LEFT', 'authors as a');

        $this->assert_same('LEFT', $join->type);
        $this->assert_same('authors as a', $join->table);
        $this->assert_exception_message_contains(
            'on() needs the JoinClause of a query: the closure given to join(), left_join() or right_join() gets one',
            fn() => $join->on('a.author_id', '=', 'books.author_id'),
            ActiveRecordException::class
        );
    }

    // -- the values of the joins

    // Spec §3.2: the values of a SELECT come in the order of its clauses, the joins' after those of the select
    // and of the FROM and before those of the WHERE, whatever the order of the calls; and they are the values
    // get() sends, and exists() but for the select's (spec §5.3: it selects 1)
    public function test_the_values_of_the_joins_come_between_those_of_the_from_and_of_the_where(): void
    {
        $query = Book::new_query()
            ->where('book_id', '>', 1)
            ->join('authors', fn(JoinClause $join) => $join->on('authors.author_id', '=', 'books.author_id')->where('authors.author_id', '<', 100))
            ->from_raw('(SELECT * FROM books WHERE book_id > ?) books', [0])
            ->select('book_id')
            ->select_raw('book_id * ? AS scaled', [10]);
        $books = [];

        $statements = GoldenStatements::capture(function () use ($query, &$books): void {
            $books = $query->get();
        });

        $this->assert_same([10, 0, 100, 1], $query->get_bindings());
        $this->assert_same([['sql' => $query->to_sql(), 'values' => $query->get_bindings()]], $statements);
        $this->assert_same([20], array_map(fn(Book $book): int => (int) $book->scaled, $books));

        $exists = null;
        $statements = GoldenStatements::capture(function () use ($query, &$exists): void {
            $exists = $query->exists();
        });

        $this->assert_true($exists);
        $this->assert_same([[0, 100, 1]], array_column($statements, 'values'));

        $sql = new SQLBuilder($this->conn, 'authors');
        $sql->where('name = ?', 'where')->join_values(['join'])->from_values(['from'])->select_values(['select'])->select('? AS a');

        $this->assert_same(['select', 'from', 'join', 'where'], $sql->select_bind_values());
        $this->assert_same(['where'], $sql->get_where_values());
    }

    // -- joins and left_joins by relationship

    // joins() takes what the legacy 'joins' option takes, relationships and hand-written JOIN fragments, and
    // sends the same SQL; left_joins() joins the same relationships with LEFT JOIN
    public function test_joins_take_what_the_legacy_option_takes(): void
    {
        $fragment = 'LEFT JOIN authors a ON(books.secondary_author_id = a.author_id)';

        foreach ([['author'], [$fragment], ['author', $fragment], [$fragment, 'author']] as $joins) {
            $this->assert_same(Book::table()->options_to_sql(['joins' => $joins])->to_s(), Book::joins($joins)->to_sql());
        }

        $this->assert_same(Book::table()->options_to_sql(['joins' => $fragment])->to_s(), Book::joins($fragment)->to_sql());
        $this->assert_same([1, 2], $this->values(Book::joins(['author', $fragment])->get(), 'book_id'));

        $this->assert_same(Event::table()->options_to_sql(['joins' => ['host', 'venue']])->to_s(), Event::joins(['host', 'venue'])->to_sql());
        $this->assert_same(str_replace('INNER JOIN', 'LEFT JOIN', Event::joins(['host', 'venue'])->to_sql()), Event::left_joins(['host', 'venue'])->to_sql());
        $this->assert_same([1, 2, 3, 5, 7], $this->values(Event::joins(['host', 'venue'])->get(), 'id'));
        $this->assert_same([1, 2, 3, 5, 6, 7], $this->values(Event::left_joins(['host', 'venue'])->get(), 'id'));

        // nothing to join: the query is left as it was
        $this->assert_same($this->sql('SELECT * FROM `books`'), Book::joins([])->left_joins([])->to_sql());
    }

    // A table that two relationships of one call join gets the name of the second as its alias, as in the
    // legacy option (#23); left_joins() aliases it the same way
    public function test_a_table_joined_twice_in_one_call_is_aliased(): void
    {
        $belongs_to = Book::$belongs_to;
        Book::$belongs_to = [['author'], ['secondary_author', 'class_name' => 'Author', 'foreign_key' => 'secondary_author_id']];

        try {
            $joins = ['author', 'secondary_author'];
            $legacy = Book::table()->options_to_sql(['joins' => $joins])->to_s();

            $this->assert_true(str_contains($legacy, $this->conn->quote_name('secondary_author') . ' ON('));
            $this->assert_same($legacy, Book::joins($joins)->to_sql());
            $this->assert_same(str_replace('INNER JOIN', 'LEFT JOIN', $legacy), Book::left_joins($joins)->to_sql());
            $this->assert_same([1, 2], $this->values(Book::left_joins($joins)->get(), 'book_id'));
        } finally {
            Book::$belongs_to = $belongs_to;
        }
    }

    // Relationship::construct_join_sql() writes the join of construct_inner_join_sql() with another keyword:
    // INNER or LEFT, and no other, since the type goes into the SQL. construct_inner_join_sql() reads its
    // arguments as it always did, the flag by its truth
    public function test_a_relationship_writes_its_join_with_a_type(): void
    {
        $table = Book::table();
        $relationship = $table->get_relationship('author');
        $inner = $relationship->construct_inner_join_sql($table);

        $this->assert_same($table->create_joins(['author']), $inner);
        $this->assert_same($inner, $relationship->construct_join_sql($table));
        $this->assert_same($inner, $relationship->construct_inner_join_sql($table, null, null));
        $this->assert_same(str_replace('INNER JOIN', 'LEFT JOIN', $inner), $relationship->construct_join_sql($table, false, null, 'LEFT'));
        $this->assert_same(
            $this->sql('LEFT JOIN `authors` `writer` ON(`books`.author_id = `writer`.author_id)'),
            $relationship->construct_join_sql($table, false, 'writer', 'LEFT')
        );
        $this->assert_exception_message_contains(
            "Unknown join type 'RIGHT' for relationship 'author': INNER or LEFT",
            fn() => $relationship->construct_join_sql($table, type: 'RIGHT'),
            RelationshipException::class
        );
    }

    // Spec §5.7: a name that is no relationship of the model is refused at the call with today's
    // RelationshipException, and so is a JOIN fragment given to left_joins(), which joins relationships only,
    // with a message that sends the fragment to joins()
    public function test_what_names_no_relationship_is_refused_at_the_call(): void
    {
        $query = Book::new_query();
        $fragment = 'LEFT JOIN authors a ON(books.secondary_author_id = a.author_id)';

        foreach ([
            ['Relationship named nope has not been declared for class: Book', fn() => $query->joins('nope')],
            ['Relationship named nope has not been declared for class: Book', fn() => $query->joins(['author', 'nope'])],
            ['Relationship named nope has not been declared for class: Book', fn() => $query->left_joins(['author', 'nope'])],
            ["left_joins() on Book takes relationship names only, and '$fragment' is a JOIN fragment: a fragment belongs in joins()", fn() => $query->left_joins($fragment)],
        ] as [$message, $call]) {
            $this->assert_exception_message_contains($message, $call, RelationshipException::class);
        }

        $this->assert_exception_message_contains('A relationship name must be a string, not array, in joins() on Book', fn() => $query->joins([['author']]), ActiveRecordException::class);
        $this->assert_exception_message_contains('A relationship name must be a string, not int, in left_joins() on Book', fn() => $query->left_joins(['author', 1]), ActiveRecordException::class);
        $this->assert_same($this->sql('SELECT * FROM `books`'), $query->to_sql());
    }

    // -- order, state and forwarding

    // The joins are sent in the order of the calls, those by relationship and the builder's alike
    public function test_the_joins_are_sent_in_the_order_of_the_calls(): void
    {
        $query = Book::new_query()
            ->left_join('authors as s', 's.author_id', '=', 'books.secondary_author_id')
            ->joins('author')
            ->cross_join('hosts');

        $this->assert_same(
            $this->sql('SELECT `books`.* FROM `books` LEFT JOIN `authors` AS `s` ON `s`.`author_id` = `books`.`secondary_author_id` ')
                . Book::table()->create_joins(['author']) . $this->sql(' CROSS JOIN `hosts`'),
            $query->to_sql()
        );
        $this->assert_same([1, 1, 1, 1, 2, 2, 2, 2], $this->values($query->get(), 'book_id'));
    }

    // A join is a clause of the builder: a where() group holds none, the legacy options are never compiled
    // with one, and the engine's writes refuse one, since an UPDATE or a DELETE would drop it
    public function test_a_join_is_a_clause_of_the_builder(): void
    {
        $this->assert_exception_message_contains(
            'A where() group can only hold where clauses: the one given to where() on Book also holds joins',
            fn() => Book::where(fn(Query $q) => $q->joins('author')),
            ActiveRecordException::class
        );
        $this->assert_exception_message_contains(
            'A query on Author holds both legacy options and builder clauses',
            fn() => Query::from_options(Author::table(), ['conditions' => ['name' => 'Tito']])->join('books', 'books.author_id', '=', 'authors.author_id')->to_sql(),
            ActiveRecordException::class
        );

        // built before the capture, which would otherwise hold the schema reads of the tables they load
        $joined = Author::joins('books');
        $joined_by_hand = Author::join('books', 'books.author_id', '=', 'authors.author_id');

        foreach ([
            'update' => fn() => $joined->execute_update(['name' => 'x']),
            'delete' => fn() => $joined_by_hand->execute_delete(),
        ] as $method => $write) {
            $this->assert_same(
                [['exception' => ActiveRecordException::class, 'message' => "$method() cannot use joins, on Author: it takes only where, order_by and limit"]],
                GoldenStatements::capture($write)
            );
        }

        $this->assert_same(['Bill Clinton', 'George W. Bush', 'Tito', 'Uncle Bob'], $this->values(Author::all(), 'name'));
    }

    // The join methods are reached statically (spec §4.1); each changes the query it is called on and returns it
    public function test_the_join_methods_are_forwarded_and_return_the_query_they_change(): void
    {
        $on = ['authors.author_id', '=', 'books.author_id'];

        $this->assert_same(Book::new_query()->join('authors', ...$on)->to_sql(), Book::join('authors', ...$on)->to_sql());
        $this->assert_same(Book::new_query()->left_join('authors', ...$on)->to_sql(), Book::left_join('authors', ...$on)->to_sql());
        $this->assert_same(Book::new_query()->right_join('authors', ...$on)->to_sql(), Book::right_join('authors', ...$on)->to_sql());
        $this->assert_same(Book::new_query()->cross_join('authors')->to_sql(), Book::cross_join('authors')->to_sql());
        $this->assert_same(Book::new_query()->joins('author')->to_sql(), Book::joins('author')->to_sql());
        $this->assert_same(Book::new_query()->left_joins('author')->to_sql(), Book::left_joins('author')->to_sql());

        $query = Book::new_query();

        foreach ([
            fn(Query $q) => $q->join('authors as a', 'a.author_id', '=', 'books.author_id'),
            fn(Query $q) => $q->left_join('authors as b', fn(JoinClause $join) => $join->on('b.author_id', '=', 'books.secondary_author_id')),
            fn(Query $q) => $q->right_join('hosts as c', 'c.id', '=', 'books.book_id'),
            fn(Query $q) => $q->cross_join('venues'),
            fn(Query $q) => $q->joins('author'),
            fn(Query $q) => $q->left_joins([]),
        ] as $call) {
            $this->assert_same($query, $call($query));
        }

        $this->assert_same(5, substr_count($query->to_sql(), ' JOIN '));
    }

    // Spec §6.3 and §3.2: Table, SQLBuilder and the relationships keep their public signatures, so that a
    // consumer's subclass that overrides one still loads; the join type comes through additions
    public function test_the_legacy_join_methods_keep_their_signatures(): void
    {
        $signature = function (string $class, string $method): string {
            $reflection = new ReflectionMethod($class, $method);
            $parameters = array_map(
                fn(ReflectionParameter $parameter): string => ($parameter->hasType() ? $parameter->getType() . ' ' : '')
                    . ($parameter->isPassedByReference() ? '&' : '') . '$' . $parameter->getName()
                    . ($parameter->isOptional() ? ' = ' . var_export($parameter->getDefaultValue(), true) : ''),
                $reflection->getParameters()
            );

            return $method . '(' . implode(', ', $parameters) . ')' . ($reflection->hasReturnType() ? ': ' . $reflection->getReturnType() : '');
        };

        $this->assert_same('create_joins($joins)', $signature(Table::class, 'create_joins'));
        $this->assert_same('joins($joins)', $signature(SQLBuilder::class, 'joins'));
        $this->assert_same(
            'construct_inner_join_sql(ActiveRecord\Table $from_table, $using_through = false, $alias = NULL)',
            $signature(AbstractRelationship::class, 'construct_inner_join_sql')
        );
    }
}
