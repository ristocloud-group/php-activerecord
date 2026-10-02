<?php

use ActiveRecord\Column;

abstract class AdapterTest extends DatabaseTest
{
    public const InvalidDb = '__1337__invalid_db__';

    public function set_up($connection_name = null)
    {
        if ($connection_name) {
            $connection_string = ActiveRecord\Config::instance()->get_connection($connection_name);

            if ($connection_string == 'skip') {
                $this->mark_test_skipped($connection_name . ' drivers are not present');
            }

            // Named connections don't necessarily match a PDO driver name
            // (e.g. the 'mariadb' connection speaks the 'mysql' PDO driver),
            // so check the driver the connection string actually resolves to.
            $protocol = parse_url($connection_string, PHP_URL_SCHEME);

            if ($protocol && !in_array($protocol, PDO::getAvailableDrivers())) {
                $this->mark_test_skipped($connection_name . ' drivers are not present');
            }
        }

        parent::set_up($connection_name);
    }

    public function test_i_have_a_default_port()
    {
        $c = $this->conn;
        $this->assert_true($c::$DEFAULT_PORT > 0);
    }

    public function test_should_set_adapter_variables()
    {
        $this->assert_not_null($this->conn->protocol);
    }

    public function test_empty_connection_string_uses_default_connection()
    {
        $this->assert_not_null(ActiveRecord\Connection::instance(''));
        $this->assert_not_null(ActiveRecord\Connection::instance());
    }

    public function test_invalid_connection_protocol()
    {
        $this->expectException(ActiveRecord\DatabaseException::class);

        ActiveRecord\Connection::instance('terribledb://user:pass@host/db');
    }

    public function test_no_host_connection()
    {
        $this->expectException(ActiveRecord\DatabaseException::class);

        ActiveRecord\Connection::instance("{$this->conn->protocol}://user:pass");
    }

    public function test_connection_failed_invalid_host()
    {
        // .invalid is reserved (RFC 2606): resolution fails fast and
        // deterministically, so no slow network timeout is involved
        $this->expectException(ActiveRecord\DatabaseException::class);

        ActiveRecord\Connection::instance("{$this->conn->protocol}://user:pass@host-that-does-not-exist.invalid/db");
    }

    public function test_connection_failed()
    {
        $this->expectException(ActiveRecord\DatabaseException::class);

        ActiveRecord\Connection::instance("{$this->conn->protocol}://baduser:badpass@127.0.0.1/db");
    }

    public function test_connect_failed()
    {
        $this->expectException(ActiveRecord\DatabaseException::class);

        ActiveRecord\Connection::instance("{$this->conn->protocol}://zzz:zzz@127.0.0.1/test");
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function test_connect_with_port()
    {
        $config = ActiveRecord\Config::instance();
        $name = $config->get_default_connection();
        $url = parse_url($config->get_connection($name));
        $conn = $this->conn;
        $port = $conn::$DEFAULT_PORT;

        // Build the connection string with optional password
        $connection_string = "{$url['scheme']}://{$url['user']}";
        if (isset($url['pass'])) {
            $connection_string = "{$connection_string}:{$url['pass']}";
        }
        $connection_string = "{$connection_string}@{$url['host']}:$port{$url['path']}";

        if ($this->conn->protocol != 'sqlite') {
            ActiveRecord\Connection::instance($connection_string);
        }
    }

    public function test_connect_to_invalid_database()
    {
        $this->expectException(ActiveRecord\DatabaseException::class);

        ActiveRecord\Connection::instance("{$this->conn->protocol}://test:test@127.0.0.1/" . self::InvalidDb);
    }

    public function test_date_time_type()
    {
        $columns = $this->conn->columns('authors');
        $this->assert_equals('datetime', $columns['created_at']->raw_type);
        $this->assert_equals(Column::DATETIME, $columns['created_at']->type);
        $this->assert_true($columns['created_at']->length > 0);
    }

    public function test_date()
    {
        $columns = $this->conn->columns('authors');
        $this->assert_equals('date', $columns['some_Date']->raw_type);
        $this->assert_equals(Column::DATE, $columns['some_Date']->type);
        $this->assert_true($columns['some_Date']->length >= 7);
    }

    public function test_columns_no_inflection_on_hash_key()
    {
        $author_columns = $this->conn->columns('authors');
        $this->assert_true(array_key_exists('author_id', $author_columns));
    }

    public function test_columns_nullable()
    {
        $author_columns = $this->conn->columns('authors');
        $this->assert_false($author_columns['author_id']->nullable);
        $this->assert_true($author_columns['parent_author_id']->nullable);
    }

    public function test_columns_pk()
    {
        $author_columns = $this->conn->columns('authors');
        $this->assert_true($author_columns['author_id']->pk);
        $this->assert_false($author_columns['parent_author_id']->pk);
    }

    public function test_columns_sequence()
    {
        $author_columns = $this->conn->columns('authors');

        if ($this->conn->supports_sequences()) {
            $this->assert_equals('authors_author_id_seq', $author_columns['author_id']->sequence);
        } else {
            $this->assert_null($author_columns['author_id']->sequence);
        }
    }

    public function test_columns_default()
    {
        $author_columns = $this->conn->columns('authors');
        $this->assert_equals('default_name', $author_columns['name']->default);
    }

    public function test_columns_type()
    {
        $author_columns = $this->conn->columns('authors');
        $this->assert_equals('varchar', substr($author_columns['name']->raw_type, 0, 7));
        $this->assert_equals(Column::STRING, $author_columns['name']->type);
        $this->assert_equals(25, $author_columns['name']->length);
    }

    public function test_columns_text()
    {
        $author_columns = $this->conn->columns('authors');
        $this->assert_equals('text', $author_columns['some_text']->raw_type);
        $this->assert_equals(null, $author_columns['some_text']->length);
    }

    public function test_columns_time()
    {
        $author_columns = $this->conn->columns('authors');
        $this->assert_equals('time', $author_columns['some_time']->raw_type);
        $this->assert_equals(Column::TIME, $author_columns['some_time']->type);
    }

    public function test_query()
    {
        $sth = $this->conn->query('SELECT * FROM authors');

        while (($row = $sth->fetch())) {
            $this->assert_not_null($row);
        }

        $sth = $this->conn->query('SELECT * FROM authors WHERE author_id=1');
        $row = $sth->fetch();
        $this->assert_equals('Tito', $row['name']);
    }

    public function test_invalid_query()
    {
        $this->expectException(ActiveRecord\DatabaseException::class);
        $this->conn->query('alsdkjfsdf');
    }

    public function test_fetch()
    {
        $sth = $this->conn->query('SELECT * FROM authors WHERE author_id IN(1,2,3) ORDER BY author_id');
        $i = 0;
        $ids = [];

        while (($row = $sth->fetch())) {
            ++$i;
            $ids[] = $row['author_id'];
        }

        $this->assert_equals(3, $i);
        $this->assert_equals([1,2,3], $ids);
    }

    public function test_query_with_params()
    {
        $x = ['Bill Clinton','Tito'];
        $sth = $this->conn->query('SELECT * FROM authors WHERE name IN(?,?) ORDER BY name DESC', $x);
        $row = $sth->fetch();
        $this->assert_equals('Tito', $row['name']);

        $row = $sth->fetch();
        $this->assert_equals('Bill Clinton', $row['name']);

        $row = $sth->fetch();
        $this->assert_equals(null, $row);
    }

    public function test_insert_id_should_return_explicitly_inserted_id()
    {
        $this->conn->query('INSERT INTO authors(author_id,name) VALUES(99,\'name\')');

        if ($this->conn->supports_sequences()) {
            // Postgres' lastval() only reflects sequence use; an explicit pk
            // insert never advances the sequence, so 99 is not visible here
            $this->assert_true($this->conn->insert_id() > 0);
        } else {
            $this->assert_equals(99, $this->conn->insert_id());
        }
    }

    public function test_insert_id()
    {
        $this->conn->query("INSERT INTO authors(name) VALUES('name')");
        $this->assert_true($this->conn->insert_id() > 0);
    }

    public function test_insert_id_with_params()
    {
        $x = ['name'];
        $this->conn->query('INSERT INTO authors(name) VALUES(?)', $x);
        $this->assert_true($this->conn->insert_id() > 0);
    }

    public function test_next_sequence_value()
    {
        if ($this->conn->supports_sequences()) {
            $this->assert_equals("nextval('authors_author_id_seq')", $this->conn->next_sequence_value('authors_author_id_seq'));
        } else {
            $this->assert_null($this->conn->next_sequence_value('authors_author_id_seq'));
        }
    }

    public function test_native_database_types_cover_the_core_types()
    {
        $types = $this->conn->native_database_types();

        foreach (['primary_key', 'string', 'text', 'integer', 'float', 'datetime', 'timestamp', 'time', 'date', 'binary', 'boolean'] as $type) {
            $this->assert_true(array_key_exists($type, $types), "missing native type: $type");
        }
    }

    public function test_inflection()
    {
        $columns = $this->conn->columns('authors');
        $this->assert_equals('parent_author_id', $columns['parent_author_id']->inflected_name);
    }

    public function test_escape()
    {
        $s = "Bob's";
        $this->assert_not_equals($s, $this->conn->escape($s));
    }

    public function test_columnsx()
    {
        $columns = $this->conn->columns('authors');
        $names = ['author_id','parent_author_id','name','updated_at','created_at','some_Date','some_time','some_text','encrypted_password','mixedCaseField'];

        foreach ($names as $field) {
            $this->assert_true(array_key_exists($field, $columns));
        }

        $this->assert_equals(true, $columns['author_id']->pk);
        $this->assert_equals('int', $columns['author_id']->raw_type);
        $this->assert_equals(Column::INTEGER, $columns['author_id']->type);

        // MySQL 8.0.19+/9.x no longer reports an integer display width (`int`
        // instead of `int(11)`), so no length can be parsed out of it there;
        // MariaDB (and every other supported adapter) still reports/derives
        // one. Accept either: when a length is reported, it must be sane.
        $this->assert_true($columns['author_id']->length === null || $columns['author_id']->length > 1);

        $this->assert_false($columns['author_id']->nullable);

        $this->assert_equals(false, $columns['parent_author_id']->pk);
        $this->assert_true($columns['parent_author_id']->nullable);

        $this->assert_equals('varchar', substr($columns['name']->raw_type, 0, 7));
        $this->assert_equals(Column::STRING, $columns['name']->type);
        $this->assert_equals(25, $columns['name']->length);
    }

    public function test_columns_decimal()
    {
        $columns = $this->conn->columns('books');
        $this->assert_equals(Column::DECIMAL, $columns['special']->type);
        $this->assert_true($columns['special']->length >= 10);
    }

    private function limit($offset, $limit)
    {
        $ret = [];
        $sql = 'SELECT * FROM authors ORDER BY name ASC';
        $this->conn->query_and_fetch($this->conn->limit($sql, $offset, $limit), function ($row) use (&$ret) {
            $ret[] = $row;
        });
        return ActiveRecord\collect($ret, 'author_id');
    }

    public function test_limit()
    {
        $this->assert_equals([2,1], $this->limit(1, 2));
    }

    public function test_limit_to_first_record()
    {
        $this->assert_equals([3], $this->limit(0, 1));
    }

    public function test_limit_to_last_record()
    {
        $this->assert_equals([1], $this->limit(2, 1));
    }

    public function test_limit_with_null_offset()
    {
        $this->assert_equals([3], $this->limit(null, 1));
    }

    public function test_limit_with_nulls()
    {
        $this->assert_equals([], $this->limit(null, null));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<int> the author_id of every Author::all($options) row, ordered by author_id
     */
    private function author_ids(array $options): array
    {
        $authors = Author::all(['order' => 'author_id'] + $options);

        return ActiveRecord\collect($authors, 'author_id');
    }

    public function test_gh34_limit_zero_returns_no_rows()
    {
        $this->assert_equals([], $this->author_ids(['limit' => 0]));
        $this->assert_equals([], $this->author_ids(['limit' => '0']));
        $this->assert_equals([], $this->author_ids(['limit' => 0, 'offset' => 0]));
        $this->assert_equals([], $this->author_ids(['limit' => 0, 'offset' => 1]));
    }

    public function test_gh34_null_limit_still_means_no_limit()
    {
        $this->assert_equals([1, 2, 3, 4], $this->author_ids([]));
        $this->assert_equals([1, 2, 3, 4], $this->author_ids(['limit' => null]));
        $this->assert_equals([1, 2, 3, 4], $this->author_ids(['limit' => null, 'offset' => null]));
    }

    public function test_gh34_offset_without_limit_returns_every_row_after_the_offset()
    {
        $this->assert_equals([2, 3, 4], $this->author_ids(['offset' => 1]));
        $this->assert_equals([4], $this->author_ids(['offset' => '3']));
        $this->assert_equals([3, 4], $this->author_ids(['limit' => null, 'offset' => 2]));
        $this->assert_equals([], $this->author_ids(['offset' => 4]));
        $this->assert_equals([1, 2, 3, 4], $this->author_ids(['offset' => 0]));
        $this->assert_equals([2, 3], $this->author_ids(['limit' => 2, 'offset' => 1]));
    }

    public function test_gh34_update_all_and_delete_all_with_limit_zero()
    {
        $options = ['conditions' => ['author_id > ?', 1], 'order' => 'author_id'];

        if ($this->conn->accepts_limit_and_order_for_update_and_delete()) {
            // MySQL / MariaDB / SQLite: LIMIT 0 touches no row
            $this->assert_equals(0, Author::update_all(['set' => ['name' => 'X'], 'limit' => 0] + $options));
            $this->assert_sql_has('ORDER BY author_id LIMIT 0', Author::table()->last_sql);
            $this->assert_equals(0, Author::delete_all(['limit' => '0'] + $options));
            $this->assert_sql_has('ORDER BY author_id LIMIT 0', Author::table()->last_sql);
            $this->assert_equals(0, Author::count(['conditions' => ['name = ?', 'X']]));
            $this->assert_equals(4, Author::count());
        } else {
            // Postgres has no LIMIT on UPDATE/DELETE: the limit is ignored, as it always was
            $this->assert_equals(3, Author::update_all(['set' => ['name' => 'X'], 'limit' => 0] + $options));
            $this->assert_sql_doesnt_has('LIMIT', Author::table()->last_sql);
            $this->assert_equals(3, Author::delete_all(['limit' => '0'] + $options));
            $this->assert_sql_doesnt_has('LIMIT', Author::table()->last_sql);
            $this->assert_equals(1, Author::count());
        }
    }

    public function test_gh34_count_is_zero_when_the_count_query_returns_no_row()
    {
        $this->assert_same(0, Author::count(['limit' => 0]));
        $this->assert_same(0, Author::count(['limit' => '0']));
        $this->assert_same(0, Author::count(['offset' => 1]));
        $this->assert_same(0, Author::count(['limit' => 5, 'offset' => 1]));
        $this->assert_same(0, Author::count(['group' => 'parent_author_id', 'having' => 'COUNT(*) > 99']));
    }

    public function test_gh34_count_that_returns_a_row_is_unchanged()
    {
        $all = Author::count();
        $this->assert_equals(4, $all);
        $this->assert_same($all, Author::count(['limit' => 5]));
        $this->assert_same($all, Author::count(['limit' => null, 'offset' => 0]));

        $this->assert_equals(3, Author::count(['conditions' => ['author_id > ?', 1]]));
        $this->assert_equals(0, Author::count(['conditions' => ['author_id > ?', 99]]));
        $this->assert_same($this->conn->query_and_fetch_one('SELECT COUNT(*) FROM authors WHERE author_id > 99'), Author::count(['conditions' => ['author_id > ?', 99]]));
        $this->assert_equals(2, Author::count_by_parent_author_id(2));
    }

    public function test_gh34_base_offset_without_limit_keeps_the_pre_34_rendering_and_returns_no_rows()
    {
        // what an adapter that does not override the hook gets: limit($sql, $offset, 0)
        $base = new ReflectionMethod(ActiveRecord\Connection::class, 'offset_without_limit');
        $sql = 'SELECT * FROM authors ORDER BY author_id';
        $rendered = $base->invoke($this->conn, $sql, 1);

        $this->assert_equals($this->conn->limit($sql, 1, 0), $rendered);
        $this->assert_equals([], $this->conn->query($rendered)->fetchAll());
    }

    public function test_fetch_no_results()
    {
        $sth = $this->conn->query('SELECT * FROM authors WHERE author_id=65534');
        $this->assert_equals(null, $sth->fetch());
    }

    public function test_tables()
    {
        $this->assert_true(count($this->conn->tables()) > 0);
    }

    public function test_query_column_info()
    {
        $this->assert_greater_than(0, $this->conn->query_column_info("authors")->rowCount());
    }

    public function test_query_table_info()
    {
        $this->assert_greater_than(0, $this->conn->query_for_tables()->rowCount());
    }

    public function test_query_table_info_must_return_one_field()
    {
        $sth = $this->conn->query_for_tables();
        $this->assert_equals(1, count($sth->fetch()));
    }

    public function test_transaction_commit()
    {
        $original = $this->conn->query_and_fetch_one("select count(*) from authors");

        $this->conn->transaction();
        $this->conn->query("insert into authors(author_id,name) values(9999,'blahhhhhhhh')");
        $this->conn->commit();

        $this->assert_equals($original + 1, $this->conn->query_and_fetch_one("select count(*) from authors"));
    }

    public function test_transaction_rollback()
    {
        $original = $this->conn->query_and_fetch_one("select count(*) from authors");

        $this->conn->transaction();
        $this->conn->query("insert into authors(author_id,name) values(9999,'blahhhhhhhh')");
        $this->conn->rollback();

        $this->assert_equals($original, $this->conn->query_and_fetch_one("select count(*) from authors"));
    }

    public function test_show_me_a_useful_pdo_exception_message()
    {
        try {
            $this->conn->query('select * from an_invalid_column');
            $this->fail();
        } catch (Exception $e) {
            $this->assert_equals(1, preg_match('/(an_invalid_column)|(exist)/', $e->getMessage()));
        }
    }

    public function test_quote_name_does_not_over_quote()
    {
        $c = $this->conn;
        $q = $c::$QUOTE_CHARACTER;

        // only a correctly quoted name passes through (#64); a half-quoted one
        // is no longer trusted: it is wrapped, its quote char doubled
        $this->assert_equals("{$q}string{$q}", $c->quote_name("{$q}string{$q}"));
        $this->assert_equals("{$q}{$q}{$q}string{$q}", $c->quote_name("{$q}string"));
        $this->assert_equals("{$q}string{$q}{$q}{$q}", $c->quote_name("string{$q}"));
    }

    public function test_quote_name_wraps_a_plain_name()
    {
        $c = $this->conn;
        $q = $c::$QUOTE_CHARACTER;

        $this->assert_equals("{$q}name{$q}", $c->quote_name('name'));
        $this->assert_equals("{$q}with space{$q}", $c->quote_name('with space'));
        // quote_name() never splits on dots (#35 splits hash-condition keys only)
        $this->assert_equals("{$q}db.t{$q}", $c->quote_name('db.t'));
    }

    public function test_quote_name_keeps_dotted_quoted_names()
    {
        $c = $this->conn;
        $q = $c::$QUOTE_CHARACTER;

        foreach (["{$q}db{$q}.{$q}t{$q}", "{$q}db{$q}.{$q}t{$q}.{$q}c{$q}", "{$q}a.b{$q}"] as $name) {
            $this->assert_equals($name, $c->quote_name($name));
        }
    }

    public function test_quote_name_doubles_embedded_quote_chars()
    {
        $c = $this->conn;
        $q = $c::$QUOTE_CHARACTER;

        $this->assert_equals("{$q}evil{$q}{$q}name{$q}", $c->quote_name("evil{$q}name"));
        $this->assert_equals("{$q}foo{$q}{$q}{$q}", $c->quote_name("foo{$q}"));
        // not a quoted sequence: neither half of a "half-quoted" dotted name
        // nor an inner-quoted one passes through
        $this->assert_equals("{$q}{$q}{$q}db{$q}{$q}.t{$q}", $c->quote_name("{$q}db{$q}.t"));
        $this->assert_equals("{$q}db{$q}{$q}.{$q}{$q}t{$q}", $c->quote_name("db{$q}.{$q}t"));
    }

    public function test_quote_name_keeps_doubled_quote_inside_quoted_identifier()
    {
        $c = $this->conn;
        $q = $c::$QUOTE_CHARACTER;

        foreach (["{$q}a{$q}{$q}b{$q}", "{$q}a{$q}{$q}b{$q}.{$q}c{$q}", "{$q}{$q}{$q}{$q}"] as $name) {
            $this->assert_equals($name, $c->quote_name($name));
        }
    }

    public function test_quote_name_of_empty_string_has_no_warning()
    {
        $c = $this->conn;
        $q = $c::$QUOTE_CHARACTER;
        $warnings = [];

        set_error_handler(function ($errno, $errstr) use (&$warnings) {
            $warnings[] = $errstr;
            return true;
        });

        try {
            $quoted = $c->quote_name('');
        } finally {
            restore_error_handler();
        }

        $this->assert_equals([], $warnings);
        $this->assert_equals("{$q}{$q}", $quoted);
    }

    public function test_identifier_containing_the_quote_char_works_end_to_end()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        foreach (["evil{$q}name", "foo{$q}", "{$q}foo"] as $alias) {
            $row = $this->conn->query('SELECT 1 AS ' . $this->conn->quote_name($alias))->fetch(PDO::FETCH_ASSOC);
            $this->assert_equals([$alias], array_keys($row));
        }
    }

    public function test_hash_condition_key_cannot_inject_sql()
    {
        $q = $this->conn::$QUOTE_CHARACTER;
        $key = "author_id{$q} IS NOT NULL OR {$q}author_id";

        // used to render `author_id` IS NOT NULL OR `author_id`=? and return every author;
        // it is one identifier (#64), no column of authors, so it is rejected before the query
        $this->assert_unknown_hash_key(Author::class, $key, fn() => Author::all(['conditions' => [$key => 999]]));
        $this->assert_sql_has_exact("WHERE {$q}author_id{$q}{$q} IS NOT NULL OR {$q}{$q}author_id{$q}=?", $this->hash_where_sql([$key => 999]));

        // the joins path prefixes the base table to the key
        $key = "parent_author_id{$q} IS NOT NULL OR {$q}parent_author_id";
        $this->assert_unknown_hash_key(Author::class, $key, fn() => Author::all(['joins' => ['books'], 'conditions' => [$key => 999]]));
        $this->assert_sql_has_exact(
            "WHERE {$q}authors{$q}.{$q}parent_author_id{$q}{$q} IS NOT NULL OR {$q}{$q}parent_author_id{$q}=?",
            $this->hash_where_sql([$key => 999], 'INNER JOIN books ON(books.author_id = authors.author_id)')
        );
    }

    public function test_hash_condition_expression_key_is_a_single_identifier()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        // an SQL expression wrapped in quote chars only ever worked through the
        // bypass; it is now one (unknown) identifier, i.e. an error (#64)
        $key = "{$q}author_id{$q} + {$q}parent_author_id{$q}";
        $this->assert_unknown_hash_key(Author::class, $key, fn() => Author::all(['conditions' => [$key => 4]]));
        $this->assert_sql_has_exact("WHERE {$q}{$q}{$q}author_id{$q}{$q} + {$q}{$q}parent_author_id{$q}{$q}{$q}=?", $this->hash_where_sql([$key => 4]));
    }

    public function test_update_all_set_key_cannot_inject_sql()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        try {
            Author::update_all(['set' => ["parent_author_id{$q} = 99, {$q}name" => 'x'], 'conditions' => ['author_id' => 1]]);
            $this->fail('the crafted set key must not reach the database as SQL');
        } catch (ActiveRecord\DatabaseException) {
        }

        $this->assert_equals(0, Author::count(['conditions' => ['parent_author_id' => 99]]));
    }

    public function test_hash_condition_with_table_qualified_key()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        $authors = Author::all(['conditions' => ['authors.author_id' => 1]]);

        $this->assert_equals(['Tito'], array_map(fn($author) => $author->name, $authors));
        $this->assert_sql_has_exact("WHERE {$q}authors{$q}.{$q}author_id{$q}=?", Author::table()->last_sql);
    }

    public function test_hash_condition_with_qualified_key_of_joined_table()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        $authors = Author::all(['joins' => ['books'], 'conditions' => ['books.name' => 'Another Book']]);

        $this->assert_equals([2], array_map(fn($author) => $author->author_id, $authors));
        $this->assert_sql_has_exact("WHERE {$q}books{$q}.{$q}name{$q}=?", Author::table()->last_sql);
    }

    public function test_hash_condition_with_qualified_base_table_key_and_joins()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        // neither an unquoted nor a pre-quoted qualified key gets the base table prepended
        $authors = Author::all(['joins' => ['books'], 'conditions' => [
            'authors.name' => 'Tito',
            "{$q}books{$q}.{$q}book_id{$q}" => 1,
        ]]);

        $this->assert_equals([1], array_map(fn($author) => $author->author_id, $authors));
        $this->assert_sql_has_exact("WHERE {$q}authors{$q}.{$q}name{$q}=? AND {$q}books{$q}.{$q}book_id{$q}=?", Author::table()->last_sql);
    }

    public function test_unqualified_hash_key_with_joins_still_gets_the_base_table()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        // `name` exists in both tables: the base table is prepended, as before
        $authors = Author::all(['joins' => ['books'], 'conditions' => ['name' => 'Tito']]);

        $this->assert_equals([1], array_map(fn($author) => $author->author_id, $authors));
        $this->assert_sql_has_exact("WHERE {$q}authors{$q}.{$q}name{$q}=?", Author::table()->last_sql);
    }

    public function test_qualified_hash_key_in_list_with_null()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        $authors = Author::all(['conditions' => ['authors.parent_author_id' => [3, null]]]);
        $this->assert_equals([1], array_map(fn($author) => $author->author_id, $authors));
        $this->assert_sql_has_exact("WHERE ({$q}authors{$q}.{$q}parent_author_id{$q} IN(?) OR {$q}authors{$q}.{$q}parent_author_id{$q} IS NULL)", Author::table()->last_sql);

        $authors = Author::all(['joins' => ['books'], 'conditions' => ['books.name' => ['Another Book', null]]]);
        $this->assert_equals([2], array_map(fn($author) => $author->author_id, $authors));
        $this->assert_sql_has_exact("WHERE ({$q}books{$q}.{$q}name{$q} IN(?) OR {$q}books{$q}.{$q}name{$q} IS NULL)", Author::table()->last_sql);
    }

    public function test_relationship_hash_condition_with_qualified_key()
    {
        $q = $this->conn::$QUOTE_CHARACTER;
        $condition = "{$q}authors{$q}.{$q}name{$q}=?";

        // lazy load (create_conditions_from_keys)
        $this->assert_equals('Tito', QualifiedConditionBook::find(1)->author->name);
        $this->assert_sql_has_exact($condition, Author::table()->last_sql);
        $this->assert_null(QualifiedConditionBook::find(2)->author);

        // eager load (query_and_attach_related_models_eagerly)
        $books = QualifiedConditionBook::all(['include' => ['author'], 'order' => 'book_id']);
        $this->assert_equals('Tito', $books[0]->author->name);
        $this->assert_null($books[1]->author);
        $this->assert_sql_has_exact($condition, Author::table()->last_sql);
    }

    public function test_qualified_hash_key_parts_are_quoted_as_identifiers()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        // each dot-separated part is one identifier, never raw SQL
        $this->assert_hash_condition_fails(Author::class, ['conditions' => ['authors.author_id IS NOT NULL OR authors.author_id' => 999]]);
        $this->assert_sql_has_exact("WHERE {$q}authors{$q}.{$q}author_id IS NOT NULL OR authors{$q}.{$q}author_id{$q}=?", Author::table()->last_sql);
    }

    public function test_joins_prefix_only_unqualified_hash_keys()
    {
        $q = $this->conn::$QUOTE_CHARACTER;
        $joins = 'INNER JOIN books ON(books.author_id = authors.author_id)';

        $sql = new ActiveRecord\SQLBuilder($this->conn, "{$q}authors{$q}");
        $sql->joins($joins);
        $sql->where(['id' => 1, "{$q}name{$q}" => 2, "{$q}a.b{$q}" => 3, 'books.name' => 4, "{$q}books{$q}.{$q}book_id{$q}" => 5]);
        $this->assert_equals(
            "SELECT * FROM {$q}authors{$q} $joins WHERE {$q}authors{$q}.{$q}id{$q}=? AND {$q}authors{$q}.{$q}name{$q}=?"
            . " AND {$q}authors{$q}.{$q}a.b{$q}=? AND {$q}books{$q}.{$q}name{$q}=? AND {$q}books{$q}.{$q}book_id{$q}=?",
            $sql->to_s()
        );

        // a db-qualified base table is prefixed as before
        $sql = new ActiveRecord\SQLBuilder($this->conn, "{$q}db{$q}.{$q}authors{$q}");
        $sql->joins($joins);
        $sql->where(['id' => 1]);
        $this->assert_equals("SELECT * FROM {$q}db{$q}.{$q}authors{$q} $joins WHERE {$q}db{$q}.{$q}authors{$q}.{$q}id{$q}=?", $sql->to_s());
    }

    public function test_delete_all_with_table_qualified_hash_key()
    {
        Author::delete_all(['conditions' => ['authors.author_id' => 4]]);

        $this->assert_equals(0, Author::count(['conditions' => ['author_id' => 4]]));
        $this->assert_equals(3, Author::count());
    }

    public function test_joins_keep_every_hash_key_naming_the_same_column()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        // a mandatory scope plus a second key on the same column, spelled another way:
        // with joins both stay ANDed, exactly as without joins (they used to collapse
        // into one key, the last one, so the scope was overridden)
        foreach (["{$q}author_id{$q}", "{$q}books{$q}.{$q}author_id{$q}", 'books.author_id'] as $key) {
            $conditions = ['author_id' => 1, $key => 2];

            $this->assert_equals([], Book::all(['conditions' => $conditions]), $key);
            $this->assert_equals([], Book::all(['joins' => ['author'], 'conditions' => $conditions]), $key);
        }

        Book::all(['joins' => ['author'], 'conditions' => ['author_id' => 1, "{$q}author_id{$q}" => 2]]);
        $this->assert_sql_has_exact("WHERE {$q}books{$q}.{$q}author_id{$q}=? AND {$q}books{$q}.{$q}author_id{$q}=?", Book::table()->last_sql);
    }

    public function test_joins_keep_bind_values_aligned_for_keys_naming_the_same_column()
    {
        $q = $this->conn::$QUOTE_CHARACTER;
        $conditions = [
            'author_id' => [1, 2],
            "{$q}author_id{$q}" => 2,
            'name' => 'Another Book',
            "{$q}books{$q}.{$q}author_id{$q}" => [2, null],
        ];

        $this->assert_equals([2], array_map(fn($book) => $book->book_id, Book::all(['conditions' => $conditions])));
        $this->assert_equals([2], array_map(fn($book) => $book->book_id, Book::all(['joins' => ['author'], 'conditions' => $conditions])));
        $this->assert_sql_has_exact(
            "WHERE {$q}books{$q}.{$q}author_id{$q} IN(?,?) AND {$q}books{$q}.{$q}author_id{$q}=? AND {$q}books{$q}.{$q}name{$q}=?"
            . " AND ({$q}books{$q}.{$q}author_id{$q} IN(?) OR {$q}books{$q}.{$q}author_id{$q} IS NULL)",
            Book::table()->last_sql
        );
    }

    public function test_joins_render_keys_naming_the_same_column_in_order()
    {
        $q = $this->conn::$QUOTE_CHARACTER;
        $joins = 'INNER JOIN books ON(books.author_id = authors.author_id)';

        $sql = new ActiveRecord\SQLBuilder($this->conn, "{$q}authors{$q}");
        $sql->joins($joins);
        $sql->where(['id' => 1, 'name' => 'x', "{$q}id{$q}" => 2, "{$q}authors{$q}.{$q}id{$q}" => 3, 'authors.id' => 4]);

        $this->assert_equals(
            "SELECT * FROM {$q}authors{$q} $joins WHERE {$q}authors{$q}.{$q}id{$q}=? AND {$q}authors{$q}.{$q}name{$q}=?"
            . " AND {$q}authors{$q}.{$q}id{$q}=? AND {$q}authors{$q}.{$q}id{$q}=? AND {$q}authors{$q}.{$q}id{$q}=?",
            $sql->to_s()
        );
        $this->assert_equals([1, 'x', 2, 3, 4], $sql->bind_values());
    }

    public function test_alias_key_and_its_column_are_both_kept_in_hash_conditions()
    {
        $q = $this->conn::$QUOTE_CHARACTER;
        $ids = fn(array $venues) => array_values(array_unique(array_map(fn($v) => $v->id, $venues)));

        // a scope on the column plus a filter on its alias_attribute name (marquee => name):
        // both stay ANDed, in either order, with and without joins (the alias used to
        // replace the column's condition, so 'Blender…' came back)
        foreach ([['name' => 'Warner Theatre', 'marquee' => 'Blender Theater at Gramercy'], ['marquee' => 'Blender Theater at Gramercy', 'name' => 'Warner Theatre']] as $conditions) {
            $this->assert_equals([], Venue::all(['conditions' => $conditions]));
            $this->assert_equals([], Venue::all(['joins' => ['events'], 'conditions' => $conditions]));
            $this->assert_null(Venue::first(['conditions' => $conditions]));
        }

        Venue::all(['conditions' => ['name' => 'Warner Theatre', 'marquee' => 'x']]);
        $this->assert_sql_has_exact("WHERE {$q}name{$q}=? AND {$q}name{$q}=?", Venue::table()->last_sql);
        Venue::all(['joins' => ['events'], 'conditions' => ['name' => 'Warner Theatre', 'marquee' => 'x']]);
        $this->assert_sql_has_exact("WHERE {$q}venues{$q}.{$q}name{$q}=? AND {$q}venues{$q}.{$q}name{$q}=?", Venue::table()->last_sql);

        // with (A)'s spellings of the same column, and the bind values aligned
        $conditions = ['name' => ['Warner Theatre', 'x'], "{$q}name{$q}" => 'Warner Theatre', 'marquee' => ['Warner Theatre', null], 'venues.name' => 'Warner Theatre', 'mycity' => 'Washington'];
        $this->assert_equals([2], $ids(Venue::all(['conditions' => $conditions])));
        $this->assert_equals([2], $ids(Venue::all(['joins' => ['events'], 'conditions' => $conditions])));
        $this->assert_sql_has_exact(
            "WHERE {$q}venues{$q}.{$q}name{$q} IN(?,?) AND {$q}venues{$q}.{$q}name{$q}=? AND ({$q}venues{$q}.{$q}name{$q} IN(?) OR {$q}venues{$q}.{$q}name{$q} IS NULL)"
            . " AND {$q}venues{$q}.{$q}name{$q}=? AND {$q}venues{$q}.{$q}city{$q}=?",
            Venue::table()->last_sql
        );
    }

    public function test_alias_keys_are_mapped_in_count_exists_update_all_and_delete_all()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        // used to reach the database unmapped (unknown column `marquee`)
        $this->assert_equals(1, Venue::count(['conditions' => ['marquee' => 'Warner Theatre']]));
        $this->assert_equals(1, Venue::count(['marquee' => 'Warner Theatre', 'mycity' => 'Washington']));
        $this->assert_true(Venue::exists(['marquee' => 'Warner Theatre']));
        $this->assert_equals(1, Venue::update_all(['set' => ['state' => 'ZZ'], 'conditions' => ['marquee' => 'Warner Theatre']]));
        $this->assert_sql_has_exact("WHERE {$q}name{$q}=?", Venue::table()->last_sql);
        $this->assert_equals(1, Venue::count(['conditions' => ['state' => 'ZZ']]));

        // an alias and its column are both kept, as in finders
        $both = ['name' => 'Warner Theatre', 'marquee' => 'Blender Theater at Gramercy'];
        $this->assert_equals(0, Venue::count(['conditions' => $both]));
        $this->assert_false(Venue::exists($both));
        $this->assert_equals(0, Venue::update_all(['set' => ['state' => 'XX'], 'conditions' => $both]));
        $this->assert_sql_has_exact("WHERE {$q}name{$q}=? AND {$q}name{$q}=?", Venue::table()->last_sql);
        $this->assert_equals(0, Venue::delete_all(['conditions' => $both]));
        $this->assert_sql_has_exact("WHERE {$q}name{$q}=? AND {$q}name{$q}=?", Venue::table()->last_sql);

        $count = Venue::count();
        $this->assert_equals(1, Venue::delete_all(['conditions' => ['marquee' => 'Warner Theatre', 'name' => 'Warner Theatre']]));
        $this->assert_equals($count - 1, Venue::count());
    }

    public function test_alias_keys_are_mapped_in_relationship_hash_conditions()
    {
        // lazy
        $this->assert_equals(2, MarqueeEvent::find(2)->venue->id);
        $this->assert_null(MarqueeEvent::find(1)->venue);
        $this->assert_null(MarqueeEvent::find(2)->scoped_venue);
        $this->assert_null(MarqueeEvent::find(1)->scoped_venue);

        // eager
        $events = MarqueeEvent::all(['conditions' => ['id' => [1, 2]], 'include' => ['venue', 'scoped_venue'], 'order' => 'id']);
        $this->assert_equals([null, 2], array_map(fn($e) => $e->venue?->id, $events));
        $this->assert_equals([null, null], array_map(fn($e) => $e->scoped_venue?->id, $events));
    }

    public function test_alias_key_without_its_column_renders_as_before()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        $this->assert_equals([2], array_map(fn($v) => $v->id, Venue::all(['conditions' => ['marquee' => 'Warner Theatre', 'mycity' => 'Washington']])));
        $this->assert_sql_has_exact("WHERE {$q}name{$q}=? AND {$q}city{$q}=?", Venue::table()->last_sql);
    }

    public function test_unknown_hash_condition_key_is_rejected_before_the_query()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        foreach (['nope', "{$q}nope{$q}"] as $key) {
            $conditions = [$key => 1];

            $this->assert_unknown_hash_key(Author::class, $key, fn() => Author::all(['conditions' => $conditions]));
            $this->assert_unknown_hash_key(Author::class, $key, fn() => Author::find('all', ['conditions' => $conditions]));
            $this->assert_unknown_hash_key(Author::class, $key, fn() => Author::first(['conditions' => $conditions]));
            $this->assert_unknown_hash_key(Author::class, $key, fn() => Author::last(['conditions' => $conditions]));
            $this->assert_unknown_hash_key(Author::class, $key, fn() => Author::all(['joins' => ['books'], 'conditions' => $conditions]));
            $this->assert_unknown_hash_key(Author::class, $key, fn() => Author::count(['conditions' => $conditions]));
            $this->assert_unknown_hash_key(Author::class, $key, fn() => Author::count($conditions));
            $this->assert_unknown_hash_key(Author::class, $key, fn() => Author::exists($conditions));
            $this->assert_unknown_hash_key(Author::class, $key, fn() => Author::update_all(['set' => ['name' => 'x'], 'conditions' => $conditions]));
            $this->assert_unknown_hash_key(Author::class, $key, fn() => Author::delete_all(['conditions' => $conditions]));
        }

        // a valid key next to the unknown one changes nothing; no row was touched
        $this->assert_unknown_hash_key(Author::class, 'nope', fn() => Author::delete_all(['conditions' => ['author_id' => 1, 'nope' => 1]]));
        $this->assert_equals(4, Author::count());
        $this->assert_equals(0, Author::count(['conditions' => ['name' => 'x']]));
    }

    public function test_function_call_hash_condition_key_is_an_unknown_column()
    {
        // a hash key is always an identifier: LOWER(name) is one (unknown) column name,
        // never a call; write expressions as a positional condition instead
        $this->assert_unknown_hash_key(Author::class, 'LOWER(name)', fn() => Author::all(['conditions' => ['LOWER(name)' => 'tito']]));
        $this->assert_equals(['Tito'], array_map(fn($a) => $a->name, Author::all(['conditions' => ['LOWER(name) = ?', 'tito']])));
    }

    public function test_hash_condition_keys_match_column_names_as_the_database_does()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        if ($this->conn instanceof ActiveRecord\PgsqlAdapter) {
            // a quoted identifier is case-sensitive
            $this->assert_unknown_hash_key(Author::class, 'AUTHOR_ID', fn() => Author::all(['conditions' => ['AUTHOR_ID' => 1]]));
            // system columns and the whole-row reference resolve too
            $this->assert_equals([], Author::all(['conditions' => ['ctid' => null, 'tableoid' => null, 'xmin' => null]]));
            $this->assert_equals([], Author::all(['conditions' => ['authors' => null]]));
        } else {
            // MySQL, MariaDB and SQLite compare column names case-insensitively
            foreach (['AUTHOR_ID', "{$q}Author_Id{$q}"] as $key) {
                $this->assert_equals(['Tito'], array_map(fn($a) => $a->name, Author::all(['conditions' => [$key => 1]])), $key);
            }

            $this->assert_equals([1], array_map(fn($b) => $b->book_id, Book::all(['conditions' => ['AUTHOR_ID' => 1]])));
            $pseudo = $this->conn instanceof ActiveRecord\SqliteAdapter ? ['rowid', 'OID', '_rowid_'] : ['_rowid', '_ROWID'];

            foreach ($pseudo as $key) {
                $this->assert_equals(['Tito'], array_map(fn($a) => $a->name, Author::all(['conditions' => [$key => 1]])), $key);
            }
        }

        // an alias_attribute name is mapped to its column by the finders
        $this->assert_equals(1, count(Venue::all(['conditions' => ['marquee' => 'Warner Theatre']])));
    }

    public function test_qualified_hash_condition_keys_are_not_checked()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        foreach (['authors.nope', "{$q}authors{$q}.{$q}nope{$q}", 'books.nope'] as $key) {
            Author::table()->last_sql = 'not run';

            try {
                Author::all(['conditions' => [$key => 1]]);
                $this->fail("$key must fail at the database");
            } catch (ActiveRecord\DatabaseException $e) {
                // the database itself rejected it
                $this->assert_not_equals('not run', Author::table()->last_sql, $key);
                $this->assert_false(str_starts_with($e->getMessage(), 'Unknown column'), $e->getMessage());
            }
        }
    }

    public function test_hash_condition_keys_are_not_checked_with_from()
    {
        // `from` may name any table: the database decides
        Author::table()->last_sql = 'not run';

        try {
            Author::all(['from' => 'authors', 'conditions' => ['nope' => 1]]);
            $this->fail('nope must fail at the database');
        } catch (ActiveRecord\DatabaseException) {
            $this->assert_not_equals('not run', Author::table()->last_sql);
        }

        $this->assert_equals(1, count(Author::all(['from' => 'authors', 'conditions' => ['author_id' => 1]])));
    }

    public function test_hash_condition_key_naming_a_select_alias_on_sqlite()
    {
        $options = ['select' => 'name AS label', 'conditions' => ['label' => 'Tito']];

        if (!($this->conn instanceof ActiveRecord\SqliteAdapter)) {
            // MySQL, MariaDB and Postgres do not resolve select aliases in WHERE
            $this->assert_unknown_hash_key(Author::class, 'label', fn() => Author::all($options));

            return;
        }

        // SQLite resolves a select-list alias in WHERE: left to the database
        $this->assert_equals(['Tito'], array_map(fn($a) => $a->label, Author::all($options)));
    }

    public function test_hash_condition_key_missing_from_a_stale_schema_cache_is_rechecked()
    {
        // a column added after the schema was cached (e.g. by a migration) is not rejected
        $table = Author::table();
        $columns = $table->columns;
        unset($table->columns['name']);

        try {
            $authors = Author::all(['conditions' => ['name' => 'Tito']]);
        } finally {
            $table->columns = $columns;
        }

        $this->assert_equals([1], array_map(fn($a) => $a->author_id, $authors));
    }

    public function test_unknown_relationship_hash_condition_key_is_rejected()
    {
        // lazy load
        $this->assert_unknown_hash_key(Author::class, 'nope', fn() => UnknownKeyConditionBook::find(1)->author);

        // eager load
        $this->assert_unknown_hash_key(Author::class, 'nope', fn() => UnknownKeyConditionBook::all(['include' => ['author']]));
    }

    public function test_through_relationship_hash_condition_key_may_name_a_middle_table_column()
    {
        // 'title' is events.title (the middle table), not a column of hosts
        $this->assert_equals([3], array_map(fn($h) => $h->id, TitleScopedVenue::find(2)->hosts));

        $venues = TitleScopedVenue::all(['conditions' => ['id' => [1, 2]], 'include' => ['hosts'], 'order' => 'id']);
        $this->assert_equals([[], [3]], array_map(fn($v) => array_map(fn($h) => $h->id, $v->hosts), $venues));
    }

    public function test_reverse_fk_through_qualifies_the_owner_key()
    {
        $q = $this->conn::$QUOTE_CHARACTER;
        $ids = fn(array $people) => array_map(fn($p) => $p->id, $people);

        // awesome_people (the target) has its own author_id: the unqualified owner key used
        // to be ambiguous. Make it differ from the book's author, so that only books.author_id
        // gives these results.
        AwesomePerson::update_all(['set' => ['author_id' => 3], 'conditions' => ['id' => 1]]);

        $this->assert_equals([1], $ids(ThroughFkAuthor::find(1)->awesome_people));
        $this->assert_sql_has_exact("WHERE {$q}books{$q}.{$q}author_id{$q}=?", AwesomePerson::table()->last_sql);
        $this->assert_equals([], ThroughFkAuthor::find(3)->awesome_people);

        $authors = ThroughFkAuthor::all(['include' => ['awesome_people'], 'order' => 'author_id']);
        $this->assert_equals([[1], [2], [], []], array_map(fn($a) => $ids($a->awesome_people), $authors));
        $this->assert_sql_has_exact("WHERE {$q}books{$q}.{$q}author_id{$q} IN(?,?,?,?)", AwesomePerson::table()->last_sql);

        // the target keeps its own author_id when eager loaded, as when lazy loaded
        $this->assert_equals(3, ThroughFkAuthor::find(1)->awesome_people[0]->author_id);
        $this->assert_equals(3, $authors[0]->awesome_people[0]->author_id);
    }

    public function test_reverse_fk_through_without_a_colliding_target_column_keeps_its_select()
    {
        $q = $this->conn::$QUOTE_CHARACTER;
        Book::$has_many = [['book_reviews']];
        Author::$has_many = [['books'], ['book_reviews', 'through' => 'books', 'order' => 'book_reviews.id asc']];
        ActiveRecord\Table::clear_cache();

        try {
            $authors = Author::all(['include' => ['book_reviews'], 'order' => 'author_id']);
            $sql = BookReview::table()->last_sql;
        } finally {
            Book::$has_many = [];
            Author::$has_many = ['books'];
            ActiveRecord\Table::clear_cache();
        }

        // book_reviews has no author_id: the middle key is still exposed under its own name
        $this->assert_sql_has_exact("SELECT {$q}book_reviews{$q}.*, {$q}books{$q}.author_id AS author_id FROM", $sql);
        $this->assert_equals([1, 1], array_map(fn($r) => $r->author_id, $authors[0]->book_reviews));
    }

    public function test_belongs_to_shaped_through_qualifies_the_owner_key()
    {
        $q = $this->conn::$QUOTE_CHARACTER;
        $ids = fn(array $authors) => array_map(fn($a) => $a->author_id, $authors);

        // books.author_id is the owner key; the target (authors) has an author_id of its own
        $this->assert_equals([2], $ids(CoauthoredAuthor::find(1)->secondary_authors));
        $this->assert_sql_has_exact("WHERE {$q}books{$q}.{$q}author_id{$q}=?", SecondaryAuthor::table()->last_sql);
        $this->assert_equals([], CoauthoredAuthor::find(3)->secondary_authors);

        // eager: same rows, and each target keeps its own author_id
        $owners = CoauthoredAuthor::all(['include' => ['secondary_authors'], 'order' => 'author_id']);
        $this->assert_equals([[2], [2], [], []], array_map(fn($o) => $ids($o->secondary_authors), $owners));
        $this->assert_sql_has_exact("WHERE {$q}books{$q}.{$q}author_id{$q} IN(?,?,?,?)", SecondaryAuthor::table()->last_sql);
    }

    public function test_reverse_fk_has_one_through_with_conditions_qualifies_the_owner_key()
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        // the declared condition names books.name (the middle table) unqualified
        $this->assert_equals(1, ThroughFkAuthor::find(1)->awesome_person->id);
        $this->assert_sql_has_exact("WHERE ({$q}name{$q}=?) AND {$q}books{$q}.{$q}author_id{$q}=?", AwesomePerson::table()->last_sql);
        $this->assert_null(ThroughFkAuthor::find(2)->awesome_person);

        $authors = ThroughFkAuthor::all(['include' => ['awesome_person'], 'order' => 'author_id']);
        $this->assert_equals([1, null], [$authors[0]->awesome_person?->id, $authors[1]->awesome_person?->id]);
    }

    /**
     * Runs $finder, which must throw the "unknown column" DatabaseException for
     * $key before any query reaches the database.
     *
     * @param class-string<ActiveRecord\Model> $class the model whose table is checked
     */
    private function assert_unknown_hash_key(string $class, string $key, callable $finder): void
    {
        $table = $class::table();
        $table->last_sql = 'not run';

        try {
            $finder();
        } catch (ActiveRecord\DatabaseException $e) {
            $this->assert_equals("Unknown column '$key' in hash conditions for $class (table {$table->table})", $e->getMessage());
            $this->assert_equals('not run', $table->last_sql);

            return;
        }

        $this->fail("expected the unknown hash key '$key' to be rejected: " . $table->last_sql);
    }

    /**
     * The WHERE rendering of a conditions hash on the authors table, without running it.
     *
     * @param array<string, mixed> $conditions
     */
    private function hash_where_sql(array $conditions, ?string $joins = null): string
    {
        $sql = new ActiveRecord\SQLBuilder($this->conn, Author::table()->get_fully_qualified_table_name());
        $sql->joins($joins);

        return $sql->where($conditions)->to_s();
    }

    /**
     * Runs a finder that must fail at the database (unknown identifier) and
     * must never return rows.
     *
     * @param class-string<ActiveRecord\Model> $class
     * @param array<string, mixed> $options
     */
    private function assert_hash_condition_fails(string $class, array $options): void
    {
        try {
            $rows = $class::all($options);
        } catch (ActiveRecord\DatabaseException) {
            return;
        }

        $this->fail('expected a DatabaseException, the query returned ' . count($rows) . ' row(s): ' . $class::table()->last_sql);
    }

    private function assert_sql_has_exact(string $needle, ?string $sql): void
    {
        $this->assert_true(str_contains((string) $sql, $needle), "'$needle' not found in: $sql");
    }

    public function test_datetime_to_string()
    {
        $datetime = '2009-01-01 01:01:01 EST';
        $this->assert_equals($datetime, $this->conn->datetime_to_string(date_create($datetime)));
    }

    public function test_date_to_string()
    {
        $datetime = '2009-01-01';
        $this->assert_equals($datetime, $this->conn->date_to_string(date_create($datetime)));
    }

    public function test_boolean_column_defaults_hydrate_as_native_bools()
    {
        // Same semantics on every adapter (pg/sqlite `boolean`, mysql/mariadb
        // tinyint(1) by convention): DEFAULT true => bool true, DEFAULT false
        // => bool false — never the truthy string 'false' or int 0/1 (GH-30).
        $venue = new Venue();
        $this->assert_same(true, $venue->is_available);
        $this->assert_same(false, $venue->is_retired);
    }

    public function test_boolean_attribute_round_trip()
    {
        // GH-30: on Postgres, saving false used to bind the empty string and
        // blow up with SQLSTATE[22P02]; on SQLite values hydrated as strings.
        $venue = new Venue(['name' => 'Bool Roundtrip']);
        $venue->is_available = false;
        $venue->save();

        $found = Venue::find($venue->id);
        $this->assert_same(false, $found->is_available);

        $found->is_available = true;
        $found->save();
        $this->assert_same(true, Venue::find($venue->id)->is_available);
    }

    public function test_boolean_assignment_shapes_cast_to_bool()
    {
        $venue = new Venue();

        $venue->is_available = 't';
        $this->assert_same(true, $venue->is_available);

        $venue->is_available = 1;
        $this->assert_same(true, $venue->is_available);

        $venue->is_available = '0';
        $this->assert_same(false, $venue->is_available);
    }

    public function test_boolean_in_hash_conditions()
    {
        // bools in conditions must be bindable on every adapter (Postgres
        // rejects PDO's default stringification of false as '')
        $venue = new Venue(['name' => 'Bool Cond']);
        $venue->is_available = false;
        $venue->save();

        $matches = Venue::find('all', ['conditions' => ['name' => 'Bool Cond', 'is_available' => false]]);
        $this->assert_equals(1, count($matches));
        $this->assert_same(false, $matches[0]->is_available);
    }

    public function test_boolean_attribute_null_stays_null()
    {
        $venue = new Venue(['name' => 'Bool Null']);
        $venue->is_available = null;
        $venue->save();

        $this->assert_same(null, Venue::find($venue->id)->is_available);
    }

    public function test_exists_sql_yields_integer_scalar()
    {
        // at least one author exists -> 1
        $sql = $this->conn->exists_sql('SELECT 1 FROM authors');
        $this->assert_same(1, (int) $this->conn->query_and_fetch_one($sql));

        // no matching row -> 0. Also proves Postgres' boolean is normalized to an
        // integer here rather than coming back as the string 't'/'f'.
        $none = $this->conn->exists_sql(
            'SELECT 1 FROM authors WHERE ' . $this->conn->quote_name('author_id') . ' = -1'
        );
        $this->assert_same(0, (int) $this->conn->query_and_fetch_one($none));
    }

    private function count_authors_named($name)
    {
        $values = [$name];
        return (int) $this->conn->query_and_fetch_one('SELECT COUNT(*) FROM authors WHERE name = ?', $values);
    }

    private function insert_author_named($name)
    {
        $values = [$name];
        $this->conn->query('INSERT INTO authors(name) VALUES(?)', $values);
    }

    public function test_transaction_commit_persists_writes()
    {
        $this->conn->transaction();
        $this->insert_author_named('tx_commit');
        $this->conn->commit();

        $this->assert_false($this->conn->inTransaction());
        $this->assert_equals(1, $this->count_authors_named('tx_commit'));
    }

    public function test_transaction_rollback_discards_writes()
    {
        $this->conn->transaction();
        $this->insert_author_named('tx_rollback');
        $this->conn->rollback();

        $this->assert_false($this->conn->inTransaction());
        $this->assert_equals(0, $this->count_authors_named('tx_rollback'));
    }

    public function test_nested_transaction_savepoints_and_state()
    {
        $this->assert_false($this->conn->inTransaction());

        $this->conn->transaction();
        $this->assert_true($this->conn->inTransaction());

        $this->conn->transaction();
        $this->assert_equals('SAVEPOINT ar_sp_1', $this->conn->last_query);
        $this->assert_true($this->conn->inTransaction());

        $this->conn->commit();
        $this->assert_equals('RELEASE SAVEPOINT ar_sp_1', $this->conn->last_query);
        // releasing the savepoint must NOT close the real transaction
        $this->assert_true($this->conn->inTransaction());

        $this->conn->commit();
        $this->assert_false($this->conn->inTransaction());
    }

    public function test_nested_transaction_rollback_discards_only_inner_writes()
    {
        $this->conn->transaction();
        $this->insert_author_named('tx_outer');

        $this->conn->transaction();
        $this->insert_author_named('tx_inner');
        $this->conn->rollback();
        $this->assert_equals('ROLLBACK TO SAVEPOINT ar_sp_1', $this->conn->last_query);

        $this->conn->commit();

        $this->assert_equals(1, $this->count_authors_named('tx_outer'));
        $this->assert_equals(0, $this->count_authors_named('tx_inner'));
    }

    public function test_commit_without_active_transaction_throws()
    {
        $this->expect_exception(PDOException::class);
        $this->conn->commit();
    }

    public function test_rollback_without_active_transaction_throws()
    {
        $this->expect_exception(PDOException::class);
        $this->conn->rollback();
    }
}
