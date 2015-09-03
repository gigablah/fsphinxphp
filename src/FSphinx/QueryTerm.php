<?php

namespace FSphinx;

/**
 * @brief       A class that represents a single query term for a Sphinx query.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */
class QueryTerm
{
    /**
     * @var string Specifies whether the term is active. Inactive terms are not passed to Sphinx.
     */
    private $_status;

    /**
     * @var string Query term value.
     */
    private $_term;

    /**
     * @var string User-facing query term value.
     */
    private $_user_term;

    /**
     * @var string User-facing query field name.
     */
    private $_user_field;

    /**
     * @var array Mapping between user-facing query field and the actual Sphinx field.
     */
    private $_sph_field;

    /**
     * @var array Mapping between user-facing query field and the multi-value Sphinx attribute.
     */
    private $_attr_field;

    /**
     * Creates a single query term used internally by the MultiFieldQuery object.
     * A raw query term is of the form "@genre drama" where "genre" is the field to filter by
     * and "drama" is the filtering term. Numerical terms may also be used if the FSphinx client
     * is configured to filter by multi-valued attribute IDs.
     *
     * Query terms may be created from an FSphinx result array or a string representation.
     *
     * @param string $status Whether a query term is active. '+' for on, '-' for off.
     * @param string $field Field to filter by.
     * @param string $term Term to filter by.
     * @param array $user_sph_map Mapping between user fields and actual Sphinx fields.
     * @param array $user_attr_map Mapping between user fields and Sphinx multi-value attributes.
     */
    public function __construct($status, $field, $term, array $user_sph_map = array(), array $user_attr_map = array())
    {
        $field = strtolower(trim($field));

        $this->_status = $status;
        $this->_term = trim($term);
        $this->_user_term = $this->_term;
        $this->_user_field = $field;

        // If Sphinx field mapping not found, use default values.
        $this->_sph_field = isset($user_sph_map[$field]) ? strtolower($user_sph_map[$field]) : $field;

        $this->_attr_field = isset($user_attr_map[$field]) ? strtolower($user_attr_map[$field]) : $field . '_attr';
    }

    /**
     * Create a query term object by parsing a result match from FSphinx.
     *
     * @param array $match Result match to be parsed.
     * @param array $user_sph_map Mapping between user fields and actual Sphinx fields.
     * @param array $user_attr_map Mapping between user fields and Sphinx multi-value attributes.
     * @return QueryTerm|null Parsed query term, or null if invalid.
     */
    public static function fromMatchObject(array $match, array $user_sph_map = array(), array $user_attr_map = array())
    {
        if (!$match) {
            return null;
        }

        list($status, $field, $term, $all) = $match;

        if ($all && !(trim($all))) {
            return null;
        }
        if ($all) {
            $term = $all;
            $field = '*';
        }
        if (!$term = trim($term, '-')) {
            return null;
        }
        if ($status != '-') {
            $status = '';
        }
        if ($field = trim($field)) {
            return new QueryTerm($status, $field, $term, $user_sph_map, $user_attr_map);
        }

        return null;
    }

    /**
     * Create a query term object by parsing a raw string.
     *
     * @param string $str String to be parsed.
     * @param array $user_sph_map Mapping between user fields and actual Sphinx fields.
     * @param array $user_attr_map Mapping between user fields and Sphinx multi-value attributes.
     * @return QueryTerm|null Parsed query term, or null if invalid.
     */
    public static function fromString($str, array $user_sph_map = array(), array $user_attr_map = array())
    {
        if (trim($str) && preg_match(MultiFieldQuery::QUERY_PATTERN, $str, $match) !== false) {
            return self::fromMatchObject(
                array(
                    isset($match['status']) ? $match['status'] : '',
                    isset($match['field']) ? $match['field'] : '',
                    isset($match['term']) ? $match['term'] : '',
                    isset($match['all']) ? $match['all'] : ''
                ),
                $user_sph_map,
                $user_attr_map
            );
        }

        return null;
    }

    /**
     * Comparison function for query terms. Field names are compared first,
     * then field terms are compared case insensitively.
     *
     * @param QueryTerm $a Query term A.
     * @param QueryTerm $b Query term B.
     * @return integer 1, -1 or 0 depending on comparison result.
     */
    public static function cmp(QueryTerm $a, QueryTerm $b)
    {
        if ($a->getUserField() > $b->getUserField()) {
            return 1;
        }
        if ($a->getUserField() < $b->getUserField()) {
            return -1;
        }
        if (strtolower($a->getTerm()) > strtolower($b->getTerm())) {
            return 1;
        }
        if (strtolower($a->getTerm()) < strtolower($b->getTerm())) {
            return -1;
        }

        return 0;
    }

    /**
     * Return the QueryTerm representation in string format.
     *
     * @return string QueryTerm string representation.
     */
    public function __toString()
    {
        return sprintf(
            '(@%s%s %s)',
            $this->getStatus(),
            $this->getUserField(),
            $this->getUserTerm()
        );
    }

    /**
     * Return a hash of the query term attributes.
     *
     * @return string Hashed query term attributes.
     */
    public function toHash()
    {
        return md5($this->getUserField() . strtolower($this->getTerm()));
    }

    /**
     * Return the query term string to be sent to Sphinx.
     *
     * @param boolean $exclude_numeric If TRUE, returns only if non-numeric term.
     * @return string Query term string for Sphinx.
     */
    public function toSphinx($exclude_numeric = false)
    {
        if ($this->getStatus() == '-') {
            return '';
        }
        if ($exclude_numeric && is_numeric($this->getTerm())) {
            return '';
        }

        $term = preg_replace('#(\w)(-)(\w)#u', '\\1 \\3', $this->getTerm());
        $term = str_replace('"', '', $term);
        if (strpos($term, ' ') !== false) {
            $term = '"' . $term . '"';
        }

        return sprintf('(@%s %s)', $this->getSphinxField(), $term);
    }

    /**
     * Return the canonical query term representation in string format.
     *
     * @return string Canonical query term string representation.
     */
    public function toCanonical()
    {
        return strtolower(trim($this->toSphinx()));
    }

    /**
     * Set the current status of the query term.
     *
     * @param string $status Query term status, '-' for off.
     */
    public function setStatus($status)
    {
        $this->_status = $status;
    }

    /**
     * Return the current status of the query term.
     *
     * @return string Query term status, '-' for off.
     */
    public function getStatus()
    {
        return $this->_status;
    }

    /**
     * Return the user-facing field name of the query term.
     *
     * @return string Query field name.
     */
    public function getUserField()
    {
        return $this->_user_field;
    }

    /**
     * Return the Sphinx field name of the query term.
     *
     * @return string Sphinx field name.
     */
    public function getSphinxField()
    {
        return $this->_sph_field;
    }

    /**
     * Return the Sphinx multi-value attribute name for the query term.
     *
     * @return string Sphinx multi-value attribute.
     */
    public function getAttribute()
    {
        return $this->_attr_field;
    }

    /**
     * Return the value of the query term.
     *
     * @return string Query term value.
     */
    public function getTerm()
    {
        return $this->_term;
    }

    /**
     * Return the value of the user-facing query term.
     *
     * @return string User-facing query term value.
     */
    public function getUserTerm()
    {
        return $this->_user_term;
    }

    /**
     * Set the user-facing query term value (usually matching a string to the corresponding ID).
     *
     * @param string $term User-facing query term value.
     */
    public function setUserTerm($term)
    {
        $this->_user_term = $term;
    }

    /**
     * Check if the query term matches the specified field name.
     *
     * @param string $field Query field name.
     * @return boolean TRUE if the query term matches the field.
     */
    public function hasField($field)
    {
        return ($field == $this->_user_field || $field == $this->_sph_field);
    }
}
