This document is mostly based on the original [fSphinx tutorial] [1], although there are some differences in library structure and functionality.

Users are expected to be familiar with [Sphinx] [2]. If you're new to Sphinx search, you might want to check out the latest [documentation] [3] first.

Setting up the index
--------------------

This tutorial uses a scrape of the top 400 movies found on IMDb. First, get the MySQL database and Sphinx searchd up and running:

    CREATE DATABASE fsphinx CHARACTER SET utf8;
    GRANT ALL PRIVILEGES ON fsphinx.* TO 'fsphinx'@'localhost' IDENTIFIED BY 'fsphinx';
    USE fsphinx;
    SOURCE /path/to/imdb_top400.data.sql

Create the Sphinx indexes and serve them:

    /path/to/indexer -c /path/to/sphinx.conf --all
    /path/to/searchd -c /path/to/sphinx.conf

To verify that the index works:

    <?php
    require(dirname(__DIR__) . '/lib/sphinxapi.php');
    $sphinx = new SphinxClient();
    $sphinx->SetServer('127.0.0.1', 9312);
    $results = $sphinx->Query('drama', 'items');
    var_dump($results);

Configuring facets and data sources
-----------------------------------

Every facet in FSphinx must be declared as an attribute in the Sphinx index, whether as a single-value or multi-value attribute (MVA). A sample configuration can be found in `sphinx.conf`. To enable the `director` facet, the index definition must have the following lines:

    sql_attr_multi = uint director_attr from query; \
                     select imdb_id, imdb_director_id from directors

Due to a limitation of Sphinx, multi-value attributes can only contain integers. This isn't a problem if the facet comprises numerical value terms in the first place, such as `year`. For facets such as `actor` and `director` though, a separate lookup must be done to resolve IDs into terms. FSphinx offers a way to do this during the computation process by attaching a data source to each individual facet.

A data source can be any object that implements `DataFetchInterface`. Currently this includes `Facet` and `FSphinxClient`. To use a facet as its own data source, an additional field must be added to the Sphinx index to store a serialized list of IDs and terms. For example:

    sql_query = select ... , \
        (select group_concat(distinct concat(imdb_actor_id, ',', actor_name)) \
        from casts as c2 where c2.imdb_id = t.imdb_id) as actor_terms_attr, \
        ... from titles as t
    sql_attr_string = actor_terms_attr

Then, to identify `actor_terms_attr` as the lookup field:

    $facet = new Facet('actor');
    $facet->AttachDataFetch($facet, array('name' => 'actor_terms_attr'));

Alternatively, a separate index can be used to serve as a lookup table. In `sphinx.conf`, there is an `actor_terms` index which provides the actor ID as an integer attribute and the name as a string attribute. Thus the code becomes:

    $facet->AttachDataFetch($fsphinx, array('name' => 'actor_terms'));

The first method is preferable since it avoids additional calls to Sphinx. Nevertheless, one could eschew data sources and simply perform a database query with all the IDs returned from a facet computation.

Computing facets
----------------

Creating a facet is easy:

    $facet = new Facet('actor');
    $facet->AttachSphinxClient($fsphinx); // a Sphinx client is needed to perform the computation
    $facet->SetMaxNumValues(5); // limit the number of facet values to 5

Here we've created a new facet named `actor` with default values assumed, since we didn't pass in additional configuration parameters. Hence Sphinx will group by `actor_attr`. The number of facet values is also limited to 5 (down from the default of 15).

Now we can proceed to compute the facet:

    $results = $facet->Compute('drama');

Note that when you're computing facets for a particular query, you're essentially performing the same query multiple times but grouping by a different facet attribute each time. All facet values have the following elements:

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

    $facet->SetGroupFunc('sum(user_rating_attr * nb_votes_attr)');

You can pass in any Sphinx [expression] [4] wrapped by an aggregate function such as `avg`, `min`, `max` or `sum`. Let's additionally order the results by the value of the above expression:

    $facet->SetOrderBy('@groupfunc', 'desc');

And since we want to map the numerical IDs to the actual terms, we attach `FSphinxClient` as a data source:

    $facet->AttachDataFetch($fsphinx, array('name' => 'actor_terms'));
    $results = $facet->Compute('drama');

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

Performance, caching and facet groups
-------------------------------------

Putting everything together
---------------------------

FSphinx extends the Sphinx API, so you can use `FSphinxClient` in place of a normal `SphinxClient`.

Creating the client:

    $fsphinx = new FSphinxClient();
    $fsphinx->SetServer('127.0.0.1', 9312); // configure it like you would a normal Sphinx client
    $fsphinx->SetDefaultIndex('items'); // now you don't have to specify the index for each query

Attach the facets from before:

    $fsphinx->AttachFacets($fyear, $fgenre);

And finally we run the query:

    $results = $fsphinx->Query('movie');
    $results = $fsphinx->Query($query); // it also accepts a MultiFieldQuery object

The `$results` array now contains a `facets` entry, which holds the attributes and computed values for each attached facet.

Loading configuration files
---------------------------

Since facet and data source configuration adds quite a bit of boilerplate code, you can opt to do all the initialization in a separate config file which returns an `FSphinxClient` object. Take a look at `config.sample.php` in the source folder. To use it:

    $fsphinx = FSphinxClient::FromConfig('/path/to/config.sample.php');
    $results = $fsphinx->Query('movie');

User interface
--------------

For an example of front-end presentation, check out the demo (still in progress).

[1]: http://github.com/alexksikes/fSphinx/tree/master/tutorial
[2]: http://sphinxsearch.com
[3]: http://sphinxsearch.com/docs/current.html
[4]: http://sphinxsearch.com/docs/current.html#expressions