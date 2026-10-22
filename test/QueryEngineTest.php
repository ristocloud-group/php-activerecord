<?php

use ActiveRecord\Query;

/**
 * The Query engine behind the legacy entries: Table::options_to_sql() builds its SELECT through
 * Query::from_options(). The statements every entry sends are pinned in GoldenSqlTest.
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
}
