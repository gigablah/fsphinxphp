<?php

namespace FSphinx;

/**
 * @brief       A class for adding facet computation to Sphinx.
 *              Can be defined individually or as part of a FacetGroup.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */
class Facet implements \IteratorAggregate, \Countable, DataFetchInterface
{
	/** 
	 * @var string Facet identifier, used as a basis for some default values.
	 */
	private $_name;
	
	/**
	 * @var FSphinxClient Sphinx client used to compute Facet values.
	 */
	private $_sphinx;
	
	/**
	 * @var DataFetchInterface (Optional) Data source used to fetch string terms.
	 */
	private $_datafetch;
	
	/**
	 * @var string Name of corresponding Sphinx index attribute. Defaults to "facet_name_attr".
	 */
	private $_attr;
	
	/**
	 * @var integer Sphinx grouping function identifier. Defaults to SPH_GROUPBY_ATTR (4).
	 */
	private $_func;
	
	/**
	 * @var string Sphinx extended group sorting clause.
	 */
	private $_group_sort;
	
	/**
	 * @var string Comma delimited list of Sphinx attributes to fetch.
	 */
	private $_set_select;
	
	/**
	 * @var string Name of corresponding Sphinx search field.
	 */
	private $_sph_field;
	
	/**
	 * @var string (Optional) Limit Sphinx search to this index.
	 */
	private $_default_index;
	
	/**
	 * @var Closure Anonymous function that performs custom sorting of results.
	 */
	private $_order_by;
	
	/**
	 * @var boolean TRUE for descending sort order, FALSE for ascending.
	 */
	private $_order_by_desc;
	
	/**
	 * @var integer Limit the maximum number of results (defaults to 15).
	 */
	private $_max_num_values;
	
	/**
	 * @var integer Maximum number of matches to keep in memory.
	 */
	private $_max_matches;
	
	/**
	 * @var integer Threshold amount of matches to stop computing at.
	 */
	private $_cutoff;
	
	/**
	 * @var boolean If TRUE, increases the max number of results by the number of selected values.
	 */
	private $_augment;
	
	/**
	 * @var array (Optional) Data source configuration array.
	 */
	private $_source;
	
	/**
	 * @var array Sphinx result array.
	 */
	private $_results;
	
	/**
	 * Creates a new facet of a given facet_name.
	 * 
	 * The facet must have a corresponding attribute declared in Sphinx conf.
	 * The attribute may either be single or multi-valued and its name defaults
	 * to facet_name_attr.
	 * 
	 * Suppose we have a database of IMDB movies where each title is identified
	 * by imdb_id. There is a lookup table "director_terms" consisting of
	 * director names, each with an imdb_director_id. A relationship table,
	 * "directors", links imdb_id to imdb_director_id. To build a director
	 * facet, the attribute could be defined like this:
	 * 
	 * <code>
	 * sql_attr_multi = uint director_attr from query;
	 *                  select imdb_id, imdb_director_id from directors
	 * </code>
	 * 
	 * Additionally there needs to be a corresponding data source which maps ids
	 * to terms. This is because Sphinx only supports integers for multi-valued
	 * attributes. If the facet values are integers (e.g. year or rating) then
	 * no data source needs to be defined.
	 * 
	 * Data sources are required to implement DataFetchInterface::FetchTerms()
	 * which returns an array of id-term pairs when supplied with a list of ids.
	 * Both FSphinxClient and Facet can be used as data sources. For the former,
	 * a separate index must be defined that serves as a lookup dictionary. The
	 * attributes can be defined as follows:
	 * 
	 * <code>
	 * sql_query = select imdb_director_id, director, \
	 *             imdb_director_id as director_id_attr, \
	 *             director as director_term_attr from director_terms
	 * sql_attr_uint = director_id_attr
	 * sql_attr_string = director_term_attr
	 * </code>
	 * 
	 * For a Facet to use itself as a data source, the id-term mapping must be
	 * returned in the result array in serialized form. This can be achieved by
	 * concatenating the ids and terms in a separate column in the main query:
	 * 
	 * <code>
	 * (SELECT GROUP_CONCAT(DISTINCT CONCAT(imdb_director_id,',',director_name))
	 * FROM directors d WHERE d.imdb_id = title.imdb_id) AS director_terms_attr
	 * 
	 * sql_attr_string = director_terms_attr
	 * </code>
	 * 
	 * When a Facet is attached to itself as a data source, the string attribute
	 * as defined in the "source" parameter in $options will be supplied to
	 * SphinxClient::SetSelect() so that it is included in the result array to
	 * be extracted, unserialized and merged. Retrieving facet terms in this way
	 * removes the need to hit the database or Sphinx again, although it
	 * increases index size and communication overhead.
	 * 
	 * @param string $name Facet name.
	 * @param array $options Option values to override defaults.
	 * @param DataFetchInterface $datafetch Data source used to fetch Facet terms.
	 */
	public function __construct ( $name, $options=array (), DataFetchInterface $datafetch=null )
	{
		assert ( !empty ( $name ) );
		$this->_name = $name;
		
		$this->SetOptions ( $options );
		if ( $datafetch )
		{
			// $_source already handled by SetOptions
			$this->AttachDataFetch ( $datafetch, null );
		}
		
		// initialize results array
		$this->_results = array (
			'time' => 0,
			'total_found' => 0,
			'error' => '',
			'warning' => '',
			'matches' => array ()
		);
	}
	
	/**
	 * Initialize the Facet settings. All settings have default values.
	 * 
	 * @param array $options Array of options to override.
	 */
	public function SetOptions ( array $options=array () )
	{
		// Sphinx parameters
		$this->_attr = isset ( $options['attr'] ) ?
			$options['attr'] : $this->_name . '_attr';
		
		$this->_func = isset ( $options['func'] ) ?
			$options['func'] : SPH_GROUPBY_ATTR;
		
		$this->_group_sort = isset ( $options['group_sort'] ) ?
			$options['group_sort'] : '@count desc';
		
		$this->_set_select = isset ( $options['set_select'] ) ?
			'@groupby, @count, ' . $options['set_select'] : '@groupby, @count';
		
		$this->_sph_field = isset ( $options['sph_field'] ) ?
			$options['sph_field'] : $this->_name;
		
		$this->_default_index = isset ( $options['default_index'] ) ?
			$options['default_index'] : null;
		
		$this->_max_num_values = isset ( $options['max_num_values'] ) ?
			$options['max_num_values'] : 15;
		
		$this->_max_matches = isset ( $options['max_matches'] ) ?
			$options['max_matches'] : 1000;
		
		$this->_cutoff = isset ( $options['cutoff'] ) ?
			$options['cutoff'] : 0;
		
		// Facet parameters
		$this->_order_by = isset ( $options['order_by'] ) ?
			$options['order_by'] : function ( $v ) { return $v['@count']; };
		
		$this->_order_by_desc = true;
		
		$this->_augment = isset ( $options['augment'] ) ?
			$options['augment'] : true;
		
		$this->_source = null;
		
		if ( isset ( $options['source'] ) )
			$this->SetSource ( $options['source'] );
	}
	
	/**
	 * Initialize the data source configuration.
	 * Only applicable if the Facet requires term mapping, and a data source is attached.
	 * 
	 * @param array $options Array of options to override.
	 */
	public function SetSource ( array $options=array () )
	{
		// Data fetch parameters
		$this->_source = array ();
		$this->_source['name'] = isset ( $options['name'] ) ?
			$options['name'] : $this->_name . '_terms';
		
		$this->_source['id'] = isset ( $options['id'] ) ?
			$options['id'] : $this->_name . '_id_attr';
		
		$this->_source['term'] = isset ( $options['term'] ) ?
			$options['term'] : $this->_name . '_term_attr';
		
		$this->_source['delim'] = isset ( $options['delim'] ) ?
			$options['delim'] : ',';
		
		$this->_source['query'] = array_key_exists ( 'query', $options ) ?
			$options['query'] : sprintf (
				'select %s from %s where %s in ($id) order by field(%s, $id)',
				$this->_source['term'],
				$this->_source['name'],
				$this->_source['id'],
				$this->_source['id']
			);
	}
	
	/**
	 * Attach an FSphinx client to perform computations. If the Facet is part of a FacetGroup,
	 * the FSphinx client attached to the FacetGroup will be used instead.
	 * 
	 * @param FSphinxClient $sphinx FSphinx client object.
	 */
	public function AttachSphinxClient ( FSphinxClient $sphinx )
	{
		$this->_sphinx = $sphinx;
	}
	
	/**
	 * Attach a data source implementing DataFetchInterface. This source will be queried each
	 * time the Facet is computed to match the resulting IDs with string terms.
	 * 
	 * @param DataFetchInterface $datafetch Data source object.
	 * @param array $options Source configuration options.
	 */
	public function AttachDataFetch ( DataFetchInterface $datafetch, array $options=null )
	{
		$this->_datafetch = $datafetch;
		
		if ( $options )
			$this->SetSource ( $options );
		
		// if attaching a Facet to itself, add the source attribute to the Sphinx selection
		if ( $datafetch instanceof Facet && $datafetch->GetName () == $this->GetName () )
			$this->_set_select .= ', ' . $this->_source['name'];
	}
	
	/**
	 * Set grouping attribute, function and group sorting clause. The attribute must refer
	 * to the Facet attribute as defined in the Sphinx conf.
	 * 
	 * @param string $attr Facet attribute.
	 * @param integer $func Grouping function identifier.
	 * @param string $group_sort Extended group sorting clause.
	 */
	public function SetGroupBy ( $attr, $func, $group_sort='@count desc' )
	{
		$this->_attr = $attr;
		$this->_func = $func;
		$this->SetGroupSort ( $group_sort );
	}
	
	/**
	 * Set a custom group sorting function, aliased to "@groupfunc" by default.
	 * 
	 * @param string $group_func Grouping function to sort by.
	 * @param string $alias Function alias.
	 * @param string $order Sort order.
	 */
	public function SetGroupFunc ( $group_func, $alias='@groupfunc', $order='desc' )
	{
		$this->_set_select = sprintf ( '%s, %s as %s', $this->_set_select, $group_func, $alias );
		$this->SetGroupSort ( sprintf ( '%s %s', $alias, $order ) );
	}
	
	/**
	 * Set group sorting clause. Result order may still be overridden by internal sorting.
	 * 
	 * @param string $group_sort Group sorting clause (including sort order).
	 * @see SetOrderBy()
	 */
	public function SetGroupSort ( $group_sort='@count desc' )
	{
		$this->_group_sort = $group_sort;
	}
	
	/**
	 * Specify the attribute in the returned Facet values to sort by.
	 * Possible attributes include '@count', '@groupby' or '@groupfunc'.
	 * 
	 * @param string $key Result attribute to sort by.
	 * @param string $order Sort ascending or descending.
	 */
	public function SetOrderBy ( $key, $order='desc' )
	{
		$this->_order_by = function ( $v ) use ( $key ) { return $v[$key]; };
		$this->_order_by_desc = ( $order == 'desc' );
	}
	
	/**
	 * Limit the maximum number of Facet values returned.
	 * 
	 * @param integer $max_num_values Maximum number of results.
	 */
	public function SetMaxNumValues ( $max_num_values )
	{
		assert ( intval ( $max_num_values ) > 0 );
		$this->_max_num_values = intval ( $max_num_values );
	}
	
	/**
	 * Stop computation once a threshold amount of matches has been reached.
	 * 
	 * @param integer $cutoff Cutoff threshold.
	 */
	public function SetCutOff ( $cutoff )
	{
		$this->_cutoff = intval ( $cutoff );
	}
	
	/**
	 * Whether to compute additional Facet values if one or more values have been selected.
	 * 
	 * @param boolean $augment If TRUE, augment the number of Facet values returned.
	 */
	public function SetAugment ( $augment )
	{
		$this->_augment = $augment ? true : false;
	}
	
	/**
	 * Set the attribute list returned by Sphinx.
	 * 
	 * @param string $select Comma delimited list of attributes to include.
	 */
	public function SetSelect ( $select )
	{
		$this->_set_select = '@groupby, @count, ' . $select;
	}
	
	/**
	 * Declare the index to compute the Facet against. If not set, the default index declared
	 * in the attached FSphinx client will be used instead.
	 * 
	 * @param string $index Name of Sphinx index.
	 */
	public function SetDefaultIndex ( $index )
	{
		$this->_default_index = $index;
	}
	
	/**
	 * Compute the Facet values for a given Sphinx query.
	 * 
	 * @param MultiFieldQuery|string $query Sphinx query to be computed.
	 * @return array|null Computed results from Sphinx, or null if none returned.
	 */
	public function Compute ( $query )
	{
		$query = $this->_Prepare ( $query, $this->_sphinx );
		
		// switch to array result mode and execute query
		$arrayresult = $this->_sphinx->_arrayresult;
		$this->_sphinx->SetArrayResult ( true );
		$results = $this->_sphinx->RunQueries ();
		$this->_sphinx->SetArrayResult ( $arrayresult );
		
		if ( is_array ( $results ) )
		{
			$results = $results[0];
			$this->_Reset ();
			$this->_SetValues ( $query, $results, $this->_datafetch );
			$this->_OrderValues ();
			
			return $results;
		}
		return null;
	}
	
	/**
	 * Used internally to prepare the Facet for computation against a given Sphinx query.
	 * 
	 * @param MultiFieldQuery|string $query Sphinx query to be computed.
	 * @param FSphinxClient $sphinx FSphinx client.
	 * @return MultiFieldQuery Processed Sphinx query as a MultiFieldQuery object.
	 */
	public function _Prepare ( $query, FSphinxClient $sphinx )
	{
		if ( !( $query instanceof MultiFieldQuery ) )
			$query = $sphinx->Parse ( $query );
		
		$max_num_values = $this->_max_num_values;
		if ( $this->_augment )
			$max_num_values += $query->CountField ( $this->_sph_field );
		
		// stash current Sphinx settings
		$sphinx->SaveOptions ( array (
			'_offset', '_limit', '_maxmatches', '_cutoff',            // modified by SetLimits
			'_select',                                                // modified by SetSelect
			'_groupby', '_groupfunc', '_groupsort', '_groupdistinct', // modified by SetGroupBy
			'_sort', '_sortby',                                       // modified by SetSortMode
		) );
		
		$sphinx->SetLimits ( 0, $max_num_values, $this->_max_matches, $this->_cutoff );
		$sphinx->SetSelect ( $this->_set_select );
		$sphinx->SetGroupBy ( $this->_attr, $this->_func, $this->_group_sort );
		$sphinx->AddQuery ( $query, $this->_default_index );
		$sphinx->LoadOptions ();
		
		return $query;
	}
	
	/**
	 * Used to reset the Facet values.
	 */
	public function _Reset ()
	{
		// initialize results array
		$this->_results = array (
			'time' => 0,
			'total_found' => 0,
			'error' => '',
			'warning' => '',
			'matches' => array ()
		);
	}
	
	/**
	 * Used internally to set the computed Facet results, metadata and terms.
	 * 
	 * @param MultiFieldQuery $query Sphinx query as a MultiFieldQuery object.
	 * @param array $results Computed results from Sphinx.
	 * @param DataFetchInterface $datafetch Data source object.
	 */
	public function _SetValues ( MultiFieldQuery $query, array $results, DataFetchInterface $datafetch=null )
	{
		foreach ( array_keys ( $this->_results ) as $key )
		{
			if ( $key != 'matches' && array_key_exists ( $key, $results ) )
				$this->_results[$key] = $results[$key];
		}
		
		if ( !isset ( $results['matches'] ) )
			return;
		
		$terms = array ();
		
		// internal data source always overrides external data source
		if ( $this->_datafetch )
			$datafetch = $this->_datafetch;
		
		// fetch string terms if data source attached and configured
		if ( $datafetch && $this->_source )
		{
			$terms = $datafetch->FetchTerms (
				$results['matches'],
				$this->_source,
				function ( $match ) {
					return $match['attrs']['@groupby'];
				}
			);
		}
		
		// extend results array with additional attributes (@term, @selected)
		foreach ( $results['matches'] as $match )
		{
			if ( count ( $terms ) && isset ( $terms[$match['attrs']['@groupby']] ) )
				$match['@hit'] = $terms[$match['attrs']['@groupby']];
			else
				$match['@hit'] = $match['attrs']['@groupby'];
			
			$value = array ();
			foreach ( $match['attrs'] as $key => $item )
			{
				if ( $key[0] == '@' )
					$value[$key] = $item;
			}
			
			$value['@term'] = $match['@hit'];
			$value['@groupfunc'] = isset ( $value['@groupfunc'] ) ?
				$value['@groupfunc'] : $value['@count'];
			$term = sprintf ( '@%s %s', $this->_sph_field, strtolower ( $value['@term'] ) );
			$value['@selected'] = $query->HasQueryTerm ( $term ) ? 'True' : 'False';
			$this->_results['matches'][] = $value;
		}
		
		// supply the query object with matched terms as well
		foreach ( $query as $qt )
		{
			if ( $qt->HasField ( $this->_sph_field ) && isset ( $terms[$qt->GetTerm ()] ) )
				$qt->SetUserTerm ( $terms[$qt->GetTerm ()] );
		}
	}
	
	/**
	 * Perform internal custom sorting of Sphinx results.
	 */
	public function _OrderValues ()
	{
		if ( !count ( $this->_results['matches'] ) )
			return;
		
		$func = $this->_order_by;
		$desc = $this->_order_by_desc;
		usort ( $this->_results['matches'], function ( $a, $b ) use ( $func, $desc ) {
			$a_key = $func ( $a );
			$b_key = $func ( $b );
			if ( $a_key > $b_key )
				return ( $desc ? -1 : 1 );
			if ( $a_key < $b_key )
				return ( $desc ? 1 : -1 );
			return 0;
		} );
	}
	
	/**
	 * Manually set the computation results. Used by FacetGroupCache.
	 * 
	 * @param array $results Computed result array.
	 */
	public function SetResults ( array $results )
	{
		$this->_results = $results;
	}
	
	/**
	 * Return the computation results.
	 * 
	 * @return array Computed result array.
	 */
	public function GetResults ()
	{
		return $this->_results;
	}
	
	/**
	 * Return the name of the Facet.
	 * 
	 * @return string Facet name.
	 */
	public function GetName ()
	{
		return $this->_name;
	}
	
	/**
	 * Return the attribute field of the Facet.
	 * 
	 * @return string Attribute field.
	 */
	public function GetAttribute ()
	{
		return $this->_attr;
	}
	
	/**
	 * Return the computation time as reported by the Sphinx client.
	 * 
	 * @return double Time taken for Facet computation.
	 */
	public function GetTime ()
	{
		return $this->_results['time'];
	}
	
	/**
	 * Return all Sphinx-specific parameters.
	 * 
	 * @return array Sphinx parameter list.
	 */
	public function GetParams ()
	{
		$paramlist = array (
			'attr',
			'func',
			'group_sort',
			'set_select',
			'sph_field',
			'default_index',
			'max_num_values',
			'max_matches',
			'cutoff'
		);
		
		$params = array ();
		foreach ( $paramlist as $param )
		{
			$params[$param] = $this->{'_' . $param};
		}
		return $params;
	}
	
	/**
	 * Return the data source configuration.
	 * 
	 * @return array Source configuration options.
	 */
	public function GetSource ()
	{
		return $this->_source;
	}
	
	/**
	 * Return the Facet representation in string format.
	 * 
	 * @return string Facet string representation.
	 */
	public function __toString ()
	{
		$stats = sprintf (
			'(%s/%s values group sorted by "%s" in %s sec.)', 
			$this->_max_num_values,
			$this->_results['total_found'],
			$this->_group_sort,
			$this->_results['time']
		);
		$s = sprintf ( '%s: %s' . PHP_EOL, $this->_name, $stats );
		foreach ( $this->_results['matches'] as $index => $match )
		{
			$s .= sprintf ( "\t%s. %s, ", $index + 1, $match['@term'] );
			$s .= sprintf ( '@count=%s, @groupby=%s, ', $match['@count'], $match['@groupby'] );
			$items = array ();
			foreach ( $match as $key => $value )
			{
				if ( $key != '@term' && $key != '@count' && $key != '@groupby' )
					$items[] = sprintf ( '%s=%s', $key, $value );
			}
			$s .= implode ( ', ', $items ) . PHP_EOL;
		}
		return $s;
	}
	
	/**
	 * Return the Facet representation in array format.
	 * 
	 * @return array Facet array representation.
	 */
	public function ToArray ()
	{
		return array (
			'max_num_values' => $this->_max_num_values,
			'total_found' => $this->_results['total_found'],
			'group_sort' => $this->_group_sort,
			'time' => $this->_results['time'],
			'matches' => $this->_results['matches'],
		);
	}
	
	/**
	 * IteratorAggregate interface method. Makes the Facet values iterable.
	 * 
	 * @return ArrayIterator Array iterator object.
	 */
	public function getIterator ()
	{
		return new \ArrayIterator ( $this->_results['matches'] );
	}
	
	/**
	 * Countable interface method.
	 * 
	 * @return integer Number of Facet values.
	 */
	public function count ()
	{
		return count ( $this->_results['matches'] );
	}
	
	/**
	 * DataFetchInterface method. Allows a Facet to act as a data source for term mapping.
	 *
	 * @param array $matches Results from Sphinx computation.
	 * @param array $options Source config where 'name' specifies the required attribute.
	 * @param ignored $getter
	 * @return array ID-term pairs unserialized from Sphinx string attribute.
	 */
	public function FetchTerms ( array $matches, array $options, \Closure $getter )
	{
		$attr = $options['name'];
		$terms = array();
		
		foreach ( $matches as $match )
		{
			if ( isset ( $match['attrs'][$attr] ) )
			{
				// The serialized attribute is in the form "1,value,2,value,3,value"
				// This converts it into an indexed array
				$tokens = explode ( $this->_source['delim'], $match['attrs'][$attr] );
				while ( $id = array_shift ( $tokens ) )
					$terms[$id] = array_shift ( $tokens );
			}
		}
		return $terms;
	}
}
