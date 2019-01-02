<?php
class BaseBmFront extends ModuleFrontControllerCore
{
    /**
     * @var BmConfigHelper
     */
    protected $configHelper;

    public function __construct()
    {
        $this->configHelper = new BmConfigHelper();
        parent::__construct();
    }

    /**
     * @param $testMode
     *
     * @return BillMate
     */
    public function getBillmateConnection($testMode = false)
    {
        return $this->configHelper->getBillmateConnection($testMode);
    }
}