<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

require_once 'Column.php';

use PDO;
use PDOException;
use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The base class for database connection adapters.
 *
 * @package ActiveRecord
 * @phpstan-type ConnectionInfo \stdClass&object{protocol: string, host: string, db: string|null, user: string|null, pass: string|null, port?: int, charset?: string}
 * @method Column create_column(array<string, mixed> $column) Builds a {@see Column} from one raw row of
 *         driver-specific column-metadata (as returned by query_column_info()). Implemented
 *         by every concrete adapter (Mysql/Pgsql/Sqlite); some accept $column by reference
 *         as a micro-optimization, none rely on mutating the caller's array.
 */
abstract class Connection
{
    /**
     * The PDO connection object.
     * @var mixed
     */
    public $connection;
    /**
     * The last query run.
     * @var string
     */
    public $last_query;
    /**
     * Contains a Logger object that must impelement a log() method.
     *
     * @var LoggerInterface
     */
    private $logger;
    /**
     * The name of the protocol that is used.
     * @var string
     */
    public $protocol;
    /**
     * Current nesting depth of transaction()/commit()/rollback() calls.
     * Depth 1 is the real PDO transaction; deeper levels are SAVEPOINTs.
     */
    private int $transaction_depth = 0;
    /**
     * Database's date format
     * @var string
     */
    public static $date_format = 'Y-m-d';
    /**
     * Database's datetime format
     * @var string
     */
    public static $datetime_format = 'Y-m-d H:i:s T';
    /**
     * Default PDO options to set for each connection.
     * @var array<int, int|bool>
     */
    public static $PDO_OPTIONS = [
        PDO::ATTR_CASE => PDO::CASE_LOWER,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false];
    /**
     * The quote character for stuff like column and field names.
     * @var string
     */
    public static $QUOTE_CHARACTER = '`';
    /**
     * Default port.
     * @var int
     */
    public static $DEFAULT_PORT = 0;
    /**
     * Maximum number of bind parameters allowed in a single prepared statement.
     * Used by Table::upsert() to chunk large batches. Each concrete adapter
     * declares its own so tests can override one adapter without affecting others.
     * @var int
     */
    public static $MAX_BIND_PARAMS = 65535;

    /**
     * Retrieve a database connection.
     *
     * @param string $connection_string_or_connection_name A database connection string (ex. mysql://user:pass@host[:port]/dbname)
     *   Everything after the protocol:// part is specific to the connection adapter.
     *   OR
     *   A connection name that is set in ActiveRecord\Config
     *   If null it will use the default connection specified by ActiveRecord\Config->set_default_connection
     * @return Connection
     * @see parse_connection_url
     */
    public static function instance(string $connection_string_or_connection_name = "")
    {
        $config = Config::instance();

        if (strpos($connection_string_or_connection_name, '://') === false) {
            $connection_string = $connection_string_or_connection_name
                ? $config->get_connection($connection_string_or_connection_name)
                : $config->get_default_connection_string();
        } else {
            $connection_string = $connection_string_or_connection_name;
        }

        if (!$connection_string) {
            throw new DatabaseException("Empty connection string");
        }

        $info = static::parse_connection_url($connection_string);
        $fqclass = self::load_adapter_class($info->protocol);

        try {
            /** @var Connection $connection */
            $connection = new $fqclass($info);
            $connection->protocol = $info->protocol;
            // fall back to a NullLogger: query() logs unconditionally, so a
            // consumer that never configured a logger must not fatal there
            $connection->logger = $config->get_logger() ?? new NullLogger();

            if (isset($info->charset)) {
                $connection->set_encoding($info->charset);
            }
        } catch (PDOException $e) {
            throw new DatabaseException($e);
        }
        return $connection;
    }

    /**
     * Loads the specified class for an adapter.
     *
     * @param string $adapter Name of the adapter.
     * @return string The full name of the class including namespace.
     */
    private static function load_adapter_class($adapter)
    {
        if (strtolower($adapter) === 'oci') {
            throw new DatabaseException('The OCI/Oracle adapter was removed in php-activerecord v1.8.0.');
        }

        $class = ucwords($adapter) . 'Adapter';
        $fqclass = 'ActiveRecord\\' . $class;
        $source = __DIR__ . "/adapters/$class.php";

        if (!file_exists($source)) {
            throw new DatabaseException("$fqclass not found!");
        }

        require_once($source);
        return $fqclass;
    }

    /**
     * Use this for any adapters that can take connection info in the form below
     * to set the adapters connection info.
     *
     * <code>
     * protocol://username:password@host[:port]/dbname
     * protocol://urlencoded%20username:urlencoded%20password@host[:port]/dbname?decode=true
     * protocol://username:password@unix(/some/file/path)/dbname
     * </code>
     *
     * Sqlite has a special syntax, as it does not need a database name or user authentication:
     *
     * <code>
     * sqlite://file.db
     * sqlite://../relative/path/to/file.db
     * sqlite://unix(/absolute/path/to/file.db)
     * sqlite://windows(c%3A/absolute/path/to/file.db)
     * </code>
     *
     * @param string $connection_url A connection URL
     * @return ConnectionInfo the parsed URL as an object.
     */
    public static function parse_connection_url($connection_url)
    {
        $url = @parse_url($connection_url);

        if (!isset($url['host'])) {
            throw new DatabaseException('Database host must be specified in the connection string. If you want to specify an absolute filename, use e.g. sqlite://unix(/path/to/file)');
        }

        $info = new \stdClass();
        $info->protocol = $url['scheme'] ?? '';
        $info->host = $url['host'];
        $info->db = isset($url['path']) ? substr($url['path'], 1) : null;
        $info->user = $url['user'] ?? null;
        $info->pass = $url['pass'] ?? null;

        $allow_blank_db = ($info->protocol == 'sqlite');

        if ($info->host == 'unix(') {
            $socket_database = $info->host . '/' . $info->db;

            if ($allow_blank_db) {
                $unix_regex = '/^unix\((.+)\)\/?().*$/';
            } else {
                $unix_regex = '/^unix\((.+)\)\/(.+)$/';
            }

            if (preg_match_all($unix_regex, $socket_database, $matches) > 0) {
                $info->host = $matches[1][0];
                $info->db = $matches[2][0];
            }
        } elseif (substr($info->host, 0, 8) == 'windows(') {
            $info->host = urldecode(substr($info->host, 8) . '/' . substr($info->db ?? '', 0, -1));
            $info->db = null;
        }

        if ($allow_blank_db && $info->db) {
            $info->host .= '/' . $info->db;
        }

        if (isset($url['port'])) {
            $info->port = $url['port'];
        }

        if (isset($url['query'])) {
            parse_str($url['query'], $params);

            if (isset($params['charset']) && is_string($params['charset']) && $params['charset'] !== '') {
                $info->charset = $params['charset'];
            }

            if (($params['decode'] ?? null) === 'true') {
                if ($info->user) {
                    $info->user = urldecode($info->user);
                }

                if ($info->pass) {
                    $info->pass = urldecode($info->pass);
                }
            }
        }

        /** @var ConnectionInfo $info */
        return $info;
    }

    /**
     * Class Connection is a singleton. Access it via instance().
     *
     * @param ConnectionInfo $info Parsed connection-url object (see parse_connection_url())
     * @return Connection
     */
    protected function __construct($info)
    {
        try {
            // unix sockets start with a /
            if ($info->host[0] != '/') {
                $host = "host=$info->host";

                if (isset($info->port)) {
                    $host .= ";port=$info->port";
                }
            } else {
                $host = "unix_socket=$info->host";
            }

            $this->connection = new PDO("$info->protocol:$host;dbname=$info->db", $info->user, $info->pass, static::$PDO_OPTIONS);
        } catch (PDOException $e) {
            throw new DatabaseException($e);
        }
    }

    /**
     * Retrieves column meta data for the specified table.
     *
     * @param string $table Name of a table
     * @return array<string, Column> An array of {@link Column} objects, keyed by column name.
     */
    public function columns($table)
    {
        $columns = [];
        $sth = $this->query_column_info($table);

        while (($row = $sth->fetch())) {
            $c = $this->create_column($row);
            $columns[$c->name] = $c;
        }
        return $columns;
    }

    /**
     * Escapes quotes in a string.
     *
     * @param string $string The string to be quoted.
     * @return string The string with any quotes in it properly escaped.
     */
    public function escape($string)
    {
        return $this->connection->quote($string);
    }

    /**
     * Retrieve the insert id of the last model saved.
     *
     * @param string $sequence Optional name of a sequence to use
     * @return int
     */
    public function insert_id($sequence = null)
    {
        return $this->connection->lastInsertId($sequence);
    }

    /**
     * Execute a raw SQL query on the database.
     *
     * @param string $sql Raw SQL string to execute.
     * @param array<int, mixed> &$values Optional array of bind values
     * @return mixed A result set object
     */
    public function query($sql, &$values = [])
    {
        $this->logger->debug($sql, compact('values'));

        $this->last_query = $sql;

        try {
            if (!($sth = $this->connection->prepare($sql))) {
                throw new DatabaseException($this);
            }
        } catch (PDOException $e) {
            throw new DatabaseException($this);
        }

        $sth->setFetchMode(PDO::FETCH_ASSOC);

        try {
            if (!$this->bind_values($sth, $values)) {
                throw new DatabaseException($this);
            }
        } catch (PDOException $e) {
            throw new DatabaseException($e);
        }
        return $sth;
    }

    /**
     * Bind the positional values and execute the prepared statement.
     *
     * Default binding delegates to PDOStatement::execute(), which binds every
     * value as PDO::PARAM_STR. Adapters may override to bind by type.
     *
     * PHP bools (boolean model attributes and condition values) are converted
     * to ints first: PDO would stringify false to '' — rejected by Postgres
     * boolean input and by MySQL strict mode for numeric columns — whereas
     * '0'/'1' are valid boolean input everywhere.
     *
     * @param array<int, mixed> $values Positional bind values
     */
    protected function bind_values(\PDOStatement $sth, array $values): bool
    {
        foreach ($values as &$value) {
            if (is_bool($value)) {
                $value = (int) $value;
            }
        }
        unset($value);

        return $sth->execute($values);
    }

    /**
     * Execute a query that returns maximum of one row with one field and return it.
     *
     * @param string $sql Raw SQL string to execute.
     * @param array<int, mixed> &$values Optional array of values to bind to the query.
     * @return mixed
     */
    public function query_and_fetch_one($sql, &$values = [])
    {
        $sth = $this->query($sql, $values);
        $row = $sth->fetch(PDO::FETCH_NUM);
        return $row[0];
    }

    /**
     * Wrap an inner SELECT in an existence check the database can short-circuit
     * at the first matching row. Returns a query that yields a single scalar
     * 1/0 row (so it fits query_and_fetch_one()). Adapters whose EXISTS() does
     * not already yield an integer override this to normalize to 1/0.
     *
     * @param string $inner a complete inner SELECT, e.g. "SELECT 1 FROM t WHERE …"
     * @return string
     */
    public function exists_sql(string $inner): string
    {
        return "SELECT EXISTS($inner)";
    }

    /**
     * Execute a raw SQL query and fetch the results.
     *
     * @param string $sql Raw SQL string to execute.
     * @param Closure $handler Closure that will be passed the fetched results.
     * @return void
     */
    public function query_and_fetch($sql, Closure $handler)
    {
        $sth = $this->query($sql);

        while (($row = $sth->fetch(PDO::FETCH_ASSOC))) {
            $handler($row);
        }
    }

    /**
     * Returns all tables for the current database.
     *
     * @return list<string> Array containing table names.
     */
    public function tables()
    {
        $tables = [];
        $sth = $this->query_for_tables();

        while (($row = $sth->fetch(PDO::FETCH_NUM))) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    /**
     * Starts a transaction, or a SAVEPOINT when one is already active.
     *
     * Calls nest: the first call opens the real PDO transaction, every
     * further call creates a savepoint, so nested scopes compose instead of
     * clobbering the outer transaction (supported by MySQL/MariaDB,
     * PostgreSQL and SQLite).
     *
     * @return void
     */
    public function transaction()
    {
        if ($this->transaction_depth === 0) {
            if (!$this->connection->beginTransaction()) {
                throw new DatabaseException($this);
            }
        } else {
            $this->query('SAVEPOINT ar_sp_' . $this->transaction_depth);
        }
        ++$this->transaction_depth;
    }

    /**
     * Commits the current scope: releases the savepoint when nested,
     * commits the real transaction at the outermost level.
     *
     * @return void
     */
    public function commit()
    {
        if ($this->transaction_depth > 1) {
            $this->query('RELEASE SAVEPOINT ar_sp_' . ($this->transaction_depth - 1));
            --$this->transaction_depth;
            return;
        }

        if (!$this->connection->commit()) {
            throw new DatabaseException($this);
        }
        $this->transaction_depth = 0;
    }

    /**
     * Rolls back the current scope: back to the savepoint when nested,
     * the whole transaction at the outermost level.
     *
     * @return void
     */
    public function rollback()
    {
        if ($this->transaction_depth > 1) {
            $this->query('ROLLBACK TO SAVEPOINT ar_sp_' . ($this->transaction_depth - 1));
            --$this->transaction_depth;
            return;
        }

        if (!$this->connection->rollback()) {
            throw new DatabaseException($this);
        }
        $this->transaction_depth = 0;
    }

    /**
     * Indicates whether or not this connection is currently executing within a DB transaction
     *
     * @return bool
     */
    public function inTransaction()
    {
        return $this->connection->inTransaction();
    }

    /**
     * Tells you if this adapter supports sequences or not.
     *
     * @return boolean
     */
    public function supports_sequences()
    {
        return false;
    }

    /**
     * Return a default sequence name for the specified table.
     *
     * @param string $table Name of a table
     * @param string $column_name Name of column sequence is for
     * @return string sequence name or null if not supported.
     */
    public function get_sequence_name($table, $column_name)
    {
        return "{$table}_seq";
    }

    /**
     * Return SQL for getting the next value in a sequence.
     *
     * @param string $sequence_name Name of the sequence
     * @return string|null
     */
    public function next_sequence_value($sequence_name)
    {
        return null;
    }

    /**
     * Quote a name like table names and field names.
     *
     * @param string $string String to quote.
     * @return string
     */
    public function quote_name($string)
    {
        return $string[0] === static::$QUOTE_CHARACTER || $string[strlen($string) - 1] === static::$QUOTE_CHARACTER
            ? $string : static::$QUOTE_CHARACTER . $string . static::$QUOTE_CHARACTER;
    }

    /**
     * Builds the standard-SQL conflict clause for an upsert (used by Postgres and
     * SQLite). MysqlAdapter overrides this with ON DUPLICATE KEY UPDATE.
     *
     * @param string[] $unique Column names forming the conflict target
     * @param string[] $update Column names to overwrite on conflict
     * @return string
     */
    public function upsert_conflict_clause(array $unique, array $update): string
    {
        $target = implode(', ', array_map([$this, 'quote_name'], $unique));

        $sets = [];
        foreach ($update as $column) {
            $q = $this->quote_name($column);
            $sets[] = "$q = EXCLUDED.$q";
        }

        return "ON CONFLICT ($target) DO UPDATE SET " . implode(', ', $sets);
    }

    /**
     * Return a date time formatted into the database's date format.
     *
     * @param \DateTime $datetime The DateTime object (native \DateTime or ActiveRecord\DateTime)
     * @return string
     */
    public function date_to_string($datetime)
    {
        return $datetime->format(static::$date_format);
    }

    /**
     * Return a date time formatted into the database's datetime format.
     *
     * @param \DateTime $datetime The DateTime object (native \DateTime or ActiveRecord\DateTime)
     * @return string
     */
    public function datetime_to_string($datetime)
    {
        return $datetime->format(static::$datetime_format);
    }

    /**
     * Converts a string representation of a datetime into a DateTime object.
     *
     * @param string $string A datetime in the form accepted by date_create()
     * @return DateTime|null
     */
    public function string_to_datetime($string)
    {
        $date = date_create($string);
        $errors = \DateTime::getLastErrors();

        if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        if (false === $date) {
            return null;
        }

        return new DateTime($date->format(static::$datetime_format));
    }

    /**
     * Adds a limit clause to the SQL query.
     *
     * @param string $sql The SQL statement.
     * @param int|null $offset Row offset to start at.
     * @param int $limit Maximum number of rows to return.
     * @return string The SQL query that will limit results to specified parameters
     */
    abstract public function limit($sql, $offset, $limit);

    /**
     * Query for column meta info and return statement handle.
     *
     * @param string $table Name of a table
     * @return \PDOStatement
     */
    abstract public function query_column_info($table);

    /**
     * Query for all tables in the current database. The result must only
     * contain one column which has the name of the table.
     *
     * @return \PDOStatement
     */
    abstract public function query_for_tables();

    /**
     * Executes query to specify the character set for this connection.
     *
     * @param string $charset
     * @return void
     */
    abstract public function set_encoding($charset);

    /**
     * Returns an array mapping of native database types
     *
     * @return array<string, string|array{name: string, length?: int}>
     */
    abstract public function native_database_types();

    /**
     * Specifies whether or not adapter can use LIMIT/ORDER clauses with DELETE & UPDATE operations
     *
     * @internal
     * @return bool (FALSE by default)
     */
    public function accepts_limit_and_order_for_update_and_delete()
    {
        return false;
    }

    /**
     * Closes the underlying PDO connection.
     *
     * @return void
     */
    public function close()
    {
        // Clear reference to PDO conn so that PHP will garbage collect and trigger PDO to close DB conn
        $this->connection = null;
    }

}

;
