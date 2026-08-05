<?php

use ActiveRecord\Config;
use ActiveRecord\ConnectionManager;

class ConnectionManagerTest extends DatabaseTest
{
    public function test_get_connection_with_null_connection()
    {
        $this->assert_not_null(ConnectionManager::get_connection(null));
        $this->assert_not_null(ConnectionManager::get_connection());
    }

    public function test_get_connection()
    {
        $this->assert_not_null(ConnectionManager::get_connection('mysql'));
    }

    public function test_get_connection_uses_existing_object()
    {
        $a = ConnectionManager::get_connection('mysql');
        $a->last_query = 'remember me';

        $this->assert_same($a, ConnectionManager::get_connection('mysql'));
    }

    public function test_gh_91_get_connection_with_null_connection_is_always_default()
    {
        $conn_one = ConnectionManager::get_connection('mysql');
        $conn_two = ConnectionManager::get_connection();
        $conn_three = ConnectionManager::get_connection('mysql');
        $conn_four = ConnectionManager::get_connection();

        $this->assert_same($conn_one, $conn_three);
        $this->assert_same($conn_two, $conn_three);
        $this->assert_same($conn_four, $conn_three);
    }

    public function test_close_releases_the_real_pdo_connection_property()
    {
        $connection = ConnectionManager::get_connection('mysql');
        $this->assert_not_null($connection->connection);

        $connection->close();

        // Regression for a $conn/$connection typo: close() used to assign to a
        // never-declared $conn property (a dynamic property under PHP 8.2+,
        // which --fail-on-deprecation turns into a build failure) and left the
        // real $connection property (the PDO handle) untouched.
        $this->assert_null($connection->connection);
        $this->assert_false(property_exists($connection, 'conn'));

        // get_connection() treats a falsy ->connection as dead and transparently
        // reconnects, so subsequent tests aren't left with a closed connection.
        $this->assert_not_null(ConnectionManager::get_connection('mysql')->connection);
    }

    public function test_table_reestablish_connection_closes_without_dynamic_property()
    {
        $table = ActiveRecord\Table::load('Author');
        $old_connection = $table->conn;

        $table->reestablish_connection(true);

        $this->assert_null($old_connection->connection);
        $this->assert_false(property_exists($old_connection, 'conn'));
        $this->assert_not_null($table->conn);
    }

    public function test_drop_connection_forces_a_fresh_object_on_next_get()
    {
        $a = ConnectionManager::get_connection('mysql');
        ConnectionManager::drop_connection('mysql');
        $b = ConnectionManager::get_connection('mysql');

        // dropping unsets the cached Connection, so the manager rebuilds it
        $this->assert_not_same($a, $b);
        $this->assert_not_null($b->connection);
    }

    public function test_drop_connection_with_unknown_name_is_a_noop()
    {
        $a = ConnectionManager::get_connection('mysql');
        ConnectionManager::drop_connection('never_registered');

        // an unknown name must not disturb existing connections
        $this->assert_same($a, ConnectionManager::get_connection('mysql'));
    }

    public function test_get_connection_throws_on_unconfigured_name()
    {
        $this->expectException(ActiveRecord\DatabaseException::class);
        $this->expectExceptionMessage('Empty connection string');

        ConnectionManager::get_connection('this_connection_is_not_configured');
    }
}
