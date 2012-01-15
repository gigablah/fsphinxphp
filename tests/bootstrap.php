<?php

require(dirname(__DIR__) . '/lib/sphinxapi.php');
require(dirname(__DIR__) . '/src/fsphinxapi.php');

// Sphinx connection parameters
define('SPHINX_HOST', '127.0.0.1');
define('SPHINX_PORT', 9312);

// Redis connection parameters
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);

// Memcached connection parameters
define('MEMCACHE_HOST', '127.0.0.1');
define('MEMCACHE_PORT', 11211);

$_ENV['APPLICATION_ENV'] = 'test';