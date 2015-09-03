<?php

namespace FSphinx;

/**
 * @brief       A class for storing computed Facet values.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */
class FacetGroupCache
{
    /**
     * @var CacheInterface Datastore adapter object.
     */
    private $_cache;

    /**
     * Create a cache to store computed Facet values.
     *
     * @param CacheInterface $cache Datastore adapter object.
     */
    public function __construct(CacheInterface $cache)
    {
        $this->_cache = $cache;
    }

    /**
     * Return the cached Facet values for a given Sphinx query.
     *
     * @param MultiFieldQuery $query Sphinx query as a MultiFieldQuery object.
     * @return array|false Unserialized result array, or false if not found.
     */
    public function getFacets(MultiFieldQuery $query)
    {
        $key = $this->getKey($query->toCanonical());

        $result = $this->_cache->get($key);
        if ($result !== false) {
            $result = unserialize($result);
        }

        return $result;
    }

    /**
     * Store the Facet values for a given Sphinx query.
     * If replace is TRUE, always rewrite the values in the cache.
     * If sticky is TRUE, make the cached values always survive.
     *
     * @param MultiFieldQuery $query Sphinx query as a MultiFieldQuery object.
     * @param array $facets Array of Facet objects.
     * @param boolean $replace Whether to replace existing values.
     * @param boolean $sticky Whether to make the cached values sticky (for preloaded Facets).
     * @return boolean TRUE on success, FALSE on failure.
     */
    public function setFacets(MultiFieldQuery $query, array $facets, $replace = false, $sticky = false)
    {
        $key = $this->getKey($query->toCanonical());
        $results = array();

        foreach ($facets as $facet) {
            $results[] = $facet->getResults();
        }

        $results = serialize($results);

        return $this->_cache->set($key, $results, $replace, $sticky);
    }

    /**
     * Clear the cache.
     *
     * @param boolean $clear_sticky Whether to clear preloaded Facet values as well.
     * @return boolean TRUE on success, FALSE on failure.
     */
    public function clear($clear_sticky = false)
    {
        $prefix = $this->getKey();
        return $this->_cache->clear($prefix, $clear_sticky);
    }

    /**
     * Generate a cache key. If the candidate string is empty, generates the prefix instead.
     *
     * @param string $string Candidate string.
     * @return string Generated cache key.
     */
    public function getKey($string = null)
    {
        return $_ENV['APPLICATION_ENV'] . ($string ? md5($string) : '');
    }
}
