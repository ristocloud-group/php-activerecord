<?php

/**
 * BIGINT UNSIGNED values above PHP_INT_MAX (#44). Only MySQL/MariaDB have an
 * unsigned 64-bit integer (Postgres bigint and SQLite INTEGER are signed), so
 * the rows above PHP_INT_MAX are inserted here rather than in the shared
 * big_ids fixture, which every adapter loads.
 */
class MysqlBigintUnsignedTest extends DatabaseTest
{
    public const U64_MAX = '18446744073709551615';
    public const U64_MAX_MINUS_ONE = '18446744073709551614';

    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name ?? 'mysql');

        // the fixture already holds 1 and PHP_INT_MAX ('int max')
        $values = [self::U64_MAX_MINUS_ONE, 'u64 max - 1', self::U64_MAX, 'u64 max'];
        $this->conn->query('INSERT INTO big_ids(id, note) VALUES(?, ?), (?, ?)', $values);
    }

    /** @return array<string, string> id => note, read with PDO (no model casting) */
    private function rows(): array
    {
        $rows = [];
        $this->conn->query_and_fetch('SELECT id, note FROM big_ids ORDER BY id', function (array $row) use (&$rows) {
            $rows[(string) $row['id']] = $row['note'];
        });

        return $rows;
    }

    public function test_hydrates_a_value_above_php_int_max_as_the_exact_string()
    {
        $model = BigId::find_by_note('u64 max');

        $this->assert_same(self::U64_MAX, $model->id);
        $this->assert_same(['id' => self::U64_MAX], $model->values_for_pk());
        $this->assert_false($model->is_dirty());
    }

    public function test_values_within_the_int_range_are_still_ints()
    {
        $this->assert_same(PHP_INT_MAX, BigId::find_by_note('int max')->id);
        $this->assert_same(1, BigId::find_by_note('one')->id);
    }

    public function test_assigning_a_value_above_php_int_max_keeps_the_exact_string()
    {
        $model = new BigId();
        $model->id = self::U64_MAX;

        $this->assert_same(self::U64_MAX, $model->id);
    }

    public function test_find_by_pk_tells_neighbouring_values_apart()
    {
        $this->assert_same('u64 max', BigId::find(self::U64_MAX)->note);
        $this->assert_same('u64 max - 1', BigId::find(self::U64_MAX_MINUS_ONE)->note);
        $this->assert_same(self::U64_MAX_MINUS_ONE, BigId::find(self::U64_MAX_MINUS_ONE)->id);
    }

    public function test_save_updates_the_row_it_was_loaded_from()
    {
        $model = BigId::find(self::U64_MAX);
        $model->note = 'touched';
        $this->assert_same(['note' => 'touched'], $model->dirty_attributes());
        $model->save();

        $this->assert_same([
            '1' => 'one',
            (string) PHP_INT_MAX => 'int max',
            self::U64_MAX_MINUS_ONE => 'u64 max - 1',
            self::U64_MAX => 'touched',
        ], $this->rows());
    }

    public function test_delete_removes_the_row_it_was_loaded_from()
    {
        BigId::find(self::U64_MAX)->delete();

        $this->assert_same([
            '1' => 'one',
            (string) PHP_INT_MAX => 'int max',
            self::U64_MAX_MINUS_ONE => 'u64 max - 1',
        ], $this->rows());
    }

    public function test_reload_reads_the_row_it_was_loaded_from()
    {
        $model = BigId::find(self::U64_MAX);
        $values = ['changed', self::U64_MAX];
        $this->conn->query('UPDATE big_ids SET note = ? WHERE id = ?', $values);

        $model->reload();

        $this->assert_same(self::U64_MAX, $model->id);
        $this->assert_same('changed', $model->note);
    }

    public function test_create_then_update_with_a_value_above_php_int_max()
    {
        $id = '18446744073709551613';
        $model = BigId::create(['id' => $id, 'note' => 'created']);
        $this->assert_same($id, $model->id);

        $model->update_attribute('note', 'updated');

        $this->assert_same('updated', BigId::find($id)->note);
        $this->assert_same('u64 max - 1', BigId::find(self::U64_MAX_MINUS_ONE)->note);
    }
}
