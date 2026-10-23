<?php

use ActiveRecord\Query;
use ActiveRecord\Table;
use ActiveRecord\Timestamps;

/**
 * The Query engine behind the legacy entries: Table::options_to_sql() builds its SELECT through
 * Query::from_options(), find(), exists() and count() run it there, and so do the INSERTs, UPDATEs
 * and DELETEs of Table, update_all() and delete_all(). The statements every entry sends are pinned
 * in GoldenSqlTest.
 */
class QueryEngineTest extends DatabaseTest
{
    private const string FROZEN = '2026-10-21 10:00:00';

    private const string GIVEN = '2001-02-03 04:05:06';

    public function test_options_to_sql_is_served_by_the_engine(): void
    {
        $options = ['conditions' => ['name' => 'Tito'], 'order' => 'name', 'limit' => 1];
        $engine = Query::from_options(Author::table(), $options)->to_sql_builder();

        $this->assert_same(Author::table()->options_to_sql($options)->to_s(), $engine->to_s());
        $this->assert_sql_has('WHERE name=?', $engine->to_s());
        $this->assert_same(['Tito'], $engine->get_where_values());
    }

    public function test_from_options_keeps_the_options_exactly_as_given(): void
    {
        $options = ['conditions' => ['name' => 'Tito'], 'joins' => ['books'], 'mapped_names' => [], 'order' => 'name'];

        $this->assert_same($options, Query::from_options(Author::table(), $options)->state()->legacy);
    }

    // QueryCompiler::compile_legacy() rewrites the options it is given (a string of conditions
    // becomes a list, joins add a select) and hands the conditions to Table::map_names() by
    // reference. It does so on its own copy: the query still holds what it was given, and every
    // compile is a new SQLBuilder.
    public function test_compiling_leaves_the_legacy_options_untouched(): void
    {
        foreach ([
            ['conditions' => ['name' => 'Tito']],
            ['conditions' => "name = 'Tito'"],
            ['joins' => ['books'], 'conditions' => ['name' => 'Tito']],
        ] as $options) {
            $query = Query::from_options(Author::table(), $options);

            $first = $query->to_sql_builder();
            $second = $query->to_sql_builder();

            $this->assert_not_same($first, $second);
            $this->assert_same($first->to_s(), $second->to_s());
            $this->assert_same($options, $query->state()->legacy);
        }
    }

    public function test_a_query_without_options_selects_every_row(): void
    {
        $builder = (new Query(Author::class))->to_sql_builder();

        $this->assert_sql_has('SELECT * FROM authors', $builder->to_s());
        $this->assert_same([], $builder->get_where_values());
    }

    public function test_legacy_count_is_served_by_the_engine(): void
    {
        $this->assert_same(1, Author::count(['conditions' => ['name' => 'Tito']]));
        $this->assert_true(method_exists(Query::class, 'legacy_count'));
    }

    public function test_legacy_count_leaves_last_sql_alone(): void
    {
        $table = Author::table();
        $table->last_sql = 'untouched';

        $this->assert_same(4, (int) Author::count());
        $this->assert_same('untouched', $table->last_sql);
    }

    // Review Focus 1: a subclass overriding a legacy finder keeps its override.
    // OverridingAuthor extends Author and overrides all() to add ['conditions' => ['author_id' => 1]]
    // when no conditions are given (as the monolith base model injects deleted_at).
    public function test_legacy_finder_override_still_runs(): void
    {
        $this->assert_same([1], array_map(fn($a) => $a->author_id, OverridingAuthor::all()));
    }

    // Spec §6.3: Model::connection() is a public static that a subclass can override, and count()
    // has always run its statement on static::connection().
    public function test_count_still_calls_the_connection_override(): void
    {
        ConnectionCountingAuthor::$connection_calls = 0;

        $this->assert_same(4, (int) ConnectionCountingAuthor::count());
        $this->assert_greater_than_or_equal(1, ConnectionCountingAuthor::$connection_calls);
    }

    public function test_get_hydrates_the_models_of_a_legacy_state(): void
    {
        $table = Author::table();
        $models = Query::from_options($table, ['conditions' => ['author_id' => [2, 3]], 'order' => 'author_id'])->get();

        $this->assert_same([2, 3], array_map(fn($author) => $author->author_id, $models));
        $this->assert_instance_of(Author::class, $models[0]);
        // through the Table the query was given: it holds the statement that ran
        $this->assert_sql_has('WHERE author_id IN(?,?)', $table->last_sql);
    }

    public function test_get_reads_readonly_from_the_legacy_options(): void
    {
        $table = Author::table();
        [$writable] = Query::from_options($table, ['conditions' => ['author_id' => 1]])->get();
        [$readonly] = Query::from_options($table, ['conditions' => ['author_id' => 1], 'readonly' => true])->get();

        $this->assert_false($writable->is_readonly());
        $this->assert_true($readonly->is_readonly());
    }

    public function test_get_eager_loads_the_include_of_the_legacy_options(): void
    {
        $table = Author::table();
        Book::table();
        [$author] = Query::from_options($table, ['conditions' => ['author_id' => 1], 'include' => ['books']])->get();

        // the books came with the author: reading them sends no statement
        $this->assert_same([], GoldenStatements::capture(fn() => $author->books));
        $this->assert_same([1], array_map(fn($book) => $book->book_id, $author->books));
    }

    public function test_exists_runs_the_adapters_exists_statement(): void
    {
        $table = Author::table();
        $found = Query::from_options($table, ['select' => '1', 'conditions' => ['name' => 'Tito']]);

        $this->assert_true($found->exists());
        $this->assert_same($table->connection()->exists_sql($found->to_sql_builder()->to_s()), $table->last_sql);
        $this->assert_false(Query::from_options($table, ['select' => '1', 'conditions' => ['name' => 'Nobody']])->exists());
    }

    public function test_the_terminals_run_through_the_table_the_query_was_given(): void
    {
        // a relationship names its target class with a leading backslash, under which Table keeps
        // another instance of the model's table
        $given = Table::load('\\Author');
        $this->assert_not_same(Author::table(), $given);

        Query::from_options($given, ['conditions' => ['author_id' => 1]])->get();
        $this->assert_sql_has('WHERE author_id=?', $given->last_sql);

        Query::from_options($given, ['select' => '1'])->exists();
        $this->assert_sql_has('EXISTS(', $given->last_sql);

        $this->assert_null(Author::table()->last_sql);
    }

    // Spec §6.2.1: only find() read an alias_attribute name; count() and exists() read it too
    public function test_count_and_exists_resolve_aliases_like_find(): void
    {
        $this->assert_same(1, Venue::count(['conditions' => ['marquee' => 'Warner Theatre']]));
        $this->assert_true(Venue::exists(['conditions' => ['marquee' => 'Warner Theatre']]));
    }

    public function test_the_models_aliases_are_the_default_and_explicit_names_win(): void
    {
        $table = Venue::table();

        // no 'mapped_names': the model's $alias_attribute
        $this->assert_sql_has('WHERE name=?', $table->options_to_sql(['conditions' => ['marquee' => 'x']])->to_s());
        // given, they replace it: an empty map reads no alias, another maps as it says
        $this->assert_sql_has('WHERE marquee=?', $table->options_to_sql(['conditions' => ['marquee' => 'x'], 'mapped_names' => []])->to_s());
        $this->assert_sql_has('WHERE city=?', $table->options_to_sql(['conditions' => ['marquee' => 'x'], 'mapped_names' => ['marquee' => 'city']])->to_s());
    }

    // An alias named like the pk column must not move the pk: a finder names the pk as the column
    // itself, whichever alias default the conditions are read with.
    public function test_a_pk_is_never_read_through_an_alias(): void
    {
        // author_id is an alias of name in this model: 1 Tito, 2 George W. Bush
        $this->assert_same('Tito', AliasedPkAuthor::find(1)->name);
        $names = array_map(fn($author) => $author->name, AliasedPkAuthor::find(1, 2));
        sort($names);
        $this->assert_same(['George W. Bush', 'Tito'], $names);
        $this->assert_same(1, (int) AliasedPkAuthor::count(1));
        $this->assert_true(AliasedPkAuthor::exists(1));

        // a hash left before an options hash is no conditions: the first argument alone is the pk
        $this->assert_same(1, (int) AliasedPkAuthor::count(1, ['name' => 'nobody'], ['readonly' => true]));
        $this->assert_true(AliasedPkAuthor::exists(1, ['name' => 'nobody'], ['readonly' => true]));

        // the conditions of the call do read the alias, beside the pk
        $this->assert_same(1, (int) AliasedPkAuthor::count(1, ['conditions' => ['author_id' => 'Tito']]));
        $this->assert_same(0, (int) AliasedPkAuthor::count(2, ['conditions' => ['author_id' => 'Tito']]));
        $this->assert_same(1, (int) AliasedPkAuthor::count(['author_id' => 'Tito']));
        $this->assert_same('Tito', AliasedPkAuthor::find(1, ['conditions' => ['author_id' => 'Tito']])->name);
    }

    // Spec §6.2.4: with joins, a hash key is qualified with the model's table only when it names
    // one of the model's columns. parent_author_id is a column of the joined authors table.
    public function test_joins_qualify_only_model_columns(): void
    {
        $books = Book::all(['joins' => ['author'], 'conditions' => ['name' => 'Ancient Art of Main Tanking', 'authors.name' => 'Tito', 'parent_author_id' => 3]]);
        $this->assert_same([1], array_map(fn($b) => $b->book_id, $books));
        $this->assert_sql_has('books.name', Book::table()->last_sql);
        $this->assert_sql_doesnt_has('books.parent_author_id', Book::table()->last_sql);
    }

    // The model's own columns keep its table whatever the spelling of the key, so that a call that
    // works today sends the same SQL: authors has an author_id column too, and a key left
    // unqualified would be ambiguous. MySQL, MariaDB and SQLite name Book's column Author_Id and
    // match names regardless of case; a key may also come pre-quoted.
    public function test_joins_qualify_a_model_column_spelled_another_way(): void
    {
        $keys = ['author_id', $this->conn->quote_name('author_id')];

        if (!$this->conn->column_names_are_case_sensitive()) {
            $keys[] = 'AUTHOR_ID';
        }

        foreach ($keys as $key) {
            $books = Book::all(['joins' => ['author'], 'conditions' => [$key => 1]]);

            $this->assert_same([1], array_map(fn($b) => $b->book_id, $books), $key);
            $this->assert_sql_has("WHERE books.$key=?", Book::table()->last_sql);
        }
    }

    // An alias_attribute key is read as the column it names before its key is qualified
    public function test_joins_qualify_the_column_of_an_alias(): void
    {
        Venue::all(['joins' => ['events'], 'conditions' => ['marquee' => 'Warner Theatre']]);

        $this->assert_sql_has('WHERE venues.name=?', Venue::table()->last_sql);
    }

    // A 'from' names another table than the model's, whose columns the model does not know: every
    // key keeps naming it, as it always did
    public function test_joins_with_a_from_qualify_every_key_with_it(): void
    {
        $sql = Book::table()->options_to_sql([
            'from' => 'authors',
            'joins' => 'INNER JOIN books ON(books.author_id = authors.author_id)',
            'conditions' => ['parent_author_id' => 3, 'name' => 'Tito'],
        ]);

        $this->assert_sql_has('WHERE authors.parent_author_id=? AND authors.name=?', $sql->to_s());
    }

    // -- inserts (spec §6.2.3)

    /**
     * The timestamps of the authors row named $name, as strings (null where the column is).
     *
     * @return array{created_at: ?string, updated_at: ?string}
     */
    private function stamps_of(string $name): array
    {
        $author = Author::find_by_name($name);

        return [
            'created_at' => $author->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $author->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function test_direct_table_insert_fills_timestamps(): void
    {
        Timestamps::freeze('2026-10-21 10:00:00');
        $data = ['name' => 'Direct'];
        Author::table()->insert($data);
        $row = Author::find_by_name('Direct');
        $this->assert_equals('2026-10-21 10:00:00', $row->created_at->format('Y-m-d H:i:s'));
        $this->assert_equals('2026-10-21 10:00:00', $row->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_insert_rows_renders_multi_values(): void
    {
        $sql = (new ActiveRecord\SQLBuilder(Author::connection(), 'authors'))->insert_rows(['name', 'parent_author_id'], 2);
        $this->assert_sql_has('INSERT INTO authors (name, parent_author_id) VALUES (?, ?), (?, ?)', $sql->to_s());
    }

    public function test_insert_rows_quotes_the_columns_and_renders_as_many_rows_as_it_is_given(): void
    {
        $conn = Author::connection();
        $columns = '(' . $conn->quote_name('name') . ', ' . $conn->quote_name('parent_author_id') . ')';

        $this->assert_same("INSERT INTO authors $columns VALUES (?, ?)", (new ActiveRecord\SQLBuilder($conn, 'authors'))->insert_rows(['name', 'parent_author_id'], 1)->to_s());
        $this->assert_same("INSERT INTO authors $columns VALUES (?, ?), (?, ?), (?, ?)", (new ActiveRecord\SQLBuilder($conn, 'authors'))->insert_rows(['name', 'parent_author_id'], 3)->to_s());
    }

    // the VALUES list of an upsert is the one of a multi-row insert: the upsert only ends with its conflict clause
    public function test_an_upsert_starts_with_the_statement_of_a_multi_row_insert(): void
    {
        $conn = Author::connection();
        $insert = (new ActiveRecord\SQLBuilder($conn, 'authors'))->insert_rows(['author_id', 'name'], 2)->to_s();
        $upsert = (new ActiveRecord\SQLBuilder($conn, 'authors'))->upsert(['author_id', 'name'], 2, ['author_id'], ['name'])->to_s();

        $this->assert_same($insert . ' ' . $conn->upsert_conflict_clause(['author_id'], ['name']), $upsert);
    }

    public function test_execute_insert_applies_the_timestamp_rule_unless_it_is_told_not_to(): void
    {
        Timestamps::freeze(self::FROZEN);
        $query = Query::for_table(Author::table());

        $query->execute_insert(['name' => 'Ruled']);
        $query->execute_insert(['name' => 'Unruled'], timestamps: false);

        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => self::FROZEN], $this->stamps_of('Ruled'));
        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps_of('Unruled'));
    }

    // The engine prepares the values of its row itself, as the finders prepare their conditions: a
    // caller of the engine does not come through Table::run_insert(), which formats $data for its own caller
    public function test_execute_insert_formats_the_dates_of_its_row(): void
    {
        $when = new DateTime(self::GIVEN);

        Query::for_table(Author::table())->execute_insert(['name' => 'Dated', 'created_at' => $when], timestamps: false);

        $this->assert_same(self::GIVEN, Author::find_by_name('Dated')->created_at->format('Y-m-d H:i:s'));
    }

    // The pk column a sequence feeds and the column an INSERT returns are named as the columns (#92):
    // space_name is the column "space name", and some_day is "some day", on every adapter
    public function test_compile_insert_names_the_pk_and_the_returned_column_as_the_columns(): void
    {
        $sql = (new ActiveRecord\QueryCompiler(OddColumn::table()))->compile_insert(['dash-name' => 'x'], 'space_name', 'an_id_seq', 'some_day')->to_s();

        $this->assert_sql_has('INSERT INTO odd_columns(dash-name,space name) VALUES(?,', $sql);
        $this->assert_sql_has(' RETURNING some day', $sql);
    }

    public function test_a_direct_insert_returns_its_statement_and_leaves_it_in_last_sql(): void
    {
        $table = Author::table();
        $data = ['name' => 'Statement'];

        $statement = $table->insert($data);

        $this->assert_instance_of(PDOStatement::class, $statement);
        $this->assert_same(1, $statement->rowCount());
        // created_at before updated_at, after the row's own columns
        $this->assert_sql_has('INSERT INTO authors(name,created_at,updated_at) VALUES(?,?,?)', $table->last_sql);
    }

    public function test_a_direct_insert_keeps_the_timestamps_the_row_gives(): void
    {
        Timestamps::freeze(self::FROZEN);

        $given = ['name' => 'Given', 'created_at' => self::GIVEN];
        Author::table()->insert($given);
        // a null the caller gave is a value too
        $nulled = ['name' => 'Nulled', 'updated_at' => null];
        Author::table()->insert($nulled);

        $this->assert_same(['created_at' => self::GIVEN, 'updated_at' => self::FROZEN], $this->stamps_of('Given'));
        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => null], $this->stamps_of('Nulled'));
    }

    public function test_a_direct_insert_into_a_table_without_timestamps_sends_what_it_always_did(): void
    {
        $table = Book::table();
        $data = ['name' => 'Plain'];

        $table->insert($data);

        $this->assert_sql_has('INSERT INTO books(name) VALUES(?)', $table->last_sql);
    }

    // Table::insert() takes its data by reference. The caller's array comes back as it always did: the
    // values as they were sent (a DateTime as the string the database takes), under the names it gave.
    // The timestamps the engine adds go to the statement, not to the array.
    public function test_a_direct_insert_leaves_the_callers_data_as_it_was_sent(): void
    {
        Timestamps::freeze(self::FROZEN);
        $when = new DateTime(self::GIVEN);
        $data = ['name' => 'By ref', 'created_at' => $when];

        Author::table()->insert($data);

        $this->assert_same(['name' => 'By ref', 'created_at' => $this->conn->datetime_to_string($when)], $data);
        $this->assert_same(['created_at' => self::GIVEN, 'updated_at' => self::FROZEN], $this->stamps_of('By ref'));
    }

    // Anything but a hash is refused as it always was, and an empty row is not turned into one by the
    // timestamps it would get
    public function test_a_direct_insert_of_something_that_is_no_hash_is_refused(): void
    {
        foreach ([[], [1, 2], null] as $data) {
            $this->assert_exception_message_contains('Inserting requires a hash.', function () use ($data): void {
                Author::table()->insert($data);
            }, ActiveRecord\ActiveRecordException::class);
        }

        $this->assert_exception_message_contains('Inserting requires a hash.', fn() => Query::for_table(Author::table())->execute_insert([]), ActiveRecord\ActiveRecordException::class);
        $this->assert_same(4, (int) Author::count());
    }

    // #92: the timestamp columns are found by their attribute names ("created at" is created_at), and
    // a row that names one by its attribute name does not get it twice
    public function test_a_direct_insert_names_the_timestamp_columns_of_an_odd_table(): void
    {
        Timestamps::freeze(self::FROZEN);
        $table = OddTimestamp::table();

        $plain = ['name' => 'plain'];
        $table->insert($plain);
        $named = ['name' => 'named', 'created_at' => self::GIVEN, 'updated_at' => self::GIVEN];
        $table->insert($named);

        $odd = OddTimestamp::find_by_name('plain');
        $this->assert_same([self::FROZEN, self::FROZEN], [$odd->created_at->format('Y-m-d H:i:s'), $odd->updated_at->format('Y-m-d H:i:s')]);
        $odd = OddTimestamp::find_by_name('named');
        $this->assert_same([self::GIVEN, self::GIVEN], [$odd->created_at->format('Y-m-d H:i:s'), $odd->updated_at->format('Y-m-d H:i:s')]);
    }

    // like the terminals that read, an insert runs through the Table its query was given
    public function test_an_insert_runs_through_the_table_the_query_was_given(): void
    {
        $given = Table::load('\\Author');
        $this->assert_not_same(Author::table(), $given);
        $data = ['name' => 'Given'];

        $given->insert($data);

        $this->assert_sql_has('INSERT INTO authors', $given->last_sql);
        $this->assert_null(Author::table()->last_sql);
    }

    // A save() sets its timestamps through the model's set_timestamps(), which a model can override
    // to opt out: it keeps saving without them. The rule of the engine is for the rows that bypass the
    // model, so a direct insert into the same table gets them.
    public function test_a_save_leaves_its_timestamps_to_the_model_and_a_direct_insert_gets_them(): void
    {
        Timestamps::freeze(self::FROZEN);

        $quiet = OptOutAuthor::create(['name' => 'Quiet']);
        $data = ['name' => 'Direct'];
        OptOutAuthor::table()->insert($data);

        $saved = Author::find($quiet->author_id);
        $this->assert_same([null, null], [$saved->created_at, $saved->updated_at]);
        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => self::FROZEN], $this->stamps_of('Direct'));
    }

    public function test_insert_for_save_leaves_the_timestamps_to_the_model(): void
    {
        Timestamps::freeze(self::FROZEN);
        $table = Author::table();
        $data = ['name' => 'Saved'];

        $table->insert_for_save($data);

        $this->assert_sql_has('INSERT INTO authors(name) VALUES(?)', $table->last_sql);
        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps_of('Saved'));
    }

    // A save() keeps the columns it always sent, in the order the model sets them
    public function test_a_save_sends_the_timestamps_of_the_model(): void
    {
        Timestamps::freeze(self::FROZEN);

        Author::create(['name' => 'Saved']);

        $this->assert_sql_has('INSERT INTO authors(name,updated_at,created_at) VALUES(?,?,?)', Author::table()->last_sql);
    }

    // The rule is that of the class a table was loaded for, not of a name its caller gives: Author's
    // suspension covers a direct insert through its table, whichever way the table was loaded ('\Author'
    // is another instance of it), and leaves another model of the same table alone
    public function test_a_direct_insert_follows_the_suspension_of_the_class_of_its_table(): void
    {
        Timestamps::freeze(self::FROZEN);

        Author::without_timestamps(function (): void {
            $quiet = ['name' => 'Quiet'];
            Author::table()->insert($quiet);
            $quiet_too = ['name' => 'Quiet too'];
            Table::load('\\Author')->insert($quiet_too);
            $loud = ['name' => 'Loud'];
            JoinAuthor::table()->insert($loud);
        });

        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps_of('Quiet'));
        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps_of('Quiet too'));
        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => self::FROZEN], $this->stamps_of('Loud'));
    }

    // -- updates and deletes (spec §6.2.1, §6.2.2, §6.2.5)

    public function test_update_all_hash_set_touches_updated_at(): void
    {
        ActiveRecord\Timestamps::freeze('2026-10-21 10:00:00');
        Author::update_all(['set' => ['name' => 'X'], 'conditions' => ['author_id' => 1]]);
        $this->assert_equals('2026-10-21 10:00:00', Author::find(1)->updated_at->format('Y-m-d H:i:s'));
        ActiveRecord\Timestamps::freeze(null);
    }

    public function test_update_all_string_set_and_without_timestamps_leave_updated_at(): void
    {
        Author::update_all(['set' => "name = 'S'", 'conditions' => 'author_id = 2']);
        Author::without_timestamps(fn() => Author::update_all(['set' => ['name' => 'Q'], 'conditions' => ['author_id' => 3]]));
        $this->assert_null(Author::find(2)->updated_at);
        $this->assert_null(Author::find(3)->updated_at);
    }

    public function test_bulk_writes_resolve_aliases(): void
    {
        $this->assert_same(1, Venue::update_all(['set' => ['state' => 'XX'], 'conditions' => ['marquee' => 'Warner Theatre']]));
        $this->assert_same(1, Venue::delete_all(['conditions' => ['marquee' => 'Warner Theatre']]));
    }

    public function test_update_all_empty_set_throws_without_warning(): void
    {
        $this->assert_exception_message_contains('Updating requires a hash or string.', fn() => Author::update_all(['set' => []]), ActiveRecord\ActiveRecordException::class);
    }

    // The rule adds updated_at where the set has no value for that column: one the set gives is kept
    public function test_update_all_keeps_the_updated_at_its_set_gives(): void
    {
        Timestamps::freeze(self::FROZEN);

        Author::update_all(['set' => ['name' => 'Given', 'updated_at' => self::GIVEN], 'conditions' => ['author_id' => 1]]);

        $this->assert_sql_has('UPDATE authors SET name=?, updated_at=? WHERE author_id=?', Author::table()->last_sql);
        $this->assert_same(['created_at' => null, 'updated_at' => self::GIVEN], $this->stamps_of('Given'));
    }

    // The set binds a DateTime in the format of its column (#138), the date alone for a DATE column,
    // and the conditions bind theirs as positional values are bound: what MySQL stores either way
    public function test_update_all_binds_a_date_of_its_set_in_the_format_of_its_column(): void
    {
        // loaded first: loading it reads the schema, which is not the statement this pins
        DatedCount::table();
        $when = new DateTime('2026-03-04 10:00:00');

        [$update] = GoldenStatements::capture(fn() => DatedCount::update_all(['set' => ['day' => $when], 'conditions' => ['at < ?', $when]]));

        $this->assert_same([$this->conn->date_to_string($when), $this->conn->datetime_to_string($when)], $update['values']);
    }

    // #92: the column is named as its table names it ("UPDATED_AT" here), and a set that gives it by
    // its attribute name keeps its value, assigned once (Postgres refuses a column assigned twice)
    public function test_update_all_names_the_updated_at_column_of_an_odd_table(): void
    {
        Timestamps::freeze(self::FROZEN);
        OddTimestamp::without_timestamps(fn() => OddTimestamp::create(['name' => 'odd']));

        OddTimestamp::update_all(['set' => ['name' => 'stamped'], 'conditions' => ['name' => 'odd']]);
        $odd = OddTimestamp::find_by_name('stamped');
        $this->assert_null($odd->created_at);
        $this->assert_same(self::FROZEN, $odd->updated_at->format('Y-m-d H:i:s'));

        OddTimestamp::update_all(['set' => ['name' => 'given', 'updated_at' => self::GIVEN]]);
        $this->assert_same(self::GIVEN, OddTimestamp::find_by_name('given')->updated_at->format('Y-m-d H:i:s'));
    }

    // Spec §6.2.5: an empty set is refused before any SQL. The updated_at of the rule must not make a
    // SET of it, which would write every row
    public function test_update_all_with_an_empty_set_sends_no_statement(): void
    {
        Timestamps::freeze(self::FROZEN);
        Author::table();
        $refused = [['exception' => ActiveRecord\ActiveRecordException::class, 'message' => 'Updating requires a hash or string.']];

        $this->assert_same($refused, GoldenStatements::capture(fn() => Author::update_all(['set' => []])));
        $this->assert_same($refused, GoldenStatements::capture(fn() => Author::update_all(['set' => ['name', 'X']])));
        $this->assert_same(0, (int) Author::count(['conditions' => ['updated_at' => self::FROZEN]]));
    }

    // A set that is neither a hash nor a string is refused before any SQL, as it always was: no such
    // value is made into a SET string
    public function test_update_all_refuses_a_set_that_is_neither_a_hash_nor_a_string(): void
    {
        Author::table();
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return "name = 'X'";
            }
        };

        foreach ([5, 2.5, true, false, $stringable] as $set) {
            $this->assert_same(
                [['exception' => ActiveRecord\ActiveRecordException::class, 'message' => 'Updating requires a hash or string.']],
                GoldenStatements::capture(fn() => Author::update_all(['set' => $set, 'conditions' => ['author_id' => 1]])),
            );
        }
    }

    // Spec §5.6: a save() sends the timestamps its model sets, and the engine adds none. A model read
    // with a partial select has no updated_at, so its save() sends none: the rule of update_all() is
    // not the save()'s, which goes through Table::update()
    public function test_a_save_of_a_partial_select_leaves_updated_at_alone(): void
    {
        Timestamps::freeze(self::FROZEN);
        $author = Author::find(1, ['select' => 'author_id, name']);
        $author->name = 'Partial';

        $this->assert_true($author->save());

        $this->assert_sql_has('UPDATE authors SET name=? WHERE author_id=?', Author::table()->last_sql);
        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps_of('PARTIAL'));
    }

    // Table::update() is the UPDATE of a save() and sends what it is given, called directly too:
    // unlike Table::insert() (spec §6.2.3), it gets no timestamps from the engine
    public function test_a_direct_table_update_adds_no_timestamps(): void
    {
        Timestamps::freeze(self::FROZEN);
        $table = Author::table();
        $data = ['name' => 'Direct'];

        $statement = $table->update($data, ['author_id' => 1]);

        $this->assert_instance_of(PDOStatement::class, $statement);
        $this->assert_same(1, $statement->rowCount());
        $this->assert_sql_has('UPDATE authors SET name=? WHERE author_id=?', $table->last_sql);
        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps_of('Direct'));
    }

    // Table::update() takes its data by reference. The caller's array comes back as it always did: the
    // values as they were sent (a DateTime as the string the database takes), under the names it gave
    public function test_a_direct_table_update_leaves_the_callers_data_as_it_was_sent(): void
    {
        $when = new DateTime(self::GIVEN);
        $data = ['name' => 'By ref', 'created_at' => $when];

        Author::table()->update($data, ['author_id' => 1]);

        $this->assert_same(['name' => 'By ref', 'created_at' => $this->conn->datetime_to_string($when)], $data);
        $this->assert_same(['created_at' => self::GIVEN, 'updated_at' => null], $this->stamps_of('By ref'));
    }

    // Anything but a hash is refused as it always was, an empty array and null included, and gets no
    // updated_at that would make a SET of it
    public function test_a_direct_table_update_of_something_that_is_no_hash_is_refused(): void
    {
        foreach ([[], null, [1, 2]] as $data) {
            $this->assert_exception_message_contains('Updating requires a hash or string.', function () use ($data): void {
                Author::table()->update($data, ['author_id' => 1]);
            }, ActiveRecord\ActiveRecordException::class);
        }
    }

    // The pk hash of a save() or a delete() binds a DateTime in the format of its column, as it always
    // did: SQLite compares a DATE column as text, '2026-01-01' with '2026-01-01 00:00:00'
    public function test_table_update_and_delete_bind_a_date_pk_in_its_columns_format(): void
    {
        $table = DatedCount::table();
        $pk = ['day' => new DateTime('2026-01-01'), 'at' => new DateTime('2026-01-01 10:00:00')];
        $bound = [$this->conn->date_to_string($pk['day']), $this->conn->datetime_to_string($pk['at'])];
        $data = ['hits' => 7];

        [$update] = GoldenStatements::capture(fn() => $table->update($data, $pk));
        $this->assert_same([7, ...$bound], $update['values']);
        $this->assert_same(1, (int) DatedCount::count(['conditions' => ['hits' => 7]]));

        [$delete] = GoldenStatements::capture(fn() => $table->delete($pk));
        $this->assert_same($bound, $delete['values']);
        $this->assert_same([2], array_map(fn($count) => (int) $count->hits, DatedCount::all()));
    }

    // A WHERE that is no hash is the one argument of a condition, as it always was: a string is the
    // condition, and an array that is no hash names none. It is refused as before (SQLBuilder takes no
    // array as a condition string), and never makes an UPDATE or a DELETE of every row
    public function test_table_update_and_delete_read_a_where_that_is_no_hash_as_one_condition(): void
    {
        $table = Author::table();
        $data = ['name' => 'One'];

        $this->assert_same(1, $table->update($data, 'author_id = 1')->rowCount());
        $this->assert_sql_has('UPDATE authors SET name=? WHERE author_id = 1', $table->last_sql);

        $data = ['name' => 'Every'];
        $refused = 0;

        foreach ([fn() => $table->update($data, []), fn() => $table->update($data, ['author_id = ?', 2]), fn() => $table->delete([])] as $write) {
            try {
                $write();
            } catch (TypeError) {
                $refused++;
            }
        }

        $this->assert_same(3, $refused);
        $this->assert_same(0, (int) Author::count(['conditions' => ['name' => 'Every']]));
        $this->assert_same(4, (int) Author::count());
    }

    // Table::update() and Table::delete() send their values as Table prepares them, as they always did:
    // process_data() formats a DateTime of the pk hash by its column, and leaves one inside a list as
    // it is. The bulk writes bind that one as a positional value (#138); these two never did
    public function test_table_update_and_delete_send_their_values_as_table_prepares_them(): void
    {
        $table = DatedCount::table();
        $day = new ActiveRecord\DateTime('2026-01-01');
        $data = ['hits' => 7];

        [$update] = GoldenStatements::capture(fn() => $table->update($data, ['day' => [$day]]));
        [$delete] = GoldenStatements::capture(fn() => $table->delete(['day' => [$day]]));

        $this->assert_same([7, $day], $update['values']);
        $this->assert_same([$day], $delete['values']);
    }

    // A pk hash names its columns as they are, never through an alias: the alias map the conditions of
    // update_all() and delete_all() are read with is not a save()'s or a delete()'s. AliasedPkAuthor has
    // an alias named like its pk column (author_id => name)
    public function test_table_update_and_delete_never_read_a_pk_hash_through_an_alias(): void
    {
        $table = AliasedPkAuthor::table();
        $data = ['name' => 'Renamed'];

        $this->assert_same(1, $table->update($data, ['author_id' => 1])->rowCount());
        $this->assert_sql_has('UPDATE authors SET name=? WHERE author_id=?', $table->last_sql);
        $this->assert_same(1, $table->delete(['author_id' => 2])->rowCount());
        $this->assert_sql_has('DELETE FROM authors WHERE author_id=?', $table->last_sql);

        $this->assert_same('Renamed', Author::find(1)->name);
        $this->assert_null(Author::find_by_author_id(2));
    }

    // Spec §6.2.1 (#144): the conditions of update_all() and delete_all() read an alias as find() and
    // count() do, and the alias wins, also over a column of its name
    public function test_bulk_writes_read_an_alias_named_like_a_column_as_the_alias(): void
    {
        $this->assert_same(1, AliasedPkAuthor::update_all(['set' => ['parent_author_id' => 9], 'conditions' => ['author_id' => 'Tito']]));
        $this->assert_sql_has('WHERE name=?', AliasedPkAuthor::table()->last_sql);
        $this->assert_same(1, AliasedPkAuthor::delete_all(['conditions' => ['author_id' => 'Uncle Bob']]));
        $this->assert_sql_has('DELETE FROM authors WHERE name=?', AliasedPkAuthor::table()->last_sql);

        $this->assert_same(9, Author::find(1)->parent_author_id);
        $this->assert_null(Author::find_by_author_id(4));
    }

    // Spec §6.3: Model::connection() is a public static that a subclass can override, and update_all()
    // and delete_all() have always taken their connection from it
    public function test_bulk_writes_still_call_the_connection_override(): void
    {
        ConnectionCountingAuthor::table();

        ConnectionCountingAuthor::$connection_calls = 0;
        ConnectionCountingAuthor::update_all(['set' => ['name' => 'Counted'], 'conditions' => ['author_id' => 1]]);
        $this->assert_greater_than_or_equal(1, ConnectionCountingAuthor::$connection_calls);

        ConnectionCountingAuthor::$connection_calls = 0;
        ConnectionCountingAuthor::delete_all(['conditions' => ['author_id' => 1]]);
        $this->assert_greater_than_or_equal(1, ConnectionCountingAuthor::$connection_calls);
    }

    // ... and run their statement on the connection it returns: a subclass can route its writes
    public function test_bulk_writes_run_on_the_connection_the_override_returns(): void
    {
        RoutedAuthor::table();
        $routed = ActiveRecord\Connection::instance(ActiveRecord\Config::instance()->get_default_connection());
        $this->conn->last_query = 'untouched';
        RoutedAuthor::$route = $routed;

        try {
            $this->assert_same(1, RoutedAuthor::update_all(['set' => ['name' => 'Routed'], 'conditions' => ['author_id' => 1]]));
            $this->assert_sql_has('UPDATE authors SET name=?, updated_at=? WHERE author_id=?', $routed->last_query);
            $this->assert_same(1, RoutedAuthor::delete_all(['conditions' => ['author_id' => 2]]));
            $this->assert_sql_has('DELETE FROM authors WHERE author_id=?', $routed->last_query);
        } finally {
            RoutedAuthor::$route = null;
            $routed->close();
        }

        $this->assert_same('untouched', $this->conn->last_query);
        $this->assert_same('Routed', Author::find(1)->name);
        $this->assert_null(Author::find_by_author_id(2));
    }

    // like the other terminals, an update and a delete run through the Table their query was given
    public function test_an_update_and_a_delete_run_through_the_table_they_were_given(): void
    {
        $given = Table::load('\\Author');
        $this->assert_not_same(Author::table(), $given);
        $data = ['name' => 'Given'];

        $given->update($data, ['author_id' => 1]);
        $this->assert_sql_has('UPDATE authors SET name=? WHERE author_id=?', $given->last_sql);
        $given->delete(['author_id' => 2]);
        $this->assert_sql_has('DELETE FROM authors WHERE author_id=?', $given->last_sql);

        $this->assert_null(Author::table()->last_sql);
    }

    public function test_for_table_is_a_query_of_the_table_without_options(): void
    {
        $query = Query::for_table(Author::table());

        $this->assert_null($query->state()->legacy);
        $this->assert_same('DELETE FROM ' . $this->conn->quote_name('authors'), (new ActiveRecord\QueryCompiler(Author::table()))->compile_delete($query->state())->to_s());
    }

    // Where the adapter has no LIMIT on UPDATE and DELETE (Postgres), a limited one picks its rows by the
    // pk in a subquery (#92): by the pk's column ("space name"), not by its attribute name
    public function test_limited_writes_pick_their_rows_by_the_pk_column(): void
    {
        $table = OddColumnBySpace::table();
        $state = Query::from_options($table, ['order' => 'id', 'limit' => 1])->state();
        $compiler = new ActiveRecord\QueryCompiler($table);
        $column = $this->conn->quote_name('space name');

        foreach ([$compiler->compile_update($state, ['camelcase' => 'y']), $compiler->compile_delete($state)] as $sql) {
            if ($this->conn->accepts_limit_and_order_for_update_and_delete()) {
                $this->assert_string_contains_string(' ORDER BY id LIMIT 1', $sql->to_s());
            } else {
                $this->assert_string_contains_string(" WHERE $column IN (SELECT $column FROM ", $sql->to_s());
            }
        }
    }
}
