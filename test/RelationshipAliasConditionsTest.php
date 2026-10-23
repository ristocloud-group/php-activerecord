<?php

use ActiveRecord\Model;
use ActiveRecord\PgsqlAdapter;
use ActiveRecord\Table;

/**
 * The keys of a relationship's declared hash conditions name the columns of the associated model as its
 * finders read them (#144, spec §6.2.7): an $alias_attribute key, and an attribute name whose column is
 * named differently (#92), in the lazy load and in the eager include alike. An alias next to its column's
 * own name keeps both conditions, ANDed (#129). In a has_many through, which joins the middle table, a key
 * the mapping changed is named with the target's table. Every other declared condition sends the SQL it
 * always did.
 *
 * Fixture data:
 *   venues:       1 New York, 2 "Warner Theatre" (Washington, DC), 6 West Chester, 7 and 8 (VA), 9 "YMCA" (Washington, DC)
 *   events:       1 (venue 1, host 1), 2 "Yeah Yeah Yeahs" (venue 2, host 2), 3 "Love Overboard" (venue 2, host 3),
 *                 5 (venue 6, host 4), 6 (venue 500, host 4), 7 "Blah" (venue 9, host 4); type 'Music' except 7 ('Blah')
 *   authors:      1 (parent 3), 2 (parent 2), 3 (parent 1), 4 (parent 2); books 1 (author 1), 2 (author 2)
 *   book_reviews: 1 (book 1, rating 5), 2 (book 1, rating 3), 3 (book 2, rating 4)
 *   composite_items (author_ref, parent_ref): 1 (1, 3) "keep", 2 (1, 3) "x", 3 (1, 4) "other", 4 (2, 3) "orphan",
 *                 5 (3, 1) "mirror", 6 (3, 1) "x"
 */
class RelationshipAliasConditionsTest extends DatabaseTest
{
    public function tear_down(): void
    {
        // the relationships a test declared are not the model's
        AliasConditionSettableHost::$has_many = [];

        parent::tear_down();
    }

    // ---- each relationship type: the lazy load and the eager include give every owner the same rows ----

    public function test_has_many_conditions_name_an_alias_of_the_associated_model(): void
    {
        // kind is type: venue 9's only event (7) is 'Blah'
        $sql = $this->assert_parity([1 => [1], 2 => [2, 3], 6 => [5], 7 => [], 8 => [], 9 => []], 'AliasConditionVenue', 'music_events');
        $this->assert_sql_has('WHERE (type=?) AND venue_id IN(?,?,?,?,?,?) ORDER BY id asc', $sql);

        $venue = AliasConditionVenue::find(2);
        $this->assert_statement('WHERE (type=?) AND venue_id=? ORDER BY id asc', ['Music', 2], fn() => $venue->music_events);
    }

    public function test_has_one_conditions_name_an_alias_of_the_associated_model(): void
    {
        // headline is title: venue 2's first such event is 3, venue 9's is 7
        $sql = $this->assert_parity([1 => null, 2 => 3, 6 => null, 7 => null, 8 => null, 9 => 7], 'AliasConditionVenue', 'headline_event');
        $this->assert_sql_has('WHERE (title IN(?,?)) AND venue_id IN(?,?,?,?,?,?) ORDER BY id asc', $sql);

        $venue = AliasConditionVenue::find(2);
        $this->assert_statement('WHERE (title IN(?,?)) AND venue_id=? ORDER BY id asc', ['Love Overboard', 'Blah', 2], fn() => $venue->headline_event);
    }

    public function test_belongs_to_conditions_name_an_alias_of_the_associated_model(): void
    {
        // mycity is city: venues 2 and 9 are in Washington, event 6's venue 500 does not exist
        $sql = $this->assert_parity([1 => null, 2 => 2, 3 => 2, 5 => null, 6 => null, 7 => 9], 'AliasConditionEvent', 'washington_venue');
        $this->assert_sql_has('WHERE (city=?) AND id IN(?,?,?,?,?,?)', $sql);

        $event = AliasConditionEvent::find(7);
        $this->assert_statement('WHERE (city=?) AND id=?', ['Washington', 9], fn() => $event->washington_venue);
    }

    // the join shape: the load finds the target, Venue, through events; its aliases are the ones read, and a
    // key they map is named with the target's table
    public function test_has_many_through_conditions_name_an_alias_of_the_target(): void
    {
        // host 4's events are at venues 6 (West Chester), 500 (none) and 9 (Washington)
        $sql = $this->assert_parity([1 => [], 2 => [2], 3 => [2], 4 => [9]], 'AliasConditionHost', 'venues');
        $this->assert_sql_has('WHERE (venues.city=?) AND events.host_id IN(?,?,?,?) ORDER BY venues.id asc', $sql);

        $host = AliasConditionHost::find(4);
        $this->assert_statement('WHERE (venues.city=?) AND events.host_id=? ORDER BY venues.id asc', ['Washington', 4], fn() => $host->venues);
    }

    // the reverse-FK shape (#22): the load finds the target, AliasedBookReview, through books
    public function test_reverse_fk_has_many_through_conditions_name_an_alias_of_the_target(): void
    {
        // score is rating: author 1's reviews are 1 (5) and 2 (3), author 2's is 3 (4)
        $sql = $this->assert_parity([1 => [1], 2 => [3], 3 => [], 4 => []], 'AliasConditionAuthor', 'book_reviews', 'author_id asc', 'author_id');
        $this->assert_sql_has('WHERE (book_reviews.rating IN(?,?)) AND books.author_id IN(?,?,?,?) ORDER BY book_reviews.id asc', $sql);

        $author = AliasConditionAuthor::find(1);
        $this->assert_statement('WHERE (book_reviews.rating IN(?,?)) AND books.author_id=? ORDER BY book_reviews.id asc', [4, 5, 1], fn() => $author->book_reviews);
    }

    // ---- through: a key the mapping changed is named with the target's table, which the middle table joins ----

    // the join shape: venue_number is venues.id, and events has an id too; state, unchanged, stays as declared.
    // The statements are pinned whole: the qualifier is the target's table as the rest of the statement names
    // it, not aliased
    public function test_has_many_through_names_a_mapped_key_with_the_target_table(): void
    {
        $this->assert_parity([1 => [], 2 => [2], 3 => [2], 4 => [9]], 'AliasConditionHost', 'shared_column_venues');

        $host = AliasConditionHost::find(4);
        $this->assert_same([[
            'sql' => $this->on_connection('SELECT `venues`.* FROM `venues` INNER JOIN `events` ON(`venues`.id = `events`.venue_id) WHERE (`venues`.`id` IN(?,?) AND `state`=?) AND `events`.`host_id`=? ORDER BY venues.id asc'),
            'values' => [2, 9, 'DC', 4],
        ]], GoldenStatements::capture(fn() => $host->shared_column_venues));

        $this->assert_same([[
            'sql' => $this->on_connection('SELECT * FROM `hosts` ORDER BY id asc'),
            'values' => [],
        ], [
            'sql' => $this->on_connection('SELECT `venues`.*, `events`.host_id AS host_id FROM `venues` INNER JOIN `events` ON(`venues`.id = `events`.venue_id) WHERE (`venues`.`id` IN(?,?) AND `state`=?) AND `events`.`host_id` IN(?,?,?,?) ORDER BY venues.id asc'),
            'values' => [2, 9, 'DC', 1, 2, 3, 4],
        ]], GoldenStatements::capture(fn() => AliasConditionHost::all(['include' => 'shared_column_venues', 'order' => 'id asc'])));
    }

    // the reverse-FK shape: reviewed_book is book_reviews.book_id, and books has a book_id too; rating, unchanged,
    // stays as declared
    public function test_reverse_fk_has_many_through_names_a_mapped_key_with_the_target_table(): void
    {
        // author 2's review 3 is the one rated 4
        $sql = $this->assert_parity([1 => [], 2 => [3], 3 => [], 4 => []], 'AliasConditionAuthor', 'scored_reviews', 'author_id asc', 'author_id');
        $this->assert_sql_has('WHERE (book_reviews.book_id IN(?,?) AND rating=?) AND books.author_id IN(?,?,?,?) ORDER BY book_reviews.id asc', $sql);

        $author = AliasConditionAuthor::find(2);
        $this->assert_statement('WHERE (book_reviews.book_id IN(?,?) AND rating=?) AND books.author_id=? ORDER BY book_reviews.id asc', [1, 2, 4, 2], fn() => $author->scored_reviews);
    }

    // the exception spec §6.2.7 accepts: an unqualified key named like a column of the middle table (events.title)
    // that is an alias of the target (title is name) names the target's column. No event is titled 'Warner Theatre'
    public function test_through_key_named_like_a_middle_column_and_aliased_by_the_target_names_the_target_column(): void
    {
        $sql = $this->assert_parity([1 => [], 2 => [2], 3 => [2], 4 => []], 'AliasConditionHost', 'titled_venues');
        $this->assert_sql_has('WHERE (venues.name=?) AND events.host_id IN(?,?,?,?) ORDER BY venues.id asc', $sql);

        $host = AliasConditionHost::find(2);
        $this->assert_statement('WHERE (venues.name=?) AND events.host_id=? ORDER BY venues.id asc', ['Warner Theatre', 2], fn() => $host->titled_venues);
    }

    // ---- #129: an alias and its column's own name in one hash are both kept, ANDed ----

    public function test_an_alias_and_its_column_are_both_kept_in_a_has_many(): void
    {
        $sql = $this->assert_parity([1 => [1], 2 => [2, 3], 6 => [5], 7 => [], 8 => [], 9 => []], 'AliasConditionVenue', 'music_events_twice');
        $this->assert_sql_has('WHERE (type=? AND type=?) AND venue_id IN(?,?,?,?,?,?) ORDER BY id asc', $sql);

        // neither wins: 'Music' and 'Blah' together match no event (either alone would match some)
        $sql = $this->assert_parity([1 => [], 2 => [], 6 => [], 7 => [], 8 => [], 9 => []], 'AliasConditionVenue', 'contradicted_events');
        $this->assert_sql_has('WHERE (type=? AND type=?) AND venue_id IN(?,?,?,?,?,?) ORDER BY id asc', $sql);

        $venue = AliasConditionVenue::find(9);
        $this->assert_statement('WHERE (type=? AND type=?) AND venue_id=? ORDER BY id asc', ['Music', 'Blah', 9], fn() => $venue->contradicted_events);
    }

    public function test_an_alias_and_its_column_are_both_kept_in_a_belongs_to(): void
    {
        $this->assert_parity([1 => null, 2 => 2, 3 => 2, 5 => null, 6 => null, 7 => null], 'AliasConditionEvent', 'warner_venue');

        // the binds keep the order of the declared hash, before the key's
        $this->assert_statement(
            'WHERE (name=? AND name=?) AND id IN(?,?,?,?,?,?)',
            ['Warner Theatre', 'YMCA', 1, 2, 2, 6, 500, 9],
            fn() => $this->related(AliasConditionEvent::all(['include' => 'contradicted_venue', 'order' => 'id asc']), 'contradicted_venue'),
            2,
        );

        $event = AliasConditionEvent::find(2);
        $this->assert_statement('WHERE (name=? AND name=?) AND id=?', ['Warner Theatre', 'YMCA', 2], fn() => $event->contradicted_venue);
        $this->assert_parity([1 => null, 2 => null, 3 => null, 5 => null, 6 => null, 7 => null], 'AliasConditionEvent', 'contradicted_venue');
    }

    public function test_an_alias_and_its_column_are_both_kept_in_a_has_one(): void
    {
        $sql = $this->assert_parity([1 => 1, 2 => 2, 6 => 5, 7 => null, 8 => null, 9 => null], 'AliasConditionVenue', 'first_music_event');
        $this->assert_sql_has('WHERE (type=? AND type=?) AND venue_id IN(?,?,?,?,?,?) ORDER BY id asc', $sql);

        // either title alone would match an event of venue 2 (3) or of venue 9 (7)
        $this->assert_parity([1 => null, 2 => null, 6 => null, 7 => null, 8 => null, 9 => null], 'AliasConditionVenue', 'contradicted_event');

        $venue = AliasConditionVenue::find(2);
        $this->assert_statement('WHERE (title=? AND title=?) AND venue_id=? ORDER BY id asc', ['Love Overboard', 'Blah', 2], fn() => $venue->contradicted_event);
    }

    // in a through, the key the mapping changed is named with the target's table, its column's own name as declared
    public function test_an_alias_and_its_column_are_both_kept_in_a_through(): void
    {
        $sql = $this->assert_parity([1 => [], 2 => [2], 3 => [2], 4 => []], 'AliasConditionHost', 'warner_venues_twice');
        $this->assert_sql_has('WHERE (venues.name=? AND name=?) AND events.host_id IN(?,?,?,?) ORDER BY venues.id asc', $sql);

        // host 4's venue 9 is the YMCA: name alone would match it
        $this->assert_parity([1 => [], 2 => [], 3 => [], 4 => []], 'AliasConditionHost', 'contradicted_venues');

        $host = AliasConditionHost::find(4);
        $this->assert_statement('WHERE (venues.name=? AND name=?) AND events.host_id=? ORDER BY venues.id asc', ['Warner Theatre', 'YMCA', 4], fn() => $host->contradicted_venues);
    }

    // a key declared as the qualification writes the alias's column (`venues`.`name`, quoted for the connection)
    // comes to equal the qualified alias in one hash: both are kept, ANDed, not one overwriting the other
    public function test_a_key_declared_as_the_qualified_alias_is_kept_too_in_a_through(): void
    {
        $q = $this->conn::$QUOTE_CHARACTER;
        AliasConditionSettableHost::$has_many = [
            ['events', 'class_name' => 'Event', 'foreign_key' => 'host_id'],
            ['venues', 'through' => 'events', 'class_name' => 'AliasThrough\Venue', 'foreign_key' => 'host_id', 'order' => 'venues.id asc',
                'conditions' => ['title' => 'Warner Theatre', "{$q}venues{$q}.{$q}name{$q}" => 'YMCA']],
        ];

        // host 4's venue 9 is the YMCA: the declared key alone would match it
        $sql = $this->assert_parity([1 => [], 2 => [], 3 => [], 4 => []], 'AliasConditionSettableHost', 'venues');
        $this->assert_sql_has('WHERE (venues.name=? AND venues.name=?) AND events.host_id IN(?,?,?,?) ORDER BY venues.id asc', $sql);

        $host = AliasConditionSettableHost::find(4);
        $this->assert_statement('WHERE (venues.name=? AND venues.name=?) AND events.host_id=? ORDER BY venues.id asc', ['Warner Theatre', 'YMCA', 4], fn() => $host->venues);
    }

    // ---- #92: attribute names whose columns are named differently ----

    public function test_conditions_name_columns_named_differently_from_their_attributes(): void
    {
        // odd_columns: "space name" is the attribute space_name (aliased nick), "camelCase" the attribute camelcase
        foreach ([[1, 'keep', 'x'], [2, 'drop', 'x'], [6, 'keep', 'y'], [9, 'drop', 'y']] as [$id, $space, $camel]) {
            OddColumn::create(['id' => $id, 'space_name' => $space, 'camelcase' => $camel]);
        }

        $sql = $this->assert_parity([1 => 1, 2 => null, 3 => null, 5 => 6, 6 => null, 7 => null], 'AliasConditionOddEvent', 'nick_kept');
        $this->assert_sql_has('WHERE (space name=?) AND id IN(?,?,?,?,?,?)', $sql);

        // camelcase is "camelCase" where quoted names are case-sensitive (Postgres); elsewhere it matches as written
        $camel = $this->conn->column_names_are_case_sensitive() ? 'camelCase' : 'camelcase';
        $sql = $this->assert_parity([1 => 1, 2 => null, 3 => null, 5 => null, 6 => null, 7 => null], 'AliasConditionOddEvent', 'space_kept');
        $this->assert_sql_has("WHERE (space name=? AND $camel=?) AND id IN(?,?,?,?,?,?)", $sql);

        // the alias and the attribute name both stand for "space name": both kept, ANDed
        $sql = $this->assert_parity([1 => null, 2 => null, 3 => null, 5 => null, 6 => null, 7 => null], 'AliasConditionOddEvent', 'nick_contradicted');
        $this->assert_sql_has('WHERE (space name=? AND space name=?) AND id IN(?,?,?,?,?,?)', $sql);

        $event = AliasConditionOddEvent::find(1);
        $this->assert_statement('WHERE (space name=? AND space name=?) AND id=?', ['keep', 'drop', 1], fn() => $event->nick_contradicted);
    }

    // in a through (the reverse-FK shape, odd_columns.id = books.book_id), a name the mapping changed is named with
    // the target's table: space_name everywhere, camelcase on Postgres only
    public function test_through_conditions_name_columns_named_differently_from_their_attributes(): void
    {
        foreach ([[1, 'keep', 'x'], [2, 'keep', 'y']] as [$id, $space, $camel]) {
            OddColumn::create(['id' => $id, 'space_name' => $space, 'camelcase' => $camel]);
        }

        // author 1's book 1 is odd_columns 1, author 2's book 2 odd_columns 2
        $camel = $this->conn->column_names_are_case_sensitive() ? 'odd_columns.camelCase' : 'camelcase';
        $sql = $this->assert_parity([1 => [1], 2 => [], 3 => [], 4 => []], 'AliasConditionAuthor', 'odd_columns', 'author_id asc', 'author_id');
        $this->assert_sql_has("WHERE (odd_columns.space name=? AND $camel=?) AND books.author_id IN(?,?,?,?) ORDER BY odd_columns.id asc", $sql);

        $author = AliasConditionAuthor::find(1);
        $this->assert_statement("WHERE (odd_columns.space name=? AND $camel=?) AND books.author_id=? ORDER BY odd_columns.id asc", ['keep', 'x', 1], fn() => $author->odd_columns);
    }

    // ---- composite keys: one bind per placeholder after the mapped conditions (#116) ----

    public function test_composite_keys_bind_one_value_per_placeholder_after_mapped_conditions(): void
    {
        // label is title: author 1's key (1, 3) has items 1 "keep" and 2 "x", author 3's (3, 1) items 5 "mirror" and 6 "x"
        $sql = $this->assert_parity([1 => [1], 2 => [], 3 => [5], 4 => []], 'AliasConditionCompositeAuthor', 'items', 'author_id asc', 'author_id');
        $this->assert_sql_has('WHERE (title IN(?,?,?)) AND ((author_ref=? AND parent_ref=?) OR (author_ref=? AND parent_ref=?) OR', $sql);

        // both kept: title in (keep, mirror, x) and in (x, keep)
        $this->assert_parity([1 => [1, 2], 2 => [], 3 => [6], 4 => []], 'AliasConditionCompositeAuthor', 'labelled_items', 'author_id asc', 'author_id');

        $author = AliasConditionCompositeAuthor::find(1);
        $this->assert_statement(
            'WHERE (title IN(?,?,?) AND title IN(?,?)) AND author_ref=? AND parent_ref=? ORDER BY id asc',
            ['keep', 'mirror', 'x', 'x', 'keep', 1, 3],
            fn() => $author->labelled_items,
        );

        $this->assert_statement(
            'WHERE (title IN(?,?,?) AND title IN(?,?)) AND ((author_ref=? AND parent_ref=?) OR (author_ref=? AND parent_ref=?) OR (author_ref=? AND parent_ref=?) OR (author_ref=? AND parent_ref=?)) ORDER BY id asc',
            ['keep', 'mirror', 'x', 'x', 'keep', 1, 3, 2, 2, 3, 1, 4, 2],
            fn() => $this->related(AliasConditionCompositeAuthor::all(['include' => 'labelled_items', 'order' => 'author_id asc']), 'labelled_items'),
            2,
        );
    }

    // ---- every other declared condition: the very SQL and binds it always had ----

    // recorded before #144's change (f927646) on the four connections: they differ only by the quote
    // character and, on Postgres, the LIMIT. A qualified key stays as written, as in finders.
    public function test_conditions_without_aliases_send_the_statements_they_always_did(): void
    {
        $this->assert_parity([1 => null, 2 => 2, 3 => 2, 5 => null, 6 => null, 7 => 9], 'AliasConditionEvent', 'plain_venue');
        $this->assert_parity([1 => [], 2 => [2], 3 => [], 4 => [9]], 'AliasConditionHost', 'plain_venues');

        $event = AliasConditionEvent::find(2);
        $this->assert_same([[
            'sql' => $this->on_connection('SELECT * FROM `venues` WHERE (`city`=? AND (`state` IN(?) OR `state` IS NULL) AND `venues`.`name` IN(?,?)) AND `id`=? LIMIT 0,1'),
            'values' => ['Washington', 'DC', 'Warner Theatre', 'YMCA', 2],
        ]], GoldenStatements::capture(fn() => $event->plain_venue));

        $this->assert_same([[
            'sql' => $this->on_connection('SELECT * FROM `events` ORDER BY id asc'),
            'values' => [],
        ], [
            'sql' => $this->on_connection('SELECT * FROM `venues` WHERE (`city`=? AND (`state` IN(?) OR `state` IS NULL) AND `venues`.`name` IN(?,?)) AND `id` IN(?,?,?,?,?,?)'),
            'values' => ['Washington', 'DC', 'Warner Theatre', 'YMCA', 1, 2, 2, 6, 500, 9],
        ]], GoldenStatements::capture(fn() => AliasConditionEvent::all(['include' => 'plain_venue', 'order' => 'id asc'])));

        $host = AliasConditionHost::find(4);
        $this->assert_same([[
            'sql' => $this->on_connection('SELECT `venues`.* FROM `venues` INNER JOIN `events` ON(`venues`.id = `events`.venue_id) WHERE (`events`.`title` IN(?,?) AND `state`=?) AND `events`.`host_id`=? ORDER BY venues.id asc'),
            'values' => ['Yeah Yeah Yeahs', 'Blah', 'DC', 4],
        ]], GoldenStatements::capture(fn() => $host->plain_venues));

        $this->assert_same([[
            'sql' => $this->on_connection('SELECT * FROM `hosts` ORDER BY id asc'),
            'values' => [],
        ], [
            'sql' => $this->on_connection('SELECT `venues`.*, `events`.host_id AS host_id FROM `venues` INNER JOIN `events` ON(`venues`.id = `events`.venue_id) WHERE (`events`.`title` IN(?,?) AND `state`=?) AND `events`.`host_id` IN(?,?,?,?) ORDER BY venues.id asc'),
            'values' => ['Yeah Yeah Yeahs', 'Blah', 'DC', 1, 2, 3, 4],
        ]], GoldenStatements::capture(fn() => AliasConditionHost::all(['include' => 'plain_venues', 'order' => 'id asc'])));
    }

    // ---- helpers ----

    /**
     * $sql as the connection under test writes it: its quote character for the backticks, and on Postgres
     * its LIMIT.
     */
    private function on_connection(string $sql): string
    {
        $sql = str_replace('`', $this->conn::$QUOTE_CHARACTER, $sql);

        return $this->conn instanceof PgsqlAdapter ? str_replace('LIMIT 0,1', 'LIMIT 1 OFFSET 0', $sql) : $sql;
    }

    /**
     * Asserts that $load sends $count statements, the last of which has $sql in its SQL (quotes aside)
     * and $values as its binds, in order.
     *
     * @param list<mixed> $values
     */
    private function assert_statement(string $sql, array $values, \Closure $load, int $count = 1): void
    {
        $statements = GoldenStatements::capture($load);
        $this->assert_count($count, $statements, json_encode($statements) ?: '');

        $last = end($statements);
        $this->assert_string_contains_string($sql, str_replace(['"', '`'], '', $last['sql'] ?? ''));
        $this->assert_same($values, $last['values'] ?? null);
    }

    /**
     * What a relationship gives each owner.
     *
     * @param list<Model> $owners
     * @return list<mixed>
     */
    private function related(array $owners, string $relationship): array
    {
        return array_map(fn(Model $owner) => $owner->$relationship, $owners);
    }

    /**
     * @param list<Model>|Model|null $related
     * @return list<mixed>|mixed
     */
    private function ids($related, string $pk)
    {
        if (null === $related) {
            return null;
        }

        if ($related instanceof Model) {
            return $related->$pk;
        }

        return array_map(fn(Model $model) => $model->$pk, $related);
    }

    /**
     * owner key => the ids the relationship gives it, loaded lazily or eagerly.
     *
     * @param class-string<Model> $class
     * @return array<int|string, mixed>
     */
    private function load(string $class, string $relationship, bool $eager, string $order, string $owner_pk, string $pk): array
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
     * Asserts lazy == $expected == eager, and returns the last SQL of the associated table: the eager load's.
     *
     * @param class-string<Model> $class
     * @param array<int|string, mixed> $expected
     */
    private function assert_parity(array $expected, string $class, string $relationship, string $order = 'id asc', string $owner_pk = 'id', string $pk = 'id'): string
    {
        $this->assert_same($expected, $this->load($class, $relationship, false, $order, $owner_pk, $pk), 'lazy');
        $this->assert_same($expected, $this->load($class, $relationship, true, $order, $owner_pk, $pk), 'eager');

        $associated = Table::load($class)->get_relationship($relationship)?->class_name;
        $this->assert_not_null($associated);

        return (string) $associated::table()->last_sql;
    }
}
