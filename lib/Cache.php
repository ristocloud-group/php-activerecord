<?php

namespace ActiveRecord;

use Closure;

/**
 * Cache::get('the-cache-key', function() {
 *	 # this gets executed when cache is stale
 *	 return "your cacheable datas";
 * });
 */
class Cache
{
    /** @var File|Memcache|Redis|null */
    public static $adapter = null;

    /** @var array<string, mixed> */
    public static $options = [];

    /**
     * Initializes the cache.
     *
     * With the $options array it's possible to define:
     * - expiration of the key, (time in seconds)
     * - a namespace for the key
     *
     * this last one is useful in the case two applications use
     * a shared key/store (for instance a shared Memcached db)
     *
     * Ex:
     * $cfg_ar = ActiveRecord\Config::instance();
     * $cfg_ar->set_cache('memcache://localhost:11211',array('namespace' => 'my_cool_app',
     *																											 'expire'		 => 120
     *																											 ));
     *
     * In the example above all the keys expire after 120 seconds, and the
     * all get a postfix 'my_cool_app'.
     *
     * (Note: expiring needs to be implemented in your cache store.)
     *
     * @param string $url URL to your cache server
     * @param array<string, mixed> $options Specify additional options
     * @return void
     */
    public static function initialize($url, $options = [])
    {
        if ($url) {
            $parsed_url = parse_url($url);

            if (!isset($parsed_url['scheme'])) {
                throw new CacheException("Cache URL must specify a scheme (e.g. memcache://... or file://...): $url");
            }

            $file = ucwords(Inflector::instance()->camelize($parsed_url['scheme']));
            $class = "ActiveRecord\\$file";
            require_once __DIR__ . "/cache/$file.php";
            /** @var File|Memcache|Redis $adapter */
            $adapter = new $class($parsed_url, $options);
            static::$adapter = $adapter;
        } else {
            static::$adapter = null;
        }

        static::$options = array_merge(['expire' => 30, 'namespace' => ''], $options);
    }

    /**
     * @return void
     */
    public static function flush()
    {
        if (static::$adapter) {
            static::$adapter->flush();
        }
    }

    /**
     * @param string $key
     * @param Closure $closure
     * @return mixed
     */
    public static function get($key, $closure)
    {
        $key = self::get_namespace() . $key;

        if (!static::$adapter) {
            return $closure();
        }

        if (!($value = static::$adapter->read($key))) {
            static::$adapter->write($key, ($value = $closure()), static::$options['expire']);
        }

        return $value;
    }

    private static function get_namespace(): string
    {
        return (isset(static::$options['namespace']) && strlen(static::$options['namespace']) > 0) ? (static::$options['namespace'] . "::") : "";
    }
}
