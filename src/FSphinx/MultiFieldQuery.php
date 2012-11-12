<?php

namespace FSphinx;

/**
 * @brief       A class that provides multi-field query object functionality for Sphinx.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */
class MultiFieldQuery implements \IteratorAggregate, \Countable
{
    /** Whether to use full scan mode for empty queries. */
    const ALLOW_EMPTY = true;

    /** Regex for extracting query terms. */
    const QUERY_PATTERN = '#@(?P<status>[+-]?)(?P<field>\w+|\*)\s+(?P<term>[^@()]+)?|(?P<all>[^@()]+)#iux';

    /**
     * @var array Array of parsed QueryTerm objects.
     */
    private $_qts;

    /**
     * @var string Stores the raw query string.
     */
    private $_raw;

    /**
     * @var array Mapping between user-facing query fields and the actual Sphinx fields.
     */
    private $_user_sph_map;

    /**
     * @var array Mapping between user-facing query fields and Sphinx multi-value attributes.
     */
    private $_user_attr_map;

    /**
     * Creates a multi-field query object that extracts a list of query terms from a regular
     * query string and allows the user to refine and toggle those terms.
     *
     * The user_sph_map specifies the mapping between user-facing query field names and their
     * corresponding Sphinx field names. This information is required by Facets for computation.
     *
     * FSphinx clients that make use of queries with field terms must have extended2 match mode:
     *
     * $cl->SetMatchMode(SPH_MATCH_EXTENDED2);
     *
     * @param array $user_sph_map Mapping between user fields and actual Sphinx fields.
     * @param array $user_attr_map Mapping between user fields and Sphinx multi-value attributes.
     */
    public function __construct(array $user_sph_map = array(), array $user_attr_map = array())
    {
        $this->_user_sph_map = array_change_key_case(
            array_map('strtolower', $user_sph_map),
            CASE_LOWER
        );
        $this->_user_attr_map = array_change_key_case(
            array_map('strtolower', $user_attr_map),
            CASE_LOWER
        );
        $this->_qts = array();
        $this->_raw = '';
    }

    /**
     * Parse a query string and store all resulting QueryTerms in an internal array.
     * Every query passed to a Facet or FSphinx client must be parsed in this way.
     *
     * @param string $query Query string to be parsed.
     * @return MultiFieldQuery Parsed query object.
     */
    public function parse($query)
    {
        $this->_raw = $query;
        $this->_qts = array();
        if (preg_match_all(self::QUERY_PATTERN, $query, $matches) !== false) {
            foreach ($matches['field'] as $index => $field) {
                if ($query_term = QueryTerm::fromMatchObject(
                    array(
                        $matches['status'][$index],
                        $field,
                        $matches['term'][$index],
                        $matches['all'][$index]
                    ),
                    $this->_user_sph_map,
                    $this->_user_attr_map
                )) {
                    $this->addQueryTerm($query_term);
                }
            }
        }
        return $this;
    }

    /**
     * Used internally to add a QueryTerm object to the list of query terms.
     * Indexed by hash to prevent duplicate query terms.
     *
     * @param QueryTerm $query_term QueryTerm object to be added.
     */
    protected function addQueryTerm(QueryTerm $query_term)
    {
        $this->_qts[$query_term->toHash()] = $query_term;
    }

    /**
     * Return a QueryTerm object from the list.
     *
     * @param QueryTerm|string $query_term QueryTerm object or term string.
     * @return QueryTerm|null Matching QueryTerm object, or null if none found.
     */
    public function getQueryTerm($query_term)
    {
        if (is_string($query_term)) {
            $query_term = QueryTerm::fromString($query_term, $this->_user_sph_map);
        }
        if ($query_term instanceof QueryTerm) {
            $hash = $query_term->toHash();
            if (isset($this->_qts[$hash])) {
                return $this->_qts[$hash];
            }
        }

        return null;
    }

    /**
     * Determine if the QueryTerm object exists in the list.
     *
     * @param QueryTerm|string $query_term QueryTerm object or term string.
     * @return boolean Whether the QueryTerm object exists.
     */
    public function hasQueryTerm($query_term)
    {
        return ($this->getQueryTerm($query_term) !== null);
    }

    /**
     * Toggle a query term on or off. The target state can be specified.
     *
     * @param QueryTerm|string $query_term QueryTerm object or term string.
     * @param state string '-' for off, '+' for on.
     */
    public function toggle($query_term, $state = null)
    {
        if ($qt = $this->getQueryTerm($query_term)) {
            if ($state === null) {
                $qt->setStatus($qt->getStatus() ? '' : '-');
            } else {
                $qt->setStatus($state == '-' ? '-' : '');
            }
        }
    }

    /**
     * Toggle a query term on.
     *
     * @param QueryTerm|string $query_term QueryTerm object or term string.
     */
    public function toggleOn($query_term)
    {
        $this->toggle($query_term, '+');
    }

    /**
     * Toggle a query term off.
     *
     * @param QueryTerm|string $query_term QueryTerm object or term string.
     */
    public function toggleOff($query_term)
    {
        $this->toggle($query_term, '-');
    }

    /**
     * Count the number of query terms that match the specified field.
     *
     * @param string $field Query field to look for.
     */
    public function countField($field)
    {
        $sum = 0;
        $field = strtolower($field);
        foreach ($this->_qts as $qt) {
            if ($qt->hasField($field)) {
                $sum++;
            }
        }

        return $sum;
    }

    /**
     * Return the combined query term representation in string format.
     *
     * @return string Combined query term string representation.
     */
    public function __toString()
    {
        return implode(' ', $this->_qts);
    }

    /**
     * Return the combined query term string to be sent to Sphinx.
     *
     * @param boolean $exclude_numeric If TRUE, returns only non-numeric terms.
     * @return string Combined query term string for Sphinx.
     */
    public function toSphinx($exclude_numeric = false)
    {
        $qts = array();
        $sph = '';

        foreach ($this->_qts as $qt) {
            if ($term = $qt->toSphinx($exclude_numeric)) {
                $qts[] = $term;
            }
        }

        if ($qts) {
            $sph = implode(' ', $qts);
        }
        if (!$sph && !self::ALLOW_EMPTY) {
            $sph = ' ';
        }

        return $sph;
    }

    /**
     * Return the canonical combined query term representation in string format.
     * By "canonical", all query terms are sorted in alphabetical order.
     *
     * @return string Canonical combined query term string representation.
     */
    public function toCanonical()
    {
        $_qts = array();
        $qts = $this->_qts;
        usort($qts, array('\\FSphinx\\QueryTerm', 'cmp'));

        foreach ($qts as $qt) {
            $_qts[] = $qt->toCanonical();
        }

        return trim(implode(' ', $_qts));
    }

    /**
     * IteratorAggregate interface method. Makes the query terms iterable.
     *
     * @return ArrayIterator Array iterator object.
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->_qts);
    }

    /**
     * Countable interface method.
     *
     * @return integer Number of query terms.
     */
    public function count()
    {
        return count($this->_qts);
    }
}
