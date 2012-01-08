<?php

use \FSphinx\FacetGroupCache;

class CacheTest extends PHPUnit_Framework_TestCase
{
	protected $cache;
	protected $matches;
	protected $facet;
	protected $facets;
	protected $query;
	
	protected function setUp()
	{
		$this->cache = new FacetGroupCache();
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
		$this->facet = $this->getMockBuilder('\FSphinx\Facet')
		                    ->disableOriginalConstructor()
		                    ->getMock();
		$this->facet->expects($this->any())
		            ->method('GetResults')
		            ->will($this->returnValue(array(
						'time' => 0.001,
						'total_found' => 2,
						'error' => null,
						'warning' => null,
						'matches' => $this->matches
		              )));
		$this->facets = array($this->facet, $this->facet);
		$this->query = $this->getMockBuilder('\FSphinx\MultiFieldQuery')
		                    ->disableOriginalConstructor()
		                    ->getMock();
		$this->query->expects($this->any())
		            ->method('ToCanonical')
		            ->will($this->returnValue('(@* drama)(@* drama)'));
	}
	
	public function testKeyGeneration()
	{
		$hash = 'fsphinx';
		
		// Normal key
		$key = $this->cache->GetKey($hash);
		$this->assertEquals('test_FS_779134b4a0491467801b4960d8e5683a', $key);
		
		// Sticky key
		$key = $this->cache->GetKey($hash, true);
		$this->assertEquals('*test_FS_779134b4a0491467801b4960d8e5683a', $key);
		
		// Normal prefix pattern
		$key = $this->cache->GetKey(null, false, true);
		$this->assertEquals('/^test_FS_/', $key);
		
		// Sticky prefix pattern
		$key = $this->cache->GetKey(null, true, true);
		$this->assertEquals('/^\*?test_FS_/', $key);
		
		// Remove ENV prefix
		$_ENV['APPLICATION_ENV'] = '';
		$key = $this->cache->GetKey($hash, true);
		$this->assertEquals('*_FS_779134b4a0491467801b4960d8e5683a', $key);
	}
	
	public function testSetCache()
	{
		if (!extension_loaded('apc') || ini_get('apc.enabled') != '1') {
			$this->markTestSkipped('The APC extension is not loaded.');
        }
		elseif (php_sapi_name() == 'cli' && ini_get('apc.enable_cli') != '1') {
			$this->markTestSkipped('APC is not enabled on command line. Please set apc.enable_cli = 1');
		}
		
		$this->cache->SetFacets($this->query, $this->facets, true, false);
		$results = $this->cache->GetFacets($this->query);
		$match = array(
			'time' => 0.001,
			'total_found' => 2,
			'error' => null,
			'warning' => null,
			'matches' => $this->matches
		);
		$this->assertEquals($results, array($match, $match));
	}
	
	public function testClearCache()
	{
		if (!extension_loaded('apc') || ini_get('apc.enabled') != '1') {
			$this->markTestSkipped('The APC extension is not loaded.');
        }
		elseif (php_sapi_name() == 'cli' && ini_get('apc.enable_cli') != '1') {
			$this->markTestSkipped('APC is not enabled on command line. Please set apc.enable_cli = 1');
		}
		
		// sticky key
		$this->cache->SetFacets($this->query, $this->facets, true, true);
		$this->cache->Clear();
		$results = $this->cache->GetFacets($this->query);
		$match = array(
			'time' => 0.001,
			'total_found' => 2,
			'error' => null,
			'warning' => null,
			'matches' => $this->matches
		);
		$this->assertEquals($results, array($match, $match));
		
		// clear sticky keys
		$this->cache->Clear(true);
		$results = $this->cache->GetFacets($this->query);
		$this->assertEquals($results, false);
	}
	
	protected function tearDown()
	{
		$this->cache->Clear(true);
	}
}