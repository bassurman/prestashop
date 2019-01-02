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

class BillmategatewayCancelModuleFrontController extends BaseBmFront
{
    /**
     * @var bool
     */
    public $ssl = true;

    /**
     * @var bool
     */
    public $ajax = true;

    public function postProcess()
    {
        $this->billmate      = $this->getBillmateConnection();
        $_POST               = !empty($_POST) ? $_POST : $_GET;
        $data                = $this->billmate->verify_hash($_POST);
        $this->coremodule = new BillmateGateway();

        if(isset($data['code'])){
            $this->errors[] = $this->coremodule->l('Unfortunately your card payment was not processed with the provided card details. Please try again or choose another payment method.');
        }
        if(isset($data['status'])){
            switch(strtolower($data['status'])){
                case 'failed':
                    $this->errors[] = $this->coremodule->l('Unfortunately your card payment was not processed with the provided card details. Please try again or choose another payment method.');
                    break;
                case 'cancelled':
                    $this->errors[] = $this->coremodule->l('The card payment has been canceled before it was processed. Please try again or choose a different payment method.');
                    break;
            }
        }
        $order_url = $this->context->link->getPageLink('order.php', true);
        if(isset($_GET['type']) && $_GET['type'] == 'checkout')
            $order_url = $this->context->link->getModuleLink('billmatecheckout', 'billmatecheckout', array(), true);

        if (version_compare(_PS_VERSION_,'1.7','>=')) {
            $this->redirectWithNotifications($order_url);
        } else {
            Tools::redirectLink($order_url);
        }
    }
}