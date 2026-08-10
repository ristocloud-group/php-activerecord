<?php

use ActiveRecord\SQLBuilder;
use ActiveRecord\Table;

class SQLBuilderTest extends DatabaseTest
{
    /** @var SQLBuilder */
    private $sql;

    protected $table_name = 'authors';
    protected $class_name = 'Author';
    protected $table;

    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name);
        $this->sql = new SQLBuilder($this->conn, $this->table_name);
        $this->table = Table::load($this->class_name);
    }

    protected function cond_from_s($name, $values = null, $map = null)
    {
        return SQLBuilder::create_conditions_from_underscored_string($this->table->conn, $name, $values, $map);
    }

    public function assert_conditions($expected_sql, $values, $underscored_string, $map = null)
    {
        $cond = SQLBuilder::create_conditions_from_underscored_string($this->table->conn, $underscored_string, $values, $map);
        $this->assert_sql_has($expected_sql, array_shift($cond));

        if ($values) {
            $this->assert_equals(array_values(array_filter($values, function ($s) {
                return $s !== null;
            })), array_values($cond));
        } else {
            $this->assert_equals([], $cond);
        }
    }

    public function test_no_connection()
    {
        $this->expectException(ActiveRecord\ActiveRecordException::class);

        new SQLBuilder(null, 'authors');
    }

    public function test_nothing()
    {
        $this->assert_equals('SELECT * FROM authors', (string) $this->sql);
    }

    public function test_where_with_array()
    {
        $this->sql->where("id=? AND name IN(?)", 1, ['Tito','Mexican']);
        $this->assert_sql_has("SELECT * FROM authors WHERE id=? AND name IN(?,?)", (string) $this->sql);
        $this->assert_equals([1,'Tito','Mexican'], $this->sql->get_where_values());
    }

    public function test_where_with_hash()
    {
        $this->sql->where(['id' => 1, 'name' => 'Tito']);
        $this->assert_sql_has("SELECT * FROM authors WHERE id=? AND name=?", (string) $this->sql);
        $this->assert_equals([1,'Tito'], $this->sql->get_where_values());
    }

    public function test_where_with_hash_and_array()
    {
        $this->sql->where(['id' => 1, 'name' => ['Tito','Mexican']]);
        $this->assert_sql_has("SELECT * FROM authors WHERE id=? AND name IN(?,?)", (string) $this->sql);
        $this->assert_equals([1,'Tito','Mexican'], $this->sql->get_where_values());
    }

    public function test_gh134_where_with_hash_and_null()
    {
        $this->sql->where(['id' => 1, 'name' => null]);
        $this->assert_sql_has("SELECT * FROM authors WHERE id=? AND name IS NULL", (string) $this->sql);
        $this->assert_equals([1], $this->sql->get_where_values());
    }

    public function test_where_with_null()
    {
        $this->sql->where(null);
        $this->assert_equals('SELECT * FROM authors', (string) $this->sql);
    }

    public function test_where_with_no_args()
    {
        $this->sql->where();
        $this->assert_equals('SELECT * FROM authors', (string) $this->sql);
    }

    public function test_order()
    {
        $this->sql->order('name');
        $this->assert_equals('SELECT * FROM authors ORDER BY name', (string) $this->sql);
    }

    public function test_limit()
    {
        $this->sql->limit(10)->offset(1);
        $this->assert_equals($this->conn->limit('SELECT * FROM authors', 1, 10), (string) $this->sql);
    }

    public function test_select()
    {
        $this->sql->select('id,name');
        $this->assert_equals('SELECT id,name FROM authors', (string) $this->sql);
    }

    public function test_joins()
    {
        $join = 'inner join books on(authors.id=books.author_id)';
        $this->sql->joins($join);
        $this->assert_equals("SELECT * FROM authors $join", (string) $this->sql);
    }

    public function test_group()
    {
        $this->sql->group('name');
        $this->assert_equals('SELECT * FROM authors GROUP BY name', (string) $this->sql);
    }

    public function test_having()
    {
        $this->sql->having("created_at > '2009-01-01'");
        $this->assert_equals("SELECT * FROM authors HAVING created_at > '2009-01-01'", (string) $this->sql);
    }

    public function test_all_clauses_after_where_should_be_correctly_ordered()
    {
        $this->sql->limit(10)->offset(1);
        $this->sql->having("created_at > '2009-01-01'");
        $this->sql->order('name');
        $this->sql->group('name');
        $this->sql->where(['id' => 1]);
        $this->assert_sql_has($this->conn->limit("SELECT * FROM authors WHERE id=? GROUP BY name HAVING created_at > '2009-01-01' ORDER BY name", 1, 10), (string) $this->sql);
    }

    public function test_insert_requires_hash()
    {
        $this->expectException(ActiveRecord\ActiveRecordException::class);

        $this->sql->insert([1]);
    }

    public function test_insert()
    {
        $this->sql->insert(['id' => 1, 'name' => 'Tito']);
        $this->assert_sql_has("INSERT INTO authors(id,name) VALUES(?,?)", (string) $this->sql);
    }

    public function test_insert_with_null()
    {
        $this->sql->insert(['id' => 1, 'name' => null]);
        $this->assert_sql_has("INSERT INTO authors(id,name) VALUES(?,?)", $this->sql->to_s());
    }

    public function test_update_with_hash()
    {
        $this->sql->update(['id' => 1, 'name' => 'Tito'])->where('id=1 AND name IN(?)', ['Tito','Mexican']);
        $this->assert_sql_has("UPDATE authors SET id=?, name=? WHERE id=1 AND name IN(?,?)", (string) $this->sql);
        $this->assert_equals([1,'Tito','Tito','Mexican'], $this->sql->bind_values());
    }

    public function test_update_with_limit_and_order()
    {
        if (!$this->conn->accepts_limit_and_order_for_update_and_delete()) {
            $this->mark_test_skipped('Only MySQL & Sqlite accept limit/order with UPDATE operation');
        }

        $this->sql->update(['id' => 1])->order('name asc')->limit(1);
        $this->assert_sql_has("UPDATE authors SET id=? ORDER BY name asc LIMIT 1", $this->sql->to_s());
    }

    public function test_update_with_string()
    {
        $this->sql->update("name='Bob'");
        $this->assert_sql_has("UPDATE authors SET name='Bob'", $this->sql->to_s());
    }

    public function test_update_with_null()
    {
        $this->sql->update(['id' => 1, 'name' => null])->where('id=1');
        $this->assert_sql_has("UPDATE authors SET id=?, name=? WHERE id=1", $this->sql->to_s());
    }

    public function test_delete()
    {
        $this->sql->delete();
        $this->assert_equals('DELETE FROM authors', $this->sql->to_s());
    }

    public function test_delete_with_where()
    {
        $this->sql->delete('id=? or name in(?)', 1, ['Tito','Mexican']);
        $this->assert_equals('DELETE FROM authors WHERE id=? or name in(?,?)', $this->sql->to_s());
        $this->assert_equals([1,'Tito','Mexican'], $this->sql->bind_values());
    }

    public function test_delete_with_hash()
    {
        $this->sql->delete(['id' => 1, 'name' => ['Tito','Mexican']]);
        $this->assert_sql_has("DELETE FROM authors WHERE id=? AND name IN(?,?)", $this->sql->to_s());
        $this->assert_equals([1,'Tito','Mexican'], $this->sql->get_where_values());
    }

    public function test_delete_with_limit_and_order()
    {
        if (!$this->conn->accepts_limit_and_order_for_update_and_delete()) {
            $this->mark_test_skipped('Only MySQL & Sqlite accept limit/order with DELETE operation');
        }

        $this->sql->delete(['id' => 1])->order('name asc')->limit(1);
        $this->assert_sql_has("DELETE FROM authors WHERE id=? ORDER BY name asc LIMIT 1", $this->sql->to_s());
    }

    public function test_reverse_order()
    {
        $this->assert_equals('id ASC, name DESC', SQLBuilder::reverse_order('id DESC, name ASC'));
        $this->assert_equals('id ASC, name DESC , zzz ASC', SQLBuilder::reverse_order('id DESC, name ASC , zzz DESC'));
        $this->assert_equals('id DESC, name DESC', SQLBuilder::reverse_order('id, name'));
        $this->assert_equals('id DESC', SQLBuilder::reverse_order('id'));
        $this->assert_equals('', SQLBuilder::reverse_order(''));
        $this->assert_equals(' ', SQLBuilder::reverse_order(' '));
        $this->assert_equals(null, SQLBuilder::reverse_order(null));
    }

    public function test_create_conditions_from_underscored_string()
    {
        $this->assert_conditions('id=? AND name=? OR z=?', [1,'Tito','X'], 'id_and_name_or_z');
        $this->assert_conditions('id=?', [1], 'id');
        $this->assert_conditions('id IN(?)', [[1,2]], 'id');
    }

    public function test_create_conditions_from_underscored_string_with_nulls()
    {
        $this->assert_conditions('id=? AND name IS NULL', [1,null], 'id_and_name');
    }

    public function test_create_conditions_from_underscored_string_with_missing_args()
    {
        $this->assert_conditions('id=? AND name IS NULL OR z IS NULL', [1,null], 'id_and_name_or_z');
        $this->assert_conditions('id IS NULL', [], 'id');
    }

    public function test_create_conditions_from_underscored_string_with_blank()
    {
        $this->assert_conditions('id=? AND name IS NULL OR z=?', [1,null,''], 'id_and_name_or_z');
    }

    // A null inside an array value must also match NULL rows (Rails parity):
    // the builder partitions the list into 'col IN(?)' over the non-null
    // values OR'd with a literal 'col IS NULL', parenthesized so it composes
    // with surrounding AND/OR glue. Only the non-null values remain bound.
    public function test_create_conditions_from_underscored_string_with_array_containing_null()
    {
        $values = [[1, null]];
        $cond = $this->cond_from_s('id', $values);
        $this->assert_sql_has('(id IN(?) OR id IS NULL)', array_shift($cond));
        $this->assert_equals([[1]], array_values($cond));
    }

    // An all-null array collapses to a single IS NULL with no bind values —
    // it must not be treated as an empty IN list.
    public function test_create_conditions_from_underscored_string_with_all_null_array()
    {
        $values = [[null, null]];
        $cond = $this->cond_from_s('id', $values);
        $this->assert_sql_has('id IS NULL', array_shift($cond));
        $this->assert_equals([], array_values($cond));
    }

    // Null position inside the array is irrelevant for the dynamic-finder
    // builder too: repeated nulls around the value collapse into the same
    // single IS NULL with only the non-null value bound.
    public function test_create_conditions_from_underscored_string_array_null_positions_are_irrelevant()
    {
        $values = [[null, 1, null]];
        $cond = $this->cond_from_s('id', $values);
        $this->assert_sql_has('(id IN(?) OR id IS NULL)', array_shift($cond));
        $this->assert_equals([[1]], array_values($cond));
    }

    public function test_create_conditions_from_underscored_string_array_with_null_composes_with_glue()
    {
        $values = [[1, null], 'Tito'];
        $cond = $this->cond_from_s('id_and_name', $values);
        $this->assert_sql_has('(id IN(?) OR id IS NULL) AND name=?', array_shift($cond));
        $this->assert_equals([[1], 'Tito'], array_values($cond));
    }

    public function test_create_conditions_from_underscored_string_invalid()
    {
        $this->assert_equals(null, $this->cond_from_s(''));
        $this->assert_equals(null, $this->cond_from_s(null));
    }

    public function test_create_conditions_from_underscored_string_with_mapped_columns()
    {
        $this->assert_conditions('id=? AND name=?', [1,'Tito'], 'id_and_my_name', ['my_name' => 'name']);
    }

    public function test_create_hash_from_underscored_string()
    {
        $values = [1,'Tito'];
        $hash = SQLBuilder::create_hash_from_underscored_string('id_and_my_name', $values);
        $this->assert_equals(['id' => 1, 'my_name' => 'Tito'], $hash);
    }

    public function test_create_hash_from_underscored_string_with_mapped_columns()
    {
        $values = [1,'Tito'];
        $map = ['my_name' => 'name'];
        $hash = SQLBuilder::create_hash_from_underscored_string('id_and_my_name', $values, $map);
        $this->assert_equals(['id' => 1, 'name' => 'Tito'], $hash);
    }

    public function test_where_with_joins_prepends_table_name_to_fields()
    {
        $joins = 'INNER JOIN books ON (books.id = authors.id)';
        // joins needs to be called prior to where
        $this->sql->joins($joins);
        $this->sql->where(['id' => 1, 'name' => 'Tito']);

        $this->assert_sql_has("SELECT * FROM authors $joins WHERE authors.id=? AND authors.name=?", (string) $this->sql);
    }

    public function test_build_upsert_multi_row_with_conflict_clause()
    {
        $sql = new SQLBuilder($this->conn, 'venues');
        $sql->upsert(['name', 'address', 'city'], 2, ['name', 'address'], ['city']);

        $name = $this->conn->quote_name('name');
        $addr = $this->conn->quote_name('address');
        $city = $this->conn->quote_name('city');

        $expected = "INSERT INTO venues ($name, $addr, $city) VALUES (?, ?, ?), (?, ?, ?) "
            . $this->conn->upsert_conflict_clause(['name', 'address'], ['city']);

        $this->assert_equals($expected, (string) $sql);
    }

    public function test_build_upsert_empty_update_omits_conflict_clause()
    {
        $sql = new SQLBuilder($this->conn, 'venues');
        $sql->upsert(['name', 'address'], 1, ['name', 'address'], []);

        $name = $this->conn->quote_name('name');
        $addr = $this->conn->quote_name('address');

        $this->assert_equals("INSERT INTO venues ($name, $addr) VALUES (?, ?)", (string) $sql);
    }
};
