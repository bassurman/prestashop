<?php
class BaseBmFront extends ModuleFrontController
{
    protected $configHelper;

    public function __construct()
    {
        $this->configHelper = new BmConfigHelper();
        parent::__construct();
    }
}