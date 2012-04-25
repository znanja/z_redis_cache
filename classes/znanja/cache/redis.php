<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Redis caching driver for Kohana, with tagging support
 * 
 * @package znanja/Cache
 * @category Caching
 * @author znanja, inc
 */
class znanja_Cache_Redis extends Cache implements Kohana_Cache_Tagging
{
	/**
	 * Redis class to use
	 * 
	 * @var Redis
	 */
	protected $_redis;

	/**
	 * Default configuration to merge
	 * 
	 * @var array
	 */
	private $_default_config = array(
		'host'			=> 'localhost',
		'port'			=> 6379,
		'persistent'	=> FALSE,
		'database'		=> 15,
		'timeout'		=> 0
	);

	const TAG_SET = "tag:%s:keys";
	const OBJ_TAG = "%s:tags";

	/**
	 * Build the Redis cache object.
	 * 
	 * One caveat is that onle the last defined server is used -- this is due to the phpredis
	 * PECL and not a a limitation of this module
	 * 
	 * @param array $config Config information to use for caching
	 */
	protected function __construct(array $config)
	{
		if( ! extension_loaded('redis') )
		{
			throw new Kohana_Cache_Exception('Redis PHP extension not loaded');
		}

		parent::__construct($config);

		$this->_redis = new Redis();

		$servers = Arr::get($this->_config, 'servers', NULL);

		if( ! $servers )
		{
			throw new Kohana_Cache_Exception("No Redis servers defined in the configuration");
		}

		foreach($servers as $server)
		{
			$server += $this->_default_config;

			if($server['persistent'])
			{
				$con = $this->_redis->pconnect($server['host'], $server['port'], $server['timeout']);
			} else
			{
				$con = $this->_redis->connect($server['host'], $server['port'], $server['timeout']);
			}
			if( ! $con)
			{
				// phpredis has a strange way of closing the connection -- it seems to add
				// failed connection resources to the object, which causes __destruct() to
				// eventually fail (for objects with failed connections), and calling close()
				// directly on the server fails as well
				try
				{
					$this->_redis->close();
					unset($this->_redis);
				}catch(RedisException $ex){
					continue;
				}
			}
			$this->_redis->select($server['database']);
		}
	}

	/**
	 * Get a cache item, or return a default value if the is not
	 * found in the cache
	 * 
	 * @param  string $id      The key value stored in the database
	 * @param  string $default The default value to return if nothing is found
	 * @return boolean         The result of the query to the cache database
	 */
	public function get($id, $default = NULL)
	{
		// We don't need Cache::_sanitize_id for Redis		
		$data = $this->_redis->get($id);
		
		$value = json_decode($data, TRUE);
		if($value === NULL)
		{
			$value = $data;
		}

		return $value !== FALSE ? $value : $default;
	}

	/**
	 * Set an $id to to the information stored in $data, for up to
	 * $lifetime (0 for no expiry).
	 * 
	 * This function will encode (in JSON) the data, but it will not keep the state
	 * for objects. For this case, you will want to serialize() and unserialize the
	 * data before it goes into the database.
	 * 
	 * @param string  $id      The key to use
	 * @param mixed  $data     The data to store under the key
	 * @param integer $lifeime The lifetime we should store the key for
	 * @return boolean		   If we were successful
	 */
	public function set($id, $data, $lifetime = 3600)
	{
		try
		{
			$value = json_encode($data);
		} catch(Exception $ex)
		{
			$value = $data; 
		}
		
		// No need to sanitize the ID
		if($lifetime <= 0)
		{
			return $this->_redis->set($id, $value);
		}
		return $this->_redis->setex($id, $lifetime, $value);
	}

	/**
	 * Delete a key from the database (optionally after $timeout number
	 * of seconds).
	 * 
	 * @param  string $id       The key to remove
	 * @param  integer $timeout The number of seconds before we delete it
	 * @return boolean          If we were successful
	 */
	public function delete($id, $timeout = 0)
	{
		if($timeout > 0)
		{
			return $this->_redis->expire($id, $timeout);
		}
		return $this->_redis->delete($id);
	}

	/**
	 * Delete all the items stored in our cache database
	 * 
	 * @return boolean If we managed to delete everything
	 */
	public function delete_all()
	{
		return $this->_redis->flushdb();
	}

	/**
	 * Set a value based on a key, with a set of tags.
	 * 
	 * @see Redis_Cache::set
	 * @param string $id       The key ID to use for storage
	 * @param mixed $data      The data to cache 
	 * @param int $lifetime    The storage time
	 * @param array $tags      The tags to cache with
	 */
	public function set_with_tags($id, $data, $lifetime = NULL, array $tags = NULL)
	{
		$result = $this->set($id, $data, $lifetime);

		if( $result )
		{
			if( $tags !== NULL )
			{
				foreach($tags as $_tag)
				{
					$_tid = sprintf(self::TAG_SET, $_tag);
					$_id_tags = sprintf(self::OBJ_TAG, $id);
					
					$this->_redis->sadd($_tid, $id);
					$this->_redis->sadd($_id_tags, $_tid);
				}
			}
		}
		return $result;
	}

	/**
	 * Remove a tag, and it's associated cache items
	 * 
	 * @param  string $tag The tag to remove
	 * @return boolean     Success or failure
	 */
	public function delete_tag($tag)
	{
		$_tid = sprintf(self::TAG_SET, $tag);
		$members = $this->find($tag);

		foreach($members as $_member)
		{
			// find other tags with this ID
			$tags = $this->find_tags($_member);

			// remove the member, and the member's tags object
			$this->_redis->del($_member);
			$_tob = sprintf(self::OBJ_TAG, $_member);
			$this->_redis->del($_tob);

			// remove this id from tags
			foreach($tags as $__tag)
			{
				$this->_redis->srem($__tag, $_member);
			}
		}

		return $this->_redis->del($_tid);
	}
	/**
	 * Find all cache entires based on a tag
	 * 
	 * @param  string $tag Tag to find entries for
	 * @return array       The key names of the objects in cache
	 */
	public function find($tag)
	{
		$_tid = sprintf(self::TAG_SET, $tag);

		return $this->_redis->smembers($_tid);
	}

	/**
	 * Return a list of tags currently assocaited with an ID
	 * 
	 * @param  string $id Key to check on tags for
	 * @return array      A list of the array tags
	 */
	public function find_tags($id)
	{
		$_tid = sprintf(self::OBJ_TAG, $id);

		return $this->_redis->smembers($_tid);
	}
}