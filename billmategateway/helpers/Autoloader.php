<?php

function billmateGatewayAutoloader($class)
{
    $module_dir = _PS_MODULE_DIR_ . 'billmategateway/';

    $classFile = $module_dir . 'library/' . $class . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
        return;
    }

    $classFile = $module_dir . 'settings/' . $class . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
        return;
    }

    $classFile = $module_dir . 'helpers/' . $class . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
        return;
    }
}

spl_autoload_register('billmateGatewayAutoloader');
