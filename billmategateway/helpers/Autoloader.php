<?php

function billmateGatewayAutoloader($class)
{
    $module_dir = _PS_MODULE_DIR_ . 'billmategateway/';
    $classDirectories = array(
        'library/',
        'settings/',
        'helpers/',
        'classes/',
        'methods/',
        'controllers/front/',
    );

    foreach ($classDirectories as $dir) {
        $classFile = $module_dir . $dir . $class . '.php';
        if (file_exists($classFile)) {
            require_once $classFile;
            return;
        }
    }
}

spl_autoload_register('billmateGatewayAutoloader');
