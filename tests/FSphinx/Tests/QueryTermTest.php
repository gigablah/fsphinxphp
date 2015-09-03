<?php

namespace FSphinx\Tests;

use FSphinx\QueryTerm;

class QueryTermTest extends \PHPUnit_Framework_TestCase
{
    protected $term;

    public function testConstructWithDefaults()
    {
        $this->term = new QueryTerm('', 'year', '1974');
        $this->assertEquals('', $this->term->getStatus());
        $this->assertEquals('year', $this->term->getUserField());
        $this->assertEquals('year', $this->term->getSphinxField());
        $this->assertEquals('year_attr', $this->term->getAttribute());
        $this->assertEquals('1974', $this->term->getTerm());
        $this->assertEquals('1974', $this->term->getUserTerm());
        $this->assertTrue($this->term->hasField('year'));
        $this->assertFalse($this->term->hasField('actor'));
    }

    public function testConstructWithFullValues()
    {
        $this->term = new QueryTerm('-', 'kEyword ', ' 1974', array('keyword'=>'plot_keywOrds'), array('keyword'=>'plot_Keyword_attr'));
        $this->assertEquals('-', $this->term->getStatus());
        $this->assertEquals('keyword', $this->term->getUserField());
        $this->assertEquals('plot_keywords', $this->term->getSphinxField());
        $this->assertEquals('plot_keyword_attr', $this->term->getAttribute());
        $this->assertEquals('1974', $this->term->getTerm());
        $this->assertEquals('1974', $this->term->getUserTerm());
        $this->assertTrue($this->term->hasField('keyword'));
        $this->assertTrue($this->term->hasField('plot_keywords'));
        $this->assertFalse($this->term->hasField('actor'));
    }

    public function testArraySort()
    {
        $this->term = array(
            new QueryTerm('', 'keyword', 'Dramaa', array('keyword'=>'plot_keywords')),
            new QueryTerm('-', 'keyword', 'drama'),
            new QueryTerm('', 'actor', 'Harrison Ford'),
            new QueryTerm('-', 'actor', 'Clint Eastwood'),
            new QueryTerm('', 'keyword', 'Crime', array('keyword'=>'plot_keywords'))
        );
        usort($this->term, array('\FSphinx\QueryTerm', 'cmp'));
        $this->assertEquals('(@-actor Clint Eastwood)', $this->term[0]->__toString());
        $this->assertEquals('(@actor Harrison Ford)', $this->term[1]->__toString());
        $this->assertEquals('(@keyword Crime)', $this->term[2]->__toString());
        $this->assertEquals('(@-keyword drama)', $this->term[3]->__toString());
        $this->assertEquals('(@keyword Dramaa)', $this->term[4]->__toString());
    }

    public function testToString()
    {
        $this->term = new QueryTerm('-', 'actor', 'Harrison Ford', array('actor'=>'actors'));
        $this->assertEquals('(@-actor Harrison Ford)', $this->term->__toString());
    }

    public function testToHash()
    {
        $this->term = new QueryTerm('-', 'actor', 'Harrison Ford', array('actor'=>'actors'));
        $this->assertEquals('e18101bef1c8ae8f43b2448574ed3f04', $this->term->toHash());
    }

    public function testToSphinx()
    {
        $this->term = new QueryTerm('+', 'year', '1974');
        $this->assertEquals('(@year 1974)', $this->term->toSphinx());
    }

    public function testToSphinxWithSpaceAndDash()
    {
        $this->term = new QueryTerm('+', 'actor', 'Liisa Repo-Martell', array('actor'=>'actors'));
        $this->assertEquals('(@actors "Liisa Repo Martell")', $this->term->toSphinx());
    }

    public function testToSphinxWhileInactive()
    {
        $this->term = new QueryTerm('-', 'actor', 'Liisa Repo-Martell', array('actor'=>'actors'));
        $this->assertEquals(null, $this->term->toSphinx());
    }

    public function testToSphinxExcludingNumeric()
    {
        $this->term = new QueryTerm('', 'actor', '1337', array('actor'=>'actors'));
        $this->assertEquals(null, $this->term->toSphinx(true));
    }

    public function testToCanonical()
    {
        $this->term = new QueryTerm('', 'actor', 'Harrison Ford', array('actor'=>'actors'));
        $this->assertEquals('(@actors "harrison ford")', $this->term->toCanonical());
    }

    public function testSetStatus()
    {
        $this->term = new QueryTerm('', 'actor', 'Harrison Ford', array('actor'=>'actors'));
        $this->assertEquals('', $this->term->getStatus());
        $this->assertEquals('(@actors "Harrison Ford")', $this->term->toSphinx());

        $this->term->SetStatus('-');
        $this->assertEquals('-', $this->term->getStatus());
        $this->assertEquals(null, $this->term->toSphinx());
        $this->assertEquals(null, $this->term->toCanonical());
    }

    public function testSetUserTerm()
    {
        $this->term = new QueryTerm('', 'kEyword ', ' 8 ', array('keyword'=>'plot_keywOrds'), array('keyword'=>'plot_Keyword_attr'));
        $this->assertEquals('(@keyword 8)', $this->term->__toString());
        $this->assertEquals('(@plot_keywords 8)', $this->term->toCanonical());

        $this->term->SetUserTerm('Drama');
        $this->assertEquals('Drama', $this->term->getUserTerm());
        $this->assertEquals('(@keyword Drama)', $this->term->__toString());
        $this->assertEquals('(@plot_keywords 8)', $this->term->toCanonical());
    }

    public function testConstructFromMatchObject()
    {
        $this->term = QueryTerm::fromMatchObject(array());
        $this->assertEquals(null, $this->term);

        $this->term = QueryTerm::fromMatchObject(array('', '', '', ' '));
        $this->assertEquals(null, $this->term);

        $this->term = QueryTerm::fromMatchObject(array('#', 'keyword', 'horror', 'drama'));
        $this->assertEquals('(@* drama)', $this->term->__toString());

        $this->term = QueryTerm::fromMatchObject(array('-', 'keyword', 'horror', ''));
        $this->assertEquals('(@-keyword horror)', $this->term->__toString());

        $this->term = QueryTerm::fromMatchObject(array('-', ' ', 'horror', ''));
        $this->assertEquals(null, $this->term);

        $this->term = QueryTerm::fromMatchObject(array('+', 'keyword', 'Drama', ''), array('keyword'=>'plot_keywords'), array('keyword'=>'plot_keyword_attr'));
        $this->assertEquals('(@keyword Drama)', $this->term->__toString());
        $this->assertEquals('(@plot_keywords drama)', $this->term->toCanonical());
    }

    public function testConstructFromString()
    {
        $this->term = QueryTerm::fromString('');
        $this->assertEquals(null, $this->term);

        $this->term = QueryTerm::fromString(' ');
        $this->assertEquals(null, $this->term);

        $this->term = QueryTerm::fromString('@+* drama');
        $this->assertEquals('(@* drama)', $this->term->__toString());

        $this->term = QueryTerm::fromString('@-keyword   horror ');
        $this->assertEquals('(@-keyword horror)', $this->term->__toString());

        $this->term = QueryTerm::fromString('@ horror');
        $this->assertEquals('(@* horror)', $this->term->__toString());

        $this->term = QueryTerm::fromString('@keyword Drama', array('keyword'=>'plot_keywords'), array('keyword'=>'plot_keyword_attr'));
        $this->assertEquals('(@keyword Drama)', $this->term->__toString());
        $this->assertEquals('(@plot_keywords drama)', $this->term->toCanonical());
    }
}
