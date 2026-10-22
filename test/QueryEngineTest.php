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
}
