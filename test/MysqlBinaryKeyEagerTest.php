<?php

/*
 * GH #40 (g): on MySQL/MariaDB the eager matcher also matches string keys that differ only by
 * case, as their _ci collations do, but never on binary columns (BINARY/VARBINARY/BLOB compare
 * byte for byte) and never on strings that are not valid UTF-8 (folding them would merge
 * distinct byte strings). The tables are MySQL-only, so they are created here, not in
 * test/sql/mysql.sql; MariadbBinaryKeyEagerTest runs the same tests on MariaDB.
 */

class BinaryKeyChild extends ActiveRecord\Model
{
    public static $table_name = 'binary_key_children';
    public static $belongs_to = [['owner', 'class_name' => 'BinaryKeyOwnerByUid', 'foreign_key' => 'owner_uid']];
}

class BinaryKeyOwner extends ActiveRecord\Model
{
    public static $table_name = 'binary_key_owners';
    public static $has_many = [['kids', 'class_name' => 'BinaryKeyChild', 'foreign_key' => 'owner_uid', 'primary_key' => 'uid', 'order' => 'id asc']];
}

// the same owners keyed by uid, for the belongs_to
class BinaryKeyOwnerByUid extends ActiveRecord\Model
{
    public static $table_name = 'binary_key_owners';
    public static $pk = 'uid';
}

abstract class BinaryKeyEagerTestCase extends DatabaseTest
{
    abstract protected function connection_name(): string;

    public function set_up($connection_name = null)
    {
        parent::set_up($this->connection_name());
        $this->drop_tables();
        $this->conn->query('CREATE TABLE binary_key_owners (id INT NOT NULL PRIMARY KEY, uid VARBINARY(16) NOT NULL)');
        $this->conn->query('CREATE TABLE binary_key_children (id INT NOT NULL PRIMARY KEY, owner_uid VARBINARY(16) NOT NULL)');
        // invalid UTF-8 that differs in the high byte only, and ASCII that differs only by case
        $this->conn->query("INSERT INTO binary_key_owners (id, uid) VALUES (1, X'FF01'), (2, X'FE01'), (3, X'41'), (4, X'61')");
        $this->conn->query("INSERT INTO binary_key_children (id, owner_uid) VALUES (10, X'FF01'), (11, X'41'), (12, X'61')");
        ActiveRecord\Table::clear_cache();
    }

    public function tear_down()
    {
        $this->drop_tables();
        parent::tear_down();
    }

    private function drop_tables(): void
    {
        $this->conn->query('DROP TABLE IF EXISTS binary_key_children');
        $this->conn->query('DROP TABLE IF EXISTS binary_key_owners');
    }

    /**
     * @return array<int, mixed>
     */
    private function load(string $class, string $relationship, bool $eager): array
    {
        $out = [];
        foreach ($class::find('all', ['order' => 'id asc'] + ($eager ? ['include' => $relationship] : [])) as $model) {
            $related = $model->$relationship;
            $out[$model->id] = is_array($related) ? array_map(fn($m) => (int) $m->id, $related) : (null === $related ? null : (int) $related->id);
        }

        return $out;
    }

    public function test_eager_has_many_matches_binary_keys_byte_for_byte()
    {
        $expected = [1 => [10], 2 => [], 3 => [11], 4 => [12]];

        $this->assert_same($expected, $this->load('BinaryKeyOwner', 'kids', false), 'lazy');
        $this->assert_same($expected, $this->load('BinaryKeyOwner', 'kids', true), 'eager');
    }

    public function test_eager_belongs_to_matches_binary_keys_byte_for_byte()
    {
        $expected = [10 => 1, 11 => 3, 12 => 4];

        $this->assert_same($expected, $this->load('BinaryKeyChild', 'owner', false), 'lazy');
        $this->assert_same($expected, $this->load('BinaryKeyChild', 'owner', true), 'eager');
    }
}

class MysqlBinaryKeyEagerTest extends BinaryKeyEagerTestCase
{
    protected function connection_name(): string
    {
        return 'mysql';
    }
}
