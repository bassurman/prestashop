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

class BillmatePayment
{
    /**
     * @var Context
     */
    public $context;

    /**
     * @var BmConfigHelper
     */
    protected $configHelper;

    public function __construct()
    {
        $this->context = Context::getContext();
        $this->configHelper = new BmConfigHelper();
    }

    public function getActiveMethods()
    {
        $cart = $this->getCart();
        $activeMethods = array();

        $paymentMethodsAvailable = $this->getAvailableMethods();
        $paymentsMethods = $this->configHelper->getPaymentModules();
        foreach ($paymentsMethods as $paymentName => $className) {

            if(!class_exists($className)) {
                continue;
            }

            $method = new $className();

            if (!in_array(strtolower($method->remote_name),$paymentMethodsAvailable)) {
                continue;
            }

            $result = $method->getPaymentInfo($cart);

            if (!$result) {
                continue;
            }

            if ($result['sort_order']) {
                if (array_key_exists($result['sort_order'], $activeMethods)) {
                    $activeMethods[$result['sort_order'] + 1] = $result;
                } else {
                    $activeMethods[$result['sort_order']] = $result;
                }
            } else {
                $activeMethods[] = $result;
            }
        }
        ksort($activeMethods);
        return $activeMethods;
    }

    public function getMethodOptions()
    {
        $cart = $this->getCart();
        $data = array();

        $paymentMethodsAvailable = $this->getAvailableMethods();
        $paymentModules = $this->configHelper->getPaymentModules();
        foreach ($paymentModules as $methodCode => $className) {

            if (!class_exists($className)) {
                continue;
            }
            $method = new $className();

            if(!in_array(strtolower($method->remote_name),$paymentMethodsAvailable)) {
                continue;
            }


            $result = $method->getPaymentInfo($cart);

            if (!$result) {
                continue;
            }

            $newOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            try{
                $this->smarty->assign($result);
                $this->smarty->assign(array('eid' => Configuration::get('BILLMATE_ID')));
                $this->smarty->escape_html = false;
                $newOption->setModuleName($this->name)
                    ->setCallToActionText($result['name'])
                    ->setAction($result['controller'])
                    ->setLogo($this->context->link->getBaseLink().'/modules/'.$result['icon'])
                    ->setAdditionalInformation($this->fetch('module:billmategateway/views/templates/front/'.$result['type'].'.tpl'));

            } catch(Exception $e){
                die($e->getMessage()."\r\n".$e->getTraceAsString());
            }
            if ($result['sort_order']) {
                if (array_key_exists($result['sort_order'], $data)) {
                    $data[$result['sort_order'] + 1] = $newOption;
                } else {
                    $data[$result['sort_order']] = $newOption;
                }
            } else {
                $data[] = $newOption;
            }
        }
        ksort($data);
        return $data;
    }

    /**
     * @return CartCore
     */
    public function getCart()
    {
        return $this->context->cart;
    }

    /**
     * @return array
     */
    public function getAvailableMethods()
    {
        return $this->configHelper->getAvailableMethods();
    }
}