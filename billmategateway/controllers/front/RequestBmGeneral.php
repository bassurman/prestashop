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

class RequestBmGeneral extends BaseBmFront
{

    /**
     * @var CustomerCore
     */
    protected $orderCustomer;

    /**
     * @var array
     */
    protected $paymentInfo = [];

    /**
     * @var array
     */
    protected $verifyData = [];

    /**
     * @var int
     */
    protected $currentOrderId;

    /**
     * @var int
     */
    protected $cart_id;

    public function getCheckout()
    {
        $billmate = $this->getBillmate();
        if ($hash = Common::getCartCheckoutHash()) {
            $result = $billmate->getCheckout(array('PaymentData' => array('hash' => $hash)));

            if (!isset($result['code'])) {
                if (    isset($result['PaymentData']['order']) AND
                    isset($result['PaymentData']['order']['status']) AND
                    (   strtolower($result['PaymentData']['order']['status']) != 'created' OR
                        strtolower($result['PaymentData']['order']['status']) != 'paid'
                    )
                ) {
                    $result = $this->initCheckout();
                    if (!isset($result['code'])) {
                        return $result['url'];
                    }
                } else {
                    $updateResult = $this->updateCheckout($result);
                    if (!isset($updateResult['code'])) {
                        $hash = $this->getHashFromUrl($updateResult['url']);
                        Common::setCartCheckoutHash($hash);

                        $result = $billmate->getCheckout(array('PaymentData' => array('hash' => $hash)));
                        return $result['PaymentData']['url'];
                    }
                }
            }
        } else {
            $result = $this->initCheckout();
            if(!isset($result['code'])){
                return $result['url'];
            }
        }
    }

    public function actionSetShipping($delivery_option)
    {
        $result = array();
        try {
            if (!is_array($delivery_option)) {
                $delivery_option = array(
                    $this->context->cart->id_address_delivery => $delivery_option
                );
            }

            $validateOptionResult = $this->validateDeliveryOption($delivery_option);

            if ($validateOptionResult) {
                if(version_compare(_PS_VERSION_,'1.7','>=')) {
                    $deliveryOption =  $delivery_option;
                    $realOption = array();
                    foreach ($deliveryOption as $key => $value){
                        $realOption[$key] = Cart::desintifier($value);
                    }
                    $this->context->cart->setDeliveryOption($realOption);
                }
                else {
                    $this->context->cart->setDeliveryOption($delivery_option);
                }
            }

            $cartUpdateResult = $this->context->cart->update();
            if (!$cartUpdateResult) {
                $this->context->smarty->assign(array(
                    'vouchererrors' => Tools::displayError('Could not save carrier selection'),
                ));
            }
            $this->context->cart->save();

            CartRule::autoRemoveFromCart($this->context);
            CartRule::autoAddToCart($this->context);
            $result['success'] = true;
        } catch(Exception $e){
            $result['success'] = false;
            $result['message'] = $e->getMessage();
            $result['trace'] = $e;
        }
        return $result;
    }

    public function verifyAddressProcess()
    {
        $customer = $this->getOrderCustomer();
        $cart_delivery_option = $this->context->cart->getDeliveryOption();
        if (
            $this->context->cart->id_customer == 0
            || $this->context->cart->id_address_invoice  == 0
            || $this->context->cart->id_address_delivery == 0
        ) {
            $result     = $this->fetchCheckout();
            $customer   = $result['Customer'];
            $address    = $customer['Billing'];
            $country    = isset($customer['Billing']['country']) ? $customer['Billing']['country'] : 'SE';
            $bill_phone = isset($customer['Billing']['phone']) ? $customer['Billing']['phone'] : '';
        }


        /** Create customer when missing */
        if ($this->context->cart->id_customer == 0) {
            $newCustomer = new Customer();
            $password = Tools::passwdGen(8);
            $newCustomer->firstname = !empty($address['firstname']) ? $address['firstname'] : '';
            $newCustomer->lastname  = !empty($address['lastname']) ? $address['lastname'] : '';
            $newCustomer->company   = isset($address['company']) ? $address['company'] : '';
            $newCustomer->passwd = $password;
            $newCustomer->id_default_group = (int) (Configuration::get('PS_CUSTOMER_GROUP', null, $this->context->cart->id_shop));

            $newCustomer->email = $address['email'];
            $newCustomer->active = true;
            $newCustomer->add();
            $this->context->customer = $newCustomer;
            $this->context->cart->secure_key = $newCustomer->secure_key;
            $this->context->cart->id_customer = $newCustomer->id;
            $this->orderCustomer = $newCustomer;
        }

        /** Create billing/shipping address when missing */
        if ($this->context->cart->id_address_invoice  == 0 || $this->context->cart->id_address_delivery == 0) {

            $_customer = new Customer($this->context->cart->id_customer);
            $customer_addresses = $_customer->getAddresses($this->context->language->id);

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


                        if (
                            Common::matchstr($user_bill,$api_name) &&  Common::matchstr($c_address['address1'], $address['street'])
                            && Common::matchstr($c_address['postcode'], $address['zip'])
                            && Common::matchstr($c_address['city'], $address['city'])
                            && Common::matchstr(Country::getIsoById($c_address['id_country']), $address['country'])
                        ) {
                            $matched_address_id = $c_address['id_address'];
                        }
                    }
                }
            }

            if (!$matched_address_id) {
                $addressnew              = new Address();
                $addressnew->id_customer = (int)$this->context->cart->id_customer;

                $addressnew->firstname = !empty($address['firstname']) ? $address['firstname'] : $billing->firstname;
                $addressnew->lastname  = !empty($address['lastname']) ? $address['lastname'] : $billing->lastname;
                $addressnew->company   = isset($address['company']) ? $address['company'] : '';

                $addressnew->phone        = $address['phone'];
                $addressnew->phone_mobile = $address['phone'];

                $addressnew->address1   = $address['street'];
                $addressnew->postcode   = $address['zip'];
                $addressnew->city       = $address['city'];
                $addressnew->country    = $address['country'];
                $addressnew->alias      = 'Bimport-'.date('Y-m-d');
                $addressnew->id_country = Country::getByIso($address['country']);
                $addressnew->save();

                $matched_address_id = $addressnew->id;
            }


            $billing_address_id = $shipping_address_id = $matched_address_id;

            if (
                isset($customer['Shipping'])
                && is_array($customer['Shipping'])
                && isset($customer['Shipping']['firstname'])
                && isset($customer['Shipping']['lastname'])
                && isset($customer['Shipping']['street'])
                && isset($customer['Shipping']['zip'])
                && isset($customer['Shipping']['city'])
                && $customer['Shipping']['firstname'] != ''
                && $customer['Shipping']['lastname'] != ''
                && $customer['Shipping']['street'] != ''
                && $customer['Shipping']['zip'] != ''
                && $customer['Shipping']['city'] != ''
            ) {
                $address = $customer['Shipping'];

                $logfile = $this->getLogfileName();
                file_put_contents($logfile, 'shippingAddress:'.print_r($address,true),FILE_APPEND);
                file_put_contents($logfile, 'customerAddress:'.print_r($customer_addresses,true),FILE_APPEND);

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
                            Common::matchstr(Country::getIsoById($customer_address['id_country']), isset($address['country']) ? $address['country'] : $country)) {
                            $matched_address_id = $customer_address['id_address'];
                        }
                    } else {
                        foreach ($customer_address as $c_address) {
                            $billing  = new Address($c_address['id_address']);

                            $user_bill = $billing->firstname.' '.$billing->lastname.' '.$billing->company;
                            $company = isset($address['company']) ? $address['company'] : '';
                            $api_name = $address['firstname']. ' '. $address['lastname'].' '.$company;


                            if (Common::matchstr($user_bill,$api_name) &&  Common::matchstr($c_address['address1'], $address['street']) &&
                                Common::matchstr($c_address['postcode'], $address['zip']) &&
                                Common::matchstr($c_address['city'], $address['city']) &&
                                Common::matchstr(Country::getIsoById($c_address['id_country']), isset($address['country']) ? $address['country'] : $country)
                            ) {
                                $matched_address_id = $c_address['id_address'];
                            }
                        }
                    }

                }
                if(!$matched_address_id) {
                    $address = $customer['Shipping'];
                    $addressshipping = new Address();
                    $addressshipping->id_customer = (int)$this->context->cart->id_customer;

                    $addressshipping->firstname = !empty($address['firstname']) ? $address['firstname'] : '';
                    $addressshipping->lastname = !empty($address['lastname']) ? $address['lastname'] : '';
                    $addressshipping->company = isset($address['company']) ? $address['company'] : '';

                    $addressshipping->phone = isset($address['phone']) ? $address['phone'] : $bill_phone;
                    $addressshipping->phone_mobile = isset($address['phone']) ? $address['phone'] : $bill_phone;

                    $addressshipping->address1 = $address['street'];
                    $addressshipping->postcode = $address['zip'];
                    $addressshipping->city = $address['city'];
                    $addressshipping->country = isset($address['country']) ? $address['country'] : $country;
                    $addressshipping->alias = 'Bimport-' . date('Y-m-d');


                    $_country = (isset($address['country']) AND $address['country'] != '') ? $address['country'] : $country;
                    $addressshipping->id_country = Country::getByIso($_country);
                    $addressshipping->save();
                    $shipping_address_id = $addressshipping->id;
                } else {
                    $shipping_address_id = $matched_address_id;
                }
            }

            $this->context->cart->id_address_invoice  = (int)$billing_address_id;
            $this->context->cart->id_address_delivery = (int)$shipping_address_id;

            // Connect selected shipping method to delivery address
            if (is_array($cart_delivery_option)) {
                $cart_delivery_option = current($cart_delivery_option);
            }
            $this->actionSetShipping($cart_delivery_option);
        }
    }


    /**
     * @return string
     */
    protected function getDisplayName()
    {
        $displayName = $this->paymentMethod->displayName;

        if ($this->method == 'checkout') {
            if (isset($this->paymentInfo['PaymentData']['method_name']) && $this->paymentInfo['PaymentData']['method_name'] != '') {
                $displayName .= ' (' . $this->paymentInfo['PaymentData']['method_name'] . ')';
            }
        }

        return $displayName;
    }

    protected function updatePaymentMethod()
    {
        if ($this->method != 'checkout') {
            switch($this->paymentInfo['PaymentData']['method']) {
                case '4':
                    $this->method = 'partpay';
                    break;
                case '8':
                    $this->method = 'cardpay';
                    break;
                case '16':
                    $this->method = 'bankpay';
                    break;
                default:
                    $this->method = 'invoice';
                    break;
            }
        }
    }

    /**
     * A recursive method which delays order-confirmation until order is processed
     *
     * @param $cart_id Cart Id
     *
     * @return integer OrderId
     */

    protected function checkOrder($cart_id)
    {
        $order = Order::getOrderByCartId($cart_id);
        if (!$order) {
            sleep(1);
            $this->checkOrder($cart_id);
        } else {
            return $order;
        }
    }

    /**
     * @return int
     */
    protected function getCurrentOrderId()
    {
        if(is_null($this->currentOrderId)) {
            if ($this->isAllowedToProcess()) {
                $this->currentOrderId = $this->checkOrder($this->context->cart->id);
            } else {
                $this->currentOrderId = Order::getOrderByCartId($this->context->cart->id);
            }
        }
        return $this->currentOrderId;
    }


    protected function updateOrderStatus($orderObject)
    {
        if ($orderObject->current_state == Configuration::get('BILLMATE_PAYMENT_PENDING')
            && $this->verifyData['status'] != 'Pending') {
            $orderHistory = new OrderHistory();

            $status_key = 'B'.strtoupper($this->method).'_ORDER_STATUS';
            if ($this->method == 'checkout') {
                $status_key = 'BILLMATE_CHECKOUT_ORDER_STATUS';
            }

            $status = Configuration::get($status_key);
            $status = ($this->verifyData['status'] == 'Cancelled') ? Configuration::get('PS_OS_CANCELED') : $status;
            $orderHistory->id_order = (int) $orderObject->id;
            $orderHistory->changeIdOrderState($status,(int) $orderObject->id, true);
            $orderHistory->add();
        }
    }

    /**
     * @return Customer
     */
    protected function getOrderCustomer()
    {
        if (is_null($this->orderCustomer)) {
            $this->orderCustomer = new Customer($this->context->cart->id_customer);
        }

        return $this->orderCustomer;
    }

    public function getBillmate()
    {
        $testMode = Configuration::get('BILLMATE_CHECKOUT_TESTMODE');
        return $this->getBillmateConnection($testMode);
    }


    /**
     * @return string
     */
    protected function getConfMethodStatus()
    {
        $status_key = 'B'.strtoupper($this->method).'_ORDER_STATUS';
        if ($this->method == 'checkout') {
            $status_key = 'BILLMATE_CHECKOUT_ORDER_STATUS';
        }

        $status = Configuration::get($status_key);
        if ($this->verifyData['status'] == 'Pending') {
            $status = Configuration::get('BILLMATE_PAYMENT_PENDING');
        }

        return $status;
    }

    /**
     * @return array
     */
    public function fetchCheckout()
    {
        $billmate = $this->getBillmate();
        $hash = Common::getCartCheckoutHash();
        if ($hash) {
            $result = $billmate->getCheckout(array('PaymentData' => array('hash' => $hash)));
            if(!isset($result['code'])){
                return $result;
            }
        }
        return array();
    }

    protected function validateDeliveryOption($delivery_option)
    {
        if (!is_array($delivery_option)) {
            return false;
        }
        foreach ($delivery_option as $option) {
            if (!preg_match('/(\d+,)?\d+/', $option)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return string
     */
    protected function getLockFilename()
    {
        return PS_CACHE_DIR_ . $this->verifyData['orderid'];
    }

    protected function addLockFile()
    {
        $lockfile = $this->getLockFilename();
        file_put_contents($lockfile, 1);
    }

    protected function removeLockFile()
    {
        $lockfile = $this->getLockFilename();
        unlink($lockfile);
    }

    /**
     * @return bool
     */
    protected function isAllowedToProcess()
    {
        $lockfile   = $this->getLockFilename();
        return file_exists($lockfile);
    }

    /**
     * @param $requestData
     */
    protected function sendUpdateBillmatePayment($requestData)
    {
        $this->billmateConnection->updatePayment($requestData);
    }
}