<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * The cache backends this library ships. A cache URL scheme names the case whose value is its class name, computed as
 * it always was (ucwords(camelize($scheme))), so the schemes that resolve are exactly those whose file existed.
 *
 * @internal Serves Cache::initialize(); not a supported API.
 */
enum CacheBackend: string
{
    case File = 'File';
    case Memcache = 'Memcache';
    case Redis = 'Redis';

    /**
     * @return class-string<File|Memcache|Redis>
     */
    public function class_name(): string
    {
        return __NAMESPACE__ . '\\' . $this->value;
    }
}
