<?php

namespace FSphinx;

/**
 * @brief       A class for performing computation and caching operations for a set of Facets.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */
class FacetGroup implements \Iterator, \Countable
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
	 * @var DataFetchInterface (Optional) Data source used to fetch string terms.
	 */
	private $_datafetch;
	
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
	 * @var integer Internal pointer for implementing Iterator behaviour.
	 */
	private $_pointer;
	
	/**
	 * Creates a collection of Facets which adds enhancements like batch queries and caching.
	 * With batch querying, multiple Facets can be computed with one single call to Sphinx.
	 * Facets may be preloaded or cached to improve performance.
	 * 
	 * @param mixed $objects Facet objects to attach. Also handles Sphinx client and data source.
	 */
	public function __construct ( $objects )
	{
		if ( func_num_args () == 1 )
		{
			if ( !is_array ( $objects ) )
				$objects = array ( $objects );
		}
		else
			$objects = func_get_args ();
		
		$this->_sphinx = null;
		$this->_datafetch = null;
		$this->_cache = null;
		$this->_time = 0;
		$this->_pointer = 0;
		$this->_facets = array ();
		
		foreach ( $objects as $object )
		{
			if ( is_object ( $object ) && $object instanceof Facet )
				$this->AttachFacet ( $object );
			if ( is_object ( $object ) && $object instanceof \SphinxClient )
				$this->AttachSphinxClient ( $object );
			if ( is_object ( $object ) && $object instanceof DataFetchInterface )
				$this->AttachDataFetch ( $object );
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
	public function AttachFacet ( Facet $facet )
	{
		$this->_facets[] = $facet;
	}
	
	/**
	 * Attach an FSphinx client to perform computations for all attached Facets.
	 * 
	 * @param FSphinxClient $sphinx FSphinx client object.
	 */
	public function AttachSphinxClient ( \SphinxClient $sphinx )
	{
		$this->_sphinx = $sphinx;
	}
	
	/**
	 * Attach a data source implementing DataFetchInterface. This will be passed to Facets
	 * without defined data sources to perform term mapping.
	 * 
	 * @param DataFetchInterface $datafetch Data source object.
	 */
	public function AttachDataFetch ( DataFetchInterface $datafetch )
	{
		$this->_datafetch = $datafetch;
	}
	
	/**
	 * Set the cache for storing computed Facet values.
	 */
	public function SetCache ()
	{
		$this->_cache = new FacetGroupCache ( $this->_facets );
	}
	
	/**
	 * Turns preloading on or off.
	 * 
	 * @param boolean $preloading Whether to preload Facet values.
	 */
	public function SetPreloading ( $preloading=true )
	{
		$this->_preloading = $preloading ? true : false;
	}
	
	/**
	 * Turns caching on or off.
	 * 
	 * @param boolean $caching Whether to cache computed Facet values.
	 */
	public function SetCaching ( $caching=true )
	{
		$this->_caching = $caching ? true : false;
	}
	
	/**
	 * Compute the values for all Facets in this collection for a given Sphinx query.
	 * Note that Facet::Compute() is not called directly.
	 * 
	 * @param MultiFieldQuery|string $query Sphinx query to be computed.
	 * @param boolean $caching Whether to enable caching.
	 * @return array|null Computed results from Sphinx, or null if none returned.
	 */
	public function Compute ( $query, $caching=null )
	{
		// caching parameter always overrides internal setting
		if ( $caching !== null )
			$preloading = false;
		else
		{
			$caching = $this->_caching;
			$preloading = $this->_preloading;
		}
		
		$results = null;
		if ( !$caching && !$preloading )
		{
			$query = $this->_Prepare ( $query );
			if ( $results = $this->_RunQueries () )
			{
				$this->_Reset ();
				$this->_SetValues ( $query, $results, $this->_datafetch );
				$this->_OrderValues ();
			}
		}
		else
			$results = $this->_ComputeCache ( $query, $caching );
			
		return $results;
	}
	
	/**
	 * Used internally to prepare all Facets for computation against a given Sphinx query.
	 * 
	 * @param MultiFieldQuery|string $query Sphinx query to be computed.
	 * @return MultiFieldQuery Processed Sphinx query as a MultiFieldQuery object.
	 * @see Facet::_Prepare()
	 */
	protected function _Prepare ( $query )
	{
		if ( !( $query instanceof MultiFieldQuery ) )
			$query = $this->_sphinx->Parse ( $query );
		
		foreach ( $this->_facets as $facet )
			$facet->_Prepare ( $query, $this->_sphinx );
		
		return $query;
	}
	
	/**
	 * Used internally to run all Facet computations as a single batch query.
	 */
	protected function _RunQueries ()
	{
		$arrayresult = $this->_sphinx->_arrayresult;
		$this->_sphinx->SetArrayResult ( true );
		$results = $this->_sphinx->RunQueries ();
		$this->_sphinx->SetArrayResult ( $arrayresult );
		return $results;
	}
	
	/**
	 * Used to reset values for all Facets in this collection.
	 */
	public function _Reset ()
	{
		foreach ( $this->_facets as $index => $facet )
			$facet->_Reset ();
		
		$this->_time = 0;
	}
	
	/**
	 * Used internally to set the computed results, metadata and terms for all Facets.
	 * 
	 * @param MultiFieldQuery $query Sphinx query as a MultiFieldQuery object.
	 * @param array $results Computed results from Sphinx.
	 * @param DataFetchInterface $datafetch Data source object.
	 * @see Facet::_SetValues()
	 * @see Facet::_OrderValues()
	 */
	protected function _SetValues ( MultiFieldQuery $query, array $results, DataFetchInterface $datafetch=null )
	{
		foreach ( $this->_facets as $index => $facet )
		{
			$result = $results[$index];
			
			if ( is_array ( $result ) )
				$facet->_SetValues ( $query, $result, $datafetch );
			
			$this->_time += $facet->GetTime ();
		}
	}
	
	/**
	 * Perform custom sorting of Sphinx results for each Facet.
	 */
	protected function _OrderValues ()
	{
		foreach ( $this->_facets as $index => $facet )
		{
			if ( count ( $facet ) )
				$facet->_OrderValues ();
		}
	}
	
	/**
	 * Compute the values for all Facets for a given query and store the results in the cache.
	 * 
	 * @param MultiFieldQuery|string $query Sphinx query to be computed.
	 */
	public function Preload ( $query )
	{
		if ( !$this->_cache )
			$this->SetCache ();
		
		if ( !( $query instanceof MultiFieldQuery ) )
			$query = $this->_sphinx->Parse ( $query );
		
		$this->Compute ( $query, false );
		$this->_cache->SetFacets ( $query, $this->_facets, true, true );
	}
	
	/**
	 * Attempt to get the computation results from the cache, otherwise, compute and store.
	 * 
	 * @param MultiFieldQuery|string $query Sphinx query to be computed.
	 * @param boolean $caching Whether to enable caching.
	 * @return array|null Computed results from Sphinx, or null if none returned.
	 */
	protected function _ComputeCache ( $query, $caching=true )
	{
		if ( !$this->_cache )
			$this->SetCache ();
		
		if ( !( $query instanceof MultiFieldQuery ) )
			$query = $this->_sphinx->Parse ( $query );
		
		// attempt to get results from cache
		$results = $this->_cache->GetFacets ( $query );
		
		if ( $results )
		{
			foreach ( $this->_facets as $facet )
				$facet->SetResults ( array_shift ( $results ) );
			
			// explicitly mark a successful cache hit
			$this->_time = -1;
		}
		else
		{
			// cache miss, compute manually
			$results = $this->Compute ( $query, false );
		}
		
		// save to cache if caching is enabled
		if ( $caching )
			$this->_cache->SetFacets ( $query, $this->_facets );
			
		return $results;
	}
	
	/**
	 * Return the aggregated computation time for all Facets.
	 * 
	 * @return double Total time taken for Facet computation.
	 */
	public function GetTime ()
	{
		return $this->_time;
	}
	
	/**
	 * Return the FacetGroup representation in string format.
	 * 
	 * @return string FacetGroup string representation.
	 */
	public function __toString ()
	{
		$s = sprintf (
			'facets: (%s facets in %s sec.)' . PHP_EOL,
			count ( $this->_facets ),
			$this->_time
		);
		
		foreach ( $this->_facets as $index => $facet )
			$s .= sprintf ( '%s. %s' . PHP_EOL, $index + 1, $facet );
		
		return $s;
	}
	
	/**
	 * Iterator interface method. Return the pointer to the first Facet object.
	 */
	public function rewind ()
	{
		return reset ( $this->_facets );
	}
	
	/**
	 * Iterator interface method. Return the current Facet.
	 * 
	 * @return Facet|null Current Facet, or null if not found.
	 */
	public function current ()
	{
		return current ( $this->_facets );
	}
	
	/**
	 * Iterator interface method. Return the index of the current Facet.
	 * 
	 * @return integer Index of current Facet.
	 */
	public function key ()
	{
		return key ( $this->_facets );
	}
	
	/**
	 * Iterator interface method. Move forward to the next Facet.
	 * 
	 * @return Facet|null Next Facet, or null if not found.
	 */
	public function next ()
	{
		return next ( $this->_facets );
	}
	
	/**
	 * Iterator interface method. Check if there is a current Facet.
	 * 
	 * @return boolean Whether the current Facet exists.
	 */
	public function valid ()
	{
		return ( key ( $this->_facets ) !== null );
	}
	
	/**
	 * Countable interface method.
	 * 
	 * @return integer Number of Facet objects.
	 */
	public function count ()
	{
		return count ( $this->_facets );
	}
}
