This document is mostly based on the original [fSphinx tutorial] [1], although there are some differences in library structure and functionality.

Users are expected to be familiar with [Sphinx] [2]. If you're new to Sphinx search, you might want to check out the latest [documentation] [3] first.

Setting up the index
--------------------

This tutorial uses a scrape of the top 400 movies found on IMDb. First, get the MySQL database and Sphinx searchd up and running:

    CREATE DATABASE IF NOT EXISTS test DEFAULT CHARACTER SET utf8;
    USE test;
    SOURCE /path/to/tests/FSphinx/Tests/Fixtures/imdbtop400.sql

Create the Sphinx indexes and serve them:

    indexer -c /path/to/tests/FSphinx/Tests/Fixtures/sphinx.conf --all
    searchd -c /path/to/tests/FSphinx/Tests/Fixtures/sphinx.conf

Install dependencies (namely the Sphinx PHP client) using Composer:

    curl -s https://getcomposer.org/installer | php
    php composer.phar install

To verify that the index works:

    <?php
    require(__DIR__ . '/vendor/autoload.php');
    $sphinx = new Sphinx\SphinxClient();
    $sphinx->setServer('127.0.0.1', 9312);
    $results = $sphinx->query('drama', 'items');
    var_dump($results);

Configuring facets and data sources
-----------------------------------

Every facet in FSphinx must be declared as an attribute in the Sphinx index, whether as a single-value or multi-value attribute (MVA). A sample configuration can be found in `sphinx.conf`. To enable the `director` facet, the index definition must have the following lines:

    sql_attr_multi = uint director_attr from query; \
                     select imdb_id, imdb_director_id from directors

Due to a limitation of Sphinx, multi-value attributes can only contain integers. This isn't a problem if the facet comprises numerical value terms in the first place, such as `year`. For facets such as `actor` and `director` though, a separate lookup must be done to resolve IDs into terms. FSphinx offers a way to do this during the computation process by attaching a data source to each individual facet.

A data source can be any object that implements `DataSourceInterface`. Currently this includes `Facet` and `FSphinxClient`. To use a facet as its own data source, an additional field must be added to the Sphinx index to store a serialized list of IDs and terms. For example:

    sql_query = select ... , \
        (select group_concat(distinct concat(imdb_actor_id, ',', actor_name)) \
        from casts as c2 where c2.imdb_id = t.imdb_id) as actor_terms_attr, \
        ... from titles as t
    sql_attr_string = actor_terms_attr

Then, to identify `actor_terms_attr` as the lookup field:

    $facet = new FSphinx\Facet('actor');
    $facet->attachDataSource($facet, array('name' => 'actor_terms_attr'));

Alternatively, a separate index can be used to serve as a lookup table. In `sphinx.conf`, there is an `actor_terms` index which provides the actor ID as an integer attribute and the name as a string attribute. Thus the code becomes:

    $facet->attachDataSource($fsphinx, array('name' => 'actor_terms'));

The first method is preferable since it avoids additional calls to Sphinx. Nevertheless, one could eschew data sources and simply perform a database query with all the IDs returned from a facet computation.

Computing facets
----------------

Creating a facet is easy:

    $facet = new FSphinx\Facet('actor');
    $facet->attachSphinxClient($fsphinx); // a Sphinx client is needed to perform the computation
    $facet->setMaxNumValues(5); // limit the number of facet values to 5

Here we've created a new facet named `actor` with default values assumed, since we didn't pass in additional configuration parameters. Hence Sphinx will group by `actor_attr`. The number of facet values is also limited to 5 (down from the default of 15).

Now we can proceed to compute the facet:

    $results = $facet->compute('drama');

Note that when you're computing facets for a particular query, you're essentially performing the same query repeatedly but grouping by a different facet attribute each time. (We'll address the performance considerations later in the tutorial). All facet values have the following elements:

* @groupby: numerical ID of the facet value indexed by Sphinx
* @term: defaults to the numerical ID, unless a data source is attached (see below)
* @count: number of times this facet term appears in the query
* @groupfunc: value of a custom grouping function (see below)
* @selected: whether this term is selected or "active" (see next section on multi-field queries)

Just output the facet object to check the results:

    echo $facet;
    
    actor: (5/3563 values group sorted by "@count desc" in 0.001 sec.)
        1. 134, @count=9, @groupby=134, @groupfunc=9, @selected=False
        2. 199, @count=7, @groupby=199, @groupfunc=7, @selected=False
        3. 151, @count=6, @groupby=151, @groupfunc=6, @selected=False
        4. 702798, @count=6, @groupby=702798, @groupfunc=6, @selected=False
        5. 380, @count=6, @groupby=380, @groupfunc=6, @selected=False

By default, facets are grouped by ID and sorted by decreasing order of occurrence. Let's change the group sorting function to a custom one that models popularity:

    $facet->setGroupFunc('sum(user_rating_attr * nb_votes_attr)');

You can pass in any Sphinx [expression] [4] wrapped by an aggregate function such as `avg` `min` `max` or `sum`. Let's additionally order the results by the value of the above expression:

    $facet->setOrderBy('@groupfunc', 'desc');

And since we want to map the numerical IDs to the actual terms, we attach `FSphinxClient` as a data source:

    $facet->attachDataSource($fsphinx, array('name' => 'actor_terms'));
    $results = $facet->compute('drama');

Now we get a different resultset with names instead of numbers.

    echo $facet;
    
    actor: (5/3563 values group sorted by "@groupfunc desc" in 0.002 sec.)
        1. Morgan Freeman, @count=6, @groupby=151, @groupfunc=1218292.125, @selected=False
        2. Robert De Niro, @count=9, @groupby=134, @groupfunc=933700.375, @selected=False
        3. Al Pacino, @count=7, @groupby=199, @groupfunc=868737, @selected=False
        4. Robert Duvall, @count=6, @groupby=380, @groupfunc=800953.3125, @selected=False
        5. John Cazale, @count=5, @groupby=1030, @groupfunc=676553.75, @selected=False

Multi-field queries
-------------------

A crucial aspect of faceted search is allowing the user to refine by the facet values shown. Each selected value is represented internally as a match against a specific index attribute in addition to the general query terms originally entered by the user.

There are two ways of performing attribute matching with Sphinx. First is extended query syntax using the `@` field search operator. To search for movies containing "drama" in any field, "Harrison Ford" in actors and "1974" in year of release, the query would look like:

    $sphinx->query('(@* drama) (@actors "Harrison Ford") (@year 1974)');

Another way is to explicitly filter by attribute ID. The above query would be written as:

    $sphinx->setFilter('actor_attr', array(148)); // ID for "Harrison Ford"
    $sphinx->setFilter('year_attr', array(1974));
    $sphinx->query('drama');

The advantage of using the first method is that users get the option to build their own faceted queries without having to know the ID values for each term. However, the second method eliminates any ambiguity especially if multiple terms share the same value (there may be different actors with the same name). FSphinx supports both methods seamlessly through the use of a multi-field query object which parses query strings into individual terms.

The following code creates a query object that maps a user search against `actor` or `genre` to a Sphinx search matching against the (text) fields `actors` or `genres` respectively:

    $query = new FSphinx\MultiFieldQuery(array('actor' => 'actors', 'genre' => 'genres'));

Let's parse a query string:

    $query->parse('@year 1974 @genre drama @actor harrison ford');

The query object does a pattern match and separates `year`, `genre` and `actor` into individual query terms. Printing the query displays the user representation:

    echo $query;                     // (@year 1974) (@genre drama) (@actor harrison ford)

There's also the Sphinx query representation, which uses the mappings we defined earlier. Note that values with spaces are automatically wrapped with double quotes:

    echo $query->toSphinx();         // (@year 1974) (@genres drama) (@actors "harrison ford")

A user may wish to quickly toggle individual query terms on and off. This can be easily done:

    $query->toggleOff('@year 1974'); // will be removed from the Sphinx representation
    echo $query;                     // (@-year 1974) (@genre drama) (@actor harrison ford)
    echo $query->toSphinx();         // (@genres drama) (@actors "harrison ford")

To check if a query term is present in a query object:

    assert($query->hasQueryTerm('@year 1974'));

For caching purposes, a unique or canonical representation is built by ordering the query terms in alphabetical order:

    echo $query->toCanonical();      // (@actors "harrison ford") (@genres drama) (@year 1974)

Finally, we can pass a query object into a facet computation just like a regular query string. Note that the Sphinx client must be set to extended match mode:

    $fsphinx->setMatchMode(Sphinx\SphinxClient::SPH_MATCH_EXTENDED);
    $facet->compute($query);
    echo $facet;
    
    actor: (5/25 values group sorted by "@groupfunc desc" in 0.001 sec.)
        1. Frederic Forrest, @count=2, @groupby=2078, @groupfunc=161016.6875, @selected=False
        2. Harrison Ford, @count=2, @groupby=148, @groupfunc=161016.6875, @selected=True
        3. James Keane, @count=1, @groupby=443856, @groupfunc=137119.265625, @selected=False
        4. Kerry Rossall, @count=1, @groupby=743953, @groupfunc=137119.265625, @selected=False
        5. Jerry Ziesmer, @count=1, @groupby=956310, @groupfunc=137119.265625, @selected=False
        6. G.D. Spradlin, @count=1, @groupby=819525, @groupfunc=137119.265625, @selected=False

We can see that the "Harrison Ford" term has been properly marked as selected.

By default, computations are done using string field matching. To switch to ID filtering mode:

    $fsphinx->setFiltering(true);
    $query->parse('@actor 148 @genre 8'); // IDs for "Harrison Ford" and "Drama" respectively
    $results = $facet->compute($query);

The facet values returned are the same as before.

Performance, caching and facet groups
-------------------------------------

Most of the time we'd want to refine searches by multiple facets. However, sending the same query to Sphinx with different grouping parameters for each facet would be rather inefficient. Luckily, Sphinx provides pretty good multi-query optimization via the use of batched queries. Furthermore, we'd like to avoid calls to Sphinx altogether by making sure that the facet computations are cached whenever possible.

Let's start with facets for `year` and `actor`:

    $facet_year = new FSphinx\Facet('year');
    $facet_actor = new FSphinx\Facet('actor');

Now we introduce the `FacetGroup`, which builds batch queries encompassing all of its individual members:

    $facets = new FSphinx\FacetGroup($facet_year, $facet_actor);
    $facets->attachSphinxClient($fsphinx);
    $results = $facets->compute('drama', false); // second parameter explicitly turns caching on or off

If we were to print this group of facets, we'd get the same results as if each facet had been computed independently. Note that each component facet can be set up differently, say we'd like to group sort by count on `year` but by popularity on `actor`.

Since facet computation can be expensive, we'd like to make sure that we don't perform the same computation more than once. Let's enable caching by attaching a `FacetGroupCache` with an adapter of choice:

    $cache = new FSphinx\FacetGroupCache(new FSphinx\CacheApc()); // Memcache and Redis also supported
    $facets->attachCache($cache);
    $facets->setCaching(true);

Computation results are now retrieved from the cache whenever possible:

    $facets->compute('drama');
    $facets->compute('drama');
    assert($facets->getTime() == -1); // -1 indicates a cache hit
    $results = $facets->compute('drama', false); // force computation
    assert($facets->getTime() >= 0);

We can also opt to preload the facet results beforehand:

    $facets->setPreloading(true);
    $facets->setCaching(false);
    $facets->preload('drama');
    $results = $facets->compute('drama');
    assert($facets->getTime() == -1);

Putting everything together
---------------------------

FSphinx extends the Sphinx API, so you can use `FSphinxClient` in place of a normal `SphinxClient`.

Create the client:

    $fsphinx = new FSphinx\FSphinxClient();
    $fsphinx->setServer('127.0.0.1', 9312); // configure it like you would a normal Sphinx client
    $fsphinx->setDefaultIndex('items'); // now you don't have to specify the index for each query

Attach the facets from before (this creates a `FacetGroup` internally):

    $fsphinx->attachFacets($facet_year, $facet_actor);

And finally we run the query:

    $results = $fsphinx->query('movie');
    $results = $fsphinx->query($query); // it also accepts a MultiFieldQuery object

The results array now contains a `facets` entry, which holds the attributes and computed values for each attached facet.

Loading configuration files
---------------------------

Since facet and data source configuration adds quite a bit of boilerplate code, you can opt to do all the initialization in a separate config file which returns an `FSphinxClient` object. Take a look at `config.sample.php` in the source folder. To use it:

    $fsphinx = FSphinx\FSphinxClient::fromConfig('/path/to/tests/FSphinx/Tests/Fixtures/config.sample.php');
    $results = $fsphinx->query('movie');

User interface
--------------

For an example of front-end presentation, check out the demo (still in progress).

[1]: http://github.com/alexksikes/fSphinx/tree/master/tutorial
[2]: http://sphinxsearch.com
[3]: http://sphinxsearch.com/docs/current.html
[4]: http://sphinxsearch.com/docs/current.html#expressions
