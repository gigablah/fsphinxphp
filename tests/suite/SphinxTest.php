<?php

use \FSphinx\FSphinxClient;
use \FSphinx\MultiFieldQuery;
use \FSphinx\Facet;

class SphinxTest extends PHPUnit_Framework_TestCase
{
	protected $cl;
	protected $query;
	protected $factor;
	protected $fyear;
	
	protected function setUp()
	{
		$this->cl = new FSphinxClient();
		$this->cl->SetServer(SPHINX_HOST, SPHINX_PORT);
		$this->cl->SetDefaultIndex('items');
		$this->cl->SetMatchMode(SPH_MATCH_EXTENDED2);
		$this->query = new MultiFieldQuery(array('actor'=>'actors','genre'=>'genres'));
		$this->query->Parse('@year 1974 @genre drama @actor harrison ford');
		$this->factor = new Facet('actor');
		$this->factor->SetMaxNumValues(5);
		$this->factor->AttachDataFetch($this->factor, array('name'=>'actor_terms_attr'));
		$this->fyear = new Facet('year');
		$this->cl->AttachQueryParser($this->query);
		$this->cl->AttachFacets($this->fyear, $this->factor);
	}
	
	public function testStringQuery()
	{
		$results = $this->cl->Query('movie');
		if (!$results) $this->markTestSkipped('No results returned from Sphinx.');
		$ids = array();
		foreach ($results['matches'] as $id => $result) {
			$ids[] = $id;
		}
		$this->assertEquals(array(
			56687, 56801, 85809, 109424, 118276, 379786, 1166827
		), $ids);
	}
	
	public function testMultiFieldQuery()
	{
		$this->query->Parse('@year 1974 @genre drama @actor harrison ford');
		$results = $this->cl->Query($this->query);
		if (!$results) $this->markTestSkipped('No results returned from Sphinx.');
		$ids = array();
		foreach ($results['matches'] as $id => $result) {
			$ids[] = $id;
		}
		$this->assertEquals(array(
			71360
		), $ids);
	}
}