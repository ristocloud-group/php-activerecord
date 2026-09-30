<?php

use ActiveRecord\Table;

/*
 * Fixture models for relationship `conditions` whose own SQL contains OR.
 *
 * The declared condition is merged with the key condition, so it must be
 * grouped: "(a OR b) AND fk=?". Without the parentheses SQL reads it as
 * "a OR (b AND fk=?)" and the first OR branch matches rows of ANY owner.
 *
 * They reuse the existing tables, so only the relationship declaration differs
 * between them. Fixture data:
 *   venues: 1 NY, 2 DC, 6 PA, 7 VA, 8 VA, 9 DC
 *   events: 1 -> venue 1 "Monday Night Music Club feat. The Shivers"
 *           2 -> venue 2 "Yeah Yeah Yeahs"   3 -> venue 2 "Love Overboard"
 *           7 -> venue 9 "Blah"
 *   hosts via events: event 2 -> host 2, event 3 -> host 3, event 7 -> host 4
 *   composite_items (author_ref, parent_ref): 1 (1, 3) "keep", 5 (3, 1) "mirror"
 */

class PrecedenceEvent extends ActiveRecord\Model
{
    public static $table_name = 'events';
}

class PrecedenceVenue extends ActiveRecord\Model
{
    public static $table_name = 'venues';
}

class PrecedenceCompositeItem extends ActiveRecord\Model
{
    public static $table_name = 'composite_items';
}

// has_many, OR fragment without bind values
class PrecedenceVenueOrEvents extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $has_many = [['events', 'class_name' => 'PrecedenceEvent', 'foreign_key' => 'venue_id', 'order' => 'id asc',
        'conditions' => ["title = 'Blah' OR title = 'Yeah Yeah Yeahs'"]]];
}

// has_many, OR fragment with bind values (they must keep their order, before the key)
class PrecedenceVenueOrBindEvents extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $has_many = [['events', 'class_name' => 'PrecedenceEvent', 'foreign_key' => 'venue_id', 'order' => 'id asc',
        'conditions' => ['title = ? OR title = ?', 'Blah', 'Yeah Yeah Yeahs']]];
}

// has_many, no declared condition: the SQL must stay as it is
class PrecedenceVenuePlainEvents extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $has_many = [['events', 'class_name' => 'PrecedenceEvent', 'foreign_key' => 'venue_id', 'order' => 'id asc']];
}

// has_one, OR fragment; event 2 (venue 2) sorts before venue 9's own event 7
class PrecedenceVenueOrEvent extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $has_one = [['event', 'class_name' => 'PrecedenceEvent', 'foreign_key' => 'venue_id', 'order' => 'id asc',
        'conditions' => ["title = 'Yeah Yeah Yeahs' OR title = 'Blah'"]]];
}

// belongs_to, OR fragment that the own parent (venue 9, DC) satisfies
class PrecedenceEventOrVenue extends ActiveRecord\Model
{
    public static $table_name = 'events';
    public static $belongs_to = [['venue', 'class_name' => 'PrecedenceVenue', 'foreign_key' => 'venue_id',
        'conditions' => ["state = 'NY' OR state = 'DC'"]]];
}

// belongs_to, OR fragment that only ANOTHER parent (venue 1, NY) satisfies
class PrecedenceEventOrOtherVenue extends ActiveRecord\Model
{
    public static $table_name = 'events';
    public static $belongs_to = [['venue', 'class_name' => 'PrecedenceVenue', 'foreign_key' => 'venue_id',
        'conditions' => ["state = 'NY' OR state = 'ZZ'"]]];
}

// has_many through, OR fragment on the middle table
class PrecedenceVenueOrHosts extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $has_many = [
        ['events', 'class_name' => 'Event', 'foreign_key' => 'venue_id'],
        ['hosts', 'through' => 'events', 'class_name' => 'Host', 'foreign_key' => 'venue_id', 'order' => 'hosts.id asc',
            'conditions' => ["events.title = 'Blah' OR events.title = 'Yeah Yeah Yeahs'"]],
    ];
}

// has_many with composite keys, OR fragment
class PrecedenceCompositeAuthor extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['items', 'class_name' => 'PrecedenceCompositeItem', 'foreign_key' => ['author_ref', 'parent_ref'],
        'primary_key' => ['author_id', 'parent_author_id'], 'order' => 'id asc',
        'conditions' => ["title = 'mirror' OR title = 'keep'"]]];
}

class RelationshipConditionsPrecedenceTest extends DatabaseTest
{
    /**
     * @param list<ActiveRecord\Model> $models
     * @return list<int>
     */
    private function ids(array $models): array
    {
        return array_map(fn($model) => (int) $model->id, $models);
    }

    public function test_has_many_or_condition_does_not_load_other_owners_rows()
    {
        $this->assert_equals([2], $this->ids(PrecedenceVenueOrEvents::find(2)->events));
        $this->assert_equals([], $this->ids(PrecedenceVenueOrEvents::find(1)->events));
        $this->assert_sql_has("WHERE (title = 'Blah' OR title = 'Yeah Yeah Yeahs') AND venue_id=? ORDER BY id asc", Table::load('PrecedenceEvent')->last_sql);
    }

    public function test_has_many_or_condition_with_binds_does_not_load_other_owners_rows()
    {
        $this->assert_equals([2], $this->ids(PrecedenceVenueOrBindEvents::find(2)->events));
        $this->assert_equals([7], $this->ids(PrecedenceVenueOrBindEvents::find(9)->events));
        $this->assert_sql_has('WHERE (title = ? OR title = ?) AND venue_id=? ORDER BY id asc', Table::load('PrecedenceEvent')->last_sql);
    }

    public function test_has_many_without_conditions_keeps_its_sql()
    {
        $this->assert_equals([2, 3], $this->ids(PrecedenceVenuePlainEvents::find(2)->events));
        $sql = Table::load('PrecedenceEvent')->last_sql;
        $this->assert_sql_has('WHERE venue_id=? ORDER BY id asc', $sql);
        $this->assert_sql_doesnt_has('(', $sql);
    }

    public function test_has_one_or_condition_does_not_load_another_owners_row()
    {
        $this->assert_equals(7, PrecedenceVenueOrEvent::find(9)->event->id);
        $this->assert_equals(2, PrecedenceVenueOrEvent::find(2)->event->id);
        $this->assert_null(PrecedenceVenueOrEvent::find(1)->event);
        $this->assert_sql_has("WHERE (title = 'Yeah Yeah Yeahs' OR title = 'Blah') AND venue_id=? ORDER BY id asc", Table::load('PrecedenceEvent')->last_sql);
    }

    public function test_belongs_to_or_condition_does_not_return_another_parent()
    {
        // event 7 belongs to venue 9 (DC): only venue 1 (NY) satisfies the condition
        $this->assert_null(PrecedenceEventOrOtherVenue::find(7)->venue);
        $this->assert_sql_has("WHERE (state = 'NY' OR state = 'ZZ') AND id=?", Table::load('PrecedenceVenue')->last_sql);
        $this->assert_equals(1, PrecedenceEventOrOtherVenue::find(1)->venue->id);
    }

    public function test_belongs_to_or_condition_returns_the_own_parent()
    {
        // venue 1 (NY) also satisfies the first OR branch and comes first in the table
        $this->assert_equals(9, PrecedenceEventOrVenue::find(7)->venue->id);
        $this->assert_equals(2, PrecedenceEventOrVenue::find(2)->venue->id);
        $this->assert_sql_has("WHERE (state = 'NY' OR state = 'DC') AND id=?", Table::load('PrecedenceVenue')->last_sql);
    }

    public function test_has_many_through_or_condition_does_not_load_other_owners_rows()
    {
        $this->assert_equals([2], $this->ids(PrecedenceVenueOrHosts::find(2)->hosts));
        $this->assert_sql_has("WHERE (events.title = 'Blah' OR events.title = 'Yeah Yeah Yeahs') AND venue_id=?", Table::load('Host')->last_sql);
    }

    public function test_composite_keys_or_condition_does_not_load_other_owners_rows()
    {
        $this->assert_equals([1], $this->ids(PrecedenceCompositeAuthor::find(1)->items));
        $this->assert_equals([5], $this->ids(PrecedenceCompositeAuthor::find(3)->items));
        $this->assert_sql_has("WHERE (title = 'mirror' OR title = 'keep') AND author_ref=? AND parent_ref=?", Table::load('PrecedenceCompositeItem')->last_sql);
    }

    public function test_eager_has_many_or_condition_is_grouped()
    {
        $venues = PrecedenceVenueOrEvents::find('all', ['conditions' => ['id IN(?)', [1, 2]], 'include' => ['events'], 'order' => 'id asc']);

        $this->assert_equals([], $this->ids($venues[0]->events));
        $this->assert_equals([2], $this->ids($venues[1]->events));
        $this->assert_sql_has("WHERE (title = 'Blah' OR title = 'Yeah Yeah Yeahs') AND venue_id IN(?,?) ORDER BY id asc", Table::load('PrecedenceEvent')->last_sql);
    }

    public function test_eager_has_one_or_condition_is_grouped()
    {
        $venues = PrecedenceVenueOrEvent::find('all', ['conditions' => ['id IN(?)', [2, 9]], 'include' => ['event'], 'order' => 'id asc']);

        $this->assert_equals(2, $venues[0]->event->id);
        $this->assert_equals(7, $venues[1]->event->id);
        $this->assert_sql_has("WHERE (title = 'Yeah Yeah Yeahs' OR title = 'Blah') AND venue_id IN(?,?) ORDER BY id asc", Table::load('PrecedenceEvent')->last_sql);
    }

    public function test_eager_belongs_to_or_condition_is_grouped()
    {
        $events = PrecedenceEventOrVenue::find('all', ['conditions' => ['id IN(?)', [2, 7]], 'include' => ['venue'], 'order' => 'id asc']);

        $this->assert_equals(2, $events[0]->venue->id);
        $this->assert_equals(9, $events[1]->venue->id);
        $this->assert_sql_has("WHERE (state = 'NY' OR state = 'DC') AND id IN(?,?)", Table::load('PrecedenceVenue')->last_sql);
    }

    public function test_eager_has_many_through_or_condition_is_grouped()
    {
        $venues = PrecedenceVenueOrHosts::find('all', ['conditions' => ['id IN(?)', [1, 2]], 'include' => ['hosts'], 'order' => 'id asc']);

        $this->assert_equals([], $this->ids($venues[0]->hosts));
        $this->assert_equals([2], $this->ids($venues[1]->hosts));
        $this->assert_sql_has("WHERE (events.title = 'Blah' OR events.title = 'Yeah Yeah Yeahs') AND venue_id IN(?,?)", Table::load('Host')->last_sql);
    }
}
