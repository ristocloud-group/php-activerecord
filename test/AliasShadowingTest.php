<?php

use ActiveRecord\ConfigException;
use ActiveRecord\Table;

/**
 * An $alias_attribute key that names a column of the model's own table, by its real name or by its
 * attribute name (#92), is a configuration error: the table does not load (#144, spec §6.2.6). Every other
 * alias resolves in every hash condition.
 *
 * Fixture data: authors (pk author_id): 1 Tito (parent 3), 2 George W. Bush, 3 Bill Clinton, 4 Uncle Bob;
 * venues: 2 is the Warner Theatre of Washington, 9 the YMCA of Washington.
 */
class AliasShadowingTest extends DatabaseTest
{
    public function tear_down(): void
    {
        // the map a test gave SettableAliasAuthor is not the model's
        SettableAliasAuthor::$alias_attribute = [];

        parent::tear_down();
    }

    /**
     * The message of the ConfigException $closure throws; no other outcome passes.
     */
    private function config_error(callable $closure): string
    {
        try {
            $closure();
        } catch (ConfigException $e) {
            return $e->getMessage();
        }

        $this->fail('No ConfigException was thrown.');
    }

    // The check runs when the table loads, so the first use of the model fails, whichever it is, before any
    // data is read or written: the statements sent are the ones that read the columns of authors, as for any
    // model of that table. A failed load caches nothing: every use loads again, and fails alike.
    public function test_an_alias_named_like_a_column_fails_the_first_use_of_the_model(): void
    {
        $columns_read = GoldenStatements::capture(fn() => SecondaryAuthor::table());
        $this->assert_not_empty($columns_read);

        $failure = [[
            'exception' => ConfigException::class,
            'message' => "AliasedPkAuthor::\$alias_attribute: the alias 'author_id' has the same name as the column 'author_id' of table authors; rename the alias",
        ]];

        foreach ([
            'table' => fn() => AliasedPkAuthor::table(),
            'new' => fn() => new AliasedPkAuthor(),
            'find' => fn() => AliasedPkAuthor::find(1),
            'all' => fn() => AliasedPkAuthor::all(['conditions' => ['author_id' => 'Tito']]),
            'count' => fn() => AliasedPkAuthor::count(),
            'exists' => fn() => AliasedPkAuthor::exists(1),
            'update_all' => fn() => AliasedPkAuthor::update_all(['set' => ['parent_author_id' => 9], 'conditions' => ['author_id' => 'Tito']]),
            'delete_all' => fn() => AliasedPkAuthor::delete_all(['conditions' => ['author_id' => 'Uncle Bob']]),
        ] as $use => $call) {
            $this->assert_same([...$columns_read, ...$failure], GoldenStatements::capture($call), $use);
        }

        $this->assert_same(3, Author::find(1)->parent_author_id);
        $this->assert_same(4, (int) Author::count());
    }

    // Names are compared as the database compares column names: regardless of case on MySQL, MariaDB and
    // SQLite, so Author_Id is the column author_id there; on Postgres, whose quoted names are case-sensitive,
    // it is another name, and an alias like any other
    public function test_an_alias_differing_from_a_column_only_in_case(): void
    {
        if ($this->conn->column_names_are_case_sensitive()) {
            $this->assert_same(1, (int) CaseAliasedPkAuthor::count(['Author_Id' => 'Tito']));
            $this->assert_same(1, (int) CaseAliasedPkAuthor::count(1, ['conditions' => ['Author_Id' => 'Tito']]));
            $this->assert_same(0, (int) CaseAliasedPkAuthor::count(2, ['conditions' => ['Author_Id' => 'Tito']]));
            $this->assert_same('Tito', CaseAliasedPkAuthor::find(1)->name);
        } else {
            $this->assert_same(
                "CaseAliasedPkAuthor::\$alias_attribute: the alias 'Author_Id' has the same name as the column 'author_id' of table authors; rename the alias",
                $this->config_error(fn() => CaseAliasedPkAuthor::table()),
            );
        }
    }

    // A hash condition names the column "space name" as space_name too (#92): an alias of that name
    // would hide it, and the message names the column as the table has it
    public function test_an_alias_named_like_the_attribute_of_a_column(): void
    {
        $this->assert_same(
            "OddColumnShadowAlias::\$alias_attribute: the alias 'space_name' has the same name as the column 'space name' of table odd_columns; rename the alias",
            $this->config_error(fn() => OddColumnShadowAlias::table()),
        );
    }

    // A key is read as a condition reads it: a single pre-quoted identifier by its text, so the quoted
    // author_id (as the adapter quotes) is the column author_id
    public function test_a_pre_quoted_alias_names_the_column_it_quotes(): void
    {
        $quoted = $this->conn->quote_name('author_id');
        SettableAliasAuthor::$alias_attribute = [$quoted => 'name'];

        $this->assert_same(
            "SettableAliasAuthor::\$alias_attribute: the alias '$quoted' has the same name as the column 'author_id' of table authors; rename the alias",
            $this->config_error(fn() => SettableAliasAuthor::table()),
        );
    }

    // Every alias that names no column resolves in find, count, exists, update_all and delete_all (§6.2.1)
    public function test_an_alias_naming_no_column_resolves_in_every_hash_condition(): void
    {
        $conditions = ['marquee' => 'Warner Theatre', 'mycity' => 'Washington'];

        $this->assert_same([2], array_map(fn($venue) => $venue->id, Venue::all(['conditions' => $conditions])));
        $this->assert_same(2, Venue::find(2, ['conditions' => $conditions])->id);
        $this->assert_same(1, (int) Venue::count(['conditions' => $conditions]));
        $this->assert_true(Venue::exists(['conditions' => $conditions]));
        $this->assert_same(1, Venue::update_all(['set' => ['state' => 'XX'], 'conditions' => $conditions]));
        $this->assert_same('XX', Venue::find(2)->state);
        $this->assert_same(1, Venue::delete_all(['conditions' => $conditions]));
        $this->assert_false(Venue::exists(2));
        $this->assert_same(5, (int) Venue::count());
    }

    // ... also an alias of an attribute whose column is named differently (nick is the attribute
    // space_name, the column "space name")
    public function test_an_alias_of_an_odd_column_attribute_resolves_in_every_hash_condition(): void
    {
        OddColumnAliased::create(['nick' => 'b']);
        OddColumnAliased::create(['nick' => 'y']);

        $this->assert_same(['b'], array_map(fn($row) => $row->space_name, OddColumnAliased::all(['conditions' => ['nick' => 'b']])));
        $this->assert_same(1, (int) OddColumnAliased::count(['nick' => 'b']));
        $this->assert_true(OddColumnAliased::exists(['nick' => 'b']));
        $this->assert_same(1, OddColumnAliased::update_all(['set' => ['dash_name' => 'z'], 'conditions' => ['nick' => 'b']]));
        $this->assert_same(1, (int) OddColumnAliased::count(['dash_name' => 'z']));
        $this->assert_same(1, OddColumnAliased::delete_all(['conditions' => ['nick' => 'y']]));
        $this->assert_same(['b'], array_map(fn($row) => $row->space_name, OddColumnAliased::all()));
    }

    // The residual (spec §6.2.6): a shadowing column the schema read did not show, one the table gained after
    // it loaded or a SQLite generated column, leaves the alias winning, as it does everywhere; the next load
    // with fresh metadata reports it. An alias set after the load is the same state: a loaded table, and an
    // alias named like one of its columns.
    public function test_a_shadow_the_load_did_not_see_is_reported_by_the_next_load(): void
    {
        SettableAliasAuthor::table();
        SettableAliasAuthor::$alias_attribute = ['author_id' => 'name'];

        $this->assert_same(1, (int) SettableAliasAuthor::count(['author_id' => 'Tito']));
        $this->assert_same(0, (int) SettableAliasAuthor::count(['author_id' => 1]));

        Table::clear_cache();

        $this->assert_same(
            "SettableAliasAuthor::\$alias_attribute: the alias 'author_id' has the same name as the column 'author_id' of table authors; rename the alias",
            $this->config_error(fn() => SettableAliasAuthor::table()),
        );
    }
}
