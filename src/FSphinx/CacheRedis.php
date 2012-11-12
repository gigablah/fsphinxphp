<?php

namespace FSphinx;

/**
 * @brief       Adapter for Redis.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */
class CacheRedis implements CacheInterface
{
    /** Prefix for sticky cache keys. */
    const CACHE_STICKY = '_';

    /** Namespace prefix for FSphinx. */
    const CACHE_PREFIX = 'FSPHINX_';

    /**
     * @var Redis Redis client.
     */
    private $_cache;

    /**
     * Creates a Redis adapter.
     *
     * @param Redis $cache Redis client.
     */
    public function __construct(\Redis $cache)
    {
        $this->_cache = $cache;
    }

    /**
     * Retrieve data by key.
     *
     * @param string $key Key identifier.
     * @return mixed Retrieved data.
     */
    public function get($key)
    {
        $key = self::CACHE_PREFIX . $key;
        $sticky_key = self::CACHE_STICKY . $key;

        // Sticky keys take precedence
        $result = $this->_cache->get($sticky_key);
        if ($result === false) {
            $result = $this->_cache->get($key);
        }

        return $result;
    }

    /**
     * Store data by key.
     *
     * @param string $key Key identifier.
     * @param string $data Data to store.
     * @param boolean $overwrite Whether to replace data if the key already exists.
     * @param boolean $sticky Whether to make the key "sticky".
     * @return boolean TRUE if successful, FALSE otherwise.
     */
    public function set($key, $data, $overwrite = false, $sticky = false)
    {
        $key = ($sticky ? self::CACHE_STICKY : '') . self::CACHE_PREFIX . $key;

        if ($overwrite) {
            return $this->_cache->set($key, $data);
        }

        return $this->_cache->setnx($key, $data);
    }

    /**
     * Delete stored data. "Sticky" keys will not be cleared by default.
     *
     * @param string $prefix Keys with this prefix will be deleted.
     * @param boolean $sticky Whether to clear "sticky" values as well.
     * @return boolean TRUE if successful, FALSE otherwise.
     */
    public function clear($prefix, $sticky = false)
    {
        $prefix = self::CACHE_PREFIX . $prefix;
        $prefix = ($sticky ? preg_quote(self::CACHE_STICKY) : '') . $prefix . '*';

        // only match keys in FSphinx namespace
        $entries = $this->_cache->keys($prefix);

        return $this->_cache->delete($entries);
    }
}
