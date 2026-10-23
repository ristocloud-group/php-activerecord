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
}
