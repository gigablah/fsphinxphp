**FSphinx** is a PHP port of the [fSphinx] [1] Python library, which extends the Sphinx API to easily perform faceted search.

What's faceted search?
----------------------

Think of "filtering", "refining" or "drilling down". For example, when searching through a database of movie titles, you could get a list of clickable refinement options such as actors, directors, genre and year of release. Unlike static hierarchical navigation, facets are calculated as you search so you always get options that are relevant to your current query terms.

How do I use this?
------------------

You can incorporate it into your project using [Composer] [2]. Create a `composer.json` file and run `composer install`:

    {
        "require": {
            "gigablah/fsphinxphp": "1.1.*"
        }
    }

This generates an autoloader with namespace mappings:

    require __DIR__ . '/vendor/autoload.php';
    $fsphinx = new FSphinx\FSphinxClient();
    $fsphinx->setServer('127.0.0.1', 9312);
    $fsphinx->setDefaultIndex('items');
    $fsphinx->setMatchMode(FSphinx\FSphinxClient::SPH_MATCH_EXTENDED2);
    $fsphinx->attachQueryParser(new FSphinx\MultiFieldQuery());
    $fsphinx->attachFacets(new FSphinx\Facet('actor'), new FSphinx\Facet('director'), new FSphinx\Facet('year'));
    $results = $fsphinx->query('action');
    foreach ($results['facets'] as $facet) print_r($facet);

If you're not using Composer, you can use `fsphinxapi.php` to load the FSphinx classes.

To learn more, please refer to the [tutorial] [3] or the [documentation] [4].

Requirements
------------

* PHP 5.3+ (namespaces, anonymous functions)
* Sphinx 1.10+ (string attributes)

Author
------

[Chris Heng] [5] <hengkuanyen@gmail.com>

License
-------

Released under the GNU LGPL version 3. See the LICENSE file for more details.

Acknowledgements
----------------

This library is based off the excellent work of [Alex Ksikes] [6].

[1]: http://github.com/alexksikes/fSphinx
[2]: http://getcomposer.org/
[3]: http://github.com/gigablah/fsphinxphp/tree/master/docs/
[4]: http://gigablah.github.com/fsphinxphp/
[5]: http://kuanyen.net
[6]: http://github.com/alexksikes
