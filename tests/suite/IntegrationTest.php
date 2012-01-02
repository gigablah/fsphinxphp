<?php

use \FSphinx\FSphinxClient;
use \FSphinx\MultiFieldQuery;
use \FSphinx\Facet;
use \FSphinx\FacetGroupCache;

class IntegrationTest extends PHPUnit_Framework_TestCase
{
	protected $cl;
	
	protected function setUp()
	{
		$this->cl = new FSphinxClient();
		$this->cl->SetServer(SPHINX_HOST, SPHINX_PORT);
		$this->cl->SetDefaultIndex('items');
		$this->cl->SetMatchMode(SPH_MATCH_EXTENDED2);
		$this->cl->SetSortMode(SPH_SORT_EXPR, '@weight * user_rating_attr * nb_votes_attr * year_attr / 100000');
		$this->cl->SetFieldWeights(array('title'=>30));
		$factor = new Facet('actor');
		$factor->AttachDataFetch($this->cl, array('name'=>'actor_terms'));
		$fdirector = new Facet('director');
		$fdirector->AttachDataFetch($fdirector, array('name'=>'director_terms_attr'));
		$this->cl->AttachFacets(
			new Facet('year'),
			new Facet('genre'),
			new Facet('keyword', array('attr'=>'plot_keyword_attr')),
			$fdirector,
			$factor
		);
		$group_func = 'sum(if (runtime_attr > 45, if (nb_votes_attr > 1000, if (nb_votes_attr < 10000, nb_votes_attr * user_rating_attr, 10000 * user_rating_attr), 1000 * user_rating_attr), 300 * user_rating_attr))';			
		foreach ($this->cl->facets as $facet)
		{
			$facet->SetGroupFunc($group_func);
			$facet->SetOrderBy('@term', 'asc');
			$facet->SetMaxNumValues(5);
		}
		$this->cl->AttachQueryParser(new MultiFieldQuery(array(
			'genre'=>'genres',
			'keyword'=>'plot_keywords',
			'director'=>'directors',
			'actor'=>'actors'
		)));
	}
	
	public function testFullQuery()
	{
		$results = $this->cl->Query('drama');
		if (!$results) $this->markTestSkipped('No results returned from Sphinx.');
		$ids = array();
		foreach ($results['matches'] as $id => $result) {
			$ids[] = $id;
		}
		$this->assertEquals(array(
			111161, 468569, 114369, 68646, 137523, 169547, 109830, 108052, 120815, 172495
		), array_slice($ids, 0, 10));

		$ids = array();
		foreach ($this->cl->facets as $index => $facet)
		{
			$ids[$index] = array();
			foreach ($facet as $match)
			{
				$ids[$index][] = $match['@term'];
			}
		}
		
		$this->assertEquals(array(
			1999, 2003, 2004, 2006, 2008
		), $ids[0]);
		$this->assertEquals(array(
			'Akira Kurosawa', 'Billy Wilder', 'Clint Eastwood', 'Francis Ford Coppola', 'Stanley Kubrick'
		), $ids[3]);
		$this->assertEquals(array(
			'Al Pacino', 'John Qualen', 'Morgan Freeman', 'Robert De Niro', 'Robert Duvall'
		), $ids[4]);
	}
	
	public function testFullQueryWithCaching()
	{
		if (!extension_loaded('apc') || ini_get('apc.enabled') != '1') {
			$this->markTestSkipped('The APC extension is not loaded.');
        }
		elseif (php_sapi_name() == 'cli' && ini_get('apc.enable_cli') != '1') {
			$this->markTestSkipped('APC is not enabled on command line. Please set apc.enable_cli = 1');
		}
		
		$this->cache = new FacetGroupCache();
		$this->cache->Clear(true);
		
		$results = $this->cl->Query('drama');
		if (!$results) $this->markTestSkipped('No results returned from Sphinx.');
		$this->assertGreaterThan(-1, $this->cl->facets->GetTime());
		
		$this->cl->facets->SetCaching(true);
		$results = $this->cl->Query('drama');
		$this->assertGreaterThan(-1, $this->cl->facets->GetTime());
		$results = $this->cl->Query('drama');
		$this->assertEquals(-1, $this->cl->facets->GetTime());
		
		$ids = array();
		foreach ($this->cl->facets as $index => $facet)
		{
			$ids[$index] = array();
			foreach ($facet as $match)
			{
				$ids[$index][] = $match['@term'];
			}
		}
		
		$this->assertEquals(array(
			1999, 2003, 2004, 2006, 2008
		), $ids[0]);
		$this->assertEquals(array(
			'Akira Kurosawa', 'Billy Wilder', 'Clint Eastwood', 'Francis Ford Coppola', 'Stanley Kubrick'
		), $ids[3]);
		$this->assertEquals(array(
			'Al Pacino', 'John Qualen', 'Morgan Freeman', 'Robert De Niro', 'Robert Duvall'
		), $ids[4]);
	}
}