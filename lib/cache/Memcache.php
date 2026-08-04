<?php

namespace ActiveRecord;

class Memcache
{
    public const DEFAULT_PORT = 11211;

    private \Memcached $memcached;

    /**
     * Creates a Memcache instance.
     *
     * Takes an $options array w/ the following parameters:
     *
     * <ul>
     * <li><b>host:</b> host for the memcached server </li>
     * <li><b>port:</b> port for the memcached server </li>
     * </ul>
     * @param array<string, mixed> $options
     */
    public function __construct($options)
    {
        $this->memcached = new \Memcached();
        $options['port'] ??= self::DEFAULT_PORT;

        if (!$this->memcached->addServer($options['host'], $options['port'])) {
            throw new CacheException("Could not connect to $options[host]:$options[port]");
        }
    }

    /**
     * @return void
     */
    public function flush()
    {
        $this->memcached->flush();
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function read($key)
    {
        return $this->memcached->get($key);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $expire
     * @return void
     */
    public function write($key, $value, $expire)
    {
        $this->memcached->set($key, $value, $expire);
    }
}
