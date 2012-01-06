<?php

namespace FSphinx;

/**
 * @brief       A class that provides multi-field query object functionality for Sphinx.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */
class MultiFieldQuery implements \Iterator, \Countable
{
	/** Whether to use full scan mode for empty queries. */
	const ALLOW_EMPTY = false;
	
	/** Regex for extracting query terms. */
	const QUERY_PATTERN = 
		'#@(?P<status>[+-]?)(?P<field>\w+|\*)\s+(?P<term>[^@()]+)|(?P<all>[^@()]+)#iux';
	
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
	 */
	public function __construct ( $user_sph_map=array () )
	{
		$this->_user_sph_map = array_change_key_case (
			array_map ( 'strtolower', $user_sph_map ),
			CASE_LOWER
		);
		$this->_qts = array ();
		$this->_raw = '';
	}
	
	/**
	 * Parse a query string and store all resulting QueryTerms in an internal array.
	 * Every query passed to a Facet or FSphinx client must be parsed in this way.
	 * 
	 * @param string $query Query string to be parsed.
	 * @return MultiFieldQuery Parsed query object.
	 */
	public function Parse ( $query )
	{
		$this->_raw = $query;
		$this->_qts = array ();
		if ( @preg_match_all ( self::QUERY_PATTERN, $query, $matches ) !== false )
		{
			foreach ( $matches['field'] as $index => $field ) {
				if ( $query_term = QueryTerm::FromMatchObject ( array ( 
					$matches['status'][$index], 
					$field, 
					$matches['term'][$index], 
					$matches['all'][$index] 
				), $this->_user_sph_map ) )
					$this->_AddQueryTerm ( $query_term );
			}
		}
		return $this;
	}
	
	/**
	 * Used internally to add a QueryTerm object to the list of query terms.
	 * 
	 * @param QueryTerm $query_term QueryTerm object to be added.
	 */
	protected function _AddQueryTerm ( QueryTerm $query_term )
	{
		$this->_qts[$query_term->ToHash ()] = $query_term;
	}
	
	/**
	 * Return a QueryTerm object from the list.
	 * 
	 * @param QueryTerm|string $query_term QueryTerm object or term string.
	 * @return QueryTerm|null Matching QueryTerm object, or null if none found.
	 */
	public function GetQueryTerm ( $query_term )
	{
		if ( is_string ( $query_term ) )
			$query_term = QueryTerm::FromString ( $query_term, $this->_user_sph_map );
		
		if ( $query_term instanceof QueryTerm )
		{
			$hash = $query_term->ToHash ();
			if ( isset ( $this->_qts[$hash] ) )
				return $this->_qts[$hash];
		}
		
		return null;
	}
	
	/**
	 * Determine if the QueryTerm object exists in the list.
	 * 
	 * @param QueryTerm|string $query_term QueryTerm object or term string.
	 * @return boolean Whether the QueryTerm object exists.
	 */
	public function HasQueryTerm ( $query_term )
	{
		return ( $this->GetQueryTerm ( $query_term ) !== null );
	}
	
	/**
	 * Toggle a query term on or off. The target state can be specified.
	 * 
	 * @param QueryTerm|string $query_term QueryTerm object or term string.
	 * @param state string '-' for off, '+' for on.
	 */
	public function Toggle ( $query_term, $state=null )
	{
		if ( $qt = $this->GetQueryTerm ( $query_term ) )
		{
			if ( !$state )
				$qt->SetStatus ( $qt->GetStatus () ? '' : '-' );
			else
				$qt->SetStatus ( $state == '-' ? '-' : '' );
		}
	}
	
	/**
	 * Toggle a query term on.
	 * 
	 * @param QueryTerm|string $query_term QueryTerm object or term string.
	 */
	public function ToggleOn ( $query_term )
	{
		self::Toggle ( $query_term, '+' );
	}
	
	/**
	 * Toggle a query term off.
	 * 
	 * @param QueryTerm|string $query_term QueryTerm object or term string.
	 */
	public function ToggleOff ( $query_term )
	{
		self::Toggle ( $query_term, '-' );
	}
	
	/**
	 * Count the number of query terms that match the specified field.
	 * 
	 * @param string $field Query field to look for.
	 */
	public function CountField ( $field )
	{
		$sum = 0;
		$field = strtolower ( $field );
		foreach ( $this->_qts as $qt )
		{
			if ( $qt->HasField ( $field ) )
				$sum++;
		}
		return $sum;
	}
	
	/**
	 * Return the combined query term representation in string format.
	 * 
	 * @return string Combined query term string representation.
	 */
	public function __toString ()
	{
		return implode ( ' ', $this->_qts );
	}
	
	/**
	 * Return the combined query term string to be sent to Sphinx.
	 * 
	 * @return string Combined query term string for Sphinx.
	 */
	public function ToSphinx ()
	{
		$qts = array ();
		
		foreach ( $this->_qts as $qt )
		{
			if ( $term = $qt->ToSphinx () )
				$qts[] = $term;
		}
		
		if ( $qts )
			$sph = implode ( ' ', $qts );
			
		if ( !$sph && !self::ALLOW_EMPTY )
			$sph = ' ';
			
		return $sph;
	}
	
	/**
	 * Return the canonical combined query term representation in string format.
	 * By "canonical", all query terms are sorted in alphabetical order.
	 * 
	 * @return string Canonical combined query term string representation.
	 */
	public function ToCanonical ()
	{
		$_qts = array ();
		$qts = $this->_qts;
		usort ( $qts, array ( '\FSphinx\QueryTerm', 'cmp' ) );
		
		foreach ( $qts as $qt )
			$_qts[] = $qt->ToCanonical ();
		
		return trim ( implode ( ' ', $_qts ) );
	}
	
	/**
	 * Iterator interface method. Return the pointer to the first query term.
	 */
	public function rewind ()
	{
		return reset ( $this->_qts );
	}
	
	/**
	 * Iterator interface method. Return the current query term.
	 * 
	 * @return QueryTerm|null Current query term, or null if not found.
	 */
	public function current ()
	{
		return current ( $this->_qts );
	}
	
	/**
	 * Iterator interface method. Return the index of the current query term.
	 * 
	 * @return integer Index of query term.
	 */
	public function key ()
	{
		return key ( $this->_qts );
	}
	
	/**
	 * Iterator interface method. Move forward to the next query term.
	 * 
	 * @return QueryTerm|null Next query term, or null if not found.
	 */
	public function next ()
	{
		return next ( $this->_qts );
	}
	
	/**
	 * Iterator interface method. Check if there is a current query term.
	 * 
	 * @return boolean Whether the current query term exists.
	 */
	public function valid ()
	{
		return ( key ( $this->_qts ) !== null );
	}
	
	/**
	 * Countable interface method.
	 * 
	 * @return integer Number of query terms.
	 */
	public function count ()
	{
		return count ( $this->_qts );
	}
}
