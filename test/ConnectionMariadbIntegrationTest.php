<?php

class ConnectionMariadbIntegrationTest extends DatabaseTest
{
	public function set_up($connection_name=null)
	{
		parent::set_up('mariadb');
	}

	public function test_mariadb_connection_uses_mysql_adapter()
	{
		$this->assert_is_a('ActiveRecord\\MysqlAdapter', $this->conn);
	}

	public function test_mariadb_can_introspect_authors_table()
	{
		$columns = $this->conn->columns('authors');
		$this->assert_array_has_key('author_id', $columns);
	}
}
