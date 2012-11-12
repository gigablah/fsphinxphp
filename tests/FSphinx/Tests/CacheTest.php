<?php

namespace FSphinx\Tests;

use FSphinx\FacetGroupCache;
use FSphinx\CacheApc;
use FSphinx\CacheRedis;
use FSphinx\CacheMemcached;

class CacheTest extends \PHPUnit_Framework_TestCase
{
    protected $cache;
    protected $matches;
    protected $facet;
    protected $facets;
    protected $query;

    protected function setUp()
    {
        $this->matches = array(
            array(
                '@expr' => 8900227,
                '@groupfunc' => 85776,
                '@groupby' => 1999,
                '@count' => 11,
                '@term' => 1999,
                '@selected' => false
            ),
            array(
                '@expr' => 3032249.25,
                '@groupfunc' => 73700,
                '@groupby' => 2003,
                '@count' => 9,
                '@term' => 2003,
                '@selected' => true
            )
        );
        $this->facet = $this->getMockBuilder('\\FSphinx\\Facet')
                            ->disableOriginalConstructor()
                            ->getMock();
        $this->facet->expects($this->any())
                    ->method('getResults')
                    ->will($this->returnValue(array(
                        'time' => 0.001,
                        'total_found' => 2,
                        'error' => null,
                        'warning' => null,
                        'matches' => $this->matches
                      )));
        $this->facets = array($this->facet, $this->facet);
        $this->query = $this->getMockBuilder('\\FSphinx\\MultiFieldQuery')
                            ->disableOriginalConstructor()
                            ->getMock();
        $this->query->expects($this->any())
                    ->method('toCanonical')
                    ->will($this->returnValue('(@* drama)(@* drama)'));
    }

    protected function tearDown()
    {
        if ($this->cache['apc']) {
            $this->cache['apc']->clear(true);
        }
        if ($this->cache['redis']) {
            $this->cache['redis']->clear(true);
        }
        if ($this->cache['memcached']) {
            $this->cache['memcached']->clear(true);
        }
    }

    /*public function testKeyGeneration()
    {
        $hash = 'fsphinx';

        // Normal key
        $key = $this->cache->getKey($hash);
        $this->assertEquals('test_FS_779134b4a0491467801b4960d8e5683a', $key);

        // Sticky key
        $key = $this->cache->getKey($hash, true);
        $this->assertEquals('*test_FS_779134b4a0491467801b4960d8e5683a', $key);

        // Normal prefix pattern
        $key = $this->cache->getKey(null, false, true);
        $this->assertEquals('/^test_FS_/', $key);

        // Sticky prefix pattern
        $key = $this->cache->getKey(null, true, true);
        $this->assertEquals('/^\*?test_FS_/', $key);

        // Remove ENV prefix
        $_ENV['APPLICATION_ENV'] = '';
        $key = $this->cache->getKey($hash, true);
        $this->assertEquals('*_FS_779134b4a0491467801b4960d8e5683a', $key);
    }*/

    public function testSetCacheApc()
    {
        if (!extension_loaded('apc') || ini_get('apc.enabled') != '1') {
            $this->markTestSkipped('The APC extension is not loaded.');
        }
        elseif (php_sapi_name() == 'cli' && ini_get('apc.enable_cli') != '1') {
            $this->markTestSkipped('APC is not enabled on command line. Please set apc.enable_cli = 1');
        }

        $this->cache = array(
            'apc' => new FacetGroupCache(new CacheApc())
        );

        $this->cache['apc']->setFacets($this->query, $this->facets, true, false);
        $results = $this->cache['apc']->getFacets($this->query);
        $match = array(
            'time' => 0.001,
            'total_found' => 2,
            'error' => null,
            'warning' => null,
            'matches' => $this->matches
        );
        $this->assertEquals($results, array($match, $match));
    }

    public function testClearCacheApc()
    {
        if (!extension_loaded('apc') || ini_get('apc.enabled') != '1') {
            $this->markTestSkipped('The APC extension is not loaded.');
        }
        elseif (php_sapi_name() == 'cli' && ini_get('apc.enable_cli') != '1') {
            $this->markTestSkipped('APC is not enabled on command line. Please set apc.enable_cli = 1');
        }

        $this->cache = array(
            'apc' => new FacetGroupCache(new CacheApc())
        );

        // sticky key
        $this->cache['apc']->setFacets($this->query, $this->facets, true, true);
        $this->cache['apc']->clear();
        $results = $this->cache['apc']->getFacets($this->query);
        $match = array(
            'time' => 0.001,
            'total_found' => 2,
            'error' => null,
            'warning' => null,
            'matches' => $this->matches
        );
        $this->assertEquals($results, array($match, $match));

        // clear sticky keys
        $this->cache['apc']->clear(true);
        $results = $this->cache['apc']->getFacets($this->query);
        $this->assertEquals($results, false);
    }

    public function testSetCacheRedis()
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('The Redis extension is not loaded.');
        }

        $redis = new Redis();
        $redis->connect(REDIS_HOST, REDIS_PORT);
        $this->cache = array(
            'redis' => new FacetGroupCache(new CacheRedis($redis))
        );

        $this->cache['redis']->setFacets($this->query, $this->facets, true, false);
        $results = $this->cache['redis']->getFacets($this->query);
        $match = array(
            'time' => 0.001,
            'total_found' => 2,
            'error' => null,
            'warning' => null,
            'matches' => $this->matches
        );
        $this->assertEquals($results, array($match, $match));
    }

    public function testClearCacheRedis()
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('The Redis extension is not loaded.');
        }

        $redis = new Redis();
        $redis->connect(REDIS_HOST, REDIS_PORT);
        $this->cache = array(
            'redis' => new FacetGroupCache(new CacheRedis($redis))
        );

        // sticky key
        $this->cache['redis']->setFacets($this->query, $this->facets, true, true);
        $this->cache['redis']->clear();
        $results = $this->cache['redis']->getFacets($this->query);
        $match = array(
            'time' => 0.001,
            'total_found' => 2,
            'error' => null,
            'warning' => null,
            'matches' => $this->matches
        );
        $this->assertEquals($results, array($match, $match));

        // clear sticky keys
        $this->cache['redis']->clear(true);
        $results = $this->cache['redis']->getFacets($this->query);
        $this->assertEquals($results, false);
    }

    public function testSetCacheMemcached()
    {
        if (!extension_loaded('memcache')) {
            $this->markTestSkipped('The Memcache extension is not loaded.');
        }

        $memcache = new Memcache();
        $memcache->connect(MEMCACHE_HOST, MEMCACHE_PORT);
        $this->cache = array(
            'memcached' => new FacetGroupCache(new CacheMemcached($memcache))
        );

        $this->cache['memcached']->setFacets($this->query, $this->facets, true, false);
        $results = $this->cache['memcached']->getFacets($this->query);
        $match = array(
            'time' => 0.001,
            'total_found' => 2,
            'error' => null,
            'warning' => null,
            'matches' => $this->matches
        );
        $this->assertEquals($results, array($match, $match));
    }

    public function testClearCacheMemcached()
    {
        if (!extension_loaded('memcache')) {
            $this->markTestSkipped('The Memcache extension is not loaded.');
        }

        $memcache = new Memcache();
        $memcache->connect(MEMCACHE_HOST, MEMCACHE_PORT);
        $this->cache = array(
            'memcached' => new FacetGroupCache(new CacheMemcached($memcache))
        );

        // sticky key
        $this->cache['memcached']->setFacets($this->query, $this->facets, true, true);
        $this->cache['memcached']->clear();
        $results = $this->cache['memcached']->getFacets($this->query);
        $match = array(
            'time' => 0.001,
            'total_found' => 2,
            'error' => null,
            'warning' => null,
            'matches' => $this->matches
        );
        $this->assertEquals($results, array($match, $match));

        // clear sticky keys
        $this->cache['memcached']->clear(true);
        $results = $this->cache['memcached']->getFacets($this->query);
        $this->assertEquals($results, false);
    }
}
