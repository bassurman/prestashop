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

class BillmategatewayBillmateapiModuleFrontController extends BaseBmFront
{

    protected $method;
    public $module;
    /**
     * The total sum for costs
     * @var $totals
     */
    protected $totals = 0;
    /** @var int $paid_amount for use with Billmate Invoice to sett correct amount */
    protected $paid_amount = 0;
    /**
     * The total tax amount
     * @var $tax
     */
    protected $tax = 0;

    /** @var array with the format array('taxrate' => 'totalamount') */
    protected $prepare_discount = array();
    /** @var  pno | The personal number if invoice or Partpay */
    protected $pno;
    protected $billmate;
    protected $coremodule;
    protected $handling_fee = false;
    protected $handling_taxrate = false;
    protected $invoiceservice = false;

    public function postProcess()
    {
        $this->definePluginConstants();
        $this->defineProperties();
        $this->pno = $this->getPno();
        /**
         * @var $requestData PaymentData
         */
        $requestData = array();

        switch ($this->method) {
            case 'invoice':
            case 'partpay':
            case 'invoiceservice':
                if(Tools::getIsset('invoice_address') && class_exists('BillmateMethodInvoiceservice')) {
                    $this->invoiceservice = true;
                }
                if(Tools::getValue('geturl') == 'yes') {
                    $this->checkAddress();
                }
                $requestData = $this->prepareInvoice($this->method);
                break;
            case 'bankpay':
            case 'cardpay':
                $requestData = $this->prepareDirect($this->method);
                break;
        }

        // Populate Data with the Customer Data and Cart stuff
        $requestData['Customer'] = $this->prepareCustomer();
        $requestData['Articles'] = $this->prepareArticles();
        $discounts = $this->prepareDiscounts();

        if (count($discounts) > 0) {
            foreach ($discounts as $discount) {
                array_push($requestData['Articles'], $discount);
            }
        }
        $requestData['Cart'] = $this->prepareTotals();

        if ($this->configHelper->isEnabledBMMessage()) {
            $cartMessages = $this->getCartMessages();
            if ($cartMessages) {
                $requestData['Articles'] = array_merge($requestData['Articles'], $cartMessages);
            }
        }

        $result = $this->billmate->addPayment($requestData);

        $this->sendResponse($result);
    }

    /**
     * Returns the Customer object
     * @return array
     */
    public function prepareCustomer()
    {
        $customer             = array();
        $customer['nr']       = $this->context->cart->id_customer;
        $customer['pno']      = ($this->method == 'invoice' || $this->method == 'partpay' || $this->method == 'invoiceservice') ? $this->pno : '';
        $billing_address       = new Address($this->context->cart->id_address_invoice);
        $shipping_address      = new Address($this->context->cart->id_address_delivery);

        $customer['Billing']  = array(
            'firstname' => $this->convertToUtf($billing_address->firstname),
            'lastname'  => $this->convertToUtf($billing_address->lastname),
            'company'   => $this->convertToUtf($billing_address->company),
            'street'    => $this->convertToUtf($billing_address->address1),
            'street2'   => $this->convertToUtf($billing_address->address2),
            'zip'       => $this->convertToUtf($billing_address->postcode),
            'city'      => $this->convertToUtf($billing_address->city),
            'country'   => $this->convertToUtf(Country::getIsoById($billing_address->id_country)),
            'phone'     => $this->convertToUtf($billing_address->phone),
            'email'     => $this->convertToUtf($this->context->customer->email)
        );
        $customer['Shipping'] = array(
            'firstname' => $this->convertToUtf($shipping_address->firstname),
            'lastname'  => $this->convertToUtf($shipping_address->lastname),
            'company'   => $this->convertToUtf($shipping_address->company),
            'street'    => $this->convertToUtf($shipping_address->address1),
            'street2'   => $this->convertToUtf($shipping_address->address2),
            'zip'       => $this->convertToUtf($shipping_address->postcode),
            'city'      => $this->convertToUtf($shipping_address->city),
            'country'   => $this->convertToUtf(Country::getIsoById($shipping_address->id_country)),
            'phone'     => $this->convertToUtf($shipping_address->phone),
        );

        return $customer;
    }

    /**
     * Returns the Articles object and sets the totals of the Articles
     * @return array
     */
    public function prepareArticles()
    {
        $articles_arr = array();
        $articles    = $this->context->cart->getProducts();
        foreach ($articles as $article) {
            $taxrate = ($article['price_wt'] == $article['price']) ? 0 : $article['rate'];
            $roundedArticle = round($article['price'], 2);
            $totalArticle = ($roundedArticle * $article['cart_quantity']) * 100;
            $articles_arr[] = array(
                'quantity'   => $article['cart_quantity'],
                'title'      => (isset($article['attributes']) && !empty($article['attributes'])) ? $article['name'].  ' - '.$article['attributes'] : $article['name'],
                'artnr'      => $article['reference'],
                'aprice'     => $roundedArticle * 100,
                'taxrate'    => $taxrate,
                'discount'   => 0,
                'withouttax' => ($roundedArticle * $article['cart_quantity']) * 100

            );
            if (!isset($this->prepare_discount[$taxrate])) {
                $this->prepare_discount[$taxrate] = $totalArticle;
            } else {
                $this->prepare_discount[$taxrate] += $totalArticle;
            }

            $this->totals += $totalArticle;
            $this->tax += round($totalArticle * ($taxrate / 100));

        }

        return $articles_arr;
    }

    public function prepareDiscounts()
    {
        if (!isset($this->coremodule) || !is_object($this->coremodule)) {
            $this->coremodule = new BillmateGateway();
        }
        $details = $this->context->cart->getSummaryDetails(null, true);
        $cartRules = $this->context->cart->getCartRules();
        $title = '';
        if (count($cartRules) > 0) {
            foreach ($cartRules as $cartRule) {
                $title .= $cartRule['name'].' ';
            }
        }

        $totalTemp = $this->totals;
        $discounts = array();
        if (!empty($details['total_discounts'])) {
            foreach ($this->prepare_discount as $key => $value) {

                $percent_discount = $value / ($totalTemp);

                $discount_value = $percent_discount * $details['total_discounts'];
                $discount_amount = round($discount_value / (1 + ($key / 100)),2);

                $discounts[]    = array(
                    'quantity'   => 1,
                    'artnr'      => 'discount-'.$key,
                    'title'      => $title.sprintf($this->coremodule->l('Discount %s%% VAT'), $key),
                    'aprice'     => -($discount_amount * 100),
                    'taxrate'    => $key,
                    'discount'   => 0,
                    'withouttax' => -$discount_amount * 100
                );

                $this->totals -= $discount_amount * 100;
                $this->tax -= $discount_amount * ($key / 100) * 100;

            }

        }

        if (!empty($details['gift_products'])) {
            foreach ($details['gift_products'] as $gift) {
                $discount_amount = 0;
                $taxrate        = 0;
                foreach ($this->context->cart->getProducts() as $product) {
                    $taxrate        = ($product['price_wt'] == $product['price']) ? 0 : $product['rate'];
                    $discount_amount = $product['price'];
                }
                $price          = $gift['price'] / $gift['cart_quantity'];
                $discount_amount = round($discount_amount / $gift['cart_quantity'],2);
                $total          = -($discount_amount * $gift['cart_quantity'] * 100);
                $discounts[]    = array(
                    'quantity'   => $gift['cart_quantity'],
                    'artnr'      => $this->coremodule->l('Discount'),
                    'title'      => $gift['name'],
                    'aprice'     => $price - round($discount_amount * 100, 0),
                    'taxrate'    => $taxrate,
                    'discount'   => 0,
                    'withouttax' => $total
                );

                $this->totals += $total;
                $this->tax += $total * ($taxrate / 100);
            }
        }

        return $discounts;
    }

    /**
     * Returns the Cart Object with Totals for Handling, Shipping and Total
     * @return array
     */
    public function prepareTotals()
    {
        $totals     = array();
        $order_total = $this->context->cart->getOrderTotal();

        /** Shipping */
        $shipping = $this->getCartShipping();
        if ($shipping['withouttax'] > 0) {
            $totals['Shipping'] = array(
                'withouttax'    => $shipping['withouttax'],
                'taxrate'       => $shipping['taxrate']
            );

            $this->totals       += $shipping['withouttax'];
            $this->tax          += $shipping['tax'];
        }

        if (Configuration::get('BINVOICE_FEE') > 0 && $this->method == 'invoice') {
            $fee           = Configuration::get('BINVOICE_FEE');
            $invoice_fee_tax = Configuration::get('BINVOICE_FEE_TAX');

            $tax                = new Tax($invoice_fee_tax);
            $tax_calculator      = new TaxCalculator(array($tax));
            $tax_rate            = $tax_calculator->getTotalRate();
            $fee = Tools::convertPriceFull($fee,null,$this->context->currency);
            $fee = round($fee,2);
            $totals['Handling'] = array(
                'withouttax' => $fee * 100,
                'taxrate'    => $tax_rate
            );
            $this->handling_fee = $fee;
            $this->handling_taxrate = $tax_rate;
            $order_total += $fee * (1 + ($tax_rate / 100));
            $this->totals += $fee * 100;
            $this->tax += (($tax_rate / 100) * $fee) * 100;
        }

        if (Configuration::get('BINVOICESERVICE_FEE') > 0 && $this->method == 'invoiceservice') {
            $fee           = Configuration::get('BINVOICESERVICE_FEE');
            $invoice_fee_tax = Configuration::get('BINVOICESERVICE_FEE_TAX');

            $tax                = new Tax($invoice_fee_tax);
            $tax_calculator      = new TaxCalculator(array($tax));
            $tax_rate            = $tax_calculator->getTotalRate();
            $fee = Tools::convertPriceFull($fee,null,$this->context->currency);
            $fee = round($fee,2);
            $totals['Handling'] = array(
                'withouttax' => $fee * 100,
                'taxrate'    => $tax_rate
            );
            $this->handling_fee = $fee;
            $this->handling_taxrate = $tax_rate;
            $order_total += $fee * (1 + ($tax_rate / 100));
            $this->totals += $fee * 100;
            $this->tax += (($tax_rate / 100) * $fee) * 100;
        }

        $rounding         = round($order_total * 100) - round($this->tax + $this->totals);
        $totals['Total']  = array(
            'withouttax' => round($this->totals),
            'tax'        => round($this->tax),
            'rounding'   => round($rounding),
            'withtax'    => round($this->totals + $this->tax + $rounding)
        );
        $this->paid_amount = $totals['Total']['withtax'];

        return $totals;
    }

    /**
     * @return array with withouttax, tax, taxrate
     */
    public function getCartShipping()
    {
        $details    = $this->context->cart->getSummaryDetails(null, true);
        $carrier    = $details['carrier'];
        $notfree    = !(isset($details['free_ship']) && $details['free_ship'] == 1);

        $total_shipping_cost  = round($this->context->cart->getTotalShippingCost(null, false),2);

        $withouttax     = 0;
        $taxrate        = 0;
        $tax            = 0;

        if (    method_exists($this->context->cart, 'isMultiAddressDelivery')
                && $this->context->cart->isMultiAddressDelivery() == true
        ) {
            /** Multiple shipping addresses, get highest shipping fee taxrate */
            $_cart_carrier_ids = $this->context->cart->getDeliveryOption();
            foreach ($_cart_carrier_ids AS $_address_id => $_cart_carrier_id) {
                $_carrier = new Carrier($_cart_carrier_id, $this->context->cart->id_lang);
                if ($_carrier->id > 0) {
                    $_address = new Address($_address_id);
                    $_taxrate = $_carrier->getTaxesRate($_address);
                    $taxrate = ($_taxrate >= $taxrate) ? $_taxrate : $taxrate;
                }
            }
        } else {
            /** Single shipping address */
            if ($carrier->active && $notfree) {
                $carrier_obj    = new Carrier($this->context->cart->id_carrier, $this->context->cart->id_lang);
                $_address       = new Address($this->context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')});
                $taxrate        = $carrier_obj->getTaxesRate($_address);
            }
        }

        // Try get shipping taxrate one more time if not already found
        if ($taxrate == 0) {
            $total_shipping_cost_with_tax  = round($this->context->cart->getTotalShippingCost(null, true),2);
            if ($total_shipping_cost_with_tax > $total_shipping_cost) {
                $tax = $total_shipping_cost_with_tax - $total_shipping_cost;
                $taxrate = round(($tax / $total_shipping_cost) * 100);
            }
        }

        if ($total_shipping_cost > 0) {
            $withouttax = $total_shipping_cost * 100;
            if ($taxrate > 0) {
                $tax = ($withouttax * ($taxrate / 100));
            }
        }

        return array(
            'withouttax'    => $withouttax,
            'taxrate'       => $taxrate,
            'tax'           => $tax
        );
    }

    /**
     * Check if the address is matched with our Api
     * @return true|Array
     */
    public function checkAddress()
    {
        $address = $this->getAddressFromService();
        if (isset($address['code'])) {
            $return = array('success' => false, 'content' => utf8_encode($address['message']));
            die(Tools::jsonEncode($return));
        }

        $address = $this->convertAddress($address);
        foreach ($address as $key => $value) {
            $address[$key] = $this->convertToUtf($value);
        }

        $isMatched = $this->isAddressMatched($address);
        if ($isMatched) {
            return true;
        }

        if (Tools::getValue('geturl') == 'yes') {

            $carrier_id   = $this->context->cart->id_carrier;

            $carrier = new Carrier($carrier_id,$this->context->cart->id_lang);

            $customer_addresses = $this->context->customer->getAddresses($this->context->language->id);

            if (count($customer_addresses) == 1) {
                $customer_addresses[] = $customer_addresses;
            }

            $matched_address_id = false;
            foreach ($customer_addresses as $customer_address) {
                if (isset($customer_address['address1'])) {
                    $billing  = new Address($customer_address['id_address']);
                    $user_bill = $billing->firstname.' '.$billing->lastname.' '.$billing->company;
                    $company = isset($address['company']) ? $address['company'] : '';
                    $api_name = $address['firstname']. ' '. $address['lastname'].' '.$company;

                    if (Common::matchstr($user_bill,$api_name) && Common::matchstr($customer_address['address1'], $address['street']) &&
                        Common::matchstr($customer_address['postcode'], $address['zip']) &&
                        Common::matchstr($customer_address['city'], $address['city']) &&
                        Common::matchstr(Country::getIsoById($customer_address['id_country']), $address['country']))

                        $matched_address_id = $customer_address['id_address'];
                } else {
                    foreach ($customer_address as $c_address) {
                        $billing  = new Address($c_address['id_address']);

                        $user_bill = $billing->firstname.' '.$billing->lastname.' '.$billing->company;
                        $company = isset($address['company']) ? $address['company'] : '';
                        $api_name = $address['firstname']. ' '. $address['lastname'].' '.$company;

                        if (Common::matchstr($user_bill,$api_name) &&  Common::matchstr($c_address['address1'], $address['street']) &&
                            Common::matchstr($c_address['postcode'], $address['zip']) &&
                            Common::matchstr($c_address['city'], $address['city']) &&
                            Common::matchstr(Country::getIsoById($c_address['id_country']), $address['country'])
                        )
                            $matched_address_id = $c_address['id_address'];
                    }
                }
            }

            if (!$matched_address_id) {
                $billing  = new Address($this->context->cart->id_address_invoice);
                $addressnew              = new Address();
                $addressnew->id_customer = (int)$this->context->customer->id;

                $addressnew->firstname = !empty($address['firstname']) ? $address['firstname'] : $billing->firstname;
                $addressnew->lastname  = !empty($address['lastname']) ? $address['lastname'] : $billing->lastname;
                $addressnew->company   = isset($address['company']) ? $address['company'] : '';

                $addressnew->phone        = $billing->phone;
                $addressnew->phone_mobile = $billing->phone_mobile;

                $addressnew->address1 = $address['street'];
                $addressnew->postcode = $address['zip'];
                $addressnew->city     = $address['city'];
                $addressnew->country  = $address['country'];
                $addressnew->alias    = 'Bimport-'.date('Y-m-d');
                $addressnew->id_country = Country::getByIso($address['country']);
                $addressnew->save();

                $matched_address_id = $addressnew->id;
            }

            $sql = 'UPDATE `'._DB_PREFIX_.'cart_product`
                    SET `id_address_delivery` = '.(int)$matched_address_id.'
                    WHERE  `id_cart` = '.(int)$this->context->cart->id;
            Db::getInstance()->execute($sql);

            $sql = 'UPDATE `'._DB_PREFIX_.'customization`
                    SET `id_address_delivery` = '.(int)$matched_address_id.'
                    WHERE  `id_cart` = '.(int)$this->context->cart->id;
            Db::getInstance()->execute($sql);

            $this->context->cart->id_address_invoice  = (int)$matched_address_id;
            $this->context->cart->id_address_delivery = (int)$matched_address_id;
            if (version_compare(_PS_VERSION_,'1.7','>=')) {
                $billing = new Address($this->context->cart->id_address_invoice);
                $shipping = new Address($this->context->cart->id_address_delivery);
                $billing->update();
                $shipping->update();
                $this->context->cart->checkAndUpdateAddresses();
            }

            $this->context->cart->setDeliveryOption(array($this->context->cart->id_address_delivery => $carrier->id));
            $this->context->cart->update();

            if (Configuration::get('PS_ORDER_PROCESS_TYPE') == 1) {
                $return = array(
                    'success' => true,
                    'action'  => array(
                        'method'                                 => 'updateCarrierAndGetPayments',
                        //updateExtraCarrier
                        'gift'                                   => 0,
                        'gift_message'                           => '',
                        'recyclable'                             => 0,
                        'delivery_option['.$matched_address_id.']' => $carrier->id.',',
                        'ajax'                                   => true,
                        'token'                                  => Tools::getToken(false),
                    )
                );
            } else {
                $return = array(
                    'success' => true,
                    'action'  => array(
                        'method'             => 'updateExtraCarrier',
                        'id_delivery_option' => $carrier_id.',',
                        'id_address'         => $matched_address_id,
                        'allow_refresh'      => 1,
                        'ajax'               => true,
                        'token'              => Tools::getToken(false),
                    )
                );
            }
        } else {
            $this->context->smarty->assign(array(
                'ps_version' => _PS_VERSION_,
                'method'     => $this->method
            ));
            $this->context->smarty->assign('company',isset($address['company']) ? $address['company']: '');
            $this->context->smarty->assign('firstname', isset($address['firstname']) ? $address['firstname'] : '');
            $this->context->smarty->assign('lastname', isset($address['lastname']) ? $address['lastname'] : '');
            $this->context->smarty->assign('address', $address['street']);
            $this->context->smarty->assign('zipcode', $address['zip']);
            $this->context->smarty->assign('city', $address['city']);
            $this->context->smarty->assign('country', Country::getNameById($this->context->language->id,Country::getByIso($address['country'])));
            if (Module::isInstalled('onepagecheckout')) {
                $previouslink = $this->context->link->getPageLink('order.php',true);
            } else {
                $previouslink = $this->context->link->getPageLink('order.php', true).'?step=3';
            }

            $this->context->smarty->assign('previousLink', $previouslink);
            $html   = $this->context->smarty->fetch(_PS_MODULE_DIR_.'billmategateway/views/templates/front/wrongaddress.tpl');
            $return = array('success' => false, 'content' => $html, 'popup' => true);
        }

        return $return;
    }

    /**
     * A method for invoicePreparation for Invoice and Partpayment
     * @return array Billmate Request
     */
    public function prepareInvoice($method)
    {
        $invoiceMethod = (Configuration::get('BINVOICESERVICE_METHOD') == 2) ? 2 : 1;
        $payment_data                = array();
        $methodValue = 1;
        switch($method){
            case 'invoiceservice':
                $methodValue = 2;
                break;
            case 'invoice':
                $methodValue = $invoiceMethod;
                if ($this->invoiceservice)
                    $methodValue = 2;
                break;
            case 'partpay':
                $methodValue = 4;
                break;

        }
        $payment_data['PaymentData'] = array(
            'method'        => $methodValue,
            'paymentplanid' => ($method == 'partpay') ? Tools::getValue('paymentAccount') : '',
            'currency'      => Tools::strtoupper($this->context->currency->iso_code),
            'language'      => Tools::strtolower($this->context->language->iso_code),
            'country'       => Tools::strtoupper($this->context->country->iso_code),
            'orderid'       => Tools::substr($this->context->cart->id.'-'.time(), 0, 10),
            'logo' 			=> (Configuration::get('BILLMATE_LOGO')) ? Configuration::get('BILLMATE_LOGO') : ''

        );

        $payment_data['PaymentInfo'] = array(
            'paymentdate' => date('Y-m-d')
        );

        return $payment_data;

    }

    /**
     * A method for taking care of Direct and Card payments
     */
    public function prepareDirect($method)
    {
        $payment_data                = array();
        $payment_data['PaymentData'] = array(
            'method'   => ($method == 'cardpay') ? 8 : 16,
            'currency' => Tools::strtoupper($this->context->currency->iso_code),
            'language' => Tools::strtolower($this->context->language->iso_code),
            'country'  => Tools::strtoupper($this->context->country->iso_code),
            'orderid'  => Tools::substr($this->context->cart->id.'-'.time(), 0, 10),
            'autoactivate' => 0,//($method == 'cardpay' && (Configuration::get('BCARDPAY_AUTHORIZATION_METHOD') != 'authorize')) ? 1 : 0,
            'logo' 			=> (Configuration::get('BILLMATE_LOGO')) ? Configuration::get('BILLMATE_LOGO') : ''

        );
        $payment_data['PaymentInfo'] = array(
            'paymentdate' => date('Y-m-d')
        );

        $payment_data['Card'] = array(
            'accepturl'    => $this->context->link->getModuleLink('billmategateway', 'accept', array('method' => $this->method),true),
            'cancelurl'    => $this->context->link->getModuleLink('billmategateway', 'cancel', array('method' => $this->method),true),
            'callbackurl'  => $this->context->link->getModuleLink('billmategateway', 'callback', array('method' => $this->method),true),
            'returnmethod' => (array_key_exists('HTTPS', $_SERVER) && $_SERVER['HTTPS'] == "on") ?'POST' : 'GET'
        );

        return $payment_data;
    }

    public function sendResponse($result)
    {
        $return = array();
        switch ($this->method)
        {
            case 'invoice':
            case 'partpay':
            case 'invoiceservice':
                if (!isset($result['code']) && (isset($result['number']) && is_numeric($result['number']) && $result['number'] > 0))
                {
                    $status   = ($this->method == 'invoice') ? Configuration::get('BINVOICE_ORDER_STATUS') : Configuration::get('BPARTPAY_ORDER_STATUS');
                    $status = ($this->method == 'invoiceservice') ? Configuration::get('BINVOICESERVICE_ORDER_STATUS') : $status;
                    $status = ($result['status'] == 'Pending') ? Configuration::get('BILLMATE_PAYMENT_PENDING') : $status;
                    $extra    = array('transaction_id' => $result['number']);
                    $total    = $this->context->cart->getOrderTotal();
                    $customer = new Customer((int)$this->context->cart->id_customer);
                    $orderId = 0;
                    if ($this->method == 'partpay')
                    {
                        $this->paymentMethod->validateOrder((int)$this->context->cart->id,
                            $status,
                            ($this->method == 'invoice') ? $this->paid_amount / 100 : $total,
                            $this->paymentMethod->displayName,
                            null, $extra, null, false, $customer->secure_key);
                        $orderId = $this->paymentMethod->currentOrder;
                    }
                    else
                    {
                        $this->paymentMethod->validateOrder((int)$this->context->cart->id,
                            $status,
                            ($this->method == 'invoice' || $this->method == 'invoiceservice') ? $this->paid_amount / 100 : $total,
                            $this->paymentMethod->displayName,
                            null, $extra, null, false, $customer->secure_key);
                        $orderId = $this->paymentMethod->currentOrder;
                    }
                    $values                = array();
                    $values['PaymentData'] = array(
                        'number'  => $result['number'],
                        'orderid' => (Configuration::get('BILLMATE_SEND_REFERENCE') == 'reference') ? $this->paymentMethod->currentOrderReference : $this->paymentMethod->currentOrder
                    );

                    $this->billmate->updatePayment($values);

                    $url                = 'order-confirmation&id_cart='.(int)$this->context->cart->id.
                                          '&id_module='.(int)$this->getmoduleId('billmate'.$this->method).
                                          '&id_order='.(int)$orderId.
                                          '&key='.$customer->secure_key;
                    $return['success']  = true;
                    $return['redirect'] = $this->context->link->getPageLink($url,true);
                    if(isset($this->context->cookie->billmatepno))
                        unset($this->context->cookie->billmatepno);
                }
                else
                {
                    if (in_array($result['code'],array(2401,2402,2403,2404,2405)))
                    {
                        $result = $this->checkAddress();

                        if (is_array($result))
                            die(Tools::jsonEncode($result));
                    }
                    //Logger::addLog($result['message'], 1, $result['code'], 'Cart', $this->context->cart->id);
                    $return = array('success' => false, 'content' => utf8_encode($result['message']));
                }

                break;
            case 'bankpay':
            case 'cardpay':
                if (!isset($result['code'])) {
                    if ($this->ajax) {
                        $return = array('success' => true, 'redirect' => $result['url']);
                    } else {
                        header('Location: ' . $result['url']);
                    }
                } else {
                    $return = array('success' => false, 'content' => utf8_encode($result['message']));
                }

                break;
        }
        die(Tools::JsonEncode($return));
    }

    public function getmoduleId($method)
    {
        $id2name = array();
        $sql = 'SELECT `id_module`, `name` FROM `'._DB_PREFIX_.'module`';
        if ($results = Db::getInstance()->executeS($sql)) {
            foreach ($results as $row) {
                $id2name[$row['name']] = $row['id_module'];
            }
        }
        return $id2name[$method];
    }

    /**
     * @return string;
     */
    protected function getPno()
    {
        switch($this->method) {
            case'invoice':
            case'partpay':
                if(Tools::getIsset('pno_billmateinvoice')) {
                    $pno = Tools::getValue('pno_billmateinvoice');
                    break;
                }

                if(Tools::getIsset('pno_billmatepartpay')) {
                    $pno = Tools::getValue('pno_billmatepartpay');
                    break;
                }
                break;
            case'invoiceservice':
                    $pno = Tools::getValue('pno_billmateinvoiceservice');
                break;
            default:
                $pno = '';
        }
        return $pno;
    }

    /**
     * @return $this
     */
    public function definePluginConstants()
    {
        if (!defined('BILLMATE_SERVER')) {
            if ($this->method == 'cardpay' && version_compare(_PS_VERSION_,'1.7','>=')) {
                define('BILLMATE_SERVER', '2.1.9');
            }
        }
        return $this;
    }

    /**
     * @return bool
     */
    protected function isAddressMatched($address)
    {
        $billing  = new Address($this->context->cart->id_address_invoice);
        $shipping = new Address($this->context->cart->id_address_delivery);

        $user_ship = $shipping->firstname.' '.$shipping->lastname.' '.$shipping->company;
        $user_bill = $billing->firstname.' '.$billing->lastname.' '.$billing->company;
        if (empty($address['company'])) {
            $api_matched_name = Common::matchstr($shipping->firstname,$address['lastname'])
                && Common::matchstr($shipping->lastname,$address['lastname']);
        } else {
            $prestacompany = explode(' ', $billing->company);
            $apicompany = explode(' ', $address['company']);
            $matched_company = array_intersect($prestacompany, $apicompany);
            $api_matched_name = !empty($matched_company);
        }

        $address_same = Common::matchstr($user_ship, $user_bill) &&
            Common::matchstr($billing->city, $shipping->city) &&
            Common::matchstr($billing->postcode, $shipping->postcode) &&
            Common::matchstr($billing->address1, $shipping->address1);
        return(
            $api_matched_name
            && Common::matchstr($shipping->address1, $address['street'])
            && Common::matchstr($shipping->postcode, $address['zip'])
            && Common::matchstr($shipping->city, $address['city'])
            && Common::matchstr($address['country'], Country::getIsoById($shipping->id_country))
            && $address_same
        );
    }

    /**
     * @return array
     */
    protected function getAddressFromService()
    {
        return $this->billmate->getAddress(array('pno' => $this->pno));
    }

    /**
     * @param $address
     *
     * @return array
     */
    protected function convertAddress($address)
    {
        foreach ($address as $key => $value) {
            $address[$key] = $this->convertToUtf($value);
        }

        return $address;
    }

    /**
     * @return array
     */
    protected function getCartMessages()
    {
        $messages = [];
        $cartMessage = Message::getMessageByCartId($this->context->cart->id);

        if (is_array($cartMessage) && isset($cartMessage['message']) && strlen($cartMessage['message']) > 0) {
            $messages[] = array(
                'quantity'   => 0,
                'title'      => ' ',
                'artnr'      => '--freetext--',
                'aprice'     => 0,
                'taxrate'    => 0,
                'discount'   => 0,
                'withouttax' => 0

            );
            $messages[] = array(
                'quantity'   => 0,
                'title'      => html_entity_decode($cartMessage['message']),
                'artnr'      => '--freetext--',
                'aprice'     => 0,
                'taxrate'    => 0,
                'discount'   => 0,
                'withouttax' => 0

            );
        }


        return $messages;
    }
}