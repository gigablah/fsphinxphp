<?php

namespace FSphinx\Tests;

use FSphinx\MultiFieldQuery;
use FSphinx\QueryTerm;

class MultiFieldQueryTest extends \PHPUnit_Framework_TestCase
{
    protected $query;

    protected function setUp()
    {
        $this->query = new MultiFieldQuery(array('actor'=>'Actors', 'genRe'=>'genres'));
    }

    public function testParseSingleQueryTerm()
    {
        $this->query->parse('@year 1974');
        $this->assertTrue($this->query->hasQueryTerm('@year 1974'));
    }

    public function testParseMultipleQueryTerms()
    {
        $this->query->parse('@year 1974 @genre drama @actor harrison ford');
        $this->assertEquals(
            $this->query->__toString(),
            '(@year 1974) (@genre drama) (@actor harrison ford)'
        );
        $this->assertEquals(
            $this->query->toSphinx(),
            '(@year 1974) (@genres drama) (@actors "harrison ford")'
        );
        $this->assertEquals(
            $this->query->toCanonical(),
            '(@actors "harrison ford") (@genres drama) (@year 1974)'
        );
    }

    public function testParseMalformedQueryTerms()
    {
        $this->query->parse('-@@year 1974 @ genre drama @actor (@year 1974)');
        $this->assertEquals(
            $this->query->__toString(),
            '(@year 1974) (@* genre drama)'
        );
    }

    public function testGetQueryTerm()
    {
        $this->query->parse('@year 1974 @genre drama @actor harrison ford');
        $term1974 = QueryTerm::fromString('@year 1974');
        $this->assertEquals(
            $this->query->getQueryTerm('@year 1974'),
            $term1974
        );
        $termdrama = QueryTerm::fromString('@genre drama', array('genre'=>'genres'));
        $this->assertEquals(
            $this->query->getQueryTerm($termdrama),
            $termdrama
        );
    }

    public function testHasQueryTerm()
    {
        $this->query->parse('@year 1999');
        $term1999 = QueryTerm::fromString('@year 1999');
        $term1974 = QueryTerm::fromString('@year 1974');
        $this->assertTrue($this->query->hasQueryTerm('@year 1999'));
        $this->assertTrue($this->query->hasQueryTerm($term1999));
        $this->assertFalse($this->query->hasQueryTerm('@year 1974'));
        $this->assertFalse($this->query->hasQueryTerm($term1974));
    }

    public function testToggleQueryTerm()
    {
        $this->query->parse('@year 1974 @genre drama @actor harrison ford');
        $this->query->toggleOff('@year 1974');
        $this->assertEquals(
            $this->query->__toString(),
            '(@-year 1974) (@genre drama) (@actor harrison ford)'
        );
        $this->assertEquals(
            $this->query->toSphinx(),
            '(@genres drama) (@actors "harrison ford")'
        );
        $this->assertEquals(
            $this->query->toCanonical(),
            '(@actors "harrison ford") (@genres drama)'
        );
        $this->assertTrue($this->query->hasQueryTerm('@year 1974'));
    }

    public function testIterateQueryTerms()
    {
        $this->query->parse('@year 1974 @genre drama @actor harrison ford');
        $terms = array();
        foreach ($this->query as $term) {
            $terms[] = $term->toHash();
        }
        $this->assertEquals(array(
            '34c8591584caa46cfffd72a5e79ee044',
            'dbfce37cec16608122177c33ef54c47a',
            'e18101bef1c8ae8f43b2448574ed3f04',
        ), $terms);
    }

    public function testCountQueryTerms()
    {
        $this->query->parse('@year 1974 @genre drama @actor harrison ford');
        $this->assertEquals(3, count($this->query));
    }
}
