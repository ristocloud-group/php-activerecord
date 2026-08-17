<?php

class DatabaseLoader
{
    private $db;
    public static $instances = [];

    public function __construct($db, $key = null)
    {
        $this->db = $db;
        $key = $key ?: $db->protocol;

        if (!isset(static::$instances[$key])) {
            static::$instances[$key] = 0;
        }

        if (static::$instances[$key]++ == 0) {
            // drop and re-create the tables one time only (per connection, so
            // servers sharing a protocol — e.g. mysql and mariadb — each load)
            $this->drop_tables();
            $this->exec_sql_script($db->protocol);
        }
    }

    public function reset_table_data()
    {
        foreach ($this->get_fixture_tables() as $table) {
            $this->db->query('DELETE FROM ' . $this->quote_name($table));
            $this->load_fixture_data($table);
        }

        // Not every adapter has an after-fixtures script; a missing file is
        // fine, but SQL errors in an existing one must propagate (GH-81).
        $after_fixtures = $this->db->protocol . '-after-fixtures';
        if (is_file(__DIR__ . "/../sql/$after_fixtures.sql")) {
            $this->exec_sql_script($after_fixtures);
        }
    }

    public function drop_tables()
    {
        $tables = $this->db->tables();

        foreach ($this->get_fixture_tables() as $table) {
            if (in_array($table, $tables)) {
                $this->db->query('DROP TABLE ' . $this->quote_name($table));
            }
        }
    }

    public function exec_sql_script($file)
    {
        foreach (explode(';', $this->get_sql($file)) as $sql) {
            if (trim($sql) != '') {
                $this->db->query($sql);
            }
        }
    }

    public function get_fixture_tables()
    {
        $tables = [];

        foreach (glob(__DIR__ . '/../fixtures/*.csv') as $file) {
            $info = pathinfo($file);
            $tables[] = $info['filename'];
        }

        return $tables;
    }

    public function get_sql($file)
    {
        $file = __DIR__ . "/../sql/$file.sql";

        if (!file_exists($file)) {
            throw new Exception("File not found: $file");
        }

        return file_get_contents($file);
    }

    public function load_fixture_data($table)
    {
        $fp = fopen(__DIR__ . "/../fixtures/$table.csv", 'r');
        $fields = fgetcsv($fp, 0, ',', '"', '\\');

        if (!empty($fields)) {
            $markers = join(',', array_fill(0, count($fields), '?'));
            $table = $this->quote_name($table);

            foreach ($fields as &$name) {
                $name = $this->quote_name(trim($name));
            }

            $fields = join(',', $fields);

            while (($values = fgetcsv($fp, 0, ',', '"', '\\'))) {
                $this->db->query("INSERT INTO $table($fields) VALUES($markers)", $values);
            }
        }
        fclose($fp);
    }

    public function quote_name($name)
    {
        return $this->db->quote_name($name);
    }
}
