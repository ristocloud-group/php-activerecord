<?php

use ActiveRecord\Connection;

// Only use this to test static methods in Connection that are not specific
// to any database adapter.

class ConnectionTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    public function test_connection_info_from_should_throw_exception_when_no_host()
    {
        $this->expectException(ActiveRecord\DatabaseException::class);

        ActiveRecord\Connection::parse_connection_url('mysql://user:pass@');
    }

    public function test_connection_info()
    {
        $info = ActiveRecord\Connection::parse_connection_url('mysql://user:pass@127.0.0.1:3306/dbname');
        $this->assert_equals('mysql', $info->protocol);
        $this->assert_equals('user', $info->user);
        $this->assert_equals('pass', $info->pass);
        $this->assert_equals('127.0.0.1', $info->host);
        $this->assert_equals(3306, $info->port);
        $this->assert_equals('dbname', $info->db);
    }

    public function test_gh_103_sqlite_connection_string_relative()
    {
        $info = ActiveRecord\Connection::parse_connection_url('sqlite://../some/path/to/file.db');
        $this->assert_equals('../some/path/to/file.db', $info->host);
    }

    public function test_gh_103_sqlite_connection_string_absolute()
    {
        $this->expectException(ActiveRecord\DatabaseException::class);

        ActiveRecord\Connection::parse_connection_url('sqlite:///some/path/to/file.db');
    }

    public function test_gh_103_sqlite_connection_string_unix()
    {
        $info = ActiveRecord\Connection::parse_connection_url('sqlite://unix(/some/path/to/file.db)');
        $this->assert_equals('/some/path/to/file.db', $info->host);

        $info = ActiveRecord\Connection::parse_connection_url('sqlite://unix(/some/path/to/file.db)/');
        $this->assert_equals('/some/path/to/file.db', $info->host);

        $info = ActiveRecord\Connection::parse_connection_url('sqlite://unix(/some/path/to/file.db)/dummy');
        $this->assert_equals('/some/path/to/file.db', $info->host);
    }

    public function test_gh_103_sqlite_connection_string_windows()
    {
        $info = ActiveRecord\Connection::parse_connection_url('sqlite://windows(c%3A/some/path/to/file.db)');
        $this->assert_equals('c:/some/path/to/file.db', $info->host);
    }

    public function test_parse_connection_url_with_unix_sockets()
    {
        $info = ActiveRecord\Connection::parse_connection_url('mysql://user:password@unix(/tmp/mysql.sock)/database');
        $this->assert_equals('/tmp/mysql.sock', $info->host);
    }

    public function test_parse_connection_url_with_decode_option()
    {
        $info = ActiveRecord\Connection::parse_connection_url('mysql://h%20az:h%40i@127.0.0.1/test?decode=true');
        $this->assert_equals('h az', $info->user);
        $this->assert_equals('h@i', $info->pass);
    }

    public function test_encoding()
    {
        $info = ActiveRecord\Connection::parse_connection_url('mysql://test:test@127.0.0.1/test?charset=utf8');
        $this->assert_equals('utf8', $info->charset);
    }

    public function test_gh_46_charset_followed_by_decode_query_params()
    {
        $info = ActiveRecord\Connection::parse_connection_url('mysql://h%20az:h%40i@127.0.0.1/test?charset=utf8mb4&decode=true');
        $this->assert_equals('utf8mb4', $info->charset);
        $this->assert_equals('h az', $info->user);
        $this->assert_equals('h@i', $info->pass);
    }

    public function test_gh_46_decode_followed_by_charset_query_params()
    {
        $info = ActiveRecord\Connection::parse_connection_url('mysql://h%20az:h%40i@127.0.0.1/test?decode=true&charset=utf8mb4');
        $this->assert_equals('utf8mb4', $info->charset);
        $this->assert_equals('h az', $info->user);
        $this->assert_equals('h@i', $info->pass);
    }

    public function test_gh_46_valueless_query_param_emits_no_warning_and_sets_no_charset()
    {
        set_error_handler(static function (int $errno, string $errstr): never {
            throw new ErrorException($errstr, $errno);
        });

        try {
            $info = ActiveRecord\Connection::parse_connection_url('mysql://user:pass@127.0.0.1/test?charset');
        } finally {
            restore_error_handler();
        }

        $this->assert_false(isset($info->charset));
    }

    public function test_gh_46_decode_outside_query_string_does_not_trigger_decoding()
    {
        $info = ActiveRecord\Connection::parse_connection_url('mysql://us%40er:pass@127.0.0.1/db_decode=true');
        $this->assert_equals('us%40er', $info->user);
        $this->assert_equals('db_decode=true', $info->db);
    }

    public function test_gh_46_no_query_string_leaves_charset_unset()
    {
        $info = ActiveRecord\Connection::parse_connection_url('mysql://user:pass@127.0.0.1/db');
        $this->assert_false(isset($info->charset));
    }

    public function test_oci_protocol_throws_removed_exception()
    {
        $this->expectException(ActiveRecord\DatabaseException::class);
        $this->expectExceptionMessage('The OCI/Oracle adapter was removed in php-activerecord v1.8.0.');

        ActiveRecord\Connection::instance('oci://test:test@127.0.0.1/dev');
    }
}
