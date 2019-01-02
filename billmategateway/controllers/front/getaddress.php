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

class BillmategatewayGetaddressModuleFrontController extends BaseBmFront
{
    /**
     * @var bool
     */
    public $ajax = true;

    /**
     * @var bool
     */
    public $ssl = true;

    /**
     * @var
     */
    protected $pno;

    /**
     * @var
     */
    protected $billmate;

    public function postProcess()
    {
        $response = array();

        if (!defined('BILLMATE_LANGUAGE')) {
            define('BILLMATE_LANGUAGE', $this->context->language->iso_code);
        }

        $billmate = $this->getBillmateConnection();
        $this->pno = Tools::getValue('pno');
        $this->context->cookie->billmatepno = $this->pno;

        $address = $billmate->getAddress(array('pno' => $this->pno));
        if (!isset($address['code'])) {
            $response['success'] = true;
            $encoded_address = array();
            foreach ($address as $key => $row) {
                $encoded_address[$key] = mb_convert_encoding($row,'UTF-8','auto');
            }

            $country_id = Country::getIdByName($this->context->language->id, $encoded_address['country']);
            $encoded_address['id_country'] = $country_id;
            $response['data'] = $encoded_address;

        } else {
            $response['success'] = false;
            $response['message'] = mb_convert_encoding($address['message'], 'UTF-8', 'auto');
        }
        die(Tools::jsonEncode($response));
    }
}