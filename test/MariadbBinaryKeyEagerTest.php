<?php

require_once __DIR__ . '/MysqlBinaryKeyEagerTest.php';

class MariadbBinaryKeyEagerTest extends BinaryKeyEagerTestCase
{
    protected function connection_name(): string
    {
        return 'mariadb';
    }
}
