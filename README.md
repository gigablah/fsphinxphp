**FSphinx** is a PHP port of the [fSphinx] [1] Python library, which extends the Sphinx API to easily perform faceted search.

What's faceted search?
----------------------

Think of "filtering", "refining" or "drilling down". For example, when searching through a database of movie titles, you could get a list of clickable refinement options such as actors, directors and year of release. As opposed to static hierarchical navigation, these options are calculated as you search so that you don't end up following paths that return zero results.

How do I use this?
------------------

    $fsphinx = new FSphinxClient();
    $fsphinx->SetServer(SPHINX_HOST, SPHINX_PORT);
    $fsphinx->SetDefaultIndex('movies');
    $fsphinx->SetMatchMode(SPH_MATCH_EXTENDED2);
    $fsphinx->AttachQueryParser(new MultiFieldQuery());
    $fsphinx->AttachFacets(new Facet('actor'), new Facet('director'), new Facet('year'));
    $results = $fsphinx->Query('action');
	foreach ($results['facets'] as $facet) var_dump($facet);

To learn more, please refer to the [tutorial] [2] or the [documentation] [3].

For questions and suggestions, please email Chris Heng (hengkuanyen at gmail dot com).

[1]: http://github.com/alexksikes/fSphinx
[2]: http://github.com/gigablah/fsphinxphp/tree/master/data/
[3]: http://gigablah.github.com/fsphinxphp/