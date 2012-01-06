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
	 * @var string User-facing query field name.
	 */
	private $_user_field;
	
	/**
	 * @var array Mapping between user-facing query fields and the actual Sphinx fields.
	 */
	private $_sph_field;
	
	/**
	 * Creates a single query term used internally by the MultiFieldQuery object.
	 * A raw query term is of the form "@genre drama" where "genre" is the field to filter by
	 * and "drama" is the filtering term.
	 * 
	 * Query terms may be created from an FSphinx result array or a string representation.
	 * 
	 * @param string $status Whether a query term is active. '+' for on, '-' for off.
	 * @param string $field Field to filter by.
	 * @param string $term Term to filter by.
	 * @param array $user_sph_map Mapping between user fields and actual Sphinx fields.
	 */
	public function __construct ( $status, $field, $term, array $user_sph_map=array () )
	{
		$field = strtolower ( trim ( $field ) );
		
		$this->_status = $status;
		$this->_term = trim ( $term );
		$this->_user_field = $field;
		
		// If Sphinx field mapping not found, use the field name per se.
		$this->_sph_field = isset ( $user_sph_map[$field] ) ?
			strtolower ( $user_sph_map[$field] ) : $field;
	}
	
	/**
	 * Create a query term object by parsing a result match from FSphinx.
	 * 
	 * @param array $match Result match to be parsed.
	 * @param array $user_sph_map Mapping between user fields and actual Sphinx fields.
	 * @return QueryTerm|null Parsed query term, or null if invalid.
	 */
	public static function FromMatchObject ( array $match, array $user_sph_map=array () )
	{
		if ( !$match )
			return null;
		
		list ( $status, $field, $term, $all ) = $match;
		
		if ( $all && !( trim ( $all ) ) )
			return null;
			
		if ( $all )
		{
			$term = $all;
			$field = '*';
		}
		
		if ( $status != '-' )
			$status = '';
			
		if ( trim ( $field ) )
			return new QueryTerm ( $status, $field, $term, $user_sph_map );
			
		return null;
	}
	
	/**
	 * Create a query term object by parsing a raw string.
	 * 
	 * @param string $str String to be parsed.
	 * @param array $user_sph_map Mapping between user fields and actual Sphinx fields.
	 * @return QueryTerm|null Parsed query term, or null if invalid.
	 */
	public static function FromString ( $str, array $user_sph_map=array () )
	{
		if ( @preg_match ( MultiFieldQuery::QUERY_PATTERN, $str, $match ) !== false )
		{
			return self::FromMatchObject ( array (
				isset ( $match['status'] ) ? $match['status'] : '',
				$match['field'],
				$match['term'],
				isset ( $match['all'] ) ? $match['all'] : ''
			), $user_sph_map );
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
	public static function cmp ( QueryTerm $a, QueryTerm $b )
	{
		if ( $a->GetUserField () > $b->GetUserField () )
			return 1;
		if ( $a->GetUserField () < $b->GetUserField () )
			return -1;
		if ( strtolower ( $a->GetTerm () ) > strtolower ( $b->GetTerm () ) )
			return 1;
		if ( strtolower ( $a->GetTerm () ) < strtolower ( $b->GetTerm () ) )
			return -1;
		
		return 0;
	}
	
	/**
	 * Return the QueryTerm representation in string format.
	 * 
	 * @return string QueryTerm string representation.
	 */
	public function __toString ()
	{
		return sprintf ( '(@%s%s %s)', $this->_status, $this->_user_field, $this->_term );
	}
	
	/**
	 * Return a hash of the query term attributes.
	 * 
	 * @return string Hashed query term attributes.
	 */
	public function ToHash ()
	{
		return md5 ( $this->_user_field . strtolower ( $this->_term ) );
	}
	
	/**
	 * Return the query term string to be sent to Sphinx.
	 * 
	 * @return string Query term string for Sphinx.
	 */
	public function ToSphinx ()
	{
		$term = @preg_replace ( '#(\w)(-)(\w)#u', '\\1 \\3', $this->_term );
		if ( $this->_status == '' || $this->_status == '+' )
		{
			if ( strpos ( $term, ' ' ) !== false )
				return sprintf ( '(@%s "%s")', $this->_sph_field, $term );
			else
				return sprintf ( '(@%s %s)', $this->_sph_field, $term );
		}
		return '';
	}
	
	/**
	 * Return the canonical query term representation in string format.
	 * 
	 * @return string Canonical query term string representation.
	 */
	public function ToCanonical ()
	{
		return strtolower ( trim ( $this->ToSphinx () ) );
	}
	
	/**
	 * Set the current status of the query term.
	 * 
	 * @param string $status Query term status, '-' for off.
	 */
	public function SetStatus ( $status )
	{
		$this->_status = $status;
	}
	
	/**
	 * Return the current status of the query term.
	 * 
	 * @return string Query term status, '-' for off.
	 */
	public function GetStatus ()
	{
		return $this->_status;
	}
	
	/**
	 * Return the user-facing field name of the query term.
	 * 
	 * @return string Query field name.
	 */
	public function GetUserField ()
	{
		return $this->_user_field;
	}
	
	/**
	 * Return the value of the query term.
	 * 
	 * @return string Query term value.
	 */
	public function GetTerm ()
	{
		return $this->_term;
	}
	
	/**
	 * Check if the query term matches the specified field name.
	 * 
	 * @param string $field Query field name.
	 * @return boolean TRUE if the query term matches the field.
	 */
	public function HasField ( $field )
	{
		return ( $field == $this->_user_field || $field == $this->_sph_field );
	}
}
