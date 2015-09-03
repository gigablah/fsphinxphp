<?php

namespace FSphinx;

/**
 * @brief       Adapter for Memcached.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */
class CacheMemcached implements CacheInterface
{
    /** Prefix for sticky cache keys. */
    const CACHE_STICKY = '_';

    /** Namespace prefix for FSphinx. */
    const CACHE_PREFIX = 'FSPHINX_';

    /**
     * @var integer Namespace counter for normal keys.
     */
    private $_ns;

    /**
     * @var integer Namespace counter for sticky keys.
     */
    private $_nss;

    /**
     * @var Memcache Memcached client.
     */
    private $_cache;

    /**
     * Creates a Memcached adapter.
     *
     * @param Memcache $cache Memcached client.
     */
    public function __construct(\Memcache $cache)
    {
        $this->_cache = $cache;

        // Official memcached suggestion for namespaces
        $this->_ns = intval($this->_cache->get(self::CACHE_PREFIX . '_NAMESPACE'));
        if (!$this->_ns) {
            $this->_ns = 1;
            $this->_cache->set(self::CACHE_PREFIX . '_NAMESPACE', 1, false, 0);
        }

        $this->_nss = intval($this->_cache->get(self::CACHE_PREFIX . '_NAMESPACE_STICKY'));
        if (!$this->_nss) {
            $this->_nss = 1;
            $this->_cache->set(self::CACHE_PREFIX . '_NAMESPACE_STICKY', 1, false, 0);
        }
    }

    /**
     * Retrieve data by key.
     *
     * @param string $key Key identifier.
     * @return mixed Retrieved data.
     */
    public function get($key)
    {
        $normal_key = self::CACHE_PREFIX . $this->_ns . '_' . $key;
        $sticky_key = self::CACHE_STICKY . $this->_nss . '_' . $key;

        // Sticky keys take precedence
        $result = $this->_cache->get($sticky_key);
        if ($result === false) {
            $result = $this->_cache->get($normal_key);
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
        if ($sticky) {
            $key = self::CACHE_STICKY . $this->_nss . '_' . $key;
        } else {
            $key = self::CACHE_PREFIX . $this->_ns . '_' . $key;
        }

        if ($overwrite) {
            return $this->_cache->set($key, $data, false, 0);
        }

        return $this->_cache->add($key, $data, false, 0);
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
        $this->_ns = $this->_cache->increment(self::CACHE_PREFIX . '_NAMESPACE');

        if ($sticky) {
            $this->_nss = $this->_cache->increment(self::CACHE_PREFIX . '_NAMESPACE_STICKY');
        }

        return $this->_ns;
    }
}
