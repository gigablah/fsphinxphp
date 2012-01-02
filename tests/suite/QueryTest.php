<?php

use \FSphinx\MultiFieldQuery;

class QueryTest extends PHPUnit_Framework_TestCase
{
	protected $query;
	
	protected function setUp()
	{
		$this->query = new MultiFieldQuery(array('actor'=>'actors','genre'=>'genres'));
	}
	
	public function testQueryParse()
	{
		$this->query->Parse('@year 1974 @genre drama @actor harrison ford');
		$this->assertEquals(
			$this->query->__toString(),
			'(@year 1974) (@genre drama) (@actor harrison ford)'
		);
		$this->assertEquals(
			$this->query->ToSphinx(),
			'(@year 1974) (@genres drama) (@actors "harrison ford")'
		);
		$this->assertEquals(
			$this->query->ToCanonical(),
			'(@actors "harrison ford") (@genres drama) (@year 1974)'
		);
	}
	
	public function testQueryToggle()
	{
		$this->query->Parse('@year 1974 @genre drama @actor harrison ford');
		$this->query->ToggleOff('@year 1974');
		$this->assertEquals(
			$this->query->__toString(),
			'(@-year 1974) (@genre drama) (@actor harrison ford)'
		);
		$this->assertEquals(
			$this->query->ToSphinx(),
			'(@genres drama) (@actors "harrison ford")'
		);
		$this->assertEquals(
			$this->query->ToCanonical(),
			'(@actors "harrison ford") (@genres drama)'
		);
		$this->assertTrue($this->query->HasQueryTerm('@year 1974'));
		$this->assertFalse($this->query->HasQueryTerm('@year 1999'));
	}

	public function testQueryHash()
	{
		$this->query->Parse('@year 1974 @genre drama @actor harrison ford');
		$terms = array();
		foreach ($this->query as $term)
		{
			$terms[] = $term->ToHash();
		}
		$this->assertEquals(array(
			'34c8591584caa46cfffd72a5e79ee044',
			'dbfce37cec16608122177c33ef54c47a',
			'e18101bef1c8ae8f43b2448574ed3f04',
		), $terms);
	}
}