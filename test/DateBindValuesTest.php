<?php

use PHPUnit\Framework\Attributes\DataProvider;

/*
 * A \DateTime / \DateTimeImmutable bind value works in exists(), count(), update_all()
 * and delete_all() like in the finders (#138): those paths bound it raw and PDO threw
 * "Error: Object of class DateTime could not be converted to string". It is bound in the
 * connection's datetime format, as find()/all() bind it (positional and hash conditions,
 * IN lists); an update_all() 'set' hash formats it for its column, as save() does.
 *
 * Fixture data (dated_counts: day DATE, at DATETIME, hits INT, seen_at DATETIME):
 *   2026-01-01 | 2026-01-01 10:00:00 | 1 | null
 *   2026-01-02 | 2026-01-02 10:00:00 | 2 | null
 */
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
