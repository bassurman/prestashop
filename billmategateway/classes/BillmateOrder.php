<?php
class BillmateOrder extends Helper
{
    const SALE_METHOD_INFO = 'sale';

    const API_ERROR_CODE = 5220;

    /**
     * @var Context
     */
    public $context;

    /**
     * @var null
     */
    protected $activeMethod;

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
        $this->configHelper = new BmConfigHelper();
    }

    public function updateStatusProcess($params)
    {
        $orderStatuses = $this->configHelper->getBMActivateStatuses();

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

            $paymentModules           = $this->configHelper->getPaymentModules(true);
            $allowed_payment_statuses = $this->getPaymentStatuses();
            $methodInfo               = $this->getMethodInfo($order->module, 'authorization_method', false);

            if (in_array($order->module, $paymentModules)) {
                $testMode = $this->getMethodInfo($order->module, 'testMode', false);
                $bmConnection = $this->configHelper->getBillmateConnection($testMode);
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
                                if (isset($result['message'])) {
                                    $errorMessage = utf8_encode(utf8_decode($result['message']));
                                } else {
                                    $errorMessage = utf8_encode($result);
                                }

                                if (isset($this->context->cookie->error_orders)) {
                                    $errorMessageOrders = $this->context->cookie->error_orders . ', ' . $order_id;
                                } else {
                                    $errorMessageOrders = $order_id;
                                }
                                $this->context->cookie->error = $errorMessage;
                                $this->context->cookie->error_orders = $errorMessageOrders;
                            } else {
                                if(!isset($this->context->cookie->confirmation_orders)) {
                                    $confirmationMessage = sprintf($this->l('Order %s has been activated through Billmate.'), $order_id).
                                        ' (<a target="_blank" href="http://online.billmate.se/faktura">' . $this->l('Open Billmate Online') . '</a>)';
                                } else {
                                    $confirmationMessage = sprintf(
                                        $this->l('The following orders has been activated through Billmate: %s'),
                                        $this->context->cookie->confirmation_orders . ', ' . $order_id) .
                                        ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
                                }

                                if (isset($this->context->cookie->confirmation_orders)) {
                                    $confirmationMessageOrders = $this->context->cookie->confirmation_orders . ', ' . $order_id;
                                } else {
                                    $confirmationMessageOrders = $order_id;
                                }

                                $this->context->cookie->confirmation = $confirmationMessage;
                                $this->context->cookie->confirmation_orders = $confirmationMessageOrders;
                            }
                        } elseif (isset($paymentInfo['code'])) {
                            if ($paymentInfo['code'] == self::API_ERROR_CODE) {
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
                            $this->context->cookie->credit_confirmation = !isset($this->context->cookie->credit_confirmation_orders) ? sprintf($this->l('Order %s has been credited through Billmate.'), $order_id) . ' (<a target="_blank" href="http://online.billmate.se/faktura">' . $this->l('Open Billmate Online') . '</>)' : sprintf($this->l('The following orders has been credited through Billmate: %s'), $this->context->cookie->credit_confirmation_orders . ', ' . $order_id) . ' (<a target="_blank" href="http://online.billmate.se">' . $this->l('Open Billmate Online') . '</a>)';
                            $this->context->cookie->credit_confirmation_orders = isset($this->context->cookie->credit_confirmation_orders) ? $this->context->cookie->credit_confirmation_orders . ', ' . $order_id : $order_id;
                        }
                    }
                }
            }
        }
    }

    /**
     * @param      $name
     * @param      $key
     * @param bool $checkIfAvailable
     *
     * @return bool|
     */
    public function getMethodInfo($name, $key, $checkIfAvailable = true)
    {
        if (is_null($this->activeMethod)) {
            $payments_methods = $this->configHelper->getPaymentModules();
            if (isset($payments_methods[$name])) {
                $methodClass = $payments_methods[$name];
                $this->activeMethod = new $methodClass();

                if ($checkIfAvailable) {
                    $paymentMethodsAvailable = $this->getAvailableMethods();
                    if (!in_array($this->activeMethod->remote_name, $paymentMethodsAvailable)) {
                        $this->activeMethod = false;
                    }
                }
            }
        }

        if ($this->activeMethod && property_exists($this->activeMethod, $key)) {
            return $this->activeMethod->{$key};
        }

        return $this->activeMethod;
    }


    public function getPaymentFees($order)
    {
        $result = Db::getInstance()->getRow(
            'SELECT * FROM '._DB_PREFIX_.'billmate_payment_fees WHERE order_id = "' . (int)$order->id . '"'
        );
        $fees = array();
        if ($result) {
            $payments = $order->getOrderPaymentCollection();
            $currency = 0;
            foreach($payments as $payment) {
                $currency = $payment->id_currency;
            }
            $invoice_fee_tax    = $result['tax_rate'] / 100;
            $invoice_fee        = $result['invoice_fee'];
            $billmatetax        = $result['invoice_fee'] * $invoice_fee_tax;
            $total_fee = $invoice_fee + $billmatetax;
            $fees['invoiceFeeIncl'] = $total_fee;
            $fees['invoiceFeeTax'] = $billmatetax;
            $fees['invoiceFeeCurrency'] = $currency;
        }

        return $fees;
    }
    /**
     * @return array
     */
    public function getAvailableMethods()
    {
        return $this->configHelper->getAvailableMethods();
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