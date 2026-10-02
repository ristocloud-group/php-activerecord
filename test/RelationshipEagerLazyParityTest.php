<?php

/*
 * An eager `include` must give every owner exactly what the lazy load gives it
 * (GH #40 follow-ups). Fixture data used below:
 *   venues:          1, 2, 6, 7, 8, 9 (tier 5)
 *   events:          1 (venue 1, host 1), 2 (venue 2, host 2), 3 (venue 2, host 3),
 *                    5 (venue 6, host 4), 6 (venue 500, host 4), 7 (venue 9, host 4)
 *                    types: 'Music' except 7 ('Blah')
 *   authors:         1 (parent 3), 2 (parent 2), 3 (parent 1), 4 (parent 2)
 *   books:           1 (author 1), 2 (author 2); book_reviews: 1, 2 (book 1), 3 (book 2)
 *   composite_items: 1 (1, 3) "keep"    2 (1, 3) "x"
 *                    3 (1, 4) "other"   4 (2, 3) "orphan"
 *                    5 (3, 1) "mirror"  6 (3, 1) "x"
 */

// (a) join/belongs_to `through` shape that declares primary_key: venue.tier keys events.venue_id
class ParityTierVenue extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $has_many = [
        ['events', 'class_name' => 'Event', 'foreign_key' => 'venue_id', 'order' => 'id asc'],
        ['hosts_by_tier', 'class_name' => 'Host', 'through' => 'events', 'source' => 'host',
            'foreign_key' => 'venue_id', 'primary_key' => 'tier', 'order' => 'hosts.id asc'],
    ];
}

// (a) reverse-FK `through` shape whose middle has_many declares primary_key
class ParityReviewedBook extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $has_many = [['book_reviews', 'class_name' => 'BookReview', 'foreign_key' => 'book_id', 'order' => 'id asc']];
}

class ParityParentKeyedAuthor extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [
        ['books', 'class_name' => 'ParityReviewedBook', 'foreign_key' => 'author_id',
            'primary_key' => 'parent_author_id', 'order' => 'book_id asc'],
        ['book_reviews', 'class_name' => 'BookReview', 'through' => 'books', 'foreign_key' => 'author_id',
            'order' => 'book_reviews.id asc'],
    ];
}

class RelationshipEagerLazyParityTest extends DatabaseTest
{
    /**
     * @param list<ActiveRecord\Model>|ActiveRecord\Model|null $related
     * @return list<mixed>|mixed
     */
    private function ids($related, string $pk)
    {
        if (null === $related) {
            return null;
        }
        if ($related instanceof ActiveRecord\Model) {
            return $related->$pk;
        }

        return array_map(fn($model) => $model->$pk, $related);
    }

    /**
     * owner key => what the relationship gives it, loaded lazily or eagerly.
     *
     * @param class-string<ActiveRecord\Model> $class
     * @return array<int|string, mixed>
     */
    private function load(string $class, string $relationship, bool $eager, string $order = 'id asc', string $owner_pk = 'id', string $pk = 'id'): array
    {
        $options = ['order' => $order];
        if ($eager) {
            $options['include'] = $relationship;
        }

        $out = [];
        foreach ($class::find('all', $options) as $owner) {
            $out[$owner->$owner_pk] = $this->ids($owner->$relationship, $pk);
        }

        return $out;
    }

    /**
     * Asserts lazy == $expected == eager and returns the last SQL of the related table: the eager load's.
     *
     * @param class-string<ActiveRecord\Model> $class
     * @param array<int|string, mixed> $expected
     */
    private function assert_parity(array $expected, string $class, string $relationship, string $order = 'id asc', string $owner_pk = 'id', string $pk = 'id'): string
    {
        $this->assert_same($expected, $this->load($class, $relationship, false, $order, $owner_pk, $pk), 'lazy');
        $this->assert_same($expected, $this->load($class, $relationship, true, $order, $owner_pk, $pk), 'eager');

        $related = ActiveRecord\Table::load($class)->get_relationship($relationship)?->class_name;
        $this->assert_not_null($related);

        return $related::table()->last_sql;
    }

    // ---- (a) through + declared primary_key ----

    public function test_eager_through_join_shape_keys_off_the_declared_primary_key()
    {
        $this->conn->query('UPDATE venues SET tier = 2 WHERE id = 1');

        // venue 1 (tier 2) -> events of venue 2 -> hosts 2, 3; every other tier (5) has no events
        $sql = $this->assert_parity([1 => [2, 3], 2 => [], 6 => [], 7 => [], 8 => [], 9 => []], 'ParityTierVenue', 'hosts_by_tier');
        $this->assert_sql_has('WHERE venue_id IN(?,?,?,?,?,?) ORDER BY hosts.id asc', $sql);
    }

    public function test_eager_reverse_fk_through_keys_off_the_middle_declared_primary_key()
    {
        // books keyed by parent_author_id: author 1 -> [], 2 -> [2], 3 -> [1], 4 -> [2]
        $expected = [1 => [], 2 => [3], 3 => [1, 2], 4 => [3]];

        $this->assert_parity($expected, 'ParityParentKeyedAuthor', 'book_reviews', 'author_id asc', 'author_id');
        // and in the other order (eager first, then lazy), on a fresh relationship
        ActiveRecord\Table::clear_cache('ParityParentKeyedAuthor');
        $this->assert_same($expected, $this->load('ParityParentKeyedAuthor', 'book_reviews', true, 'author_id asc', 'author_id'));
    }

    public function test_eager_through_gives_nothing_to_an_owner_whose_declared_key_is_null()
    {
        $this->conn->query('UPDATE venues SET tier = NULL WHERE id <> 1');
        $this->conn->query('UPDATE venues SET tier = 2 WHERE id = 1');

        $lazy = $this->load('ParityTierVenue', 'hosts_by_tier', false);
        $this->assert_same([2, 3], $lazy[1]);
        $this->assert_null($lazy[2]);

        $eager = $this->load('ParityTierVenue', 'hosts_by_tier', true);
        $this->assert_same([1 => [2, 3], 2 => [], 6 => [], 7 => [], 8 => [], 9 => []], $eager);
    }
}
