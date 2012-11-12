<?php

namespace FSphinx\Tests;

use FSphinx\FSphinxClient;
use FSphinx\MultiFieldQuery;
use FSphinx\Facet;
use FSphinx\FacetGroup;
use FSphinx\FacetGroupCache;
use FSphinx\CacheApc;

class FacetTest extends \PHPUnit_Framework_TestCase
{
    protected $cl;
    protected $factor;
    protected $fyear;
    protected $facets;
    protected $cache;

    protected function setUp()
    {
        $this->cl = new FSphinxClient();
        $this->cl->setServer(SPHINX_HOST, SPHINX_PORT);
        $this->cl->setDefaultIndex('items');
        $this->cl->setMatchMode(FSphinxClient::SPH_MATCH_EXTENDED2);
        $this->cl->attachQueryParser(new MultiFieldQuery(array('actor' => 'actors')));
    }

    protected function tearDown()
    {
        if ($this->cache) $this->cache->clear(true);
    }

    public function testCompute()
    {
        $this->factor = new Facet('actor');
        $this->factor->attachSphinxClient($this->cl);
        $this->factor->setMaxNumValues(5);
        $this->factor->setOrderBy('@count', 'desc');
        $results = $this->factor->compute('drama');
        if (!$results) $this->markTestSkipped('No results returned from Sphinx.');
        $ids = array();
        foreach ($this->factor as $match) {
            $ids[] = $match['@term'];
        }
        $this->assertEquals(array(
            134, 199, 151, 702798, 380
        ), $ids);
    }

    public function testDataSourceWithSphinx()
    {
        $this->factor = new Facet('actor');
        $this->factor->attachSphinxClient($this->cl);
        $this->factor->setMaxNumValues(5);
        $this->factor->setGroupFunc('sum(user_rating_attr * nb_votes_attr)');
        $this->factor->setOrderBy('@groupfunc', 'desc');
        $this->factor->attachDataSource($this->cl, array('name' => 'actor_terms', 'query' => null));
        $this->assertEquals(array(
            'name' => 'actor_terms',
            'id' => 'actor_id_attr',
            'term' => 'actor_term_attr',
            'delim' => ',',
            'query' => null
            ), $this->factor->getSource()
        );
        $results = $this->factor->compute('drama');
        if (!$results) $this->markTestSkipped('No results returned from Sphinx.');
        $ids = array();
        foreach ($this->factor as $match) {
            $ids[] = $match['@term'];
        }
        $this->assertEquals(array(
            'Morgan Freeman', 'Robert De Niro', 'Al Pacino', 'Robert Duvall', 'John Cazale'
        ), $ids);
    }

    public function testDataSourceWithAttribute()
    {
        $this->factor = new Facet('actor');
        $this->factor->attachSphinxClient($this->cl);
        $this->factor->setMaxNumValues(5);
        $this->factor->setGroupFunc('sum(user_rating_attr * nb_votes_attr)');
        $this->factor->setOrderBy('@groupfunc', 'desc');
        $this->factor->attachDataSource($this->factor, array('name' => 'actor_terms_attr'));
        $params = $this->factor->getParams();
        $this->assertEquals(
            array(
                'attr' => 'actor_attr',
                'func' => 4,
                'group_sort' => '@groupfunc desc',
                'set_select' => '@groupby, @count, sum(user_rating_attr * nb_votes_attr) as @groupfunc, actor_terms_attr',
                'sph_field' => 'actor',
                'default_index' => null,
                'max_num_values' => 5,
                'max_matches' => 1000,
                'cutoff' => 0
            ),
            $params
        );

        $results = $this->factor->compute('drama');
        if (!$results) $this->markTestSkipped('No results returned from Sphinx.');
        $ids = array();
        foreach ($this->factor as $match) {
            $ids[] = $match['@term'];
        }
        $this->assertEquals(array(
            'Morgan Freeman', 'Robert De Niro', 'Al Pacino', 'Robert Duvall', 'John Cazale'
        ), $ids);
    }

    public function testAttributeFiltering()
    {
        $this->factor = new Facet('actor');
        $this->factor->attachSphinxClient($this->cl);
        $this->factor->setMaxNumValues(5);
        $this->factor->setGroupFunc('sum(user_rating_attr * nb_votes_attr)');
        $this->factor->setOrderBy('@groupfunc', 'desc');
        $this->factor->attachDataSource($this->factor, array('name' => 'actor_terms_attr'));
        $this->cl->setFiltering(false);

        $results = $this->factor->compute('drama (@actor "Morgan Freeman")');
        if (!$results) $this->markTestSkipped('No results returned from Sphinx.');
        $ids = array();
        foreach ($this->factor as $match) {
            $ids[] = $match['@term'];
        }
        $this->assertEquals(array(
            'Morgan Freeman', 'Bob Gunton', 'Gil Bellows', 'Mark Rolston', 'Tim Robbins', 'Clancy Brown'
        ), $ids);

        $this->cl->setFiltering(true);
        $results = $this->factor->compute('drama (@actor 151)');
        if (!$results) $this->markTestSkipped('No results returned from Sphinx.');
        $ids = array();
        foreach ($this->factor as $match) {
            $ids[] = $match['@term'];
        }
        $this->assertEquals(array(
            'Morgan Freeman', 'Bob Gunton', 'Gil Bellows', 'Mark Rolston', 'Tim Robbins', 'Clancy Brown'
        ), $ids);
    }

    public function testAlphabeticalOrder()
    {
        $this->factor = new Facet('actor');
        $this->factor->attachSphinxClient($this->cl);
        $this->factor->setMaxNumValues(5);
        $this->factor->setOrderBy('@term', 'asc');
        $this->factor->attachDataSource($this->factor, array('name' => 'actor_terms_attr'));
        $results = $this->factor->compute('drama');
        if (!$results) $this->markTestSkipped('No results returned from Sphinx.');
        $ids = array();
        foreach ($this->factor as $match) {
            $ids[] = $match['@term'];
        }
        $this->assertEquals(array(
            'Al Pacino', 'John Qualen', 'Morgan Freeman', 'Robert De Niro', 'Robert Duvall'
        ), $ids);
    }

    public function testCaching()
    {
        if (!extension_loaded('apc') || ini_get('apc.enabled') != '1') {
            $this->markTestSkipped('The APC extension is not loaded.');
        }
        elseif (php_sapi_name() == 'cli' && ini_get('apc.enable_cli') != '1') {
            $this->markTestSkipped('APC is not enabled on command line. Please set apc.enable_cli = 1');
        }

        $this->cache = new FacetGroupCache(new CacheApc());
        $this->cache->clear(true);
        $this->factor = new Facet('actor');
        $this->factor->setMaxNumValues(5);
        $this->factor->setGroupFunc('sum(user_rating_attr * nb_votes_attr)');
        $this->factor->setOrderBy('@groupfunc', 'desc');
        $this->factor->attachDataSource($this->factor, array('name' => 'actor_terms_attr'));
        $this->fyear = new Facet('year');
        $this->fyear->setMaxNumValues(5);
        $this->facets = new FacetGroup($this->fyear, $this->factor);
        $this->facets->attachSphinxClient($this->cl);
        $this->facets->attachCache($this->cache);

        $results = $this->facets->compute('drama', false);
        if (!$results) $this->markTestSkipped('No results returned from Sphinx.');

        $this->facets->setCaching(true);
        $this->facets->compute('drama');
        $this->facets->compute('drama');
        $this->assertEquals(-1, $this->facets->getTime());

        $this->facets->compute('drama', false);
        $this->assertGreaterThan(-1, $this->facets->getTime());

        $this->facets->setPreloading(true);
        $this->facets->setCaching(false);
        $this->facets->preload('drama');
        $this->facets->compute('drama');
        $this->assertEquals(-1, $this->facets->getTime());

        $ids = array();
        foreach ($this->facets as $index => $facet) {
            $ids[$index] = array();
            foreach ($facet as $match) {
                $ids[$index][] = $match['@term'];
            }
        }

        $this->assertEquals(array(
            2004, 2006, 1999, 2008, 2001
        ), $ids[0]);
        $this->assertEquals(array(
            'Morgan Freeman', 'Robert De Niro', 'Al Pacino', 'Robert Duvall', 'John Cazale'
        ), $ids[1]);
    }
}
