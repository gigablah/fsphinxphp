<?php

if (!is_file($autoloader = dirname(__DIR__) . '/vendor/autoload.php')) {
    throw new \RuntimeException('Run "composer install --dev" to create the autoloader.');
}

$loader = require $autoloader;
$loader->add('FSphinx\\Tests', __DIR__);

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
