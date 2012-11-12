<?php

namespace FSphinx;

/**
 * @brief       A class for performing computation and caching operations for a set of Facets.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */
class FacetGroup implements \IteratorAggregate, \Countable
{
    /**
     * @var array Array of Facet objects.
     */
    private $_facets;

    /**
     * @var double Aggregates the time taken for Facet computation.
     */
    private $_time;

    /**
     * @var FSphinxClient Sphinx client used to compute Facet values.
     */
    private $_sphinx;

    /**
     * @var DataSourceInterface (Optional) Data source used to fetch string terms.
     */
    private $_datasource;

    /**
     * @var FacetGroupCache (Optional) Cache for storing computed Facet values.
     */
    private $_cache;

    /**
     * @var boolean Whether to preload Facet values.
     */
    private $_preloading;

    /**
     * @var boolean Whether to cache computed Facet values.
     */
    private $_caching;

    /**
     * Creates a collection of Facets which adds enhancements like batch queries and caching.
     * With batch querying, multiple Facets can be computed with one single call to Sphinx.
     * Facets may be preloaded or cached to improve performance.
     *
     * @param mixed $objects Facet objects to attach. Also handles Sphinx client and data source.
     */
    public function __construct($objects)
    {
        if (func_num_args() === 1) {
            if (!is_array($objects)) {
                $objects = array($objects);
            }
        } else {
            $objects = func_get_args();
        }

        $this->_sphinx = null;
        $this->_datasource = null;
        $this->_cache = null;
        $this->_time = 0;
        $this->_facets = array();

        foreach ($objects as $object) {
            if (!is_object($object)) {
                continue;
            }
            elseif ($object instanceof Facet) {
                $this->attachFacet($object);
            }
            elseif ($object instanceof FSphinxClient) {
                $this->attachSphinxClient($object);
            }
            elseif ($object instanceof DataSourceInterface) {
                $this->attachDataSource($object);
            }
            elseif ($object instanceof FacetGroupCache) {
                $this->attachCache($object);
            }
        }

        // Caching parameters
        $this->_preloading = false;
        $this->_caching = false;
    }

    /**
     * Adds a Facet object to the collection.
     *
     * @param Facet $facet Facet object to add.
     */
    public function attachFacet(Facet $facet)
    {
        $this->_facets[] = $facet;
    }

    /**
     * Attach an FSphinx client to perform computations for all attached Facets.
     *
     * @param FSphinxClient $sphinx FSphinx client object.
     */
    public function attachSphinxClient(FSphinxClient $sphinx)
    {
        $this->_sphinx = $sphinx;
    }

    /**
     * Attach a data source implementing DataSourceInterface. This will be passed to Facets
     * without defined data sources to perform term mapping.
     *
     * @param DataSourceInterface $datasource Data source object.
     */
    public function attachDataSource(DataSourceInterface $datasource)
    {
        $this->_datasource = $datasource;
    }

    /**
     * Attach a cache for storing computed Facet values.
     *
     * @param FacetGroupCache $cache Object that provides an interface to a datastore.
     */
    public function attachCache(FacetGroupCache $cache)
    {
        $this->_cache = $cache;
    }

    /**
     * Turns preloading on or off.
     *
     * @param boolean $preloading Whether to preload Facet values.
     */
    public function setPreloading($preloading = true)
    {
        $this->_preloading = (Boolean) $preloading;
    }

    /**
     * Turns caching on or off.
     *
     * @param boolean $caching Whether to cache computed Facet values.
     */
    public function setCaching($caching = true)
    {
        $this->_caching = (Boolean) $caching;
    }

    /**
     * Compute the values for all Facets in this collection for a given Sphinx query.
     * Note that Facet::Compute() is not called directly.
     *
     * @param MultiFieldQuery|string $query Sphinx query to be computed.
     * @param boolean $caching Whether to enable caching.
     * @return array|null Computed results from Sphinx, or null if none returned.
     */
    public function compute($query, $caching = null)
    {
        // caching parameter always overrides internal setting
        if ($caching !== null) {
            $preloading = false;
        } else {
            $caching = $this->_caching;
            $preloading = $this->_preloading;
        }

        $results = null;
        if (!$caching && !$preloading) {
            $query = $this->prepare($query);
            if ($results = $this->runQueries()) {
                $this->reset();
                $this->setValues($query, $results, $this->_datasource);
                $this->orderValues();
            }
        } else {
            $results = $this->computeCache($query, $caching);
        }

        return $results;
    }

    /**
     * Used internally to prepare all Facets for computation against a given Sphinx query.
     *
     * @param MultiFieldQuery|string $query Sphinx query to be computed.
     * @return MultiFieldQuery Processed Sphinx query as a MultiFieldQuery object.
     * @see Facet::prepare()
     */
    protected function prepare($query)
    {
        if (!($query instanceof MultiFieldQuery)) {
            $query = $this->_sphinx->parse($query);
        }
        foreach ($this->_facets as $facet) {
            $facet->prepare($query, $this->_sphinx);
        }

        return $query;
    }

    /**
     * Used internally to run all Facet computations as a single batch query.
     */
    protected function runQueries()
    {
        $arrayresult = $this->_sphinx->arrayresult;
        $this->_sphinx->setArrayResult(true);
        $results = $this->_sphinx->runQueries();
        $this->_sphinx->setArrayResult($arrayresult);

        return $results;
    }

    /**
     * Used to reset values for all Facets in this collection.
     */
    public function reset()
    {
        foreach ($this->_facets as $facet) {
            $facet->reset();
        }

        $this->_time = 0;
    }

    /**
     * Used internally to set the computed results, metadata and terms for all Facets.
     *
     * @param MultiFieldQuery $query Sphinx query as a MultiFieldQuery object.
     * @param array $results Computed results from Sphinx.
     * @param DataSourceInterface $datasource Data source object.
     * @see Facet::_SetValues()
     * @see Facet::_OrderValues()
     */
    protected function setValues(MultiFieldQuery $query, array $results, DataSourceInterface $datasource = null)
    {
        foreach ($this->_facets as $index => $facet) {
            $result = $results[$index];
            if (is_array($result)) {
                $facet->setValues($query, $result, $datasource);
            }

            $this->_time += $facet->getTime();
        }
    }

    /**
     * Perform custom sorting of Sphinx results for each Facet.
     */
    protected function orderValues()
    {
        foreach ($this->_facets as $index => $facet) {
            if (count($facet)) {
                $facet->orderValues();
            }
        }
    }

    /**
     * Compute the values for all Facets for a given query and store the results in the cache.
     *
     * @param MultiFieldQuery|string $query Sphinx query to be computed.
     * @return boolean TRUE on success, FALSE on failure.
     */
    public function preload($query)
    {
        if (!$this->_cache) {
            return false;
        }
        if (!($query instanceof MultiFieldQuery)) {
            $query = $this->_sphinx->parse($query);
        }
        $this->compute($query, false);

        return $this->_cache->setFacets($query, $this->_facets, true, true);
    }

    /**
     * Attempt to get the computation results from the cache, otherwise, compute and store.
     *
     * @param MultiFieldQuery|string $query Sphinx query to be computed.
     * @param boolean $caching Whether to enable caching.
     * @return array|null Computed results from Sphinx, or null if none returned.
     */
    protected function computeCache($query, $caching = true)
    {
        $results = null;

        if ($this->_cache) {
            if (!( $query instanceof MultiFieldQuery)) {
                $query = $this->_sphinx->parse($query);
            }
            // attempt to get results from cache
            $results = $this->_cache->getFacets($query);
        }

        if ($results) {
            foreach ($this->_facets as $facet) {
                $facet->setResults(array_shift($results));
            }
            // explicitly mark a successful cache hit
            $this->_time = -1;
        } else {
            // cache miss, compute manually
            $results = $this->compute($query, false);
        }

        // save to cache if caching is enabled
        if ($this->_cache && $caching) {
            $this->_cache->setFacets($query, $this->_facets);
        }

        return $results;
    }

    /**
     * Return the aggregated computation time for all Facets.
     *
     * @return double Total time taken for Facet computation.
     */
    public function getTime()
    {
        return $this->_time;
    }

    /**
     * Return the FacetGroup representation in string format.
     *
     * @return string FacetGroup string representation.
     */
    public function __toString()
    {
        $s = sprintf(
            'facets: (%s facets in %s sec.)' . PHP_EOL,
            count($this->_facets),
            $this->_time
        );
        foreach ($this->_facets as $index => $facet) {
            $s .= sprintf('%s. %s' . PHP_EOL, $index + 1, $facet);
        }

        return $s;
    }

    /**
     * Return the FacetGroup representation in array format.
     *
     * @return array FacetGroup array representation.
     */
    public function toArray()
    {
        $facets = array();
        foreach ($this->_facets as $facet) {
            $facets[$facet->getName()] = $facet->toArray();
        }

        return $facets;
    }

    /**
     * IteratorAggregate interface method. Makes the facet group iterable.
     *
     * @return ArrayIterator Array iterator object.
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->_facets);
    }

    /**
     * Countable interface method.
     *
     * @return integer Number of Facet objects.
     */
    public function count()
    {
        return count($this->_facets);
    }
}
