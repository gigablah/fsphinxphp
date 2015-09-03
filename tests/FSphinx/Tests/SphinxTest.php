<?php

namespace FSphinx\Tests;

use FSphinx\FSphinxClient;
use FSphinx\MultiFieldQuery;
use FSphinx\Facet;

class SphinxTest extends \PHPUnit_Framework_TestCase
{
    protected $cl;
    protected $query;
    protected $factor;
    protected $fyear;

    protected function setUp()
    {
        $this->cl = new FSphinxClient();
        $this->cl->setServer(SPHINX_HOST, SPHINX_PORT);
        $this->cl->setDefaultIndex('items');
        $this->cl->setMatchMode(FSphinxClient::SPH_MATCH_EXTENDED2);
        $this->query = new MultiFieldQuery(array('actor'=>'actors','genre'=>'genres'));
        $this->query->parse('@year 1974 @genre drama @actor harrison ford');
        $this->factor = new Facet('actor');
        $this->factor->setMaxNumValues(5);
        $this->factor->attachDataSource($this->factor, array('name'=>'actor_terms_attr'));
        $this->fyear = new Facet('year');
        $this->cl->attachQueryParser($this->query);
        $this->cl->attachFacets($this->fyear, $this->factor);
    }

    public function testSetFilters()
    {
        $this->cl->setFiltering(true);
        $this->query->parse('@year 1974 @genre drama @actor harrison ford');
        $this->cl->setFilters($this->query);
        $this->assertEquals(array(
            array(
                'type' => FSphinxClient::SPH_FILTER_VALUES,
                'attr' => 'year_attr',
                'exclude' => null,
                'values' => array('1974')
            )
        ), $this->cl->filters);
        $this->cl->resetFilters();
        $this->query->parse('@-year 1974 @genre drama @actor harrison ford');
        $this->cl->setFilters($this->query);
        $this->assertEquals(array(), $this->cl->filters);
    }

    public function testStringQuery()
    {
        $results = $this->cl->query('movie');
        if ($this->cl->isConnectError()) $this->markTestSkipped('Could not connect to Sphinx.');
        if (!$results || !isset($results['matches'])) $this->markTestSkipped('No results returned from Sphinx.');
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
        $this->query->parse('@year 1974 @genre drama @actor harrison ford');
        $results = $this->cl->query($this->query);
        if ($this->cl->isConnectError()) $this->markTestSkipped('Could not connect to Sphinx.');
        if (!$results || !isset($results['matches'])) $this->markTestSkipped('No results returned from Sphinx.');
        $ids = array();
        foreach ($results['matches'] as $id => $result) {
            $ids[] = $id;
        }
        $this->assertEquals(array(
            71360
        ), $ids);
    }
}
