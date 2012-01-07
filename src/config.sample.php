<?php
/**
 * This file creates and returns an FSphinx client with attached query parser, facets and data sources.
 * Use it in the following manner:
 * 
 * $cl = FSphinxClient::FromConfig($filepath);
 */

use \FSphinx\FSphinxClient;
use \FSphinx\MultiFieldQuery;
use \FSphinx\Facet;

$cl = new FSphinxClient();
$cl->SetServer(defined('SPHINX_HOST') ? SPHINX_HOST : '127.0.0.1', defined('SPHINX_PORT') ? SPHINX_PORT : 9312);
$cl->SetDefaultIndex('items');
$cl->SetMatchMode(SPH_MATCH_EXTENDED2);
$cl->SetSortMode(SPH_SORT_EXPR, '@weight * user_rating_attr * nb_votes_attr * year_attr / 100000');
$cl->SetFieldWeights(array('title'=>30));
$factor = new Facet('actor');
$factor->AttachDataFetch($cl, array('name'=>'actor_terms'));
$fdirector = new Facet('director');
$fdirector->AttachDataFetch($fdirector, array('name'=>'director_terms_attr'));
$cl->AttachFacets(
	new Facet('year'),
	new Facet('genre'),
	new Facet('keyword', array('attr'=>'plot_keyword_attr')),
	$fdirector,
	$factor
);
$group_func = 'sum(if (runtime_attr > 45, if (nb_votes_attr > 1000, if (nb_votes_attr < 10000, nb_votes_attr * user_rating_attr, 10000 * user_rating_attr), 1000 * user_rating_attr), 300 * user_rating_attr))';			
foreach ($cl->facets as $facet)
{
	$facet->SetGroupFunc($group_func);
	$facet->SetOrderBy('@term', 'asc');
	$facet->SetMaxNumValues(5);
}
$cl->AttachQueryParser(new MultiFieldQuery(array(
	'genre'=>'genres',
	'keyword'=>'plot_keywords',
	'director'=>'directors',
	'actor'=>'actors'
)));

return $cl;