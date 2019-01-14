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

class BillmategatewayAcceptModuleFrontController extends RequestBmGeneral
{
    /**
     * @var array
     */
    protected $id2name = [];

    /**
     * @param $method
     *
     * @return mixed
     */
    public function getmoduleId($method)
    {
        if(!isset($this->id2name[$method])) {
            $sql = 'SELECT `id_module`, `name` FROM `'._DB_PREFIX_.'module`';
            if ($results = Db::getInstance()->executeS($sql)) {
                foreach ($results as $row) {
                    $this->id2name[$row['name']] = $row['id_module'];
                }
            }
        }

        return $this->id2name[$method];
    }

    public function postProcess()
    {
        $this->defineProperties();
        $_POST = !empty($_POST) ? $_POST : $_GET;

        $this->verifyData = $this->billmateConnection->verify_hash($_POST);

        $order               = $this->verifyData['orderid'];
        $order               = explode('-', $order);
        $this->cart_id        = $order[0];
        $this->context->cart = new Cart($this->cart_id);

        if (isset($this->verifyData['code']) && isset($this->verifyData['error'])) {
            Logger::addLog($this->verifyData['message'], 1, $this->verifyData['code'], 'Cart', $this->cart_id);
            return;
        }

        $this->paymentInfo = $this->billmateConnection->getPaymentinfo(array('number' => $this->verifyData['number']));

        $displayName = $this->getDisplayName();
        $this->updatePaymentMethod();
        $this->paymentMethod = $this->getPaymentMethodClass();

        if ($this->context->cart->orderExists() || $this->isAllowedToProcess()) {

            $orderId = $this->getCurrentOrderId();
            $orderObject = new Order($orderId);
            $this->updateOrderStatus($orderObject);

            $this->clearBillmateCookie();
            $redirectUrl = $this->getRedirectUrl();

            Tools::redirectLink($redirectUrl);
            die;
        } else {
            if ($this->isInvoiceTypeMethod()) {
                $this->method = 'invoice';
                $class = "BillmateMethod".Tools::ucfirst($this->method);
                $this->paymentMethod = new $class;
            }

            $return = array();
            $this->paymentInfo = $this->billmateConnection->getPaymentinfo(array('number' => $this->verifyData['number']));

            if (!isset($this->paymentInfo['code'])
                && (isset($this->paymentInfo['PaymentData']['order']['number'])
                && is_numeric($this->paymentInfo['PaymentData']['order']['number'])
                && $this->paymentInfo['PaymentData']['order']['number'] > 0)
            ) {
                $status = $this->getOrderStatus();
                if (!$this->context->cart->OrderExists()) {
                    $customer = $this->getOrderCustomer();
                    $extra = array('transaction_id' => $this->paymentInfo['PaymentData']['order']['number']);
                    $total = $this->paymentInfo['Cart']['Total']['withtax'] /100;

                    $this->paymentMethod->validateOrder(
                        (int)$this->context->cart->id,
                        $status,
                        $total,
                        $displayName,
                        null, $extra, null, false, $customer->secure_key
                    );

                    $updateRequestData = [];
                    $updateRequestData['PaymentData'] = array(
                        'number' => $this->paymentInfo['PaymentData']['order']['number'],
                        'orderid' => (Configuration::get('BILLMATE_SEND_REFERENCE') == 'reference') ?
                            $this->paymentMethod->currentOrderReference :
                            $this->paymentMethod->currentOrder
                    );

                    $this->sendUpdateBillmatePayment($updateRequestData);
                }

                $url = $this->context->link->getModuleLink(
                    'billmatecheckout',
                    'thankyou',
                    array('BillmateHash' => Common::getCartCheckoutHash())
                );

                $return['success'] = true;
                $return['redirect'] = $url;
                Common::unsetCartCheckoutHash();
            }
        }

        $this->addLockFile();

        $this->verifyAddressProcess();

        $this->context->cart->update();
        $this->context->cart->save();

        $customer = $this->getOrderCustomer();

        $this->context->cart->getOrderTotal(true, Cart::BOTH);
        $extra  = array('transaction_id' => $this->verifyData['number']);

        $status = $this->getConfMethodStatus();

        $total = $this->paymentInfo['Cart']['Total']['withtax'] / 100;
        $this->paymentMethod->validateOrder((int)$this->context->cart->id, $status,
            $total, $displayName, null, $extra, null, false, $customer->secure_key);
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
        $this->clearBillmateCookie();

        $hash = Common::getCartCheckoutHash();
        if (!empty($hash)) {
            Common::unsetCartCheckoutHash();
            $redirectUrl = $this->context->link->getModuleLink(
                'billmategateway',
                'thankyou',
                array('billmate_hash' => $hash)
            );
        } else {
            $redirectUrl = __PS_BASE_URI__ . 'order-confirmation.php?key=' . $customer->secure_key .
                '&id_cart=' . (int)$this->context->cart->id . '&id_module=' . (int)$this->getmoduleId('billmate' . $this->method) .
                '&id_order=' . (int)$this->paymentMethod->currentOrder;
        }
        Tools::redirectLink($redirectUrl);
        die;
    }

    protected function clearBillmateCookie()
    {
        if (isset($this->context->cookie->billmatepno)) {
            unset($this->context->cookie->billmatepno);
        }
    }

    /**
     * @return bool
     */
    protected function isInvoiceTypeMethod()
    {
        return in_array($this->paymentInfo['PaymentData']['method'],[1,2]);
    }

    /**
     * @return string
     */
    protected function getRedirectUrl()
    {
        $hash = Common::getCartCheckoutHash();
        if (!empty($hash)) {
            Common::unsetCartCheckoutHash();
         return $this->context->link->getModuleLink(
                'billmategateway',
                'thankyou',array('billmate_hash' => $hash)
            );
        }

        $orderId = $this->getCurrentOrderId();
        $customer = $this->getOrderCustomer();
        $url = __PS_BASE_URI__ . 'order-confirmation.php?key=' . $customer->secure_key .
            '&id_cart=' . (int)$this->context->cart->id .
            '&id_module=' . (int)$this->getmoduleId('billmate' . $this->method) .
            '&id_order=' . (int)$orderId;

        return $url;
    }

    /**
     * @return string
     */
    protected function getOrderStatus()
    {
        $status = $this->configHelper->getBpartOrderStatus();
        switch($this->method) {
            case 'invoice' :
                $status = $this->configHelper->getBInvoiceOrderStatus();
                break;
            case 'invoiceservice' :
                $status = $this->configHelper->getBInvoiceServiceOrderStatus();
                break;
            case 'checkout' :
                $status = $this->configHelper->getBCheckoutOrderStatus();
                break;
        }

        if ($this->paymentInfo['PaymentData']['order']['status'] == 'Pending') {
            $status = $this->configHelper->getBPaymentPendingStatus();
        }

        return $status;
    }
}
