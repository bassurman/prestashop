<?php
/**
 * 2018 BillmateCheckout Sweden AB.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to prestashop@billmate.se so we can send you a copy immediately.
 *
 *  @author    Billmate Checkout <prestashop@billmate.se>
 *  @copyright 2018 Billmate Checkout
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of Billmate Payment AB
 */

class BaseBmFront extends ModuleFrontControllerCore
{

    const P_CLASS_PREFIX = 'BillmateMethod';
    /**
     * @var BmConfigHelper
     */
    protected $configHelper;

    public function __construct()
    {
        $this->configHelper = new BmConfigHelper();
        $this->method = Tools::getValue('method');
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

    /**
     * @return BillmateGateway
     */
    public function getPaymentMethodClass()
    {
        $paymentMethodClassName = self::P_CLASS_PREFIX . Tools::ucfirst($this->method);
        return new $paymentMethodClassName;
    }


    /**
     * @return $this
     */
    public function defineProperties()
    {
        $this->paymentMethod = $this->getPaymentMethodClass();
        $this->coremodule = new BillmateGateway();
        $this->billmate = $this->getBillmateConnection($this->paymentMethod->testMode);
        return $this;
    }

    protected function convertToUtf($value)
    {
        return mb_convert_encoding($value,'UTF-8','auto');
    }
}