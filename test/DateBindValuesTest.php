<?php

use PHPUnit\Framework\Attributes\DataProvider;

/*
 * A \DateTime / \DateTimeImmutable / ActiveRecord\DateTime bind value works in exists(),
 * count(), update_all() and delete_all() like in the finders (#138): those paths bound it raw,
 * so PDO threw "Error: Object of class DateTime could not be converted to string" for a native
 * date, and bound an ActiveRecord\DateTime as its RFC 2822 __toString() text. It is bound in the
 * connection's datetime format, as find()/all() bind it (positional and hash conditions,
 * IN lists); an update_all() 'set' hash formats it for its column, as save() does.
 *
 * Fixture data (dated_counts: day DATE, at DATETIME, hits INT, seen_at DATETIME):
 *   2026-01-01 | 2026-01-01 10:00:00 | 1 | null
 *   2026-01-02 | 2026-01-02 10:00:00 | 2 | null
 */
// uniqueness of the DATETIME column `at` (pk day: "day != ? AND at = ?")
class DateBindUniqueAt extends ActiveRecord\Model
{
    public static $table_name = 'dated_counts';
    public static $primary_key = 'day';
    public static $validates_uniqueness_of = [['at']];
}

// uniqueness of the DATE column `day` (pk at: "at != ? AND day = ?")
class DateBindUniqueDay extends ActiveRecord\Model
{
    public static $table_name = 'dated_counts';
    public static $primary_key = 'at';
    public static $validates_uniqueness_of = [['day']];
}

class DateBindValuesTest extends DatabaseTest
{
    /**
     * @return array<string, array{class-string<\DateTimeInterface>}>
     */
    public static function date_classes(): array
    {
        return [
            'DateTime' => [\DateTime::class],
            'DateTimeImmutable' => [\DateTimeImmutable::class],
            'ActiveRecord\\DateTime' => [ActiveRecord\DateTime::class],
        ];
    }

    /**
     * @param class-string<\DateTimeInterface> $class
     */
    private function date(string $class, string $value): \DateTimeInterface
    {
        return new $class($value);
    }

    /**
     * @return list<int>
     */
    private function hits(): array
    {
        return array_map(fn(DatedCount $row) => (int) $row->hits, DatedCount::all(['order' => 'hits']));
    }

    /**
     * @param class-string<\DateTimeInterface> $class
     */
    #[DataProvider('date_classes')]
    public function test_exists_binds_a_date(string $class)
    {
        $this->assert_true(DatedCount::exists(['conditions' => ['at < ?', $this->date($class, '2026-01-02')]]));
        $this->assert_false(DatedCount::exists(['conditions' => ['at < ?', $this->date($class, '2026-01-01')]]));
        $this->assert_true(DatedCount::exists(['at' => $this->date($class, '2026-01-02 10:00:00')]));
        $this->assert_false(DatedCount::exists(['at' => $this->date($class, '2026-01-02 11:00:00')]));
        $this->assert_true(DatedCount::exists(['at' => [$this->date($class, '2026-01-03'), $this->date($class, '2026-01-02 10:00:00')]]));
        $this->assert_false(DatedCount::exists(['conditions' => ['at IN(?)', [$this->date($class, '2026-01-03'), $this->date($class, '2026-01-04')]]]));
    }

    /**
     * @param class-string<\DateTimeInterface> $class
     */
    #[DataProvider('date_classes')]
    public function test_count_binds_a_date(string $class)
    {
        $first = $this->date($class, '2026-01-01 10:00:00');
        $second = $this->date($class, '2026-01-02 10:00:00');

        $this->assert_equals(1, DatedCount::count(['conditions' => ['at < ?', $this->date($class, '2026-01-02')]]));
        $this->assert_equals(1, DatedCount::count(['conditions' => ['at' => $first]]));
        $this->assert_equals(1, DatedCount::count(['at' => $second]));
        $this->assert_equals(2, DatedCount::count(['conditions' => ['at' => [$first, $second]]]));
        $this->assert_equals(2, DatedCount::count(['conditions' => ['at IN(?)', [$first, $second]]]));
        $this->assert_equals(1, DatedCount::count(['conditions' => ['at >= ? AND hits > ?', $first, 1]]));
    }

    /**
     * @param class-string<\DateTimeInterface> $class
     */
    #[DataProvider('date_classes')]
    public function test_count_and_exists_agree_with_all(string $class)
    {
        foreach ([['at < ?', $this->date($class, '2026-01-01 12:00:00')], ['at' => $this->date($class, '2026-01-02 10:00:00')]] as $conditions) {
            $found = count(DatedCount::all(['conditions' => $conditions]));

            $this->assert_equals(1, $found);
            $this->assert_equals($found, DatedCount::count(['conditions' => $conditions]));
            $this->assert_true(DatedCount::exists(['conditions' => $conditions]));
        }
    }

    /**
     * @param class-string<\DateTimeInterface> $class
     */
    #[DataProvider('date_classes')]
    public function test_update_all_binds_a_date_in_set_and_conditions(string $class)
    {
        $affected = DatedCount::update_all([
            'set' => ['seen_at' => $this->date($class, '2026-02-03 04:05:06')],
            'conditions' => ['at < ?', $this->date($class, '2026-01-02')],
        ]);

        $this->assert_equals(1, $affected);
        $this->assert_equals(1, DatedCount::count(['conditions' => ['seen_at' => '2026-02-03 04:05:06']]));
        $this->assert_equals(1, DatedCount::count(['conditions' => ['seen_at IS NULL']]));
        $this->assert_equals(1, DatedCount::find_by_seen_at('2026-02-03 04:05:06')->hits);
    }

    /**
     * @param class-string<\DateTimeInterface> $class
     */
    #[DataProvider('date_classes')]
    public function test_update_all_binds_a_date_in_hash_and_in_conditions(string $class)
    {
        $this->assert_equals(1, DatedCount::update_all([
            'set' => ['hits' => 10],
            'conditions' => ['at' => $this->date($class, '2026-01-01 10:00:00')],
        ]));
        $this->assert_equals(2, DatedCount::update_all([
            'set' => ['hits' => 20, 'seen_at' => $this->date($class, '2026-02-03 04:05:06')],
            'conditions' => ['at' => [$this->date($class, '2026-01-01 10:00:00'), $this->date($class, '2026-01-02 10:00:00')]],
        ]));
        $this->assert_equals(0, DatedCount::update_all([
            'set' => ['hits' => 30],
            'conditions' => ['at IN(?)', [$this->date($class, '2026-01-03'), $this->date($class, '2026-01-04')]],
        ]));
        $this->assert_equals([20, 20], $this->hits());
        $this->assert_equals(2, DatedCount::count(['conditions' => ['seen_at' => '2026-02-03 04:05:06']]));
    }

    /**
     * A DATE column in the 'set' hash is written as the date it stores, like save() writes
     * it (SQLite keeps the bound text as is, so a datetime string would not match a date).
     *
     * @param class-string<\DateTimeInterface> $class
     */
    #[DataProvider('date_classes')]
    public function test_update_all_sets_a_date_column_in_the_date_format(string $class)
    {
        $affected = DatedCount::update_all([
            'set' => ['day' => $this->date($class, '2026-03-04 15:16:17')],
            'conditions' => ['at' => $this->date($class, '2026-01-02 10:00:00')],
        ]);

        $this->assert_equals(1, $affected);
        $this->assert_equals(2, DatedCount::find_by_day('2026-03-04')->hits);
    }

    /**
     * @param class-string<\DateTimeInterface> $class
     */
    #[DataProvider('date_classes')]
    public function test_delete_all_binds_a_date(string $class)
    {
        $this->assert_equals(0, DatedCount::delete_all(['conditions' => ['at < ?', $this->date($class, '2026-01-01')]]));
        $this->assert_equals([1, 2], $this->hits());

        $this->assert_equals(1, DatedCount::delete_all(['conditions' => ['at < ?', $this->date($class, '2026-01-02')]]));
        $this->assert_equals([2], $this->hits());

        $this->assert_equals(1, DatedCount::delete_all(['conditions' => ['at' => [$this->date($class, '2026-01-02 10:00:00')]]]));
        $this->assert_equals([], $this->hits());
    }

    /**
     * @param class-string<\DateTimeInterface> $class
     */
    #[DataProvider('date_classes')]
    public function test_delete_all_binds_a_date_in_a_scalar_hash(string $class)
    {
        $this->assert_equals(0, DatedCount::delete_all(['conditions' => ['at' => $this->date($class, '2026-01-01 11:00:00')]]));
        $this->assert_equals(1, DatedCount::delete_all(['conditions' => ['at' => $this->date($class, '2026-01-01 10:00:00')]]));
        $this->assert_equals([2], $this->hits());
    }

    /**
     * An ActiveRecord\DateTime was bound as its __toString() text ('Fri, 02 Jan 2026 00:00:00 +0000'),
     * which SQLite compares as text: every stored '2026-...' sorts before 'F', so delete_all() of
     * the rows older than the date deleted them all (MySQL rejected the value instead).
     */
    public function test_delete_all_with_an_ar_datetime_deletes_only_the_older_rows()
    {
        $ar_datetime = new ActiveRecord\DateTime('2026-01-02');

        $this->assert_equals(1, DatedCount::delete_all(['conditions' => ['at < ?', $ar_datetime]]));
        $this->assert_equals([2], $this->hits());
    }

    /**
     * update_all() writes an ActiveRecord\DateTime in the column's format, as save() does, not as
     * its RFC 2822 __toString() text (which SQLite stored verbatim and MySQL rejected).
     */
    public function test_update_all_sets_an_ar_datetime_in_the_column_format()
    {
        $affected = DatedCount::update_all([
            'set' => ['seen_at' => new ActiveRecord\DateTime('2026-02-03 04:05:06'), 'day' => new ActiveRecord\DateTime('2026-03-04 15:16:17')],
            'conditions' => ['hits' => 2],
        ]);

        $this->assert_equals(1, $affected);
        $row = DatedCount::connection()->query('SELECT day, seen_at FROM dated_counts WHERE hits = 2')->fetch(\PDO::FETCH_NUM);
        $this->assert_equals('2026-03-04', $row[0]);
        $this->assert_equals('2026-02-03 04:05:06', $row[1]);
    }

    /**
     * validates_uniqueness_of binds the model's ActiveRecord\DateTime through exists(): before #138
     * MySQL/MariaDB rejected its RFC 2822 text on every is_valid() and SQLite never found the
     * duplicate. A DATETIME column now finds it on every adapter.
     */
    public function test_validates_uniqueness_of_a_datetime_column()
    {
        $duplicate = new DateBindUniqueAt(['day' => '2026-05-05', 'at' => '2026-01-01 10:00:00']);
        $this->assert_instance_of(ActiveRecord\DateTime::class, $duplicate->at);
        $this->assert_false($duplicate->is_valid());
        $this->assert_true($duplicate->errors->is_invalid('at'));

        $this->assert_true((new DateBindUniqueAt(['day' => '2026-05-05', 'at' => '2026-01-01 11:00:00']))->is_valid());
    }

    /**
     * A DATE column's value is bound in the datetime format, as the finders bind a condition
     * ('2026-01-01 00:00:00'): MySQL/MariaDB/Postgres compare it as a date and find the duplicate;
     * SQLite compares it as text with the stored '2026-01-01' and does not (as before #138, and as
     * find_by_day() with an ActiveRecord\DateTime does). Pinned, not fixed here.
     */
    public function test_validates_uniqueness_of_a_date_column()
    {
        $duplicate = new DateBindUniqueDay(['day' => '2026-01-01', 'at' => '2026-09-09 09:00:00']);
        $this->assert_instance_of(ActiveRecord\DateTime::class, $duplicate->day);

        $this->assert_equals('sqlite' !== $this->conn->protocol, !$duplicate->is_valid());
        $this->assert_true((new DateBindUniqueDay(['day' => '2026-01-05', 'at' => '2026-09-09 09:00:00']))->is_valid());
    }

    /**
     * count_by_*() goes through count(): an ActiveRecord\DateTime is bound in the datetime format
     * (before #138: a DatabaseException on MySQL/MariaDB, 0 on SQLite).
     */
    public function test_count_by_with_an_ar_datetime()
    {
        $this->assert_equals(1, DatedCount::count_by_at(new ActiveRecord\DateTime('2026-01-02 10:00:00')));
        $this->assert_equals(0, DatedCount::count_by_at(new ActiveRecord\DateTime('2026-01-02 11:00:00')));
        $this->assert_equals(1, DatedCount::count_by_at_and_hits(new ActiveRecord\DateTime('2026-01-01 10:00:00'), 1));
    }

    public function test_non_date_values_still_bind_as_given()
    {
        $this->assert_equals(1, DatedCount::count(['conditions' => ['hits > ?', 1]]));
        $this->assert_equals(2, DatedCount::count(['conditions' => ['at' => ['2026-01-01 10:00:00', '2026-01-02 10:00:00']]]));
        $this->assert_true(DatedCount::exists(['conditions' => ['at = ?', '2026-01-01 10:00:00']]));
        $this->assert_equals(1, DatedCount::update_all(['set' => ['hits' => 5, 'seen_at' => null], 'conditions' => ['hits' => 1]]));
        $this->assert_equals(1, DatedCount::delete_all(['conditions' => ['hits' => 5]]));
        $this->assert_equals([2], $this->hits());
    }
}
