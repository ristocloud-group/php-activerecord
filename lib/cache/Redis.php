<?php
namespace ActiveRecord;

use Predis\Client;

/**
 * A Redis-backed implementation of Cache, using the Predis client.
 *
 * Reachable via a redis:// connection string, e.g.
 *   redis://user:pass@host:6379/0?read_write_timeout=2
 *
 * Connection parameters come from the URL and its query string (so any Predis
 * connection parameter is reachable from the DSN); Predis client options
 * (prefix, cluster, ...) come from the 'redis' sub-key of the cache options
 * array. Values are (de)serialized because Redis stores strings.
 *
 * Compatible with Redis 6/7/8 and Valkey 7/8/9: only GET, SET (with EX), DEL,
 * SCAN and FLUSHDB are used, with no server-version feature gating.
 *
 * @package ActiveRecord
 */
class Redis
{
	private const int DEFAULT_PORT = 6379;

	private Client $client;
	private string $namespace;

	/**
	 * @param array $url     result of parse_url() on the redis:// string
	 * @param array $options the options array passed to set_cache()
	 */
	public function __construct(array $url, array $options = [])
	{
		if (!class_exists('Predis\\Client'))
			throw new CacheException("The predis/predis package is required to use the redis cache adapter");

		$parameters = [
			'scheme' => $url['scheme'] ?? 'tcp',
			'host'   => $url['host'] ?? 'localhost',
			'port'   => $url['port'] ?? self::DEFAULT_PORT,
		];

		// A redis:// URL yields scheme 'redis'; Predis expects tcp/tls/unix.
		if ($parameters['scheme'] === 'redis')
			$parameters['scheme'] = 'tcp';

		if (isset($url['user']) && strlen($url['user']))
			$parameters['username'] = $url['user'];
		if (isset($url['pass']) && strlen($url['pass']))
			$parameters['password'] = $url['pass'];
		if (isset($url['path']) && strlen(ltrim($url['path'], '/')))
			$parameters['database'] = (int) ltrim($url['path'], '/');

		// Any Predis connection parameter can be supplied via the DSN query string.
		if (isset($url['query']))
		{
			parse_str($url['query'], $query);
			$parameters = array_merge($parameters, $query);
		}

		$client_options = is_array($options['redis'] ?? null) ? $options['redis'] : [];
		$this->namespace = (string) ($options['namespace'] ?? '');

		$this->client = new Client($parameters, $client_options);
	}

	public function flush(): void
	{
		if ($this->namespace !== '')
		{
			$pattern = $this->namespace . '::*';
			$cursor = 0;
			do
			{
				[$cursor, $keys] = $this->client->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
				if (!empty($keys))
					$this->client->del($keys);
			}
			while ((int) $cursor !== 0);
		}
		else
		{
			$this->client->flushdb();
		}
	}

	public function read($key): mixed
	{
		$value = $this->client->get($key);
		if ($value === null)
			return null;

		$result = @unserialize($value);
		return $result === false ? null : $result;
	}

	public function write($key, $value, $expire=0): void
	{
		$payload = serialize($value);
		if ($expire > 0)
			$this->client->set($key, $payload, 'EX', $expire);
		else
			$this->client->set($key, $payload);
	}
}
