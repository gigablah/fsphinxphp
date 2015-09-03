<?php

namespace FSphinx;

/**
 * @brief       Interface for data sources that provide attribute data.
 * @author      Chris Heng <hengkuanyen@gmail.com>
 * @author      Based on the fSphinx Python library by Alex Ksikes <alex.ksikes@gmail.com>
 */
interface DataSourceInterface
{
    /**
    * Fetches attribute terms based on the results of a Facet computation.
    *
    * @param array $matches Array of values to substitute into the query.
    * @param array $options Array of options consisting of identifiers for source, key and value.
    * @param Closure $getter Anonymous function that extracts the attribute ID from a result array.
    * @return array ID-term pairs.
    */
    public function fetchTerms(array $matches, array $options, \Closure $getter);
}
