<?php

require_once __DIR__ . '/MysqlAdapterTest.php';

class MariadbAdapterTest extends MysqlAdapterTest
{
	public function set_up($connection_name=null)
	{
		AdapterTest::set_up('mariadb');
	}
}
