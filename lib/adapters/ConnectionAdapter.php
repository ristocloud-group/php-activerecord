<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * The adapters this library ships. A connection protocol names the case whose value is its class name, computed as
 * it always was (ucwords($protocol) . 'Adapter'), so the protocols that resolve are exactly those whose file existed.
 *
 * @internal Serves Connection::load_adapter_class(); not a supported API.
 */
enum ConnectionAdapter: string
{
    case Mysql = 'MysqlAdapter';
    case Pgsql = 'PgsqlAdapter';
    case Sqlite = 'SqliteAdapter';

    /**
     * @return class-string<Connection>
     */
    public function class_name(): string
    {
        return __NAMESPACE__ . '\\' . $this->value;
    }
}
