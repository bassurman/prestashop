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

class BillmategatewayCallbackModuleFrontController extends RequestBmGeneral
{
    public function postProcess()
    {
        $this->defineProperties();

        $input = Tools::file_get_contents('php://input');
        $post = is_array($_GET) && isset($_GET['data']) ? $_GET : Tools::file_get_contents('php://input');

        if (is_array($post)) {
            foreach($post as $key => $value)
                    $post[$key] =  preg_replace_callback(
                "@\\\(x)?([0-9a-fA-F]{2})@",
                function($m){
                    return chr($m[1]?hexdec($m[2]):octdec($m[2]));
                },
                $value
            );
        }
        $this->verifyData = $this->billmateConnection->verify_hash($post);

        if (isset($this->verifyData['code']) && isset($this->verifyData['error'])) {
            $order        = $this->verifyData['orderid'];
            $order        = explode('-', $order);
            Logger::addLog($this->verifyData['message'], 1, $this->verifyData['code'], 'Cart', $order[0]);
            return;
        }

        $displayName = $this->getDisplayName();

        $this->paymentInfo = $this->billmateConnection->getPaymentinfo(array('number' => $this->verifyData['number']));
        $this->updatePaymentMethod();

        $this->paymentMethod = $this->getPaymentMethodClass();

        $order        = $this->verifyData['orderid'];
        $order        = explode('-', $order);
        $this->cart_id = $order[0];
        $this->context->cart = new Cart($this->cart_id);

        if ($this->context->cart->orderExists() || $this->isAllowedToProcess()) {
            error_log('order_exists');

            $orderId = $this->getCurrentOrderId();
            $orderObject = new Order($orderId);
            $this->updateOrderStatus($orderObject);

            die('OK');
        }

        if($this->verifyData['status'] == 'Cancelled') {
            die('OK');
        }

        $this->addLockFile();
        $this->verifyAddressProcess();

        $this->context->cart->update();
        $this->context->cart->save();

        $customer = $this->getOrderCustomer();
        $this->context->cart->getOrderTotal(true, Cart::BOTH);
        $extra               = array('transaction_id' => $this->verifyData['number']);

        $total = $this->paymentInfo['Cart']['Total']['withtax'] / 100;

        $status = $this->getConfMethodStatus();

        $this->paymentMethod->validateOrder((int)$this->context->cart->id,
            $status,
            $total,
            $displayName,
            null,
            $extra,
            null,
            false,
            $customer->secure_key
        );
        $values = array();

        $values['PaymentData'] = array(
            'number'  => $this->verifyData['number'],
            'orderid' => (Configuration::get('BILLMATE_SEND_REFERENCE') == 'reference') ? $this->paymentMethod->currentOrderReference : $this->paymentMethod->currentOrder
        );
        $this->sendUpdateBillmatePayment($values);

        if ($this->paymentMethod->authorization_method == 'sale' && $this->method == 'cardpay') {
            $values['PaymentData'] = array(
                'number' => $this->verifyData['number']
            );
            $this->billmateConnection->activatePayment($values);
        }
        $this->removeLockFile();
        exit('finalize');

    }
}
