<?php

/** Simple autoloader */

namespace FSphinx;

function autoload($class)
{
    $file = dirname(__FILE__) . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (file_exists($file)) {
        require($file);
        return true;
    }
    return false;
}

spl_autoload_register('\\FSphinx\\autoload');
