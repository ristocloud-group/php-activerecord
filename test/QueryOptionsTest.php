<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\JoinClause;
use ActiveRecord\Model;
use ActiveRecord\Query;
use ActiveRecord\QueryState;
use ActiveRecord\ReadOnlyException;
use ActiveRecord\RelationshipException;

/**
 * The model options, the utilities and the debug aid of the query builder (spec §4.2, §5.7): with, without and
 * with_only (the include tree of the legacy 'include' option, dot notation included), readonly, when, unless, tap,
 * clone (a deep copy) and to_raw_sql.
 *
 * Fixture data: authors 1 Tito (parent 3), 2 George W. Bush (parent 2), 3 Bill Clinton (parent 1) and 4 Uncle Bob
 * (parent 2); books 1 and 2, of authors 1 and 2; events 1, 2, 3, 5, 6 and 7 (event 2 is at venue 2, of host 2);
 * hosts 1 to 4; dated_counts (2026-01-01, 1 hit) and (2026-01-02, 2 hits).
 */
class QueryOptionsTest extends DatabaseTest
{
    /**
     * $sql with each backtick replaced by the quote character of the connection.
     */
    private function sql(string $sql): string
    {
        return str_replace('`', $this->conn::$QUOTE_CHARACTER, $sql);
    }

    /**
     * $value written as the connection quotes a string.
     */
    private function quoted(string $value): string
    {
        return $this->conn->escape($value);
    }

    /**
     * $value as to_raw_sql() writes it on this connection, which is as the adapter binds it: SQLite binds an int, and a
     * bool that turns into one, as an integer, which is written bare; the other adapters, and every other value on
     * SQLite, are bound as text, which is written quoted (a bool as '1' or '0'). The adapter is told by its protocol,
     * not by the hook that the writer asks.
     */
    private function bound(int|float|string|bool $value): string
    {
        if ('sqlite' === $this->conn->protocol && (is_int($value) || is_bool($value))) {
            return (string) (int) $value;
        }

        return $this->quoted(is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }

    /**
     * The class and the message of what $call throws, or null when it throws nothing.
     *
     * @return array{class-string<Throwable>, string}|null
     */
    private function thrown(callable $call): ?array
    {
        try {
            $call();
        } catch (Throwable $e) {
            return [$e::class, $e->getMessage()];
        }

        return null;
    }

    /**
     * The include tree of a new query on $class after with($relations).
     *
     * @param class-string<Model> $class
     * @param string|array<mixed> ...$relations
     * @return array<int|string, mixed>
     */
    private function tree(string $class, string|array ...$relations): array
    {
        return $class::new_query()->with(...$relations)->state()->includes;
    }

    // The spec's first example, and the eager load it stands for
    public function test_with_dot_notation_without_and_eager_load(): void
    {
        $this->assert_equals(['author' => ['books']], Book::with('author.books')->state()->includes);
        $this->assert_equals(['author' => []], Book::with('author.books')->without('author.books')->state()->includes);
        $author = Author::with('books')->find(1);
        $this->assert_same([], GoldenStatements::capture(fn() => $author->books)); // already loaded: no query
    }

    // The spec's second example. readonly() is no static entry: Model has an instance method of that name, which
    // PHP calls (see test_a_static_readonly_is_the_error_php_throws), so it is reached through new_query().
    public function test_readonly_conditionals_clone_and_raw_sql(): void
    {
        $this->assert_true(Author::new_query()->readonly()->first()->is_readonly());
        $this->assert_sql_doesnt_has('WHERE', Author::when(false, fn($q) => $q->where('name', 'x'))->to_sql());
        $this->assert_sql_has('WHERE name = ?', Author::unless(false, fn($q) => $q->where('name', 'x'))->to_sql());
        $a = Author::where('name', 'Tito');
        $a->clone()->where('author_id', 1);
        $this->assert_sql_doesnt_has('author_id', $a->to_sql());
        $this->assert_sql_has('name = ' . Author::connection()->escape("O'Neil"), Author::where('name', "O'Neil")->to_raw_sql());
    }

    // Spec §4.2: with() takes the relationships as the legacy 'include' option takes them: a name, or a list of
    // names, in which a name may hold a list of its own for the relationships of its model. A dotted name is that
    // nesting: 'author.books' is ['author' => ['books']]. The tree is merged, in the order the relationships were
    // first given, and a relationship given again adds nothing.
    public function test_with_builds_the_include_tree_that_the_legacy_option_takes(): void
    {
        $this->assert_same([], $this->tree(Author::class));
        $this->assert_same(['books'], $this->tree(Author::class, 'books'));
        $this->assert_same(['books', 'awesome_person'], $this->tree(Author::class, 'books', 'awesome_person'));
        $this->assert_same(['books', 'awesome_person'], $this->tree(Author::class, ['books', 'awesome_person']));
        $this->assert_same(['books', 'awesome_person'], $this->tree(Author::class, ['books'], 'awesome_person'));
        $this->assert_same(['parent_author' => ['books']], $this->tree(Author::class, 'parent_author.books'));
        $this->assert_same(['venue' => ['events' => ['host']]], $this->tree(Event::class, 'venue.events.host'));
        $this->assert_same(['host', 'venue' => ['hosts']], $this->tree(Event::class, 'host', ['venue.hosts']));

        // the legacy forms, as they are given to the 'include' option
        $this->assert_same(['parent_author' => ['books']], $this->tree(Author::class, ['parent_author' => ['books']]));
        $this->assert_same(['venue' => ['events' => ['host']]], $this->tree(Event::class, ['venue' => ['events' => ['host']]]));
        $this->assert_same(['parent_author' => ['books']], $this->tree(Author::class, ['parent_author' => ['books' => []]]));
        $this->assert_same(['parent_author' => ['parent_author' => ['books']]], $this->tree(Author::class, ['parent_author.parent_author' => ['books']]));
        $this->assert_same(['parent_author'], $this->tree(Author::class, ['parent_author' => []]));

        // merged, in the order of the first mention
        $this->assert_same(['books'], $this->tree(Author::class, 'books', 'books'));
        $this->assert_same(['books', 'parent_author' => ['books'], 'awesome_person'], $this->tree(Author::class, 'books', 'parent_author.books', 'awesome_person'));
        $this->assert_same(['parent_author' => ['books', 'awesome_person']], $this->tree(Author::class, 'parent_author.books', 'parent_author.awesome_person'));
        // a relationship that is also a parent is the parent, wherever it was first given
        $this->assert_same(['parent_author' => ['books']], $this->tree(Author::class, 'parent_author', 'parent_author.books'));
        $this->assert_same(['parent_author' => ['books']], $this->tree(Author::class, 'parent_author.books', 'parent_author'));
        $this->assert_same(['parent_author' => ['books'], 'awesome_person'], $this->tree(Author::class, 'parent_author', 'awesome_person', 'parent_author.books'));

        // calls add to the tree, and the query is the one they were called on
        $query = Author::new_query();
        $this->assert_same($query, $query->with('books'));
        $this->assert_same($query, $query->with('parent_author.books', 'awesome_person'));
        $this->assert_same(['books', 'parent_author' => ['books'], 'awesome_person'], $query->state()->includes);
        $this->assert_same($query, $query->with());
        $this->assert_same(['books', 'parent_author' => ['books'], 'awesome_person'], $query->state()->includes);
    }

    // Eloquent's without() takes a relationship off the eager loads by its name: one under another by its dotted
    // path, which leaves the other loaded without it, and one that others are loaded under with them. A path that is
    // not in the tree takes nothing off, whether the model has such a relationship or not.
    public function test_without_takes_a_relationship_off_by_its_path(): void
    {
        $without = fn(array $with, string ...$paths): array => Author::new_query()->with(...$with)->without(...$paths)->state()->includes;

        $this->assert_same(['awesome_person'], $without(['books', 'awesome_person'], 'books'));
        $this->assert_same(['books'], $without(['books', 'awesome_person'], 'awesome_person'));
        $this->assert_same([], $without(['books', 'awesome_person'], 'books', 'awesome_person'));
        $this->assert_same([], $without(['parent_author.books'], 'parent_author'));
        $this->assert_same(['parent_author' => []], $without(['parent_author.books'], 'parent_author.books'));
        $this->assert_same(['parent_author' => ['awesome_person']], $without(['parent_author.books', 'parent_author.awesome_person'], 'parent_author.books'));
        $this->assert_same(['books', 'parent_author' => ['books']], $without(['books', 'parent_author.books', 'awesome_person'], 'awesome_person'));
        $this->assert_same(['parent_author' => ['parent_author' => []]], $without(['parent_author.parent_author.books'], 'parent_author.parent_author.books'));

        $this->assert_same(['books'], $without(['books'], 'awesome_person', 'books.nope', 'nope', 'nope.books'));
        $this->assert_same(['parent_author' => ['books']], $without(['parent_author.books'], 'parent_author.awesome_person', 'books'));
        $this->assert_same([], $without([], 'books'));
        $this->assert_same(['books'], $without(['books']));

        $query = Author::new_query()->with('books');
        $this->assert_same($query, $query->without('books'));
        $this->assert_same([], $query->state()->includes);
    }

    // Eloquent's withOnly(): the relationships it is given replace those the query loads
    public function test_with_only_replaces_the_tree(): void
    {
        $query = Author::new_query()->with('books', 'awesome_person');
        $this->assert_same($query, $query->with_only('parent_author.books'));
        $this->assert_same(['parent_author' => ['books']], $query->state()->includes);
        $this->assert_same(['awesome_person', 'books'], Author::with('books')->with_only(['awesome_person'], 'books')->state()->includes);
        $this->assert_same(['parent_author' => ['books']], Author::with('books')->with_only(['parent_author' => ['books']])->state()->includes);
        $this->assert_same([], Author::with('books')->with_only()->state()->includes);
    }

    // Spec §5.7: a relationship that is not declared is refused at the call, with the RelationshipException that the
    // legacy include option throws when it runs, and the query is left as it was
    public function test_an_undeclared_relationship_is_refused_at_the_call(): void
    {
        // a cold Table adds its schema read to a capture
        Author::table();
        Book::table();

        $legacy = [RelationshipException::class, 'Relationship named nope has not been declared for class: Author'];
        $this->assert_same($legacy, $this->thrown(fn() => Author::all(['include' => ['nope']])));
        // the legacy option looks the names up when it loads them, so it says nothing when it finds no model: the
        // builder refuses at the call, whatever the query finds
        $this->assert_same([], Author::all(['conditions' => ['name' => 'Nobody'], 'include' => ['nope']]));
        $this->assert_same($legacy, $this->thrown(fn() => Author::where('name', 'Nobody')->with('nope')));
        $this->assert_same($legacy, $this->thrown(fn() => Author::new_query()->with('nope')));
        $this->assert_same($legacy, $this->thrown(fn() => Author::new_query()->with(['nope'])));
        $this->assert_same($legacy, $this->thrown(fn() => Author::new_query()->with_only('nope')));
        $this->assert_same($legacy, $this->thrown(fn() => Author::new_query()->with(['nope' => ['books']])));
        $this->assert_same([RelationshipException::class, 'Relationship named nope has not been declared for class: Book'], $this->thrown(fn() => Author::new_query()->with('books.nope')));
        $this->assert_same([RelationshipException::class, 'Relationship named nope has not been declared for class: Author'], $this->thrown(fn() => Book::new_query()->with('author.nope')));
        $this->assert_same([RelationshipException::class, 'Relationship named books has not been declared for class: Book'], $this->thrown(fn() => Book::new_query()->with('books')));
        // names are told apart by case
        $this->assert_same([RelationshipException::class, 'Relationship named Books has not been declared for class: Author'], $this->thrown(fn() => Author::new_query()->with('Books')));

        // before any statement is sent
        $this->assert_same([['exception' => RelationshipException::class, 'message' => $legacy[1]]], GoldenStatements::capture(fn() => Author::new_query()->with('nope')));

        // the query is as it was, however far the call got
        $query = Author::new_query()->with('books');
        $this->assert_same($legacy, $this->thrown(fn() => $query->with('awesome_person', 'nope')));
        $this->assert_same(['books'], $query->state()->includes);
        $this->assert_same($legacy, $this->thrown(fn() => $query->with_only('awesome_person', 'nope')));
        $this->assert_same(['books'], $query->state()->includes);
        $this->assert_same($legacy, $this->thrown(fn() => $query->with('parent_author.books', 'nope')));
        $this->assert_same(['books'], $query->state()->includes);

        // a declared relationship is accepted, whatever it is to its model: has_one, has_many, belongs_to, through
        $this->assert_same(['awesome_person', 'books', 'parent_author'], $this->tree(Author::class, 'awesome_person', 'books', 'parent_author'));
        $this->assert_same(['host', 'venue'], $this->tree(Event::class, 'host', 'venue'));
        $this->assert_same(['events', 'hosts'], $this->tree(Venue::class, 'events', 'hosts'));
    }

    // Spec §5.7: what with() cannot read it refuses at the call, naming the method, the model and the input
    public function test_with_refuses_what_it_cannot_read(): void
    {
        $empty = fn(string $name, string $method = 'with'): array => [ActiveRecordException::class, "Empty relationship name: '$name' in $method() on Author"];

        foreach (['', ' ', '.', 'books.', '.books', 'parent_author..books', 'parent_author. .books'] as $name) {
            $this->assert_same($empty($name), $this->thrown(fn() => Author::new_query()->with($name)));
            $this->assert_same($empty($name, 'with_only'), $this->thrown(fn() => Author::new_query()->with_only($name)));
            $this->assert_same($empty($name, 'without'), $this->thrown(fn() => Author::new_query()->with('books')->without($name)));
        }

        $this->assert_same($empty('books.'), $this->thrown(fn() => Author::new_query()->with(['books.'])));
        $this->assert_same($empty('.books'), $this->thrown(fn() => Author::new_query()->with(['parent_author' => ['.books']])));
        $this->assert_same($empty('.parent_author'), $this->thrown(fn() => Author::new_query()->with(['.parent_author' => ['books']])));

        // an entry of a list is a name, or a name with a list of its own: nothing else says what to load
        $unreadable = fn(string $given, string $key): array => [
            ActiveRecordException::class,
            "with() takes a relationship name, or a list of them in which a name may hold a list, not $given at key $key, on Author",
        ];
        $this->assert_same($unreadable('int', '0'), $this->thrown(fn() => Author::new_query()->with([5])));
        $this->assert_same($unreadable('array', '0'), $this->thrown(fn() => Author::new_query()->with([['books']])));
        $this->assert_same($unreadable('string', "'books'"), $this->thrown(fn() => Author::new_query()->with(['books' => 'parent_author'])));
        $this->assert_same($unreadable('Closure', "'books'"), $this->thrown(fn() => Author::new_query()->with(['books' => fn($query) => $query])));
        $this->assert_same($unreadable('null', "'books'"), $this->thrown(fn() => Author::new_query()->with(['books' => null])));
        $this->assert_same($unreadable('bool', '1'), $this->thrown(fn() => Author::new_query()->with(['parent_author' => [], 1 => true])));
        $this->assert_same($unreadable('int', '0'), $this->thrown(fn() => Author::new_query()->with(['parent_author' => [5]])));

        // none of it leaves anything in the query
        $query = Author::new_query()->with('books');
        $this->assert_not_null($this->thrown(fn() => $query->with('awesome_person', [5])));
        $this->assert_same(['books'], $query->state()->includes);
    }

    // Spec §3.3: the models are found and the relationships loaded by Table::find_by_sql(), as the legacy finders do
    // it: the statements are the legacy ones, byte for byte, and what is loaded is read with no query
    public function test_with_eager_loads_through_the_legacy_path(): void
    {
        // a first run reads the schemas of the tables a relationship loads, which a capture would show
        Author::all(['include' => ['books']]);
        Book::all(['include' => ['author' => ['books']]]);
        Event::all(['include' => ['host', 'venue' => ['hosts']]]);

        $legacy = GoldenStatements::capture(fn() => Author::all(['include' => ['books']]));
        $this->assert_same(2, count($legacy));
        $this->assert_same($legacy, GoldenStatements::capture(fn() => Author::with('books')->get()));

        $legacy = GoldenStatements::capture(fn() => Book::all(['include' => ['author' => ['books']]]));
        $this->assert_same(3, count($legacy));
        $this->assert_same($legacy, GoldenStatements::capture(fn() => Book::with('author.books')->get()));

        $legacy = GoldenStatements::capture(fn() => Event::all(['include' => ['host', 'venue' => ['hosts']]]));
        $this->assert_same($legacy, GoldenStatements::capture(fn() => Event::with('host', 'venue.hosts')->get()));
        $this->assert_same($legacy, GoldenStatements::capture(fn() => Event::with('venue.events')->with_only('host', 'venue.hosts')->get()));

        // as the legacy option does, nothing is loaded for no model: the query's own statement is the only one
        $this->assert_same(1, count(GoldenStatements::capture(fn() => Author::with('books')->where('name', 'Nobody')->get())));
        $this->assert_same(1, count(GoldenStatements::capture(fn() => Author::all(['conditions' => ['name' => 'Nobody'], 'include' => ['books']]))));

        // what is loaded is read with no query, whichever terminal found the model
        $find = [
            'get' => fn() => Author::with('books')->order_by('author_id')->get()[0],
            'first' => fn() => Author::with('books')->order_by('author_id')->first(),
            'first_or_fail' => fn() => Author::with('books')->order_by('author_id')->first_or_fail(),
            'first_or' => fn() => Author::with('books')->order_by('author_id')->first_or(fn() => null),
            'sole' => fn() => Author::with('books')->where('author_id', 1)->sole(),
            'find' => fn() => Author::with('books')->find(1),
            'find (a list)' => fn() => Author::with('books')->order_by('author_id')->find([1, 2])[0],
            'find_many' => fn() => Author::with('books')->order_by('author_id')->find_many([1, 2])[0],
            'find_or_fail' => fn() => Author::with('books')->find_or_fail(1),
            'find_or' => fn() => Author::with('books')->find_or(1, fn() => null),
        ];

        foreach ($find as $name => $found) {
            $author = $found();
            $this->assert_same(1, $author->author_id, $name);
            $this->assert_same([], GoldenStatements::capture(fn() => $author->books), $name);
            $this->assert_same(1, count($author->books), $name);
        }

        // the control: without with(), reading the relationship asks the database
        $author = Author::new_query()->order_by('author_id')->first();
        $this->assert_same(1, count(GoldenStatements::capture(fn() => $author->books)));

        // nested relationships are loaded too, each in its place of the tree
        $book = Book::with('author.books')->find(1);
        $this->assert_same([], GoldenStatements::capture(fn() => $book->author->books));
        $this->assert_same(1, count($book->author->books));
        $event = Event::with('host', 'venue.hosts')->find(2);
        $this->assert_same([], GoldenStatements::capture(fn() => [$event->host->name, $event->venue->hosts]));
        $this->assert_same('Billy Crystal', $event->host->name);

        // only what is read by the models is loaded: count(), exists(), value() and pluck() send their statement alone
        $this->assert_same(1, count(GoldenStatements::capture(fn() => Author::with('books')->count())));
        $this->assert_same(1, count(GoldenStatements::capture(fn() => Author::with('books')->exists())));
        $this->assert_same(1, count(GoldenStatements::capture(fn() => Author::with('books')->value('name'))));
        $this->assert_same(1, count(GoldenStatements::capture(fn() => Author::with('books')->pluck('name'))));
    }

    // with() goes with the rest of the query: joins, which keep the authors that have a book, and a select of the key
    // columns that the relationships are loaded by. The statements and the models are those of the legacy include
    // option, and what is loaded is read with no query.
    public function test_with_goes_with_joins_and_a_select_of_the_key_columns(): void
    {
        // a first run reads the schemas of the tables a relationship loads, which a capture would show
        Author::all(['joins' => ['books'], 'include' => ['books']]);
        Author::all(['include' => ['books']]);

        $summary = function (array $authors): array {
            $summary = array_map(fn(Author $author) => [$author->author_id, array_map(fn(Book $book) => $book->book_id, $author->books)], $authors);
            sort($summary);

            return $summary;
        };
        $cases = [
            // an INNER JOIN, which keeps the authors with a book, and selects the authors' columns only
            'joins' => [
                fn() => Author::all(['joins' => ['books'], 'include' => ['books']]),
                fn() => Author::joins('books')->with('books')->get(),
                [[1, [1]], [2, [2]]],
            ],
            'a select of the key columns' => [
                fn() => Author::all(['select' => $this->sql('`author_id`, `name`'), 'include' => ['books']]),
                fn() => Author::select('author_id', 'name')->with('books')->get(),
                [[1, [1]], [2, [2]], [3, []], [4, []]],
            ],
            'joins and a select' => [
                fn() => Author::all(['joins' => ['books'], 'select' => $this->sql('`authors`.`author_id`, `authors`.`name`'), 'include' => ['books']]),
                fn() => Author::joins('books')->select('authors.author_id', 'authors.name')->with('books')->get(),
                [[1, [1]], [2, [2]]],
            ],
        ];

        foreach ($cases as $label => [$legacy, $builder, $expected]) {
            $legacy_models = [];
            $builder_models = [];
            $legacy_statements = GoldenStatements::capture(function () use ($legacy, &$legacy_models) {
                $legacy_models = $legacy();
            });
            $builder_statements = GoldenStatements::capture(function () use ($builder, &$builder_models) {
                $builder_models = $builder();
            });

            // the query, and the one statement that loads the books
            $this->assert_same(2, count($builder_statements), $label);
            $this->assert_same($legacy_statements, $builder_statements, $label);
            $this->assert_same($expected, $summary($builder_models), $label);
            $this->assert_same($summary($legacy_models), $summary($builder_models), $label);
            $this->assert_same([], GoldenStatements::capture(fn() => $summary($builder_models)), $label);
        }
    }

    // The relationships are loaded by the keys of the models found. A select that leaves them out, select('name'),
    // fails with() as it fails the legacy include option: a statement for a NULL key is sent, PHP warns of the
    // attribute the models lack, and an UndefinedPropertyException is thrown.
    public function test_with_fails_as_the_legacy_option_does_when_the_select_leaves_the_keys_out(): void
    {
        // a first run reads the schemas of the tables a relationship loads, which a capture would show
        Author::all(['include' => ['books']]);

        $run = function (callable $call): array {
            $warnings = [];
            set_error_handler(function (int $level, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            });

            try {
                $statements = GoldenStatements::capture($call);
            } finally {
                restore_error_handler();
            }

            return [$statements, array_values(array_unique($warnings))];
        };

        [$legacy_statements, $legacy_warnings] = $run(fn() => Author::all(['select' => $this->sql('`name`'), 'include' => ['books']]));
        [$builder_statements, $builder_warnings] = $run(fn() => Author::select('name')->with('books')->get());

        $this->assert_same($legacy_statements, $builder_statements);
        $this->assert_same($legacy_warnings, $builder_warnings);
        $this->assert_same(['Undefined array key "author_id"'], $builder_warnings);
        $this->assert_same(3, count($builder_statements));
        $this->assert_same($this->sql('SELECT `name` FROM `authors`'), $builder_statements[0]['sql']);
        $this->assert_same($this->sql('SELECT * FROM `books` WHERE `author_id` IS NULL'), $builder_statements[1]['sql']);
        $this->assert_same(ActiveRecord\UndefinedPropertyException::class, $builder_statements[2]['exception']);
    }

    // Spec §5.5: a write that carries a clause it cannot use refuses it, and with() is one, as readonly() is: the
    // builder's update() and delete(), and the execute_*() methods they share with the legacy writes, refuse before
    // they send anything.
    public function test_a_write_refuses_with_and_readonly(): void
    {
        $refusal = fn(string $method, string $clause): array => [ActiveRecordException::class, "$method() cannot use $clause, on Author: it takes only where, order_by and limit"];

        $this->assert_same($refusal('delete', 'with'), $this->thrown(fn() => Author::with('books')->delete()));
        $this->assert_same($refusal('delete', 'readonly'), $this->thrown(fn() => Author::new_query()->readonly()->delete()));
        $this->assert_same($refusal('update', 'with'), $this->thrown(fn() => Author::with('books')->update(['name' => 'x'])));
        $this->assert_same($refusal('update', 'readonly'), $this->thrown(fn() => Author::new_query()->readonly()->update(['name' => 'x'])));
        $this->assert_same($refusal('delete', 'with'), $this->thrown(fn() => Author::with('books')->execute_delete()));
        $this->assert_same($refusal('update', 'readonly'), $this->thrown(fn() => Author::new_query()->readonly()->execute_update(['name' => 'x'])));
        $this->assert_same([['exception' => ActiveRecordException::class, 'message' => $refusal('delete', 'with')[1]]], GoldenStatements::capture(fn() => Author::with('books')->delete()));
        $this->assert_same(4, Author::new_query()->count());
    }

    // with() and readonly() are clauses of the query, as the others are: a group of where() holds where clauses
    // only, and a query that holds legacy options does not take them
    public function test_the_two_options_are_clauses_of_the_query_like_the_others(): void
    {
        $group = fn(string $held): array => [ActiveRecordException::class, "A where() group can only hold where clauses: the one given to where() on Author also holds $held"];

        $this->assert_same($group('includes'), $this->thrown(fn() => Author::where(fn(Query $q) => $q->with('books'))));
        $this->assert_same($group('readonly'), $this->thrown(fn() => Author::where(fn(Query $q) => $q->readonly())));
        $this->assert_same($group('includes, readonly'), $this->thrown(fn() => Author::where(fn(Query $q) => $q->readonly()->with_only('books'))));
        // a group that undoes what it set holds nothing
        $this->assert_instance_of(Query::class, Author::where(fn(Query $q) => $q->where('name', 'x')->with('books')->without('books')->readonly()->readonly(false)));

        $legacy = fn(): Query => Query::from_options(Author::table(), ['conditions' => ['name' => 'Tito']]);
        $mixing = [ActiveRecordException::class, 'A query on Author holds both legacy options and builder clauses: it compiles the one or the other'];
        $this->assert_same($mixing, $this->thrown(fn() => $legacy()->with('books')->get()));
        $this->assert_same($mixing, $this->thrown(fn() => $legacy()->readonly()->get()));
        $this->assert_same(1, count($legacy()->get()));
    }

    public function test_readonly_flags_the_models_the_query_finds(): void
    {
        $this->assert_false(Author::new_query()->first()->is_readonly());

        $query = Author::new_query();
        $this->assert_same($query, $query->readonly());
        $this->assert_true($query->state()->readonly);
        $this->assert_true($query->first()->is_readonly());
        $this->assert_same([true, true, true, true], array_map(fn(Author $author) => $author->is_readonly(), $query->get()));
        $this->assert_true($query->find(1)->is_readonly());
        $this->assert_same([true, true], array_map(fn(Author $author) => $author->is_readonly(), $query->find([1, 2])));
        $this->assert_true($query->first_or_fail()->is_readonly());
        $this->assert_true($query->find_or_fail(1)->is_readonly());
        $this->assert_true(Author::new_query()->readonly()->where('author_id', 1)->sole()->is_readonly());

        // readonly(false) is a model that can be written again
        $this->assert_same($query, $query->readonly(false));
        $this->assert_false($query->state()->readonly);
        $this->assert_false($query->first()->is_readonly());
        $this->assert_true(Author::new_query()->readonly(false)->readonly(true)->first()->is_readonly());

        // a readonly model refuses its writes
        $author = Author::new_query()->readonly()->first();
        $this->assert_same([ReadOnlyException::class, 'Author::save() cannot be invoked because this model is set to read only'], $this->thrown(fn() => $author->save()));
        $this->assert_same([ReadOnlyException::class, 'Author::delete() cannot be invoked because this model is set to read only'], $this->thrown(fn() => $author->delete()));
    }

    // As in the legacy finders, the models the query finds are readonly and those it loads for them are not
    public function test_readonly_is_the_legacy_option_on_the_models_found(): void
    {
        $legacy = Author::find('first', ['readonly' => true, 'include' => ['books']]);
        $builder = Author::new_query()->readonly()->with('books')->first();

        $this->assert_true($legacy->is_readonly());
        $this->assert_true($builder->is_readonly());
        $this->assert_same(array_map(fn(Book $book) => $book->is_readonly(), $legacy->books), array_map(fn(Book $book) => $book->is_readonly(), $builder->books));
        $this->assert_same([false], array_map(fn(Book $book) => $book->is_readonly(), $builder->books));
    }

    // readonly() is on Query only: PHP calls Model::readonly(), the instance method, before it would look for
    // __callStatic(), and refuses to call it without a model. It is no entry of the static forwarding, and is
    // reached through new_query() or a chain.
    public function test_a_static_readonly_is_the_error_php_throws(): void
    {
        $thrown = $this->thrown(fn() => Author::readonly());

        $this->assert_same(Error::class, $thrown[0] ?? null);
        $this->assert_true(str_contains($thrown[1] ?? '', 'cannot be called statically'));
        $this->assert_true(method_exists(Model::class, 'readonly'));
        $this->assert_false(in_array('readonly', Query::FORWARDABLE, true));
        $this->assert_true(Author::where('author_id', 1)->readonly()->first()->is_readonly());
    }

    // Eloquent's Conditionable: the callback runs when the value is true (when) or false (unless), else the default
    // does, if there is one. A value that is a Closure is called with the query, and what it returns is the value.
    public function test_when_and_unless_run_their_callback_by_the_truth_of_the_value(): void
    {
        $where = fn(Query $query): Query => $query->where('name', 'x');
        $has_where = fn(Query $query): bool => str_contains($query->to_sql(), 'WHERE');

        foreach ([true, 1, -1, 0.5, 'x', '0.0', ' ', [0], new stdClass()] as $value) {
            $this->assert_true($has_where(Author::new_query()->when($value, $where)), get_debug_type($value));
            $this->assert_false($has_where(Author::new_query()->unless($value, $where)), get_debug_type($value));
        }

        foreach ([false, 0, 0.0, '0', '', [], null] as $value) {
            $this->assert_false($has_where(Author::new_query()->when($value, $where)), var_export($value, true));
            $this->assert_true($has_where(Author::new_query()->unless($value, $where)), var_export($value, true));
        }

        // the default is for the other case
        $other = fn(Query $query): Query => $query->where('name', 'y');
        $this->assert_same(['x'], Author::new_query()->when(true, $where, $other)->get_bindings());
        $this->assert_same(['y'], Author::new_query()->when(false, $where, $other)->get_bindings());
        $this->assert_same(['y'], Author::new_query()->unless(true, $where, $other)->get_bindings());
        $this->assert_same(['x'], Author::new_query()->unless(false, $where, $other)->get_bindings());

        // only the callback that was chosen is called, and what it throws is thrown
        $never = fn() => throw new LogicException('the callback was called');
        $this->assert_same([], Author::new_query()->when(false, $never)->get_bindings());
        $this->assert_same([], Author::new_query()->when(true, fn() => null, $never)->get_bindings());
        $this->assert_same([], Author::new_query()->unless(true, $never)->get_bindings());
        $this->assert_same([], Author::new_query()->unless(false, fn() => null, $never)->get_bindings());
        $this->assert_same([LogicException::class, 'the callback was called'], $this->thrown(fn() => Author::new_query()->when(true, $never)));
        $this->assert_same([LogicException::class, 'the callback was called'], $this->thrown(fn() => Author::new_query()->unless(false, $never)));
    }

    public function test_the_callbacks_of_when_and_unless_get_the_query_and_the_value(): void
    {
        $query = Author::new_query();
        $received = [];
        $record = function (string $name) use (&$received): Closure {
            return function (...$arguments) use (&$received, $name) {
                $received[] = [$name, ...$arguments];
            };
        };

        $query->when('yes', $record('callback'), $record('default'));
        $query->when(0, $record('callback'), $record('default'));
        $query->unless('', $record('callback'), $record('default'));
        $query->unless([1], $record('callback'), $record('default'));
        $this->assert_same([['callback', $query, 'yes'], ['default', $query, 0], ['callback', $query, ''], ['default', $query, [1]]], $received);

        // a Closure given as the value is called with the query, and what it returns is the value the callbacks get
        $received = [];
        $query->when(fn(Query $q) => $q === $query && 'truth', $record('callback'));
        $query->when(fn() => 0, $record('callback'), $record('default'));
        $query->unless(fn() => 7, $record('callback'), $record('default'));
        $this->assert_same([['callback', $query, true], ['default', $query, 0], ['default', $query, 7]], $received);

        // it is a Closure that is called: any other callable is a value, and a true one
        $received = [];
        $query->when('strlen', $record('callback'));
        $query->when([Author::class, 'new_query'], $record('callback'));
        $this->assert_same([['callback', $query, 'strlen'], ['callback', $query, [Author::class, 'new_query']]], $received);
    }

    // The query is mutable (spec §3.2): the callback changes it, and it is what comes back, unless the callback hands
    // back another query on the model, as in Eloquent, which then is. Anything else that it returns is of no use to
    // the chain, and is left out.
    public function test_when_returns_the_query_or_the_one_the_callback_hands_back(): void
    {
        $query = Author::new_query();

        $this->assert_same($query, $query->when(true, fn(Query $q) => $q->where('name', 'x')));
        $this->assert_same($query, $query->when(true, fn() => null));
        $this->assert_same($query, $query->when(true, function (Query $q) {
            $q->where('author_id', 1);
        }));
        $this->assert_same($query, $query->when(false, fn() => null));
        $this->assert_same($query, $query->when(true, fn() => 5));
        $this->assert_same($query, $query->when(true, fn() => 'a string'));
        $this->assert_same($query, $query->when(true, fn() => [$query]));
        $this->assert_same($query, $query->when(true, fn() => new stdClass()));
        $this->assert_same($query, $query->when(true, fn() => Book::new_query()->where('name', 'other')));
        $this->assert_same($query, $query->unless(false, fn() => 5));
        $this->assert_same($query, $query->unless(true, fn() => null));
        $this->assert_same(['x', 1], $query->get_bindings());

        $other = Author::new_query()->where('name', 'other');
        $this->assert_same($other, $query->when(true, fn() => $other));
        $this->assert_same($other, $query->unless(false, fn() => $other));
        $this->assert_same($other, $query->when(false, fn() => null, fn() => $other));
        $this->assert_same($other, $query->unless(true, fn() => null, fn() => $other));
        $this->assert_same(['x', 1], $query->get_bindings());

        // a fork that the callback hands back is the query that goes on
        $this->assert_same(['x', 1, 'forked'], $query->when(true, fn(Query $q) => $q->clone()->where('name', 'forked'))->get_bindings());
        $this->assert_same(['x', 1], $query->get_bindings());
    }

    public function test_tap_passes_the_query_to_the_callback_and_returns_it(): void
    {
        $query = Author::new_query();
        $received = [];

        $this->assert_same($query, $query->tap(function (...$arguments) use (&$received) {
            $received[] = $arguments;
        }));
        $this->assert_same([[$query]], $received);

        $this->assert_same($query, $query->tap(fn(Query $q) => $q->where('name', 'x')));
        $this->assert_same(['x'], $query->get_bindings());
        // what the callback returns is of no use to tap(), not even another query
        $this->assert_same($query, $query->tap(fn() => Author::new_query()));
        $this->assert_same($query, $query->tap(fn() => 5));
        $this->assert_same(['x'], $query->get_bindings());
        $this->assert_same([LogicException::class, 'tapped'], $this->thrown(fn() => $query->tap(fn() => throw new LogicException('tapped'))));
    }

    // Spec §4.1: the names on Query::FORWARDABLE are called on a new query of the class. Model has no method of these
    // names (QueryForwardingTest pins the list and the tags), so PHP reaches __callStatic().
    public function test_the_new_names_are_forwarded_to_a_new_query(): void
    {
        foreach (['with', 'without', 'with_only', 'when', 'unless', 'tap', 'clone', 'to_raw_sql'] as $name) {
            $this->assert_true(in_array($name, Query::FORWARDABLE, true), $name);
            $this->assert_false(method_exists(Model::class, $name), $name);
        }

        $this->assert_same(['books'], Author::with('books')->state()->includes);
        $this->assert_same([], Author::without('books')->state()->includes);
        $this->assert_same(['awesome_person'], Author::with_only('awesome_person')->state()->includes);
        $this->assert_same(['x'], Author::when(true, fn($q) => $q->where('name', 'x'))->get_bindings());
        $this->assert_same(['x'], Author::unless(false, fn($q) => $q->where('name', 'x'))->get_bindings());
        $this->assert_same(['x'], Author::tap(fn($q) => $q->where('name', 'x'))->get_bindings());
        $this->assert_instance_of(Query::class, Author::clone());
        $this->assert_same($this->sql('SELECT * FROM `authors`'), Author::clone()->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `authors`'), Author::to_raw_sql());
        $this->assert_same(4, count(Author::clone()->get()));
    }

    /**
     * A query that holds a kind of everything a deep copy has to keep apart: a group of conditions in a group, the
     * conditions of a join that a closure wrote, a HAVING, the includes, and the plain clauses. It is only compiled,
     * never run.
     */
    private function rich_query(): Query
    {
        return Author::select('author_id', 'name')
            ->distinct()
            ->where('name', '!=', 'x')
            ->where(fn(Query $q) => $q
                ->where('author_id', '>', 0)
                ->or_where(fn(Query $inner) => $inner->where('author_id', 99)->where('name', 'y')))
            ->join('books', fn(JoinClause $join) => $join->on('books.author_id', '=', 'authors.author_id')->where('books.name', 'b'))
            ->where_raw('author_id > ?', [-1])
            ->group_by('author_id', 'name')
            ->having('author_id', '>', 0)
            ->order_by('author_id')
            ->limit(5)
            ->offset(1)
            ->with('books', 'parent_author.books')
            ->readonly();
    }

    /**
     * What of a query a change to it could show in: its state, down to its groups, and what it sends.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Query $query): array
    {
        return [
            'state' => serialize($query->state()),
            'sql' => $query->to_sql(),
            'bindings' => $query->get_bindings(),
            'raw' => $query->to_raw_sql(),
        ];
    }

    /**
     * The id of every state under $state, itself first: its groups of conditions in the WHERE, the HAVING and the
     * ON of a join, and theirs.
     *
     * @return list<int>
     */
    private function state_ids(QueryState $state): array
    {
        $conditions = [...$state->wheres, ...$state->havings];

        foreach ($state->joins as $join) {
            $conditions = [...$conditions, ...(is_string($join) ? [] : $join['wheres'])];
        }

        $ids = [spl_object_id($state)];

        foreach ($conditions as $condition) {
            if ('group' === $condition['type']) {
                $ids = [...$ids, ...$this->state_ids($condition['group'])];
            }
        }

        return $ids;
    }

    // Maintainer's ruling: clone() is a deep copy, so a change to the copy never reaches the query it was made
    // from, nor the other way round, whatever it is made to: the conditions, a group of them in a group, the
    // conditions of a join, the HAVING, the includes, or the plain clauses
    public function test_clone_is_a_copy_that_shares_nothing(): void
    {
        $original = $this->rich_query();
        $clone = $original->clone();

        $this->assert_instance_of(Query::class, $clone);
        $this->assert_not_same($original, $clone);
        $this->assert_not_same($original->state(), $clone->state());
        $this->assert_same($this->snapshot($original), $this->snapshot($clone));
        $this->assert_same(['b', 'x', 0, 99, 'y', -1, 0], $clone->get_bindings());
        $this->assert_same(['books', 'parent_author' => ['books']], $clone->state()->includes);
        $this->assert_true($clone->state()->readonly);

        // the groups of the one are not those of the other: the root, a group, and the group in it
        $mine = $this->state_ids($original->state());
        $theirs = $this->state_ids($clone->state());
        $this->assert_same(3, count($mine));
        $this->assert_same(3, count($theirs));
        $this->assert_same([], array_intersect($mine, $theirs));

        // a copy of a copy is as apart from both
        $again = $clone->clone();
        $this->assert_same([], array_intersect($this->state_ids($again->state()), [...$mine, ...$theirs]));
        $this->assert_same($this->snapshot($original), $this->snapshot($again));

        // and PHP's own clone is the same copy
        $php = clone $original;
        $this->assert_same([], array_intersect($this->state_ids($php->state()), $mine));
        $this->assert_same($this->snapshot($original), $this->snapshot($php));
    }

    // The builder writes no group in a HAVING, nor in the ON of a join, but the state can hold them, and a copy
    // keeps their groups apart as it does those of the WHERE
    public function test_clone_keeps_apart_the_groups_of_a_having_and_of_a_join(): void
    {
        $group = function (): array {
            $state = new QueryState();
            $state->wheres = [['type' => 'null', 'boolean' => 'AND', 'column' => 'name', 'not' => false]];

            return ['type' => 'group', 'boolean' => 'AND', 'not' => false, 'group' => $state];
        };
        $original = Author::new_query()->join('books', 'books.author_id', '=', 'authors.author_id')->left_joins('books');
        $original->state()->havings[] = $group();
        $original->state()->joins[0]['wheres'][] = $group();

        $copy = $original->clone();
        $mine = $this->state_ids($original->state());
        $theirs = $this->state_ids($copy->state());
        $this->assert_same(3, count($mine));
        $this->assert_same(3, count($theirs));
        $this->assert_same([], array_intersect($mine, $theirs));

        $before = serialize($original->state());
        $copy->state()->havings[0]['group']->wheres[] = ['type' => 'null', 'boolean' => 'AND', 'column' => 'city', 'not' => false];
        $copy->state()->joins[0]['wheres'][1]['group']->wheres = [];
        $this->assert_same($before, serialize($original->state()));
        // a join held as SQL (a relationship's) is a string, and is copied as it is
        $this->assert_same($original->state()->joins[1], $copy->state()->joins[1]);
        $this->assert_true(is_string($copy->state()->joins[1]));
    }

    public function test_changing_a_clone_through_the_builder_leaves_the_original_alone(): void
    {
        $original = $this->rich_query();
        $before = $this->snapshot($original);
        $clone = $original->clone();

        $clone->where('name', 'added')
            ->or_where(fn(Query $q) => $q->where('author_id', 5))
            ->join('events', 'events.id', '=', 'authors.author_id')
            ->left_join('hosts', fn(JoinClause $join) => $join->on('hosts.id', '=', 'authors.author_id')->where('hosts.name', 'h'))
            ->having('name', 'having')
            ->or_having_raw('COUNT(*) > ?', [3])
            ->group_by('parent_author_id')
            ->order_by('name')
            ->select('parent_author_id')
            ->limit(9)
            ->offset(7)
            ->distinct(false)
            ->readonly(false)
            ->with('awesome_person')
            ->without('books');

        $this->assert_same($before, $this->snapshot($original));
        $this->assert_not_same($before, $this->snapshot($clone));
        $this->assert_same(['parent_author' => ['books'], 'awesome_person'], $clone->state()->includes);
        $this->assert_same(['books', 'parent_author' => ['books']], $original->state()->includes);
        $this->assert_true($original->state()->readonly);

        // the other way round: what is added to the original after the copy was made is not in the copy
        $copy = $original->clone();
        $copied = $this->snapshot($copy);
        $original->where('name', 'later')->or_where(fn(Query $q) => $q->where('author_id', 6))->with('awesome_person')->readonly(false)->limit(1);
        $this->assert_same($copied, $this->snapshot($copy));
    }

    public function test_changing_a_group_of_conditions_of_a_clone_leaves_the_original_alone(): void
    {
        $original = $this->rich_query();
        $before = $this->snapshot($original);
        $null = ['type' => 'null', 'boolean' => 'AND', 'column' => 'name', 'not' => false];

        // the conditions of the group in the WHERE
        $clone = $original->clone();
        $clone->state()->wheres[1]['group']->wheres[] = $null;
        $this->assert_same($before, $this->snapshot($original));
        $this->assert_sql_has('IS NULL', $clone->to_sql());

        // and of the group in that group
        $clone = $original->clone();
        $clone->state()->wheres[1]['group']->wheres[1]['group']->wheres[] = $null;
        $this->assert_same($before, $this->snapshot($original));
        $this->assert_sql_has('IS NULL', $clone->to_sql());

        // a value in them
        $clone = $original->clone();
        $clone->state()->wheres[1]['group']->wheres[1]['group']->wheres[0]['value'] = 12345;
        $this->assert_same($before, $this->snapshot($original));
        $this->assert_true(in_array(12345, $clone->get_bindings(), true));
        $this->assert_false(in_array(12345, $original->get_bindings(), true));

        // all of them taken out
        $clone = $original->clone();
        $clone->state()->wheres[1]['group']->wheres = [];
        $this->assert_same($before, $this->snapshot($original));

        // the other way round: a change to the groups of the original, after the copy was made
        $clone = $original->clone();
        $copied = $this->snapshot($clone);
        $original->state()->wheres[1]['group']->wheres[] = $null;
        $original->state()->wheres[1]['group']->wheres[1]['group']->wheres[0]['value'] = 54321;
        $this->assert_same($copied, $this->snapshot($clone));
        $this->assert_true(in_array(54321, $original->get_bindings(), true));
        $this->assert_false(in_array(54321, $clone->get_bindings(), true));
    }

    public function test_changing_a_join_a_having_or_the_includes_of_a_clone_leaves_the_original_alone(): void
    {
        $original = $this->rich_query();
        $before = $this->snapshot($original);

        // the conditions of a join, which a JoinClause wrote
        $clone = $original->clone();
        $clone->state()->joins[0]['wheres'][] = ['type' => 'null', 'boolean' => 'OR', 'column' => 'books.name', 'not' => true];
        $clone->state()->joins[0]['wheres'][1]['value'] = 'changed';
        $clone->state()->joins[0]['type'] = 'LEFT';
        $this->assert_same($before, $this->snapshot($original));
        $this->assert_sql_has('LEFT JOIN', $clone->to_sql());
        $this->assert_sql_doesnt_has('LEFT JOIN', $original->to_sql());
        $this->assert_true(in_array('changed', $clone->get_bindings(), true));

        // the HAVING
        $clone = $original->clone();
        $clone->state()->havings[0]['value'] = 777;
        $clone->state()->havings[] = ['type' => 'null', 'boolean' => 'AND', 'column' => 'name', 'not' => false];
        $this->assert_same($before, $this->snapshot($original));
        $this->assert_true(in_array(777, $clone->get_bindings(), true));

        // the includes, a nested one too
        $clone = $original->clone();
        $clone->state()->includes['parent_author'][] = 'awesome_person';
        $clone->state()->includes[] = 'other';
        $this->assert_same($before, $this->snapshot($original));
        $this->assert_same(['books', 'parent_author' => ['books', 'awesome_person'], 'other'], $clone->state()->includes);

        // the other way round
        $clone = $original->clone();
        $copied = $this->snapshot($clone);
        $original->state()->joins[0]['wheres'][1]['value'] = 'changed';
        $original->state()->havings[0]['value'] = 777;
        $original->state()->includes['parent_author'][] = 'awesome_person';
        $this->assert_same($copied, $this->snapshot($clone));
    }

    public function test_changing_the_plain_clauses_of_a_clone_leaves_the_original_alone(): void
    {
        $original = $this->rich_query();
        $before = $this->snapshot($original);
        $clone = $original->clone();

        $clone->state()->selects[] = ['column' => 'created_at', 'alias' => null];
        $clone->state()->orders[] = ['type' => 'random'];
        $clone->state()->groups[] = ['type' => 'raw', 'sql' => '1', 'bindings' => []];
        $clone->state()->limit = 99;
        $clone->state()->offset = 98;
        $clone->state()->distinct = false;
        $clone->state()->readonly = false;
        $clone->state()->from = 'authors';
        $clone->state()->from_alias = 'a';
        $this->assert_same($before, $this->snapshot($original));
        $this->assert_true($original->state()->readonly);
        $this->assert_same(5, $original->state()->limit);
        $this->assert_same(1, $original->state()->offset);
        $this->assert_null($original->state()->from);

        // a query that stands for a legacy options array keeps its own, too
        $legacy = Query::from_options(Author::table(), ['conditions' => ['name' => 'Tito'], 'limit' => 1]);
        $legacy_clone = $legacy->clone();
        $legacy_clone->state()->legacy['limit'] = 2;
        $this->assert_same(['conditions' => ['name' => 'Tito'], 'limit' => 1], $legacy->state()->legacy);
        $this->assert_same(['conditions' => ['name' => 'Tito'], 'limit' => 2], $legacy_clone->state()->legacy);
        $this->assert_same(1, count($legacy_clone->get()));
        $this->assert_same(1, count($legacy->get()));
    }

    // A clone is a query like any other: it runs, and each of the copies of one query goes its own way
    public function test_a_clone_runs_as_the_original_does(): void
    {
        $original = Author::where('author_id', '>', 1)
            ->where(fn(Query $q) => $q->where('name', 'George W. Bush')->or_where('name', 'Uncle Bob'))
            ->order_by('author_id')
            ->with('books');
        $clone = $original->clone();
        $clone->where('author_id', 2);

        $this->assert_same([2, 4], array_map(fn(Author $author) => $author->author_id, $original->get()));
        $this->assert_same([2], array_map(fn(Author $author) => $author->author_id, $clone->get()));
        $first = $original->first();
        $this->assert_same([], GoldenStatements::capture(fn() => $first->books));
        $this->assert_same(2, $original->count());
        $this->assert_same(1, $clone->count());

        $base = Author::where('author_id', '>', 0)->order_by('author_id');
        $ids = [];

        foreach ([1, 2, 3] as $limit) {
            $ids[] = array_map(fn(Author $author) => $author->author_id, $base->clone()->limit($limit)->get());
        }

        $this->assert_same([[1], [1, 2], [1, 2, 3]], $ids);
        $this->assert_null($base->state()->limit);
    }

    // The debug aid of spec §4.2: the SELECT with each value written in place of its marker, as the adapter binds it.
    // A string goes through Connection::escape(), and so does every other scalar that the adapter binds as text: on
    // MySQL, MariaDB and Postgres a number is '5' and a bool '1' or '0', on SQLite a float and a numeric string too; the
    // int that SQLite binds as an integer, and the bool that turns into one, are bare. null is NULL.
    public function test_to_raw_sql_writes_each_value_in_place_of_its_marker(): void
    {
        $this->assert_same(
            $this->sql('SELECT * FROM `authors` WHERE `name` = ') . $this->quoted("O'Neil") . $this->sql(' AND `author_id` > ') . $this->bound(2),
            Author::where('name', "O'Neil")->where('author_id', '>', 2)->to_raw_sql()
        );
        $this->assert_same($this->sql('SELECT * FROM `authors`'), Author::new_query()->to_raw_sql());
        $this->assert_same(Author::where('name', 'Tito')->to_sql(), str_replace($this->quoted('Tito'), '?', Author::where('name', 'Tito')->to_raw_sql()));

        // an int and a float, a numeric string, null, true and false, each as it is bound
        $this->assert_same(
            $this->sql('SELECT * FROM `authors` WHERE author_id + ') . $this->bound(1) . ' > ' . $this->bound(2.5) . ' AND NULL IS NULL AND COALESCE(NULL, 1) = '
                . $this->bound('3') . ' AND ' . $this->bound(true) . ' = ' . $this->bound(false),
            Author::where_raw('author_id + ? > ? AND ? IS NULL AND COALESCE(?, 1) = ? AND ? = ?', [1, 2.5, null, null, '3', true, false])->to_raw_sql()
        );
        $this->assert_same(
            $this->sql('SELECT ') . $this->bound(1.0e25) . ', ' . $this->bound(-7) . ', ' . $this->bound(-0.5) . $this->sql(' FROM `authors`'),
            Author::select_raw('?, ?, ?', [1.0e25, -7, -0.5])->to_raw_sql()
        );
        $this->assert_same(
            $this->sql('SELECT ') . $this->bound(PHP_INT_MAX) . ', ' . $this->bound(PHP_INT_MIN) . $this->sql(' FROM `authors`'),
            Author::select_raw('?, ?', [PHP_INT_MAX, PHP_INT_MIN])->to_raw_sql()
        );
        // true, false, zero and the empty string are written, none of them as NULL or nothing
        $this->assert_same(
            $this->sql('SELECT ') . implode(', ', [$this->bound(true), $this->bound(false), $this->bound(0), $this->bound(0.0), $this->bound('')]) . $this->sql(' FROM `authors`'),
            Author::select_raw('?, ?, ?, ?, ?', [true, false, 0, 0.0, ''])->to_raw_sql()
        );
        // a float as PHP writes it, which is how PDO binds it (the digits that the precision setting keeps)
        $this->assert_same($this->sql('SELECT ') . $this->bound(0.1 + 0.2) . $this->sql(' FROM `authors`'), Author::select_raw('?', [0.1 + 0.2])->to_raw_sql());
        // a float that is no number is written as its name, in a string
        $this->assert_same(
            $this->sql('SELECT ') . $this->quoted('INF') . ', ' . $this->quoted('-INF') . ', ' . $this->quoted('NAN') . $this->sql(' FROM `authors`'),
            Author::select_raw('?, ?, ?', [INF, -INF, NAN])->to_raw_sql()
        );
        // an object that has a text is that text, a string, and no other object is a value
        $text = new class {
            public function __toString(): string
            {
                return "a'b";
            }
        };
        $this->assert_same($this->sql('SELECT ') . $this->quoted("a'b") . $this->sql(' FROM `authors`'), Author::select_raw('?', [$text])->to_raw_sql());
        $this->assert_same([ActiveRecordException::class, 'to_raw_sql() cannot write a stdClass as SQL, in Author'], $this->thrown(fn() => Author::select_raw('?', [new stdClass()])->to_raw_sql()));
    }

    // The ruling: the markers are replaced one by one, in order, and what is written in place of one is never read
    // again, so a ? or a quote in a value is the value's, and the markers after it still get their own values
    public function test_a_question_mark_in_a_value_is_never_read_as_a_marker(): void
    {
        $this->assert_same(
            $this->sql('SELECT * FROM `authors` WHERE `name` = ') . $this->quoted("what? it's ?") . $this->sql(' AND `author_id` = ') . $this->bound(3) . $this->sql(' AND `parent_author_id` = ') . $this->bound(1),
            Author::where('name', "what? it's ?")->where('author_id', 3)->where('parent_author_id', 1)->to_raw_sql()
        );
        $this->assert_same(
            $this->sql('SELECT * FROM `authors` WHERE `name` IN(') . $this->quoted('?') . ',' . $this->quoted("??'?") . $this->sql(') AND `author_id` = ') . $this->bound(4),
            Author::where_in('name', ['?', "??'?"])->where('author_id', 4)->to_raw_sql()
        );
        // a value that holds a marker, and what would end the statement, is still a value
        $this->assert_same(
            $this->sql('SELECT * FROM `authors` WHERE `name` = ') . $this->quoted('? OR 1=1 --') . $this->sql(' AND `some_text` = ') . $this->quoted('x?'),
            Author::where('name', '? OR 1=1 --')->where('some_text', 'x?')->to_raw_sql()
        );

        // the values go to the markers in the order of the clauses, whatever the order of the calls
        $raw = Author::order_by_raw('CASE WHEN name = ? THEN 0 ELSE 1 END', ['O?'])
            ->having_raw('MAX(author_id) > ?', ['H?'])
            ->group_by('author_id')
            ->where('name', 'W?')
            ->join('books', fn(JoinClause $join) => $join->on('books.author_id', '=', 'authors.author_id')->where('books.name', 'J?'))
            ->select_raw('? AS tag', ['S?'])
            ->to_raw_sql();
        $positions = array_map(fn(string $value): int|false => strpos($raw, $this->quoted($value)), ['S?', 'J?', 'W?', 'H?', 'O?']);
        $this->assert_false(in_array(false, $positions, true));
        $sorted = $positions;
        sort($sorted);
        $this->assert_same($sorted, $positions);
    }

    // The markers are those PDO reads (a ? outside a quoted string and a comment): a ? in a string or a comment of
    // SQL written by hand is no marker and takes no value, and ?? is a ? of the statement, as PDO has the operator
    // of Postgres written
    public function test_a_question_mark_in_the_sql_is_a_marker_only_where_pdo_reads_one(): void
    {
        $select = $this->sql('SELECT * FROM `authors` WHERE ');
        $three = $this->bound(3);

        $this->assert_same($select . "name <> 'what?' AND author_id = " . $three, Author::where_raw("name <> 'what?' AND author_id = ?", [3])->to_raw_sql());
        $this->assert_same($select . "name <> 'it''s?' AND author_id = " . $three, Author::where_raw("name <> 'it''s?' AND author_id = ?", [3])->to_raw_sql());
        $this->assert_same($select . "name <> 'it\\'s?' AND author_id = " . $three, Author::where_raw("name <> 'it\\'s?' AND author_id = ?", [3])->to_raw_sql());
        $this->assert_same($select . 'name <> "what?" AND author_id = ' . $three, Author::where_raw('name <> "what?" AND author_id = ?', [3])->to_raw_sql());
        $this->assert_same($select . 'author_id = /* which? */ ' . $three . " -- really?\n", Author::where_raw("author_id = /* which? */ ? -- really?\n", [3])->to_raw_sql());
        $this->assert_same($select . 'name ? ' . $this->quoted('x'), Author::where_raw('name ?? ?', ['x'])->to_raw_sql());
        $this->assert_same($select . 'name = ' . $this->quoted('x') . ' AND x ? y', Author::where_raw('name = ? AND x ?? y', ['x'])->to_raw_sql());
        // an opening quote that is never closed is no string
        $this->assert_same($select . "name = 'x AND author_id = " . $three, Author::where_raw("name = 'x AND author_id = ?", [3])->to_raw_sql());
        // a minus and a slash that open no comment are what they are
        $this->assert_same($select . 'author_id - 1 / ' . $three . ' = ' . $three, Author::where_raw('author_id - 1 / ? = ?', [3, 3])->to_raw_sql());
    }

    // A statement whose markers and values do not match cannot be written, and the error says so rather than guess
    public function test_to_raw_sql_refuses_markers_and_values_that_do_not_match(): void
    {
        $this->assert_same(
            [ActiveRecordException::class, 'to_raw_sql() found 1 bind markers in the SELECT of Author, for 0 values'],
            $this->thrown(fn() => Author::where_raw('author_id = ?')->to_raw_sql())
        );
        $this->assert_same(
            [ActiveRecordException::class, 'to_raw_sql() found 0 bind markers in the SELECT of Author, for 1 values'],
            $this->thrown(fn() => Author::where_raw('author_id = 1', [1])->to_raw_sql())
        );
        $this->assert_same(
            [ActiveRecordException::class, 'to_raw_sql() found 2 bind markers in the SELECT of Author, for 1 values'],
            $this->thrown(fn() => Author::where_raw('author_id = ? OR author_id = ?', [1])->to_raw_sql())
        );
    }

    // A date and time is written as it is bound: in the format of the column it is compared with, a date for a DATE
    // column, and as a date and time when no column is known (the values of SQL written by hand, a qualified name)
    public function test_to_raw_sql_writes_dates_as_the_builder_binds_them(): void
    {
        $day = new DateTime('2026-01-02 10:00:00');
        $date_and_time = $this->quoted($this->conn->datetime_to_string($day));
        $date = $this->quoted('2026-01-02');

        $this->assert_same($this->sql('SELECT * FROM `dated_counts` WHERE `day` = ') . $date, DatedCount::where('day', $day)->to_raw_sql());
        $this->assert_same($this->sql('SELECT * FROM `dated_counts` WHERE `at` = ') . $date_and_time, DatedCount::where('at', $day)->to_raw_sql());
        $this->assert_same($this->sql('SELECT * FROM `dated_counts` WHERE `day` BETWEEN ') . $date . ' AND ' . $this->quoted('2026-01-03'), DatedCount::where_between('day', [$day, new DateTime('2026-01-03')])->to_raw_sql());
        $this->assert_same($this->sql('SELECT * FROM `dated_counts` WHERE hits > 0 AND seen_at < ') . $date_and_time, DatedCount::where_raw('hits > 0 AND seen_at < ?', [$day])->to_raw_sql());
        $this->assert_same($this->sql('SELECT * FROM `dated_counts` WHERE `dated_counts`.`day` = ') . $date_and_time, DatedCount::where('dated_counts.day', $day)->to_raw_sql());
        $this->assert_same(
            $this->sql('SELECT * FROM `dated_counts` WHERE `day` = ') . $date . $this->sql(' AND `at` = ') . $date_and_time,
            DatedCount::where('day', $day)->where('at', new DateTimeImmutable('2026-01-02 10:00:00'))->to_raw_sql()
        );
        $this->assert_same($this->sql('SELECT * FROM `dated_counts` WHERE `day` = ') . $date, DatedCount::where('day', new ActiveRecord\DateTime('2026-01-02'))->to_raw_sql());
    }

    // Run, the SQL that to_raw_sql() writes finds the rows that the query finds: the values are written as the
    // database reads them (quotes, backslashes, a ?, a date), on every adapter. A bool, a number against a text
    // column and a number against an integer column are among them: Postgres refuses them bare ('boolean =
    // integer', 'character varying = integer') and takes the quoted text that the library binds for them.
    public function test_the_raw_sql_finds_the_rows_the_query_finds(): void
    {
        $tricky = "O'Neil? \\ \"q\" %_";
        // an id of its own: the hosts of the SQLite schema have no autoincrement
        $host = Host::create(['id' => 50, 'name' => $tricky]);
        $day = new DateTime('2026-01-02 10:00:00');

        // each query, the attribute that tells its rows apart, and whether it finds any (it must run either way)
        $queries = [
            [Host::where('name', $tricky), 'id', true],
            [Host::where_in('name', [$tricky, 'Funny Guy', "x'y"]), 'id', true],
            [Host::where('name', '!=', $tricky)->where('name', 'like', 'Funny%'), 'id', true],
            [Host::where_like('name', "O'Neil?%")->where_not_null('name'), 'id', true],
            [Host::where_like('name', "O'Neil?%", true)->where_not_like('name', 'x%', true), 'id', true],
            [Host::where_raw('name = ? AND id = ?', [$tricky, $host->id]), 'id', true],
            [Host::where_raw('name = COALESCE(?, name)', [null]), 'id', true],
            [Host::where_not_in('id', [1, 2, 3])->where_between('id', [1, $host->id]), 'id', true],
            // the title the fixture stores holds a backslash before each double quote
            [Event::where('title', '1320 Records Presents A \"Live PA Set\" By STS9 with'), 'id', true],
            [DatedCount::where('day', $day), 'hits', true],
            [DatedCount::where('at', $day), 'hits', true],
            [DatedCount::where_between('day', [new DateTime('2026-01-01'), $day]), 'hits', true],
            [DatedCount::where_raw('seen_at IS NULL AND hits >= ?', [2]), 'hits', true],
            [Author::where('author_id', '>', 1)->where(fn(Query $q) => $q->where('name', 'George W. Bush')->or_where('name', 'Uncle Bob'))->limit(5), 'author_id', true],
            [Author::where_any(['name', 'some_text'], 'Tito')->where_key([1, 2, 99]), 'author_id', true],
            // a bool against a boolean column: the venues are available, and none is retired
            [Venue::where('is_available', true), 'id', true],
            [Venue::where('is_available', false), 'id', false],
            [Venue::where('is_retired', false), 'id', true],
            [Venue::where('is_retired', true), 'id', false],
            // a number against a text column, which finds nothing (the names are no numbers)
            [Author::where('name', 5), 'author_id', false],
            [Author::where_in('name', [5, 6.5, true]), 'author_id', false],
            // a number against an integer column, and a float against a decimal one
            [Author::where('author_id', 2), 'author_id', true],
            [Author::where('author_id', '>', 1)->where_in('author_id', [2, 3, 99])->where_between('author_id', [1, 3]), 'author_id', true],
            [Book::where('special', 0.0), 'book_id', true],
            [Book::where('special', 0.5), 'book_id', false],
            // a comparison that names no column, where SQLite tells the integer it binds from the text it would be
            // quoted as: a function of a column, a number against a number, an aggregate in a HAVING
            [Author::where_raw('LENGTH(name) = ?', [14]), 'author_id', true],
            [Author::where_raw('? = 1', [1]), 'author_id', true],
            [Author::where_raw('? = 1', [true]), 'author_id', true],
            [Author::select('parent_author_id')->group_by('parent_author_id')->having_raw('COUNT(*) > ?', [1]), 'parent_author_id', true],
            [Author::select('parent_author_id')->group_by('parent_author_id')->having_raw('COUNT(*) > ?', [0]), 'parent_author_id', true],
        ];

        foreach ($queries as $i => [$query, $key, $finds]) {
            $found = array_map(fn(Model $model) => (string) $model->$key, $query->get());
            $this->assert_same($finds, [] !== $found, "the query $i " . ($finds ? 'finds a row' : 'finds none'));

            $sth = $this->conn->query($query->to_raw_sql());
            $ran = [];

            while (is_array($row = $sth->fetch(PDO::FETCH_ASSOC))) {
                $ran[] = (string) $row[$key];
            }

            sort($found);
            sort($ran);
            $this->assert_same($found, $ran, "the query $i: " . $query->to_raw_sql());
        }
    }

    // What the SELECT binds is what to_raw_sql() writes, in the order of get_bindings(), and it reads the query
    // without changing it or sending a statement
    public function test_to_raw_sql_follows_the_bindings_of_the_query(): void
    {
        $query = $this->rich_query();

        // each marker of to_sql() taken in turn, the values none of which holds a ? or is null
        $expected = $query->to_sql();
        $from = 0;

        foreach ($query->get_bindings() as $value) {
            $literal = $this->bound($value);
            $at = strpos($expected, '?', $from);
            $this->assert_true(false !== $at);
            $expected = substr_replace($expected, $literal, $at, 1);
            $from = $at + strlen($literal);
        }

        $this->assert_same(false, strpos($expected, '?', $from));
        $this->assert_same($expected, $query->to_raw_sql());

        $before = serialize($query->state());
        $this->assert_same([], GoldenStatements::capture(fn() => $query->to_raw_sql()));
        $this->assert_same($before, serialize($query->state()));

        // a query that does not compile does not here either
        $this->assert_same([ActiveRecordException::class, "Unknown operator 'nope' in where() on Author"], $this->thrown(fn() => Author::where('name', 'nope', 'x')->to_raw_sql()));
    }
}
