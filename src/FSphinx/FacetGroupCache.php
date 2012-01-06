<?php

namespace FSphinx;

/**
 * @brief       A class for storing computed Facet values. Requires APC.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */
class FacetGroupCache
{
	/** Prefix for sticky cache keys. */
	const CACHE_STICKY = '*';
	
	/** Namespace prefix for FSphinx. */
	const CACHE_PREFIX = '_FS_';
	
	/**
	 * Return the cached Facet values for a given Sphinx query.
	 * 
	 * @param MultiFieldQuery $query Sphinx query as a MultiFieldQuery object.
	 * @return array|false Unserialized result array, or false if not found.
	 */
	public function GetFacets ( MultiFieldQuery $query )
	{
		if ( !extension_loaded ('apc') || ini_get ('apc.enabled') != '1' )
			return false;
		
		$key = $this->GetKey ( $query->ToCanonical () );
		$sticky_key = $this->GetKey ( $query->ToCanonical (), true );
		
		// sticky keys take precedence
		$result = apc_fetch ( $sticky_key );
		if ( $result === false )
			$result = apc_fetch ( $key );
		if ( $result !== false )
			$result = unserialize ( $result );
		
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
	 */
	public function SetFacets ( MultiFieldQuery $query, $facets, $replace=false, $sticky=false )
	{
		if ( !extension_loaded ('apc') || ini_get ('apc.enabled') != '1' )
			return false;
		
		$key = $this->GetKey ( $query->ToCanonical (), $sticky );
		$results = array ();
		
		foreach ( $facets as $facet )
			$results[] = $facet->GetResults ();
			
		$results = serialize ( $results );
		
		if ( $replace )
			apc_store ( $key, $results );
		else
			apc_add ( $key, $results );
	}
	
	/**
	 * Clear the cache.
	 * 
	 * @param boolean $clear_sticky Whether to clear preloaded Facet values as well.
	 */
	public function Clear ( $clear_sticky=false )
	{
		if ( !extension_loaded ('apc') || ini_get ('apc.enabled') != '1' )
			return false;
		
		// only match keys in FSphinx namespace
		$entries = new \APCIterator (
			'user',
			$this->GetKey ( null, $clear_sticky, true ),
			APC_ITER_KEY
		);
		
		foreach ( $entries as $key => $data )
			apc_delete ( $key );
	}
	
	/**
	 * Generate a cache key.
	 * 
	 * @param string $string Candidate key.
	 * @param boolean $sticky Whether to generate a sticky key.
	 * @param boolean $regex Whether to return the regex for the key prefix instead.
	 */
	public function GetKey ( $string=null, $sticky=false, $regex=false )
	{
		// APPLICATION_ENV is prepended to separate test data
		if ( $regex )
		{
			return '/^' . ( $sticky ? preg_quote(self::CACHE_STICKY) . '?' : '' ) . 
				$_ENV['APPLICATION_ENV'] . self::CACHE_PREFIX . '/';
		}
		else
		{
			return ( $sticky ? self::CACHE_STICKY : '' ) . 
				$_ENV['APPLICATION_ENV'] . self::CACHE_PREFIX . md5 ( $string );
		}
	}
}
