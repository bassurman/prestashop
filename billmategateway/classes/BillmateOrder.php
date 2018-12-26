<?php
class BillmateOrder extends Helper
{
    const SALE_METHOD_INFO = 'sale';

    /**
     * @var Context
     */
    public $context;

    /**
     * @var array
     */
    protected $allowed_payment_statuses = array(
        'paid',
        'factoring',
        'partpayment',
        'handling'
    );

    public function __construct()
    {
        $this->context = Context::getContext();
        $this->config_helper = new BmConfigHelper();
    }

    public function updateStatusProcess($params)
    {
        $orderStatuses = $this->config_helper->getBMActivateStatuses();

        $activate    = Configuration::get('BILLMATE_ACTIVATE');

        $cancelStatus = Configuration::get('BILLMATE_CANCEL_STATUS');
        $cancelStatus = unserialize($cancelStatus);
        $cancelStatus = is_array($cancelStatus) ? $cancelStatus : array($cancelStatus);

        if ($activate && $orderStatuses) {
            $order_id  = $params['id_order'];
            $idStatus = $params['newOrderStatus']->id;
            $order = $this->getOrder($order_id);

            $payment = OrderPayment::getByOrderId($order_id);
            $transactionId = $payment[0]->transaction_id;

            $paymentModules           = $this->config_helper->getPaymentModules(true);
            $allowed_payment_statuses = $this->getPaymentStatuses();
            $methodInfo               = $this->getMethodInfo($order->module, 'authorization_method', false);

            if (in_array($order->module, $paymentModules)) {
                $testMode = $this->getMethodInfo($order->module, 'testMode', false);
                $bmConnection = $this->config_helper->getBillmateConnection($testMode);
                $paymentInfo   = $bmConnection->getPaymentinfo(array('number' => $transactionId));
                
                if (in_array($idStatus, $orderStatuses) && $methodInfo != self::SALE_METHOD_INFO) {
                    $paymentStatus = Tools::strtolower($paymentInfo['PaymentData']['status']);
                    if ($paymentStatus == 'created') {
                        $total      = $paymentInfo['Cart']['Total']['withtax'] / 100;
                        $orderTotal = $order->getTotalPaid();
                        $diff       = $total - $orderTotal;
                        $diff       = abs($diff);
                        if ($diff < 1) {
                            $result = $bmConnection->activatePayment(array('PaymentData' => array('number' => $transactionId)));
                            if (isset($result['code'])) {
                                (isset($result['message'])) ? utf8_encode(utf8_decode($result['message'])) : utf8_encode($result);
                                $this->context->cookie->error_orders = isset($this->context->cookie->error_orders) ? $this->context->cookie->error_orders . ', ' . $order_id : $order_id;
                            } else {
                                $this->context->cookie->confirmation        = !isset($this->context->cookie->confirmation_orders) ? sprintf($this->l('Order %s has been activated through Billmate.'), $order_id) . ' (<a target="_blank" href="http://online.billmate.se/faktura">' . $this->l('Open Billmate Online') . '</>)' : sprintf($this->l('The following orders has been activated through Billmate: %s'), $this->context->cookie->confirmation_orders . ', ' . $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
                                $this->context->cookie->confirmation_orders = isset($this->context->cookie->confirmation_orders) ? $this->context->cookie->confirmation_orders . ', ' . $order_id : $order_id;
                            }
                        } elseif (isset($paymentInfo['code'])) {
                            if ($paymentInfo['code'] == 5220) {
                                $mode = $testMode ? 'test' : 'live';
                                $this->context->cookie->api_error = !isset($this->context->cookie->api_error_orders) ? sprintf($this->l('Order %s failed to activate through Billmate. The order does not exist in Billmate Online. The order exists in (%s) mode however. Try changing the mode in the modules settings.'), $order_id, $mode) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)' : sprintf($this->l('The following orders failed to activate through Billmate: %s. The orders does not exist in Billmate Online. The orders exists in (%s) mode however. Try changing the mode in the modules settings.'), $this->context->cookie->api_error_orders, '. ' . $order_id, $mode) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
                            } else {
                                $this->context->cookie->api_error = $paymentInfo['message'];
                            }
                            $this->context->cookie->api_error_orders = isset($this->context->cookie->api_error_orders) ? $this->context->cookie->api_error_orders . ', ' . $order_id : $order_id;
                        } else {
                            $this->context->cookie->diff        = !isset($this->context->cookie->diff_orders) ? sprintf($this->l('Order %s failed to activate through Billmate. The amounts don\'t match: %s, %s. Activate manually in Billmate Online.'), $order_id, $orderTotal, $total) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)' : sprintf($this->l('The following orders failed to activate through Billmate: %s. The amounts don\'t match. Activate manually in Billmate Online.'), $this->context->cookie->diff_orders . ', ' . $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
                            $this->context->cookie->diff_orders = isset($this->context->cookie->diff_orders) ? $this->context->cookie->diff_orders . ', ' . $order_id : $order_id;
                        }
                    } elseif (in_array($paymentStatus, $allowed_payment_statuses)) {
                        $this->context->cookie->information        = !isset($this->context->cookie->information_orders) ? sprintf($this->l('Order %s is already activated through Billmate.'), $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)' : sprintf($this->l('The following orders has already been activated through Billmate: %s'), $this->context->cookie->information_orders . ', ' . $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
                        $this->context->cookie->information_orders = isset($this->context->cookie->information_orders) ? $this->context->cookie->information_orders . ', ' . $order_id : $order_id;
                    } else {
                        $this->context->cookie->error        = !isset($this->context->cookie->error_orders) ? sprintf($this->l('Order %s failed to activate through Billmate.'), $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)' : sprintf($this->l('The following orders failed to activate through Billmate: %s.'), $this->context->cookie->error_orders . ', ' . $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
                        $this->context->cookie->error_orders = isset($this->context->cookie->error_orders) ? $this->context->cookie->error_orders . ', ' . $order_id : $order_id;
                    }
                } elseif (in_array($idStatus, $cancelStatus)) {
                    $paymentStatus = Tools::strtolower($paymentInfo['PaymentData']['status']);
                    if (in_array($paymentStatus, $allowed_payment_statuses)) {
                        $creditResult = $bmConnection->creditPayment(array('PaymentData' => array('number' => $transactionId, 'partcredit' => false)));
                        if (isset($creditResult['code'])) {
                            $this->context->cookie->error_credit  = !isset($this->context->cookie->credit_orders) ? sprintf($this->l('Order %s failed to credit through Billmate.'), $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)' : sprintf($this->l('The following orders failed to credit through Billmate: %s.'), $this->context->cookie->credit_orders . ', ' . $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
                            $this->context->cookie->credit_orders = isset($this->context->cookie->credit_orders) ? $this->context->cookie->credit_orders . ', ' . $order_id : $order_id;
                        } else {
                            $this->context->cookie->credit_confirmation        = !isset($this->context->cookie->credit_confirmation_orders) ? sprintf($this->l('Order %s has been credited through Billmate.'), $order_id) . ' (<a target="_blank" href="http://online.billmate.se/faktura">' . $this->l('Open Billmate Online') . '</>)' : sprintf($this->l('The following orders has been credited through Billmate: %s'), $this->context->cookie->credit_confirmation_orders . ', ' . $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
                            $this->context->cookie->credit_confirmation_orders = isset($this->context->cookie->credit_confirmation_orders) ? $this->context->cookie->credit_confirmation_orders . ', ' . $order_id : $order_id;
                        }
                    } else {
                        $cancelResult = $bmConnection->cancelPayment(array('PaymentData' => array('number' => $transactionId)));
                        if (isset($cancelResult['code'])) {
                            $this->context->cookie->error_credit  = !isset($this->context->cookie->credit_orders) ? sprintf($this->l('Order %s failed to credit through Billmate.'), $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)' : sprintf($this->l('The following orders failed to credit through Billmate: %s.'), $this->context->cookie->credit_orders . ', ' . $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
                            $this->context->cookie->credit_orders = isset($this->context->cookie->credit_orders) ? $this->context->cookie->credit_orders . ', ' . $order_id : $order_id;
                        } else {
                            $this->context->cookie->credit_confirmation        = !isset($this->context->cookie->credit_confirmation_orders) ? sprintf($this->l('Order %s has been credited through Billmate.'), $order_id) . ' (<a target="_blank" href="http://online.billmate.se/faktura">' . $this->l('Open Billmate Online') . '</>)' : sprintf($this->l('The following orders has been credited through Billmate: %s'), $this->context->cookie->credit_confirmation_orders . ', ' . $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
                            $this->context->cookie->credit_confirmation_orders = isset($this->context->cookie->credit_confirmation_orders) ? $this->context->cookie->credit_confirmation_orders . ', ' . $order_id : $order_id;
                        }
                    }
                }
            }
        }
    }

    protected function runActivateProcess($order, $paymentInfo)
    {
        $paymentStatus = Tools::strtolower($paymentInfo['PaymentData']['status']);
        $allowed_payment_statuses = $this->getPaymentStatuses();
        $testMode = $this->getMethodInfo($order->module, 'testMode', false);
        $bmConnection = $this->config_helper->getBillmateConnection($testMode);
        $order_id = $order->id;
        $payment = OrderPayment::getByOrderId($order_id);
        $transactionId = $payment[0]->transaction_id;
    }

    public function getMethodInfo($name, $key, $checkIfAvailable = true)
    {
        $payments_methods = $this->config_helper->getPaymentModules();
        if(isset($payments_methods[$name])) {
            $method_class = $payments_methods[$name];
            if ($checkIfAvailable) {
                $paymentMethodsAvailable = $this->getAvailableMethods();
                if(!in_array(strtolower($method_class),$paymentMethodsAvailable)) {
                    return false;
                }
            }

            $method = new $method_class();

            if (property_exists($method, $key)) {
                return $method->{$key};
            }

        }
        return false;
    }

    public function getAvailableMethods()
    {
        if ($this->billmate_merchant_id && $this->billmate_secret) {
            $bmConnection = $this->config_helper->getBillmateConnection();
            $result = $bmConnection->getAccountinfo(array('time' => time()));

            $mapCodeToMethod = array(
                1 => 'invoice',
                2 => 'invoiceservice',
                4 => 'partpay',
                8 => 'cardpay',
                16 => 'bankpay'
            );
            $paymentOptions = array();
            $logfile   = _PS_CACHE_DIR_.'Billmate.log';
            file_put_contents($logfile, print_r($result['paymentoptions'],true),FILE_APPEND);
            foreach ($result['paymentoptions'] as $option) {
                /**
                 * When invoice is unavailable and invoice service is available, use invoice service as invoice
                 */
                if ($option['method'] == '2' && !isset($paymentOptions['1'])) {
                    $mapCodeToMethod['2'] = 'invoice';
                }

                if(isset($mapCodeToMethod[$option['method']]) && !in_array($mapCodeToMethod[$option['method']], $paymentOptions)) {
                    $paymentOptions[$option['method']] = $mapCodeToMethod[$option['method']];
                } else {
                    continue;
                }
            }
            // Add checkout as payment option if available
            if (isset($result['checkout']) AND $result['checkout']) {
                $paymentOptions['checkout'] = 'checkout';
            }

            /**
             * @param int 1|2 The mehtod that will be used in addPayment request when customer pay with invoice
             * Method 1 = Invoice , 2 = Invoice service
             * Is affected by available payment methods in result from getaccountinfo
             * - Default method 1
             * - Invoice available, use method 1
             * - Invoice and invoiceservice available, use method 1
             * - Invoice service availavble and invoice unavailable, use method 2
             */
            $invoiceMethod = (!isset($paymentOptions[1]) && isset($paymentOptions[2])) ? 2 : 1;
            Configuration::updateValue('BINVOICESERVICE_METHOD', $invoiceMethod);

            return $paymentOptions;
        } else {
            return array();
        }
    }

    /**
     * @return array
     */
    protected function getPaymentStatuses()
    {
        return $this->allowed_payment_statuses;
    }

    /**
     * @param $orderId
     *
     * @return Order
     */
    public function getOrder($orderId)
    {
        return new Order($orderId);
    }
}