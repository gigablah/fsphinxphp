<?php

/**
 * @mainpage
 * @brief       FSphinx PHP extends the Sphinx API to provide an easy way to perform faceted search.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */

namespace FSphinx;

use Sphinx\SphinxClient;

/**
 * @brief       A class that extends the Sphinx client to support faceted queries.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */
class FSphinxClient extends SphinxClient implements DataSourceInterface
{
    /**
     * @var FacetGroup Collection of Facet objects.
     */
    public $facets;

    /**
     * @var MultiFieldQuery Parser to extract query terms.
     */
    private $_query_parser;

    /**
     * @var MultiFieldQuery Parsed query object.
     */
    private $_query;

    /**
     * @var string (Optional) Limit Sphinx search to this index.
     */
    private $_default_index;

    /**
     * @var boolean If TRUE, uses filtering by ID rather than attribute string matching.
     */
    private $_filtering;

    /**
     * @var array Temporary cache for Sphinx client settings.
     */
    private $_options;

    /**
     * Create a Sphinx client with the additional functionalities of FSphinx.
     * If no default index is defined, queries all indexes by default.
     *
     * @param string $default_index Limit Sphinx search to this index.
     */
    public function __construct($default_index = null)
    {
        $this->facets = null;
        $this->_query_parser = null;
        $this->_query = null;
        $this->_default_index = $default_index ?: '*';
        $this->_filtering = false;
        $this->_options = array();

        parent::__construct();
    }

    /**
     * Attach a query parser to process string queries passed to a Facet or FSphinx.
     *
     * @param MultiFieldQuery $query_parser Parser to extract query terms.
     */
    public function attachQueryParser(MultiFieldQuery $query_parser)
    {
        $this->_query_parser = $query_parser;
    }

    /**
     * Attach a list of Facets to be computed.
     * The Facets are placed into a FacetGroup for better performance.
     *
     * @param Facet $facets List of Facets for the Sphinx index.
     */
    public function attachFacets($facets)
    {
        $facets = func_get_args();
        $this->facets = new FacetGroup($facets);
        $this->facets->attachSphinxClient($this);
    }
    
    /**
     * Attach a Facet to the collection for computation
     * The Facet is added to FacetGroup for better performance.
     *
     * @param Facet $facet Facet object to add
     */
    public function attachFacet($facet)
    {
        if(empty($this->facets)) {
            $this->attachFacets($facet);
        } else {
            $this->facets->attachFacet($facet);
        }
    }
    
    /**
     * Parse a query string and convert it into a MultiFieldQuery object.
     *
     * @param string $query Query string to be parsed.
     * @return MultiFieldQuery Parsed query object.
     * @see MultiFieldQuery::Parse()
     */
    public function parse($query)
    {
        if (!($query instanceof MultiFieldQuery) && $this->_query_parser) {
            return $this->_query_parser->parse($query);
        }

        return $query;
    }

    /**
     * Perform a normal Sphinx query and return the results.
     * If there are Facets defined, compute the Facet values as a batch query.
     *
     * @param MultiFieldQuery|string $query Sphinx query to be computed.
     * @param string $index (Optional) Limit Sphinx search to this index.
     * @param string $comment (Optional) Comment associated with this query.
     * @return array Sphinx result array.
     * @see Facet::Compute()
     */
    public function query($query, $index = null, $comment = '')
    {
        // extract query terms
        $query = $this->_query = $this->parse($query);

        // perform a normal query
        $this->addQuery($query, $index ?: $this->_default_index, $comment);
        $results = $this->runQueries();
        $results = $results[0];

        // compute all facets if there are results found
        if ($this->facets) {
            if (is_array($results) && isset($results['total_found']) && $results['total_found']) {
                $this->facets->compute($query);
            } else {
                $this->facets->reset();
            }
            $results['facets'] = $this->facets->toArray();
        }

        return $results;
    }

    /**
     * Add a query to Sphinx, to be run as part of a batch.
     *
     * @param MultiFieldQuery|string $query Sphinx query to be computed.
     * @param string $index (Optional) Limit Sphinx search to this index.
     * @param string $comment (Optional) Comment associated with this query.
     * @return integer Number of requests.
     */
    public function addQuery($query, $index = null, $comment = '')
    {
        $index = $index ?: $this->_default_index;

        if ($query instanceof MultiFieldQuery) {
            if ($this->getFiltering()) {
                $this->setFilters($query);
            }
            $result = parent::addQuery($query->toSphinx($this->getFiltering()), $index, $comment);
            if ($this->getFiltering()) {
                $this->resetFilters();
            }
        } else {
            $result = parent::addQuery($query, $index, $comment);
        }

        return $result;
    }

    /**
     * Set Facet attribute filters for a Sphinx query.
     *
     * @param MultiFieldQuery $query Sphinx query to be computed.
     */
    public function setFilters(MultiFieldQuery $query)
    {
        foreach ($query as $qt) {
            if (is_numeric($qt->getTerm()) && $qt->getStatus() != '-') {
                $this->setFilter($qt->getAttribute(), array($qt->getTerm()));
            }
        }
    }

    /**
     * Wrapper function for Sphinx batch query execution.
     *
     * @return array Sphinx result array.
     */
    public function runQueries()
    {
        $results = parent::runQueries();

        return $results;
    }

    /**
     * Declare the index to query against. If not set, all indexes will be searched.
     *
     * @param string $index Name of Sphinx index.
     */
    public function setDefaultIndex($index)
    {
        $this->_default_index = $index;
    }

    /**
     * Declare whether to use SetFilter on a numerical term instead of attribute string matching.
     *
     * @param boolean $filtering If TRUE, filter by numerical query term.
     */
    public function setFiltering($filtering)
    {
        $this->_filtering = (Boolean) $filtering;
    }

    /**
     * Check whether numerical term filtering is active.
     *
     * @return boolean If TRUE, numerical term filtering is active.
     */
    public function getFiltering()
    {
        return $this->_filtering;
    }

    /**
     * Retrieve the query object.
     *
     * @return MultiFieldQuery Query object.
     */
    public function getQuery()
    {
        return $this->_query;
    }

    /**
     * Stash the current Sphinx query settings.
     *
     * @param array $options List of settings to preserve.
     */
    public function saveOptions(array $options)
    {
        // clear settings cache
        $this->_options = array();

        foreach ($options as $option) {
            $this->_options[$option] = $this->$option;
        }
    }

    /**
     * Restore saved query settings.
     */
    public function loadOptions()
    {
        foreach ($this->_options as $option => $value) {
            $this->$option = $value;
        }
    }

    /**
     * Creates an FSphinxClient instance from an initialization file.
     *
     * @param string $file Path to init file (absolute or relative to include_path).
     *                     The file must be a PHP script that returns an FSphinxClient object.
     * @return FSphinxClient|null Created FSphinx client, or null if unsuccessful.
     */
    public static function fromConfig($file)
    {
        if (file_exists($file)) {
            $sphinx = include($file);
            if (is_object($sphinx) && $sphinx instanceof FSphinxClient) {
                return $sphinx;
            }
        }

        return null;
    }

    /**
     * DataSourceInterface method. Allows FSphinx to act as a data source for term mapping.
     *
     * @param array $matches Results from Sphinx computation.
     * @param array $options Source config defining index, ID attribute and term attribute names.
     * @param Closure $getter Anonymous function to extract ID attribute from a result element.
     * @return array ID-term pairs obtained from a Sphinx query.
     */
    public function fetchTerms(array $matches, array $options, \Closure $getter)
    {
        $index = $options['name'];
        $id_attr = $options['id'];
        $term_attr = $options['term'];

        $ids = array();
        foreach ($matches as $match) {
            $ids[$getter($match)] = true;
        }

        $ids = array_keys($ids);

        // stash current Sphinx settings
        $this->saveOptions(
            array(
                'offset', 'limit', 'maxmatches', 'cutoff',            // modified by setLimits
                'select',                                             // modified by setSelect
                'groupby', 'groupfunc', 'groupsort', 'groupdistinct', // modified by resetGroupBy
                'mode',                                               // modified by setMatchMode
                'sort', 'sortby',                                     // modified by setSortMode
            )
        );
        $arrayresult = $this->arrayresult;

        $this->setLimits(0, count($ids), 0, 0);
        $this->setFilter($id_attr, $ids);
        $this->setSelect('');
        $this->resetGroupBy();
        $this->setMatchMode(self::SPH_MATCH_FULLSCAN);
        $this->setSortMode(self::SPH_SORT_RELEVANCE);
        $this->setArrayResult(true);
        $this->addQuery('', $index);
        $this->resetFilters();

        $results = $this->runQueries();

        $this->loadOptions();
        $this->setArrayResult($arrayresult);

        if (is_array($results)) {
            $results = $results[0];
        }
        if (isset($results['total_found']) && $results['total_found']) {
            $terms = array();
            foreach ($results['matches'] as $match) {
                if (isset($match['attrs'][$id_attr]) && isset ($match['attrs'][$term_attr])) {
                    $terms[$match['attrs'][$id_attr]] = $match['attrs'][$term_attr];
                }
            }

            return $terms;
        }

        return null;
    }
}
