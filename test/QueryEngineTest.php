<?php

use ActiveRecord\Query;
use ActiveRecord\Table;

/**
 * The Query engine behind the legacy entries: Table::options_to_sql() builds its SELECT through
 * Query::from_options(), and find(), exists() and count() run it there. The statements every
 * entry sends are pinned in GoldenSqlTest.
 */
class QueryEngineTest extends DatabaseTest
{
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
}
