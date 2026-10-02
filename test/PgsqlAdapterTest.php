<?php

use ActiveRecord\Column;

require_once __DIR__ . '/../lib/adapters/PgsqlAdapter.php';

class RmBldgExplicitSequence extends ActiveRecord\Model
{
    public static $table = 'rm-bldg';
    public static $sequence = 'rm-bldg_explicit_seq';
}

class PgsqlAdapterTest extends AdapterTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('pgsql');
    }

    public function test_gh34_limit_zero_and_offset_without_limit_sql()
    {
        Author::all(['order' => 'author_id', 'limit' => 0]);
        $this->assert_equals('SELECT * FROM "authors" ORDER BY author_id LIMIT 0 OFFSET 0', Author::table()->last_sql);

        Author::all(['order' => 'author_id', 'offset' => 2]);
        $this->assert_equals('SELECT * FROM "authors" ORDER BY author_id OFFSET 2', Author::table()->last_sql);
    }

    public function test_insert_id()
    {
        $this->conn->query("INSERT INTO authors(author_id,name) VALUES(nextval('authors_author_id_seq'),'name')");
        $this->assert_true($this->conn->insert_id('authors_author_id_seq') > 0);
    }

    public function test_insert_id_with_params()
    {
        $x = ['name'];
        $this->conn->query("INSERT INTO authors(author_id,name) VALUES(nextval('authors_author_id_seq'),?)", $x);
        $this->assert_true($this->conn->insert_id('authors_author_id_seq') > 0);
    }

    public function test_insert_id_should_return_explicitly_inserted_id()
    {
        $this->conn->query('INSERT INTO authors(author_id,name) VALUES(99,\'name\')');
        $this->assert_true($this->conn->insert_id('authors_author_id_seq') > 0);
    }

    public function test_set_charset()
    {
        $connection_string = ActiveRecord\Config::instance()->get_connection($this->connection_name);
        $conn = ActiveRecord\Connection::instance($connection_string . '?charset=utf8');
        $this->assert_equals("SET NAMES 'utf8'", $conn->last_query);
    }

    public function test_gh96_columns_not_duplicated_by_index()
    {
        $this->assert_equals(3, $this->conn->query_column_info("user_newsletters")->rowCount());
    }

    public function test_boolean_column_introspection()
    {
        $columns = $this->conn->columns('venues');

        $this->assert_equals('boolean', $columns['is_available']->raw_type);
        $this->assert_equals(Column::BOOLEAN, $columns['is_available']->type);
        $this->assert_equals(Column::BOOLEAN, $columns['is_retired']->type);

        // pg_get_expr() yields the textual 'true'/'false' — they must cast to
        // native bools, not survive as (truthy) strings (GH-30)
        $this->assert_same(true, $columns['is_available']->default);
        $this->assert_same(false, $columns['is_retired']->default);
    }

    public function test_db_qualified_model_introspects_the_same_columns()
    {
        // `$db` ("public".authors) used to introspect no column at all
        $plain = Author::table();
        $qualified = PublicSchemaAuthor::table();
        $this->assert_equals(array_keys($plain->columns), array_keys($qualified->columns));
        $this->assert_equals(
            array_map(fn($c) => [$c->raw_type, $c->type, $c->pk, $c->nullable], $plain->columns),
            array_map(fn($c) => [$c->raw_type, $c->type, $c->pk, $c->nullable], $qualified->columns)
        );

        // the primary key is inferred, the sequence named, attributes typed and writable
        $this->assert_equals(['book_id'], PublicSchemaBook::table()->pk);
        $this->assert_equals('books_book_id_seq', PublicSchemaBook::table()->sequence);
        $this->assert_equals('Ancient Art of Main Tanking', PublicSchemaBook::find(1)->name);
        $book = PublicSchemaBook::create(['name' => 'Schema Book', 'author_id' => 1]);
        $this->assert_false($book->is_new_record());
        $this->assert_equals('Schema Book', PublicSchemaBook::find($book->book_id)->name);

        // hash keys are checked against those columns
        $this->assert_equals(['Tito'], array_map(fn($a) => $a->name, PublicSchemaAuthor::all(['conditions' => ['name' => 'Tito']])));
        $this->assert_exception_message_contains("Unknown column 'nope' in hash conditions for PublicSchemaAuthor", function () {
            PublicSchemaAuthor::all(['conditions' => ['nope' => 1]]);
        }, ActiveRecord\DatabaseException::class);
    }

    public function test_db_qualified_model_uses_its_own_schema_sequence()
    {
        $c = $this->conn;
        // introspect live: an earlier test may leave a schema cache adapter on (CacheTest)
        $cache = ActiveRecord\Cache::$adapter;
        ActiveRecord\Cache::$adapter = null;
        $seq = fn(string $name) => (int) $c->query("SELECT last_value FROM $name")->fetchColumn();

        try {
            $c->query('CREATE SCHEMA s26');
            $c->query('CREATE TABLE s26.books (book_id SERIAL PRIMARY KEY, name varchar(50))');
            $c->query("INSERT INTO s26.books (name) VALUES ('s26-a')");
            $c->query('CREATE SCHEMA "S26Mix"');
            $c->query('CREATE TABLE "S26Mix"."MixTab" (id SERIAL PRIMARY KEY, label text)');
            ActiveRecord\Table::clear_cache();

            // the sequence from the column default, schema-qualified; not public.books_book_id_seq
            $this->assert_equals('s26.books_book_id_seq', S26Book::table()->sequence);
            $this->assert_equals('"S26Mix"."MixTab_id_seq"', S26MixTab::table()->sequence);

            $public = $seq('public.books_book_id_seq');
            $book = S26Book::create(['name' => 's26-b']);
            $this->assert_equals(2, (int) $book->book_id);
            $this->assert_equals(2, $seq('s26.books_book_id_seq'));
            $this->assert_equals($public, $seq('public.books_book_id_seq'));
            $this->assert_equals('s26-b', S26Book::find(2)->name);

            $tab = S26MixTab::create(['label' => 'm']);
            $this->assert_equals(1, (int) $tab->id);
            $this->assert_equals('m', S26MixTab::find(1)->label);

            // a model without `$db` keeps the derived, unqualified name
            $this->assert_equals('books_book_id_seq', Book::table()->sequence);
        } finally {
            $c->query('DROP SCHEMA IF EXISTS s26 CASCADE');
            $c->query('DROP SCHEMA IF EXISTS "S26Mix" CASCADE');
            ActiveRecord\Table::clear_cache();
            ActiveRecord\Cache::$adapter = $cache;
        }
    }

    public function test_pre_quoted_schema_like_table_name_without_db_is_introspected_as_before()
    {
        $c = $this->conn;
        // introspect live: an earlier test may leave a schema cache adapter on (CacheTest)
        $cache = ActiveRecord\Cache::$adapter;
        ActiveRecord\Cache::$adapter = null;

        try {
            $c->query('CREATE SCHEMA s26');
            $c->query('CREATE TABLE s26.books (book_id SERIAL PRIMARY KEY, name varchar(50))');
            ActiveRecord\Table::clear_cache();

            // no `$db`: the whole '"s26".books' is the relation name, which matches nothing
            $this->assert_equals([], S26QuotedTableNameBook::table()->columns);
        } finally {
            $c->query('DROP SCHEMA IF EXISTS s26 CASCADE');
            ActiveRecord\Table::clear_cache();
            ActiveRecord\Cache::$adapter = $cache;
        }
    }

    public function test_db_qualified_model_has_its_own_schema_cache_key()
    {
        $cache = ActiveRecord\Cache::$adapter;
        ActiveRecord\Cache::initialize('file://' . sys_get_temp_dir() . '/phpar-s26-' . bin2hex(random_bytes(4)));

        try {
            // "public".authors (`$db`) and '"public".authors' (a pre-quoted $table_name) spell
            // the same string: the schema lookup must not be served to the other model
            ActiveRecord\Table::clear_cache();
            $this->assert_equals(array_keys(Author::table()->columns), array_keys(PublicSchemaAuthor::table()->columns));
            ActiveRecord\Table::clear_cache();
            $this->assert_equals([], PublicQuotedTableNameAuthor::table()->columns);
        } finally {
            ActiveRecord\Cache::flush();
            ActiveRecord\Cache::initialize(null);
            ActiveRecord\Cache::$adapter = $cache;
            ActiveRecord\Table::clear_cache();
        }
    }

    public function test_hash_condition_keys_are_left_to_the_database_when_the_schema_is_unknown()
    {
        // a table whose columns could not be introspected: no key is rejected
        $table = PublicSchemaAuthor::table();
        $columns = $table->columns;
        $table->columns = [];

        try {
            $this->assert_equals(1, PublicSchemaAuthor::count(['conditions' => ['name' => 'Tito']]));
            $this->assert_equals('Tito', PublicSchemaAuthor::find(1)->name);

            try {
                PublicSchemaAuthor::all(['conditions' => ['nope' => 1]]);
                $this->fail('nope must fail at the database');
            } catch (ActiveRecord\DatabaseException $e) {
                $this->assert_false(str_starts_with($e->getMessage(), 'Unknown column'), $e->getMessage());
            }
        } finally {
            $table->columns = $columns;
        }
    }

    public function test_table_without_primary_key_infers_no_sequence()
    {
        // rm-bldg has no primary key, so there is no pk column to derive a
        // sequence name from: loading its table must not read a missing
        // $pk[0] (E_WARNING "Undefined array key 0") nor invent "rm-bldg__seq"
        $table = RmBldg::table();

        $this->assert_same([], $table->pk);
        $this->assert_null($table->sequence);
        $this->assert_equals('name', RmBldg::first()->rm_name);
    }

    public function test_table_without_primary_key_keeps_declared_sequence()
    {
        $this->assert_equals(RmBldgExplicitSequence::$sequence, RmBldgExplicitSequence::table()->sequence);
    }

    public function test_max_bind_params_default()
    {
        $this->assert_equals(65535, $this->conn::$MAX_BIND_PARAMS);
    }

    public function test_upsert_conflict_clause_uses_on_conflict_excluded()
    {
        $clause = $this->conn->upsert_conflict_clause(['name', 'address'], ['city', 'phone']);

        $name  = $this->conn->quote_name('name');
        $addr  = $this->conn->quote_name('address');
        $city  = $this->conn->quote_name('city');
        $phone = $this->conn->quote_name('phone');

        $this->assert_equals(
            "ON CONFLICT ($name, $addr) DO UPDATE SET $city = EXCLUDED.$city, $phone = EXCLUDED.$phone",
            $clause
        );
    }
}
