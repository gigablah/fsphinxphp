<?php

namespace FSphinx;

/**
 * @brief       Adapter for the APC cache.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */
class CacheApc implements CacheInterface
{
    /** Prefix for sticky cache keys. */
    const CACHE_STICKY = '_';

    /** Namespace prefix for FSphinx. */
    const CACHE_PREFIX = 'FSPHINX_';

    /**
     * Creates an APC adapter and checks whether APC is enabled.
     */
    public function __construct()
    {
        if (!extension_loaded('apc') || ini_get('apc.enabled') != '1') {
            throw new \Exception('The APC extension is not loaded.');
        } elseif (php_sapi_name() == 'cli' && ini_get('apc.enable_cli') != '1') {
            throw new \Exception('APC is not enabled. Please set apc.enable_cli = 1');
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
        $key = self::CACHE_PREFIX . $key;
        $sticky_key = self::CACHE_STICKY . $key;

        // Sticky keys take precedence
        $result = apc_fetch($sticky_key);
        if ($result === false) {
            $result = apc_fetch($key);
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
            return apc_store($key, $data);
        }

        return apc_add($key, $data);
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
        $prefix = '/^' . ($sticky ? preg_quote(self::CACHE_STICKY) . '?' : '') . $prefix . '/';

        // only match keys in FSphinx namespace
        $entries = new \APCIterator(
            'user',
            $prefix,
            APC_ITER_KEY
        );

        // apc_delete accepts APCIterator
        return apc_delete($entries);
    }
}
