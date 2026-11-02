<?php

require_once __DIR__ . '/MysqlQueryBuilderTest.php';

/**
 * The query builder on MariaDB, which shares MySQL's adapter: MysqlQueryBuilderTest's tests.
 */
class MariadbQueryBuilderTest extends MysqlQueryBuilderTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('mariadb');
    }

    // MariaDB groups without ONLY_FULL_GROUP_BY by default, where MySQL refuses the query, so a HAVING can name a
    // column the select gives that is not grouped: a wrapped count() keeps even a select of plain columns beside a
    // HAVING (spec §5.3). The group of parent 3 is Tito's alone.
    public function test_a_having_on_a_selected_column_keeps_a_plain_select(): void
    {
        $tito = fn(): ActiveRecord\Query => Author::select('name')->group_by('parent_author_id')->having('name', '=', 'Tito');

        $this->assert_same(1, count($tito()->get()));
        $this->assert_same(1, $tito()->count());
        $this->assert_true($tito()->exists());
    }
}
