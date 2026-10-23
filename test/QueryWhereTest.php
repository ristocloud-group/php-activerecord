<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\Connection;
use ActiveRecord\Model;
use ActiveRecord\Query;
use ActiveRecord\QueryCompiler;
use ActiveRecord\Table;

/**
 * The where family of the query builder (spec §4.2, §5.2): where, or_where, where_not and or_where_not in
 * their forms (a column and a value; a column, an operator and a value; a hash; a list of conditions; a
 * closure group), where_column, where_raw, where_in, where_null, where_between, where_between_columns,
 * where_like, where_all, where_any, where_none, where_key, and the or_ twins Eloquent has.
 *
 * Fixture data: authors (author_id, parent_author_id, name) 1 (3) Tito, 2 (2) George W. Bush, 3 (1) Bill
 * Clinton, 4 (2) Uncle Bob, none with some_text; books 1 Ancient Art of Main Tanking by author 1 and 2 Another
 * Book by author 2; dated_counts (pk day DATE, at DATETIME) on 2026-01-01 and 2026-01-02.
 */
class QueryWhereTest extends DatabaseTest
{
    public function tear_down(): void
    {
        // the alias a test gave this model after its table loaded is not the model's own
        SettableAliasAuthor::$alias_attribute = [];

        parent::tear_down();
    }

    /**
     * $sql with each backquote in the quote character of the connection.
     */
    private function sql(string $sql): string
    {
        return str_replace('`', $this->conn::$QUOTE_CHARACTER, $sql);
    }

    /**
     * The SELECT of every column of the authors under this WHERE, quoted for the connection.
     */
    private function authors_where(string $where): string
    {
        return $this->sql("SELECT * FROM `authors` WHERE $where");
    }

    /**
     * The values of an attribute of the models, sorted.
     *
     * @param list<Model> $models
     * @return list<mixed>
     */
    private function values(array $models, string $attribute = 'author_id'): array
    {
        $values = array_map(fn(Model $model): mixed => $model->$attribute, $models);
        sort($values);

        return $values;
    }

    public function test_basic_operator_and_null_forms(): void
    {
        $this->assert_sql_has('WHERE name = ?', Author::where('name', 'Tito')->to_sql());
        $this->assert_sql_has('WHERE author_id >= ?', Author::where('author_id', '>=', 2)->to_sql());
        $this->assert_sql_has('WHERE parent_author_id IS NULL', Author::where('parent_author_id', null)->to_sql());
        $this->assert_sql_has('WHERE parent_author_id IS NOT NULL', Author::where('parent_author_id', '!=', null)->to_sql());
        $this->assert_exception_message_contains("Unknown operator 'equals'", fn() => Author::where('name', 'equals', 'Tito'), ActiveRecord\ActiveRecordException::class);
        $this->assert_exception_message_contains('null', fn() => Author::where('author_id', '>', null), ActiveRecord\ActiveRecordException::class);
    }

    public function test_hash_list_and_in_forms_follow_the_legacy_rules(): void
    {
        $q = Author::where(['parent_author_id' => [1, null], 'author_id' => []]);
        $this->assert_sql_has('(parent_author_id IN(?) OR parent_author_id IS NULL) AND 1=0', $q->to_sql());
        $this->assert_same([1], $q->get_bindings());
        $this->assert_sql_has('WHERE author_id > ? AND name = ?', Author::where([['author_id', '>', 1], ['name', 'Tito']])->to_sql());
        $this->assert_sql_has('1=0', Author::where_in('author_id', [])->to_sql());
        $this->assert_sql_has('1=1', Author::where_not_in('author_id', [])->to_sql());
        $this->assert_sql_has('(parent_author_id NOT IN(?) AND parent_author_id IS NOT NULL)', Author::where_not_in('parent_author_id', [1, null])->to_sql());
    }

    public function test_groups_or_not_and_results(): void
    {
        $q = Author::where('name', 'Tito')->or_where(fn($q) => $q->where('author_id', '>', 2)->where('parent_author_id', 2));
        $this->assert_sql_has('WHERE name = ? OR (author_id > ? AND parent_author_id = ?)', $q->to_sql());
        $ids = array_map(fn($a) => $a->author_id, $q->get());
        sort($ids);
        $this->assert_same([1, 4], $ids);
        $this->assert_sql_has('WHERE name = ?', Author::where(fn($q) => $q)->where('name', 'x')->to_sql());
        $this->assert_exception_message_contains('where', fn() => Author::where(fn($q) => $q->select('name')), ActiveRecord\ActiveRecordException::class);
        $this->assert_sql_has('NOT (name = ?)', Author::where_not('name', 'Tito')->to_sql());
    }

    public function test_column_raw_between_all_any_none_key(): void
    {
        $this->assert_sql_has('author_id = parent_author_id', Author::where_column('author_id', 'parent_author_id')->to_sql());
        $this->assert_sql_has('LENGTH(name) > ?', Author::where_raw('LENGTH(name) > ?', [3])->to_sql());
        $this->assert_sql_has('author_id BETWEEN ? AND ?', Author::where_between('author_id', [1, 3])->to_sql());
        $this->assert_exception_message_contains('two values', fn() => Author::where_between('author_id', [1]), ActiveRecord\ActiveRecordException::class);
        $this->assert_sql_has('author_id BETWEEN parent_author_id AND author_id', Author::where_between_columns('author_id', ['parent_author_id', 'author_id'])->to_sql());
        $this->assert_sql_has('(name = ? OR some_text = ?)', Author::where_any(['name', 'some_text'], 'Tito')->to_sql());
        $this->assert_sql_has('NOT (name = ? OR some_text = ?)', Author::where_none(['name', 'some_text'], 'Tito')->to_sql());
        $this->assert_sql_has('author_id IN(?,?)', Author::where_key([1, 2])->to_sql());
    }

    // Review Focus 2
    public function test_real_column_names_and_forwarding(): void
    {
        $this->assert_sql_has('WHERE space name = ?', OddColumn::where('space_name', 'x')->to_sql());
        $this->assert_instance_of(ActiveRecord\Query::class, Author::where('name', 'Tito')); // where() is now on FORWARDABLE
    }

    public function test_dates_bind_in_their_column_format(): void
    {
        $this->assert_same(1, count(DatedCount::where('day', new DateTime('2026-01-02'))->get()));
    }

    // -- operators (spec §5.2)

    // Laravel 13's operators, matched in any case and written in upper case
    public function test_the_operators_are_matched_in_any_case_and_written_in_upper_case(): void
    {
        $operators = [
            '=', '<', '>', '<=', '>=', '<>', '!=', '<=>', 'like', 'like binary', 'not like', 'ilike',
            '&', '|', '^', '<<', '>>', '&~', 'is', 'is not', 'rlike', 'not rlike', 'regexp', 'not regexp',
            '~', '~*', '!~', '!~*', 'similar to', 'not similar to', 'not ilike', '~~*', '!~~*',
        ];

        foreach ($operators as $operator) {
            $expected = $this->authors_where('`name` ' . strtoupper($operator) . ' ?');

            foreach ([$operator, strtoupper($operator), ucwords($operator)] as $spelling) {
                $this->assert_same($expected, Author::where('name', $spelling, 'x')->to_sql());
            }
        }

        $this->assert_same([3, 4], $this->values(Author::where('author_id', '>', 2)->get()));
        $this->assert_same([1, 2], $this->values(Author::where('author_id', '<=', 2)->get()));
        $this->assert_same([1, 3, 4], $this->values(Author::where('author_id', '<>', 2)->get()));
        $this->assert_same([4], $this->values(Author::where('name', 'LIKE', '%Bob')->get()));
    }

    // Spec §5.2: an operator that is not on the list throws at the call, where Laravel would take it as the value
    public function test_an_unknown_operator_is_refused_at_the_call(): void
    {
        foreach (['equals', '==', 'in', 'not in', 'between', 'like ', ' like', 'not  like', '', '=>', 'glob', 'sounds like', '&&', '@>'] as $operator) {
            $this->assert_exception_message_contains("Unknown operator '$operator' in where() on Author", fn() => Author::where('name', $operator, 'x'), ActiveRecordException::class);
        }

        $this->assert_exception_message_contains('Unknown operator int in where() on Author', fn() => Author::where('author_id', 5, 6), ActiveRecordException::class);
        $this->assert_exception_message_contains("Unknown operator 'equals' in or_where_not() on Author", fn() => Author::or_where_not('name', 'equals', 'x'), ActiveRecordException::class);
        $this->assert_exception_message_contains("Unknown operator 'equals' in where_column() on Author", fn() => Author::where_column('author_id', 'equals', 'parent_author_id'), ActiveRecordException::class);
        $this->assert_exception_message_contains("Unknown operator 'equals' in where_any() on Author", fn() => Author::where_any(['name'], 'equals', 'x'), ActiveRecordException::class);
    }

    // Spec §5.2: null with = (or <=>) is IS NULL, with != or <> IS NOT NULL, and with any other operator an error
    public function test_null_is_compared_with_is_null(): void
    {
        $is_null = $this->authors_where('`parent_author_id` IS NULL');
        $is_not_null = $this->authors_where('`parent_author_id` IS NOT NULL');

        $this->assert_same($is_null, Author::where('parent_author_id', null)->to_sql());
        $this->assert_same($is_null, Author::where('parent_author_id', '=', null)->to_sql());
        $this->assert_same($is_null, Author::where('parent_author_id', '<=>', null)->to_sql());
        $this->assert_same($is_not_null, Author::where('parent_author_id', '!=', null)->to_sql());
        $this->assert_same($is_not_null, Author::where('parent_author_id', '<>', null)->to_sql());
        $this->assert_same([], Author::where('parent_author_id', null)->get_bindings());

        foreach (['>', '<=', 'like', 'NOT LIKE', 'is', 'is not'] as $operator) {
            $this->assert_exception_message_contains(
                "Illegal operator and value combination: '$operator' with null in where() on Author",
                fn() => Author::where('parent_author_id', $operator, null),
                ActiveRecordException::class
            );
        }

        // every author has a parent
        $this->assert_same([], Author::where('parent_author_id', null)->get());
        $this->assert_same([1, 2, 3, 4], $this->values(Author::where('parent_author_id', '!=', null)->get()));
    }

    // Named arguments read as positional ones: a value given alone is compared with =, statically too
    public function test_named_arguments(): void
    {
        $this->assert_same($this->authors_where('`name` = ?'), Author::where('name', value: 'Tito')->to_sql());
        $this->assert_same(['Tito'], Author::where('name', value: 'Tito')->get_bindings());
        $this->assert_same(['Tito'], Author::new_query()->where('name', operator: 'Tito')->get_bindings());
        $this->assert_same($this->authors_where('`name` > ?'), Author::where(column: 'name', operator: '>', value: 'T')->to_sql());
        $this->assert_same($this->authors_where('(`name` = ? OR `some_text` = ?)'), Author::where_any(['name', 'some_text'], value: 'x')->to_sql());
    }

    // Spec §5.7: the call that introduces the problem throws, and the query is left as it was
    public function test_a_refused_call_leaves_the_query_as_it_was(): void
    {
        $query = Author::where('name', 'Tito');
        $sql = $query->to_sql();
        $list = 'A condition of the list given to where() on Author is [column, value] or [column, operator, value], not';

        foreach ([
            ["No value given for 'name' in where() on Author", fn() => $query->where('name')],
            ["An array is no value for '=' in where() on Author", fn() => $query->where('author_id', [1, 2])],
            ["An array is no value for '!=' in or_where() on Author", fn() => $query->or_where('author_id', '!=', [1, 2])],
            ["Empty column name: '' in where() on Author", fn() => $query->where('', 1)],
            ["Empty column name: ' ' in where_not() on Author", fn() => $query->where_not([' ' => 1])],
            ["$list string", fn() => $query->where(['name', 'Tito'])],
            ["$list an array of 4", fn() => $query->where([['name', '=', 'Tito', 'or']])],
            ["$list an array of 0", fn() => $query->where([[]])],
            // a list is taken whole or not at all: its first condition is not kept either
            ["Unknown operator 'equals' in where() on Author", fn() => $query->where([['author_id', '>', 1], ['name', 'equals', 'x']])],
            ["No value given for 'name' in or_where() on Author", fn() => $query->or_where([['author_id', 1], ['name']])],
        ] as [$message, $call]) {
            $this->assert_exception_message_contains($message, $call, ActiveRecordException::class);
        }

        $this->assert_same($sql, $query->to_sql());
        $this->assert_same(['Tito'], $query->get_bindings());
    }

    // -- or_ and not

    // Each where method has the or_ twin Eloquent has, with the same parameters, which joins its condition with OR
    public function test_every_or_variant_joins_its_condition_with_or(): void
    {
        $like = fn(bool $not): string => $this->conn->like_condition($this->sql('`name`'), false, $not);

        foreach ([
            ['`author_id` > ?', fn(Query $q) => $q->or_where('author_id', '>', 1)],
            ['(`author_id`=? AND `parent_author_id`=?)', fn(Query $q) => $q->or_where(['author_id' => 1, 'parent_author_id' => 3])],
            ['(`author_id` > ? AND `parent_author_id` = ?)', fn(Query $q) => $q->or_where([['author_id', '>', 1], ['parent_author_id', 3]])],
            ['(`author_id` > ?)', fn(Query $q) => $q->or_where(fn(Query $q) => $q->where('author_id', '>', 1))],
            ['NOT (`author_id` > ?)', fn(Query $q) => $q->or_where_not('author_id', '>', 1)],
            ['`author_id` = `parent_author_id`', fn(Query $q) => $q->or_where_column('author_id', 'parent_author_id')],
            ['(author_id > ?)', fn(Query $q) => $q->or_where_raw('author_id > ?', [1])],
            ['`author_id` IN(?,?)', fn(Query $q) => $q->or_where_in('author_id', [1, 2])],
            ['`author_id` NOT IN(?,?)', fn(Query $q) => $q->or_where_not_in('author_id', [1, 2])],
            ['`parent_author_id` IS NULL', fn(Query $q) => $q->or_where_null('parent_author_id')],
            ['`parent_author_id` IS NOT NULL', fn(Query $q) => $q->or_where_not_null('parent_author_id')],
            ['`author_id` BETWEEN ? AND ?', fn(Query $q) => $q->or_where_between('author_id', [1, 2])],
            ['`author_id` NOT BETWEEN ? AND ?', fn(Query $q) => $q->or_where_not_between('author_id', [1, 2])],
            ['`author_id` BETWEEN `parent_author_id` AND `author_id`', fn(Query $q) => $q->or_where_between_columns('author_id', ['parent_author_id', 'author_id'])],
            ['`author_id` NOT BETWEEN `parent_author_id` AND `author_id`', fn(Query $q) => $q->or_where_not_between_columns('author_id', ['parent_author_id', 'author_id'])],
            [$like(false), fn(Query $q) => $q->or_where_like('name', 'x%')],
            [$like(true), fn(Query $q) => $q->or_where_not_like('name', 'x%')],
            ['(`name` = ? AND `author_id` = ?)', fn(Query $q) => $q->or_where_all(['name', 'author_id'], 'x')],
            ['(`name` = ? OR `author_id` = ?)', fn(Query $q) => $q->or_where_any(['name', 'author_id'], 'x')],
            ['NOT (`name` = ? OR `author_id` = ?)', fn(Query $q) => $q->or_where_none(['name', 'author_id'], 'x')],
        ] as [$condition, $call]) {
            $query = Author::where('name', 'Tito');

            $this->assert_same($query, $call($query));
            $this->assert_same($this->authors_where("`name` = ? OR $condition"), $query->to_sql());
        }

        $this->assert_same([1, 3, 4], $this->values(Author::where('name', 'Tito')->or_where_in('author_id', [3, 4])->get()));

        // a first condition has nothing to join with: its OR is dropped
        $this->assert_same($this->authors_where('`name` = ?'), Author::or_where('name', 'Tito')->to_sql());
        $this->assert_same($this->authors_where('NOT (`name` = ?)'), Author::or_where_not('name', 'Tito')->to_sql());
    }

    // where_not() writes NOT (...) around its condition, in every form
    public function test_where_not_negates_its_condition_in_every_form(): void
    {
        $either = fn(Query $q) => $q->where('name', 'Tito')->or_where('author_id', '>', 3);

        $this->assert_same($this->authors_where('NOT (`author_id` > ?)'), Author::where_not('author_id', '>', 2)->to_sql());
        $this->assert_same($this->authors_where('NOT (`parent_author_id` IS NULL)'), Author::where_not('parent_author_id', null)->to_sql());
        $this->assert_same($this->authors_where('NOT (`name`=? AND `author_id` IN(?,?))'), Author::where_not(['name' => 'Tito', 'author_id' => [1, 2]])->to_sql());
        $this->assert_same($this->authors_where('NOT (`author_id` > ? AND `name` = ?)'), Author::where_not([['author_id', '>', 1], ['name', 'Tito']])->to_sql());
        $this->assert_same($this->authors_where('NOT (`name` = ? OR `author_id` > ?)'), Author::where_not($either)->to_sql());
        $this->assert_same($this->authors_where('`author_id` > ? AND NOT (`name` = ?)'), Author::where('author_id', '>', 1)->where_not('name', 'Uncle Bob')->to_sql());

        $this->assert_same([2, 3, 4], $this->values(Author::where_not('name', 'Tito')->get()));
        $this->assert_same([2, 3], $this->values(Author::where_not($either)->get()));
        $this->assert_same([2, 3], $this->values(Author::where('author_id', '>', 1)->where_not('name', 'Uncle Bob')->get()));

        // a negated group that holds nothing is left out, as an empty group is
        $this->assert_same($this->sql('SELECT * FROM `authors`'), Author::where_not(fn(Query $q) => $q)->where_not([])->to_sql());
    }

    // -- hashes and lists (spec §5.2)

    // A hash goes through Expressions as a legacy conditions hash does, its names read through the model's
    // aliases and attribute names: the SQL and the values of the legacy finders
    public function test_a_hash_is_rendered_as_a_legacy_conditions_hash(): void
    {
        foreach ([
            [Author::class, ['name' => 'Tito']],
            [Author::class, ['parent_author_id' => [1, null], 'author_id' => []]],
            [Author::class, ['parent_author_id' => [null], 'name' => ['Tito', 'Uncle Bob'], 'some_text' => null]],
            [Author::class, ['authors.name' => 'Tito', $this->sql('`author_id`') => 1]],
            [Venue::class, ['marquee' => 'Warner Theatre', 'mycity' => 'Washington']],
            // an alias and its column are both kept (#129)
            [Venue::class, ['marquee' => 'Warner Theatre', 'name' => 'Warner Theatre']],
            [OddColumn::class, ['space_name' => 'x', 'some_day' => '2026-01-02', 'camelcase' => 'y']],
            [OddColumnAliased::class, ['nick' => 'x']],
        ] as [$model, $hash]) {
            $legacy = $model::table()->options_to_sql(['conditions' => $hash]);
            $query = $model::where($hash);

            $this->assert_same($legacy->to_s(), $query->to_sql());
            $this->assert_same($legacy->get_where_values(), $query->get_bindings());
        }

        $this->assert_same([2], $this->values(Venue::where(['marquee' => 'Warner Theatre', 'mycity' => 'Washington'])->get(), 'id'));
        $this->assert_same([1, 4], $this->values(Author::where(['name' => ['Tito', 'Uncle Bob']])->get()));
    }

    // With joins too: the model's columns get its table, the joined tables' names stay as written (spec §6.2.4)
    public function test_with_joins_a_hash_is_rendered_as_a_legacy_conditions_hash(): void
    {
        $joins = Book::table()->create_joins(['author']);

        foreach ([
            ['name' => 'Ancient Art of Main Tanking', 'authors.name' => 'Tito', 'parent_author_id' => 3],
            ['author_id' => [1, 2], 'secondary_author_id' => null, 'NAME' => 'x'],
        ] as $hash) {
            $legacy = Book::table()->options_to_sql(['joins' => ['author'], 'conditions' => $hash]);
            $query = Book::where($hash);
            $query->state()->joins[] = $joins;

            $this->assert_same($legacy->to_s(), $query->to_sql());
            $this->assert_same($legacy->get_where_values(), $query->get_bindings());
        }

        $query = Book::where(['name' => 'Ancient Art of Main Tanking', 'authors.name' => 'Tito', 'parent_author_id' => 3]);
        $query->state()->joins[] = $joins;
        $this->assert_same([1], $this->values($query->get(), 'book_id'));
    }

    // Laravel's list of conditions, each [column, value] or [column, operator, value], ANDed; a hash and a
    // list can share an array, and a condition can be a closure or a list itself
    public function test_a_list_of_conditions(): void
    {
        $this->assert_same($this->authors_where('`author_id` > ? AND `name` = ?'), Author::where([['author_id', '>', 1], ['name', 'Uncle Bob']])->to_sql());
        $this->assert_same([4], $this->values(Author::where([['author_id', '>', 1], ['name', 'Uncle Bob']])->get()));

        $query = Author::where([
            'parent_author_id' => 2,
            ['author_id', '>', 2],
            [fn(Query $q) => $q->where('name', 'x')->or_where('name', 'Uncle Bob')],
            [[['author_id', '<', 9]]],
        ]);
        $this->assert_same($this->authors_where('`parent_author_id`=? AND `author_id` > ? AND (`name` = ? OR `name` = ?) AND `author_id` < ?'), $query->to_sql());
        $this->assert_same([2, 2, 'x', 'Uncle Bob', 9], $query->get_bindings());
        $this->assert_same([4], $this->values($query->get()));

        // its conditions are ANDed with those before and after it
        $this->assert_same(
            $this->authors_where('`name` = ? OR `author_id` > ? AND `author_id` < ? AND `parent_author_id` = ?'),
            Author::where('name', 'x')->or_where('author_id', '>', 1)->where([['author_id', '<', 4]])->where('parent_author_id', 2)->to_sql()
        );
        // an empty array adds nothing
        $this->assert_same($this->sql('SELECT * FROM `authors`'), Author::where([])->to_sql());
    }

    // -- groups

    // A closure is given a new query on the same model, and its conditions become a group in parentheses; an
    // empty group is left out, and anything but where clauses is refused (spec §5.2)
    public function test_a_closure_is_a_group_of_where_conditions(): void
    {
        $given = null;
        $query = Author::where(function (Query $q) use (&$given): void {
            $given = $q;
            $q->where('name', 'Tito')->or_where(fn(Query $q) => $q->where('author_id', '>', 2)->where_not('parent_author_id', 1));
        });

        $this->assert_same($this->authors_where('(`name` = ? OR (`author_id` > ? AND NOT (`parent_author_id` = ?)))'), $query->to_sql());
        $this->assert_same([1, 4], $this->values($query->get()));

        // the group took the conditions of that query when the closure returned
        $this->assert_instance_of(Query::class, $given);
        $this->assert_not_same($query, $given);
        $given->where('author_id', 9);
        $this->assert_same([1, 4], $this->values($query->get()));

        $this->assert_exception_message_contains(
            'A where() group can only hold where clauses: the one given to or_where() on Author also holds selects',
            fn() => Author::or_where(fn(Query $q) => $q->where('name', 'x')->select('name')),
            ActiveRecordException::class
        );
        $this->assert_exception_message_contains('also holds distinct', fn() => Author::where(fn(Query $q) => $q->distinct()), ActiveRecordException::class);
        $this->assert_exception_message_contains('also holds from, from_alias', fn() => Author::where_not(fn(Query $q) => $q->from('authors', 'a')), ActiveRecordException::class);
    }

    // -- where_column, where_raw

    public function test_where_column_compares_two_columns(): void
    {
        $this->assert_same($this->authors_where('`author_id` = `parent_author_id`'), Author::where_column('author_id', 'parent_author_id')->to_sql());
        $this->assert_same($this->authors_where('`author_id` > `parent_author_id`'), Author::where_column('author_id', '>', 'parent_author_id')->to_sql());
        $this->assert_same($this->authors_where('`author_id` = `parent_author_id`'), Author::new_query()->where_column('author_id', second: 'parent_author_id')->to_sql());
        $this->assert_same([], Author::where_column('author_id', 'parent_author_id')->get_bindings());

        $this->assert_same([2], $this->values(Author::where_column('author_id', 'parent_author_id')->get()));
        $this->assert_same([3, 4], $this->values(Author::where_column('author_id', '>', 'parent_author_id')->get()));
        $this->assert_same([1, 2], $this->values(Author::where_column('author_id', '<', 'parent_author_id')->or_where_column('author_id', 'parent_author_id')->get()));

        $this->assert_exception_message_contains("No second column for 'author_id' in where_column() on Author", fn() => Author::where_column('author_id'), ActiveRecordException::class);
        $this->assert_exception_message_contains("No second column for 'author_id' in or_where_column() on Author", fn() => Author::or_where_column('author_id', '=', null), ActiveRecordException::class);
        $this->assert_exception_message_contains("Empty column name: '' in where_column() on Author", fn() => Author::where_column('author_id', ''), ActiveRecordException::class);
    }

    // where_raw() is sent as written, in parentheses when another condition is beside it; a list among its
    // values is spread over its marker, as in a legacy positional condition
    public function test_where_raw_is_sent_as_written(): void
    {
        $this->assert_same($this->authors_where('LENGTH(name) > ?'), Author::where_raw('LENGTH(name) > ?', [3])->to_sql());

        $query = Author::where('name', 'Tito')->or_where_raw('author_id = ? OR author_id = ?', [2, 3]);
        $this->assert_same($this->authors_where('`name` = ? OR (author_id = ? OR author_id = ?)'), $query->to_sql());
        $this->assert_same(['Tito', 2, 3], $query->get_bindings());
        $this->assert_same([1, 2, 3], $this->values($query->get()));

        // without the parentheses, the OR of the raw condition would escape the AND: 1 would be found too
        $this->assert_same([2], $this->values(Author::where_raw('author_id = ? OR author_id = ?', [1, 2])->where('parent_author_id', 2)->get()));

        $legacy = Author::table()->options_to_sql(['conditions' => ['author_id IN(?) AND name != ?', [1, 2, 3], 'Tito']]);
        $query = Author::where_raw('author_id IN(?) AND name != ?', [[1, 2, 3], 'Tito']);
        $this->assert_same($legacy->to_s(), $query->to_sql());
        $this->assert_same($legacy->get_where_values(), $query->get_bindings());
        $this->assert_same([2, 3], $this->values($query->get()));

        $this->assert_same($this->authors_where('author_id IN(NULL)'), Author::where_raw('author_id IN(?)', [[]])->to_sql());
        $this->assert_same([], Author::where_raw('author_id IN(?)', [[]])->get());
    }

    // -- in, null, between

    // where_in() and where_not_in() follow the hash rules: an empty list matches nothing (1=0) or everything
    // (1=1), and a null in the list is compared with IS NULL (spec §5.2)
    public function test_in_lists_follow_the_hash_rules(): void
    {
        $this->assert_same($this->authors_where('`author_id` IN(?,?)'), Author::where_in('author_id', [1, 3])->to_sql());
        $this->assert_same($this->authors_where('(`parent_author_id` IN(?) OR `parent_author_id` IS NULL)'), Author::where_in('parent_author_id', [1, null])->to_sql());
        $this->assert_same($this->authors_where('`parent_author_id` IS NULL'), Author::where_in('parent_author_id', [null, null])->to_sql());
        $this->assert_same($this->authors_where('1=0'), Author::where_in('author_id', [])->to_sql());
        $this->assert_same($this->authors_where('`author_id` NOT IN(?,?)'), Author::where_not_in('author_id', [1, 3])->to_sql());
        $this->assert_same($this->authors_where('`parent_author_id` IS NOT NULL'), Author::where_not_in('parent_author_id', [null])->to_sql());
        $this->assert_same($this->authors_where('1=1'), Author::where_not_in('author_id', [])->to_sql());
        $this->assert_same([1, 2], Author::where_in('parent_author_id', [1, null, 2])->get_bindings());
        $this->assert_same([1, 3], Author::where_in('author_id', ['a' => 1, 'b' => 3])->get_bindings());

        $this->assert_same([1, 3], $this->values(Author::where_in('author_id', [1, 3])->get()));
        $this->assert_same([3], $this->values(Author::where_in('parent_author_id', [1, null])->get()));
        $this->assert_same([], Author::where_in('author_id', [])->get());
        $this->assert_same([2, 4], $this->values(Author::where_not_in('author_id', [1, 3])->get()));
        $this->assert_same([1, 3], $this->values(Author::where_not_in('parent_author_id', [2, null])->get()));
        $this->assert_same([1, 2, 3, 4], $this->values(Author::where_not_in('author_id', [])->get()));
        $this->assert_same([1, 2, 3, 4], $this->values(Author::where_not_in('parent_author_id', [null])->get()));
    }

    public function test_where_null_takes_one_column_or_several(): void
    {
        $this->assert_same($this->authors_where('`parent_author_id` IS NULL AND `some_text` IS NULL'), Author::where_null(['parent_author_id', 'some_text'])->to_sql());
        $this->assert_same($this->authors_where('`name` = ? OR `parent_author_id` IS NULL OR `some_text` IS NULL'), Author::where('name', 'x')->or_where_null(['parent_author_id', 'some_text'])->to_sql());
        $this->assert_same($this->authors_where('`parent_author_id` IS NOT NULL AND `name` IS NOT NULL'), Author::where_not_null(['parent_author_id', 'name'])->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `authors`'), Author::where_null([])->to_sql());

        $this->assert_same([1, 2, 3, 4], $this->values(Author::where_null('some_text')->get()));
        $this->assert_same([], Author::where_not_null('some_text')->get());
        $this->assert_same([1, 2, 3, 4], $this->values(Author::where_not_null(['parent_author_id', 'name'])->get()));

        $this->assert_exception_message_contains('A column name must be a string, not int, in where_null() on Author', fn() => Author::where_null(['name', 5]), ActiveRecordException::class);
        $this->assert_exception_message_contains("Empty column name: '' in or_where_not_null() on Author", fn() => Author::or_where_not_null(''), ActiveRecordException::class);
    }

    public function test_where_between_takes_two_values_and_where_between_columns_two_columns(): void
    {
        $this->assert_same($this->authors_where('`author_id` BETWEEN ? AND ?'), Author::where_between('author_id', [2, 3])->to_sql());
        $this->assert_same([2, 3], Author::where_between('author_id', ['from' => 2, 'to' => 3])->get_bindings());
        $this->assert_same([2, 3], $this->values(Author::where_between('author_id', [2, 3])->get()));
        $this->assert_same([1, 4], $this->values(Author::where_not_between('author_id', [2, 3])->get()));

        // the id of an author is between its parent's and its own when the parent's is not greater
        $this->assert_same([2, 3, 4], $this->values(Author::where_between_columns('author_id', ['parent_author_id', 'author_id'])->get()));
        $this->assert_same([1], $this->values(Author::where_not_between_columns('author_id', ['parent_author_id', 'author_id'])->get()));
        $this->assert_same([], Author::where_between_columns('author_id', ['parent_author_id', 'author_id'])->get_bindings());

        foreach ([
            ['where_between() needs two values, not 1, on Author', fn() => Author::where_between('author_id', [1])],
            ['where_not_between() needs two values, not 3, on Author', fn() => Author::where_not_between('author_id', [1, 2, 3])],
            ['or_where_between() needs two values, not 0, on Author', fn() => Author::or_where_between('author_id', [])],
            ['where_between_columns() needs two values, not 1, on Author', fn() => Author::where_between_columns('author_id', ['parent_author_id'])],
            ['or_where_not_between_columns() needs two values, not 3, on Author', fn() => Author::or_where_not_between_columns('author_id', ['a', 'b', 'c'])],
            ['A column name must be a string, not int, in where_between_columns() on Author', fn() => Author::where_between_columns('author_id', ['parent_author_id', 1])],
        ] as [$message, $call]) {
            $this->assert_exception_message_contains($message, $call, ActiveRecordException::class);
        }
    }

    // -- like

    // In any case by default and in the given case on request, with the % and _ wildcards and every other
    // character as itself, on every adapter (spec §5.2). The SQL of each adapter is pinned in its own tests.
    public function test_where_like_matches_in_any_case_unless_case_sensitive(): void
    {
        $this->assert_same([1], $this->values(Author::where_like('name', 't%')->get()));
        $this->assert_same([1], $this->values(Author::where_like('name', 'T_TO')->get()));
        $this->assert_same([2, 3, 4], $this->values(Author::where_not_like('name', 't%')->get()));
        $this->assert_same([1], $this->values(Author::where_like('name', 'T%', true)->get()));
        $this->assert_same([], Author::where_like('name', 't%', true)->get());
        $this->assert_same([1, 2, 3, 4], $this->values(Author::where_not_like('name', 't%', true)->get()));
        $this->assert_same([1, 4], $this->values(Author::where_like('name', '%o')->or_where_like('name', 'u%')->get()));

        // the characters that GLOB reads (SQLite's case-sensitive LIKE) are themselves in a pattern
        Book::create(['name' => 'a[b]*c?d']);
        $names = fn(Query $query): array => $this->values($query->get(), 'name');

        $this->assert_same(['a[b]*c?d'], $names(Book::where_like('name', '_[b]*c?_', true)));
        $this->assert_same(['a[b]*c?d'], $names(Book::where_like('name', '%]*c?%', true)));
        $this->assert_same(['a[b]*c?d'], $names(Book::where_like('name', 'a[b]*c?d', true)));
        $this->assert_same([], $names(Book::where_like('name', '%[B]%', true)));
        $this->assert_same(['a[b]*c?d'], $names(Book::where_like('name', '%[B]%')));
    }

    // An adapter writes a case-sensitive LIKE its own way: the default refuses it at the call, and leaves the
    // pattern as it is
    public function test_the_default_like_hooks(): void
    {
        $connection = new class extends Connection {
            // no database: the hooks only write SQL
            public function __construct() {}

            public function limit($sql, $offset, $limit)
            {
                return $sql;
            }

            public function query_column_info($table)
            {
                throw new LogicException('not read');
            }

            public function query_for_tables()
            {
                throw new LogicException('not read');
            }

            public function set_encoding($charset) {}

            public function native_database_types()
            {
                return [];
            }
        };

        $this->assert_same('`name` LIKE ?', $connection->like_condition('`name`', false, false));
        $this->assert_same('`name` NOT LIKE ?', $connection->like_condition('`name`', false, true));
        $this->assert_same('a%_[*?', $connection->like_pattern('a%_[*?', false));
        $this->assert_same('a%_[*?', $connection->like_pattern('a%_[*?', true));
        $this->assert_exception_message_contains('has no case-sensitive LIKE', fn() => $connection->like_condition('`name`', true, false), ActiveRecordException::class);

        // a query on a table of that connection refuses it at the call
        $table = new Table('Author');
        $table->conn = $connection;

        $this->assert_exception_message_contains('has no case-sensitive LIKE', fn() => Query::for_table($table)->where_like('name', 'T%', true), ActiveRecordException::class);
        $this->assert_same('SELECT * FROM `authors` WHERE `name` LIKE ?', Query::for_table($table)->where_like('name', 'T%')->to_sql());
    }

    // -- all, any, none

    // A group in parentheses of one comparison on each column, joined with AND (all) or OR (any), or the
    // negation of the OR (none), as in Laravel
    public function test_where_all_any_and_none(): void
    {
        $this->assert_same($this->authors_where('(`author_id` = ? AND `parent_author_id` = ?)'), Author::where_all(['author_id', 'parent_author_id'], 2)->to_sql());
        $this->assert_same($this->authors_where('(`author_id` > ? OR `parent_author_id` > ?)'), Author::where_any(['author_id', 'parent_author_id'], '>', 2)->to_sql());
        $this->assert_same($this->authors_where('(`author_id` IS NULL AND `parent_author_id` IS NULL)'), Author::where_all(['author_id', 'parent_author_id'], null)->to_sql());
        $this->assert_same([2, 2], Author::where_all(['author_id', 'parent_author_id'], 2)->get_bindings());

        $this->assert_same([2], $this->values(Author::where_all(['author_id', 'parent_author_id'], 2)->get()));
        $this->assert_same([1, 3, 4], $this->values(Author::where_any(['author_id', 'parent_author_id'], '>', 2)->get()));
        $this->assert_same([1, 3], $this->values(Author::where_none(['author_id', 'parent_author_id'], 2)->get()));
        $this->assert_same([4], $this->values(Author::where_any(['name', 'some_text'], 'like', '%Bob')->get()));

        // as in Laravel, no column is no condition
        $this->assert_same($this->sql('SELECT * FROM `authors`'), Author::where_any([], 'x')->to_sql());

        $this->assert_exception_message_contains('A column name must be a string, not int, in where_all() on Author', fn() => Author::where_all(['name', 5], 'x'), ActiveRecordException::class);
    }

    // -- where_key

    public function test_where_key_compares_the_primary_key(): void
    {
        $this->assert_same($this->authors_where('`author_id` = ?'), Author::where_key(1)->to_sql());
        $this->assert_same($this->authors_where('`author_id` != ?'), Author::where_key_not(1)->to_sql());
        $this->assert_same($this->authors_where('`author_id` NOT IN(?,?)'), Author::where_key_not([1, 2])->to_sql());
        $this->assert_same($this->authors_where('1=0'), Author::where_key([])->to_sql());
        $this->assert_same($this->authors_where('1=1'), Author::where_key_not([])->to_sql());

        $this->assert_same([2], $this->values(Author::where_key(2)->get()));
        $this->assert_same([1, 3], $this->values(Author::where_key([1, 3])->get()));
        $this->assert_same([2, 3, 4], $this->values(Author::where_key_not(1)->get()));
        $this->assert_same([2, 4], $this->values(Author::where_key_not([1, 3])->get()));
        $this->assert_same([1], $this->values(Author::where_key([1, 3])->where_key_not(3)->get()));

        $this->assert_exception_message_contains('where_key() needs a single-column primary key; DatedCount has a composite one', fn() => DatedCount::where_key(['2026-01-01', '2026-01-01 10:00:00']), ActiveRecordException::class);
        $this->assert_exception_message_contains('where_key_not() needs a single-column primary key; PklessItem has none', fn() => PklessItem::where_key_not(1), ActiveRecordException::class);
    }

    // The pk is named as its column, never through an alias, as the legacy finders name it: here an alias
    // named like the pk column that the load of the table could not see (spec §6.2.6)
    public function test_where_key_never_reads_the_pk_through_an_alias(): void
    {
        SettableAliasAuthor::table();
        SettableAliasAuthor::$alias_attribute = ['author_id' => 'name'];

        $this->assert_same($this->authors_where('`author_id` = ?'), SettableAliasAuthor::where_key(1)->to_sql());
        $this->assert_same(['Tito'], $this->values(SettableAliasAuthor::where_key(1)->get(), 'name'));
        // where() reads the alias
        $this->assert_same(['Tito'], $this->values(SettableAliasAuthor::where('author_id', 'Tito')->get(), 'name'));
    }

    // -- columns and data

    // Review Focus 2: an attribute name or an alias names the real column, in every where method
    public function test_attribute_names_and_aliases_name_the_real_columns(): void
    {
        OddColumn::create(['space_name' => 'v', 'camelcase' => 'c', 'some_day' => '2026-01-02']);

        foreach ([
            fn() => OddColumn::where('space_name', 'v'),
            fn() => OddColumnAliased::where('nick', 'v'),
            fn() => OddColumn::where_in('space_name', ['v', 'w']),
            fn() => OddColumn::where_not_null('space_name'),
            fn() => OddColumn::where_like('space_name', 'V'),
            fn() => OddColumn::where_column('space_name', '!=', 'camelcase'),
            fn() => OddColumn::where_between('some_day', ['2026-01-01', '2026-01-03']),
            fn() => OddColumn::where_any(['space_name', 'dash_name'], 'v'),
            fn() => OddColumn::where(['camelcase' => 'c']),
        ] as $query) {
            $this->assert_same(1, count($query()->get()));
        }

        $this->assert_same($this->sql('SELECT * FROM `odd_columns` WHERE `space name` = ?'), OddColumn::where('space_name', 'x')->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `odd_columns` WHERE `space name` IS NULL'), OddColumnAliased::where_null('nick')->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `venues` WHERE `name` = `city`'), Venue::where_column('marquee', 'mycity')->to_sql());
    }

    // Spec §5.2: each value is prepared for the column it is compared with (Table::process_data()): a DateTime
    // in the format of the column's type, which a legacy positional value, of no known column, never gets
    public function test_each_value_is_prepared_for_its_column(): void
    {
        $day = new DateTime('2026-01-02 10:00:00');
        $next = new DateTime('2026-01-03');
        // the formats of the connection: the date and time of Postgres carries the time zone
        $date_and_time = $this->conn->datetime_to_string($day);
        $this->assert_same('2026-01-02 10:00:00', substr($date_and_time, 0, 19));

        $this->assert_same(['2026-01-02'], DatedCount::where('day', $day)->get_bindings());
        $this->assert_same([$date_and_time], DatedCount::where('at', $day)->get_bindings());
        $this->assert_same(['2026-01-02'], DatedCount::where_not('day', '>=', $day)->get_bindings());
        $this->assert_same(['2026-01-02', '2026-01-03'], DatedCount::where_in('day', [$day, null, $next])->get_bindings());
        $this->assert_same(['2026-01-02', '2026-01-03'], DatedCount::where_between('day', [$day, $next])->get_bindings());
        $this->assert_same(['2026-01-02', '2026-01-03'], DatedCount::where(['day' => [$day, $next]])->get_bindings());
        $this->assert_same(['2026-01-02', $date_and_time], DatedCount::where_any(['day', 'at'], $day)->get_bindings());
        // an alias, an attribute name and a pre-quoted name name the column
        $this->assert_same(['2026-01-02'], AliasedDatedCount::where('happened_on', $day)->get_bindings());
        $this->assert_same(['2026-01-02'], AliasedDatedCount::where(['happened_on' => $day])->get_bindings());
        $this->assert_same(['2026-01-02'], OddColumn::where('some_day', $day)->get_bindings());
        $this->assert_same(['2026-01-02'], DatedCount::where($this->sql('`day`'), $day)->get_bindings());
        // a qualified name is no column of the model here: its value is sent as a positional one is
        $this->assert_same([$date_and_time], DatedCount::where('dated_counts.day', $day)->get_bindings());

        $this->assert_same(1, count(DatedCount::where('day', $day)->get()));
        $this->assert_same(1, count(DatedCount::where(['day' => $day])->get()));
        $this->assert_same(1, count(AliasedDatedCount::where('happened_on', $day)->get()));
        $this->assert_same(2, count(DatedCount::where_in('day', [new DateTime('2026-01-01'), $day])->get()));
        $this->assert_same(2, count(DatedCount::where_between('day', [new DateTime('2026-01-01'), $day])->get()));
    }

    // Spec §5.1: with joins every where method names the model's columns with its table, or with the from()
    // alias, and any other name as written
    public function test_with_joins_the_models_columns_get_its_table(): void
    {
        $joins = Book::table()->create_joins(['author']);
        $query = Book::where('name', 'Ancient Art of Main Tanking')
            ->where('authors.name', 'Tito')
            ->where_in('parent_author_id', [3])
            ->where_not_null('secondary_author_id')
            ->where_between('book_id', [1, 2])
            ->where_column('author_id', 'authors.author_id')
            ->where_like('name', 'ancient%')
            ->where_key(1);
        $query->state()->joins[] = $joins;

        $like = $this->conn->like_condition($this->sql('`books`.`name`'), false, false);
        $this->assert_same(
            $this->sql(
                "SELECT `books`.* FROM `books` $joins WHERE `books`.`name` = ? AND `authors`.`name` = ? AND `parent_author_id` IN(?)"
                . " AND `books`.`secondary_author_id` IS NOT NULL AND `books`.`book_id` BETWEEN ? AND ? AND `books`.`author_id` = `authors`.`author_id`"
                . " AND $like AND `books`.`book_id` = ?"
            ),
            $query->to_sql()
        );
        $this->assert_same(['Ancient Art of Main Tanking', 'Tito', 3, 1, 2, 'ancient%', 1], $query->get_bindings());
        $this->assert_same([1], $this->values($query->get(), 'book_id'));

        $query = Book::from('books', 'b')->where('name', 'Another Book');
        $query->state()->joins[] = 'INNER JOIN authors ON(b.author_id = authors.author_id)';

        $this->assert_same($this->sql('SELECT `b`.* FROM `books` AS `b` INNER JOIN authors ON(b.author_id = authors.author_id) WHERE `b`.`name` = ?'), $query->to_sql());
        $this->assert_same([2], $this->values($query->get(), 'book_id'));
    }

    // Spec §5.2: when the engine adds conditions of its own (a pk, ...), the builder's WHERE goes in parentheses,
    // so that an OR in it never widens them, as in the legacy finders (#149)
    public function test_the_builder_where_is_parenthesized_beside_the_engines_conditions(): void
    {
        $compiler = new QueryCompiler(Author::table());
        $state = Author::where('name', 'Tito')->or_where('author_id', '>', 3)->state();
        $pk = ['sql' => $this->sql('`author_id` = ?'), 'values' => [1]];
        $parent = ['sql' => $this->sql('`parent_author_id` = ?'), 'values' => [3]];

        $this->assert_same(['sql' => $this->sql('`name` = ? OR `author_id` > ?'), 'values' => ['Tito', 3]], $compiler->where_clause($state));
        $this->assert_same(['sql' => $this->sql('`author_id` = ? AND (`name` = ? OR `author_id` > ?)'), 'values' => [1, 'Tito', 3]], $compiler->where_clause($state, [$pk]));
        $this->assert_same(
            ['sql' => $this->sql('`author_id` = ? AND `parent_author_id` = ? AND (`name` = ? OR `author_id` > ?)'), 'values' => [1, 3, 'Tito', 3]],
            $compiler->where_clause($state, [$pk, $parent])
        );
        $this->assert_same($pk, $compiler->where_clause(Author::new_query()->state(), [$pk]));
        $this->assert_null($compiler->where_clause(Author::new_query()->state()));
    }

    // -- bindings, legacy, forwarding

    // The values of the WHERE follow those of the select and of the FROM, in the order of its conditions
    public function test_the_bindings_come_in_the_order_of_the_clauses(): void
    {
        $query = Author::where('name', '!=', 'x')
            ->select('author_id')
            ->select_raw('author_id * ? AS scaled', [10])
            ->where_raw('author_id < ?', [9])
            ->from_raw('(SELECT * FROM authors WHERE author_id > ?) authors', [1])
            ->or_where_in('author_id', [7, 8]);

        $this->assert_same(
            $this->sql('SELECT `author_id`, author_id * ? AS scaled FROM (SELECT * FROM authors WHERE author_id > ?) authors WHERE `name` != ? AND (author_id < ?) OR `author_id` IN(?,?)'),
            $query->to_sql()
        );
        $this->assert_same([10, 1, 'x', 9, 7, 8], $query->get_bindings());

        $models = [];
        $statements = GoldenStatements::capture(function () use ($query, &$models): void {
            $models = $query->get();
        });

        $this->assert_same([['sql' => $query->to_sql(), 'values' => $query->get_bindings()]], $statements);
        $scaled = array_map(fn(Model $author): int => (int) $author->scaled, $models);
        sort($scaled);

        $this->assert_same([2, 3, 4], $this->values($models));
        $this->assert_same([20, 30, 40], $scaled);
    }

    // A state holds legacy options or builder clauses, never both
    public function test_legacy_options_and_wheres_are_never_compiled_together(): void
    {
        $query = Query::from_options(Author::table(), ['conditions' => ['name' => 'Tito']])->where('author_id', 1);

        $this->assert_exception_message_contains('A query on Author holds both legacy options and builder clauses', fn() => $query->to_sql(), ActiveRecordException::class);
    }

    // Every method of the family is reached statically (spec §4.1), runs, and returns the query it changes
    public function test_every_where_method_is_forwarded_and_returns_the_query(): void
    {
        $calls = [
            'where' => ['name', 'x'],
            'or_where' => ['name', 'x'],
            'where_not' => ['name', 'x'],
            'or_where_not' => ['name', 'x'],
            'where_column' => ['author_id', 'parent_author_id'],
            'or_where_column' => ['author_id', 'parent_author_id'],
            'where_raw' => ['author_id > ?', [0]],
            'or_where_raw' => ['author_id > ?', [0]],
            'where_in' => ['author_id', [1]],
            'or_where_in' => ['author_id', [1]],
            'where_not_in' => ['author_id', [1]],
            'or_where_not_in' => ['author_id', [1]],
            'where_null' => ['some_text'],
            'or_where_null' => ['some_text'],
            'where_not_null' => ['some_text'],
            'or_where_not_null' => ['some_text'],
            'where_between' => ['author_id', [1, 2]],
            'or_where_between' => ['author_id', [1, 2]],
            'where_not_between' => ['author_id', [1, 2]],
            'or_where_not_between' => ['author_id', [1, 2]],
            'where_between_columns' => ['author_id', ['parent_author_id', 'author_id']],
            'or_where_between_columns' => ['author_id', ['parent_author_id', 'author_id']],
            'where_not_between_columns' => ['author_id', ['parent_author_id', 'author_id']],
            'or_where_not_between_columns' => ['author_id', ['parent_author_id', 'author_id']],
            'where_like' => ['name', 'T%'],
            'or_where_like' => ['name', 'T%'],
            'where_not_like' => ['name', 'T%'],
            'or_where_not_like' => ['name', 'T%'],
            'where_all' => [['name'], 'x'],
            'or_where_all' => [['name'], 'x'],
            'where_any' => [['name'], 'x'],
            'or_where_any' => [['name'], 'x'],
            'where_none' => [['name'], 'x'],
            'or_where_none' => [['name'], 'x'],
            'where_key' => [1],
            'where_key_not' => [1],
        ];

        $methods = array_values(array_filter(get_class_methods(Query::class), fn(string $method): bool => 1 === preg_match('/\A(or_)?where/', $method)));
        $names = array_keys($calls);
        sort($methods);
        sort($names);
        $this->assert_same($names, $methods);

        foreach ($calls as $method => $arguments) {
            $this->assert_true(in_array($method, Query::FORWARDABLE, true), "$method() is forwardable");
            $this->assert_instance_of(Query::class, Author::$method(...$arguments));

            $query = Author::new_query();
            $this->assert_same($query, $query->$method(...$arguments));
            $this->assert_true(is_array($query->get()));
        }
    }
}
