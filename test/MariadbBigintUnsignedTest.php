<?php

require_once __DIR__ . '/MysqlBigintUnsignedTest.php';

class MariadbBigintUnsignedTest extends MysqlBigintUnsignedTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('mariadb');
    }
}
