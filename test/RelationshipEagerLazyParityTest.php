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

// (b) composite keys: declared, declared + conditions, and a composite table pk
class ParityCompositeItem extends ActiveRecord\Model
{
    public static $table_name = 'composite_items';
}

class ParityCompositeAuthor extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [
        ['items', 'class_name' => 'ParityCompositeItem', 'foreign_key' => ['author_ref', 'parent_ref'],
            'primary_key' => ['author_id', 'parent_author_id'], 'order' => 'id asc'],
        ['kept_items', 'class_name' => 'ParityCompositeItem', 'foreign_key' => ['author_ref', 'parent_ref'],
            'primary_key' => ['author_id', 'parent_author_id'], 'conditions' => ['title <> ?', 'x'], 'order' => 'id desc'],
    ];
}

class ParityPairKeyedAuthor extends ActiveRecord\Model
{
    public static $pk = ['author_id', 'parent_author_id'];
    public static $table_name = 'authors';
    public static $has_many = [['items', 'class_name' => 'ParityCompositeItem', 'foreign_key' => ['author_ref', 'parent_ref'], 'order' => 'id asc']];
}

// (c) has_one with several children; (e) declared limit/offset
class ParityVenue extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $has_many = [
        ['limited_events', 'class_name' => 'Event', 'foreign_key' => 'venue_id', 'order' => 'id asc', 'limit' => 1],
        ['offset_events', 'class_name' => 'Event', 'foreign_key' => 'venue_id', 'order' => 'id asc', 'offset' => 1],
        ['no_events', 'class_name' => 'Event', 'foreign_key' => 'venue_id', 'order' => 'id asc', 'limit' => 0],
        ['window_events', 'class_name' => 'Event', 'foreign_key' => 'venue_id', 'order' => 'id desc', 'limit' => '1', 'offset' => 1],
    ];
    public static $has_one = [
        ['first_event', 'class_name' => 'Event', 'foreign_key' => 'venue_id', 'order' => 'id asc'],
        ['latest_event', 'class_name' => 'Event', 'foreign_key' => 'venue_id', 'order' => 'id desc'],
        ['offset_event', 'class_name' => 'Event', 'foreign_key' => 'venue_id', 'order' => 'id asc', 'offset' => 1, 'limit' => 0],
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

    // ---- (b) composite keys ----

    public function test_eager_composite_keys_query_and_match_every_pair()
    {
        $sql = $this->assert_parity([1 => [1, 2], 2 => [], 3 => [5, 6], 4 => []], 'ParityCompositeAuthor', 'items', 'author_id asc', 'author_id');
        $this->assert_sql_has('WHERE ((author_ref=? AND parent_ref=?) OR (author_ref=? AND parent_ref=?) OR (author_ref=? AND parent_ref=?) OR (author_ref=? AND parent_ref=?)) ORDER BY id asc', $sql);
    }

    public function test_eager_composite_keys_keep_declared_conditions_and_order()
    {
        $sql = $this->assert_parity([1 => [1], 2 => [], 3 => [5], 4 => []], 'ParityCompositeAuthor', 'kept_items', 'author_id asc', 'author_id');
        $this->assert_sql_has('WHERE (title <> ?) AND ((author_ref=? AND parent_ref=?) OR', $sql);
    }

    public function test_eager_composite_table_primary_key_matches_every_pair()
    {
        $this->assert_parity([1 => [1, 2], 2 => [], 3 => [5, 6], 4 => []], 'ParityPairKeyedAuthor', 'items', 'author_id asc', 'author_id');
    }

    public function test_eager_composite_key_with_a_null_part_matches_like_the_lazy_load()
    {
        $owner = ParityCompositeAuthor::create(['name' => 'no parent']);
        $null_part = (int) ParityCompositeItem::create(['author_ref' => $owner->author_id, 'title' => 'null part'])->id;
        ParityCompositeItem::create(['author_ref' => $owner->author_id, 'parent_ref' => 0, 'title' => 'zero part']);
        ParityCompositeItem::create(['author_ref' => $owner->author_id, 'parent_ref' => 3, 'title' => 'other part']);

        // lazy: author_ref = ? AND parent_ref IS NULL
        $this->assert_same([$null_part], $this->ids(ParityCompositeAuthor::find($owner->author_id)->items, 'id'));

        $eager = $this->load('ParityCompositeAuthor', 'items', true, 'author_id asc', 'author_id');
        $this->assert_same([$null_part], $eager[$owner->author_id]);
        $this->assert_same([1, 2], $eager[1]);
    }

    // ---- (c) has_one keeps the first match ----

    public function test_eager_has_one_keeps_the_first_match_in_the_declared_order()
    {
        $this->assert_parity([1 => 1, 2 => 2, 6 => 5, 7 => null, 8 => null, 9 => 7], 'ParityVenue', 'first_event');
        $this->assert_parity([1 => 1, 2 => 3, 6 => 5, 7 => null, 8 => null, 9 => 7], 'ParityVenue', 'latest_event');
    }

    // ---- (e) declared limit / offset apply per owner ----

    public function test_eager_limit_applies_per_owner()
    {
        $sql = $this->assert_parity([1 => [1], 2 => [2], 6 => [5], 7 => [], 8 => [], 9 => [7]], 'ParityVenue', 'limited_events');
        $this->assert_sql_doesnt_has('LIMIT', $sql);
    }

    public function test_eager_offset_without_limit_applies_per_owner()
    {
        $this->assert_parity([1 => [], 2 => [3], 6 => [], 7 => [], 8 => [], 9 => []], 'ParityVenue', 'offset_events');
    }

    public function test_eager_limit_zero_gives_every_owner_no_children()
    {
        $this->assert_parity([1 => [], 2 => [], 6 => [], 7 => [], 8 => [], 9 => []], 'ParityVenue', 'no_events');
    }

    public function test_eager_limit_and_offset_window_applies_per_owner()
    {
        Event::create(['venue_id' => 2, 'host_id' => 1, 'title' => 'third', 'type' => 'Music']);

        // venue 2 by id desc: [new, 3, 2] -> skip 1, take 1
        $this->assert_parity([1 => [], 2 => [3], 6 => [], 7 => [], 8 => [], 9 => []], 'ParityVenue', 'window_events');
    }

    public function test_eager_has_one_ignores_a_declared_limit_and_offset_like_the_lazy_load()
    {
        $sql = $this->assert_parity([1 => 1, 2 => 2, 6 => 5, 7 => null, 8 => null, 9 => 7], 'ParityVenue', 'offset_event');
        $this->assert_sql_doesnt_has('LIMIT', $sql);
        $this->assert_sql_doesnt_has('OFFSET', $sql);
    }
}
