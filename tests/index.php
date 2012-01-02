<?php

/** Note: This file requires PHPUnit_Html. If not installed, use phpunit from command line. */

$config['phpunit_html'] = null; // Path to PHPUnit_Html or null if include path 
$config['phpunit'] = null; // Path to PHPUnit or null if include path (ie. /usr/local/php/PEAR/PHPUnit/)
$config['template'] = 'bootstrap';
$config['test'] = null;
$config['testFile'] = null;
$config['configuration'] = 'phpunit.xml';
$config['coverageClover'] = null;
$config['reportDirectory'] = (is_dir('./reports') ? './reports' : null);
$config['filter'] = null;
$config['groups'] = null;
$config['excludeGroups'] = null;
$config['bootstrap'] = 'bootstrap.php';

require($config['phpunit_html'].'PHPUnit_Html/src/main.php');

?>