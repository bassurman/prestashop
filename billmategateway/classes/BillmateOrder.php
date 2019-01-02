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
    public function getMethodInfo($name, $methodName, $checkIfAvailable = true)
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

        if ($this->activeMethod && property_exists($this->activeMethod, $methodName)) {
            return $this->activeMethod->{$methodName};
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

    public function getSlipUpdateData($order, $transactionId)
    {
        $updateData = [];
        $orderDetailObject = new OrderDetail();
        $total = 0;
        $totaltax = 0;
        $billing_address       = new Address($order->id_address_invoice);
        $shipping_address      = new Address($order->id_address_delivery);
        $updateData['PaymentData'] = array(
            'number' => $transactionId
        );
        $updateData['Customer']['nr'] = $order->id_customer;
        $updateData['Customer']['Billing']  = array(
            'firstname' => mb_convert_encoding($billing_address->firstname,'UTF-8','auto'),
            'lastname'  => mb_convert_encoding($billing_address->lastname,'UTF-8','auto'),
            'company'   => mb_convert_encoding($billing_address->company,'UTF-8','auto'),
            'street'    => mb_convert_encoding($billing_address->address1,'UTF-8','auto'),
            'street2'   => '',
            'zip'       => mb_convert_encoding($billing_address->postcode,'UTF-8','auto'),
            'city'      => mb_convert_encoding($billing_address->city,'UTF-8','auto'),
            'country'   => mb_convert_encoding(Country::getIsoById($billing_address->id_country),'UTF-8','auto'),
            'phone'     => mb_convert_encoding($billing_address->phone,'UTF-8','auto'),
            'email'     => mb_convert_encoding($this->context->customer->email,'UTF-8','auto')
        );
        $updateData['Customer']['Shipping'] = array(
            'firstname' => mb_convert_encoding($shipping_address->firstname,'UTF-8','auto'),
            'lastname'  => mb_convert_encoding($shipping_address->lastname,'UTF-8','auto'),
            'company'   => mb_convert_encoding($shipping_address->company,'UTF-8','auto'),
            'street'    => mb_convert_encoding($shipping_address->address1,'UTF-8','auto'),
            'street2'   => '',
            'zip'       => mb_convert_encoding($shipping_address->postcode,'UTF-8','auto'),
            'city'      => mb_convert_encoding($shipping_address->city,'UTF-8','auto'),
            'country'   => mb_convert_encoding(Country::getIsoById($shipping_address->id_country),'UTF-8','auto'),
            'phone'     => mb_convert_encoding($shipping_address->phone,'UTF-8','auto'),
        );
        foreach($orderDetailObject->getList($order->id) as $orderDetail){
            $calcTax = $this->getCalculatedTaxRate($orderDetail['id_order_detail']);

            $price = $orderDetail['unit_price_tax_excl'];
            $quantity = $orderDetail['product_quantity'] - $orderDetail['product_quantity_refunded'];
            $updateData['Articles'][] = array(
                'artnr' => (string)$orderDetail['product_reference'],
                'title' => $orderDetail['product_name'],
                'quantity' => $quantity,
                'aprice' => round($price * 100),
                'taxrate' => $calcTax * 100,
                'discount' => 0,
                'withouttax' => round(100 * ($price * $quantity))
            );
            $total += round(($price * $quantity) * 100);
            $totaltax += round((100 * ($price * $quantity)) * $calcTax);
        }

        $taxrate    = $order->carrier_tax_rate;
        $total_shipping_cost  = round($order->total_shipping_tax_excl,2);
        $updateData['Cart']['Shipping'] = array(
            'withouttax' => round($total_shipping_cost * 100),
            'taxrate'    => $taxrate
        );
        $total += round($total_shipping_cost * 100);
        $totaltax += round(($total_shipping_cost * ($taxrate / 100)) * 100);

        if (Configuration::get('BINVOICE_FEE') > 0 && $order->module == 'billmateinvoice') {
            $fee           = Configuration::get('BINVOICE_FEE');
            $invoice_fee_tax = Configuration::get('BINVOICE_FEE_TAX');

            $tax                = new Tax($invoice_fee_tax);
            $tax_calculator      = new TaxCalculator(array($tax));
            $tax_rate            = $tax_calculator->getTotalRate();
            $fee = Tools::convertPriceFull($fee,null,$this->context->currency);
            $fee = round($fee,2);
            $updateData['Cart']['Handling'] = array(
                'withouttax' => $fee * 100,
                'taxrate'    => $tax_rate
            );

            $total += $fee * 100;
            $totaltax += round((($tax_rate / 100) * $fee) * 100);
        }

        $updateData['Cart']['Total'] = array(
            'withouttax' => round($total),
            'tax' => round($totaltax),
            'rounding' => 0,
            'withtax' => round($total + $totaltax)
        );

        return $updateData;
    }

    /**
     * @param $order
     * @param $transactionId
     * @param $productList
     *
     * @return mixed
     */
    public function getCreditPaymentData($transactionId, $productList)
    {

        $creditPaymentData['PaymentData']['number'] = $transactionId;
        $creditPaymentData['PaymentData']['partcredit'] = true;
        $creditPaymentData['Articles'] = array();
        $tax = 0;
        $total = 0;
        foreach ($productList as $idOrderDetail => $product) {
            $orderDetail = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'order_detail` WHERE `id_order_detail` = ' . (int)$idOrderDetail);

            $calcTax = $this->getCalculatedTaxRate($idOrderDetail);
            $marginTax = $calcTax / (1 + $calcTax);
            $price = $product['unit_price'] * (1 - $marginTax);
            $creditPaymentData['Articles'][] = array(
                'artnr' => (string)$orderDetail['product_reference'],
                'title' => $orderDetail['product_name'],
                'quantity' => $product['quantity'],
                'aprice' => round($price * 100),
                'taxrate' => $calcTax * 100,
                'discount' => 0,
                'withouttax' => round(100 * ($price * $product['quantity']))
            );
            $total += round(($price * $product['quantity']) * 100);
            $tax += round(100 *(($price * $product['quantity']) * $calcTax));
        }
        $creditPaymentData['Cart']['Total'] = array(
            'withouttax' => round($total),
            'tax' => round($tax),
            'rounding' => 0,
            'withtax' => round($total + $tax)
        );
        return $creditPaymentData;
    }

    /**
     * @param $idOrderDetail
     *
     * @return float|int
     */
    protected function getCalculatedTaxRate($idOrderDetail)
    {
        $taxCalculator = OrderDetailCore::getTaxCalculatorStatic($idOrderDetail);
        $rate = $taxCalculator->getTotalRate();
        $calculatedRate = $rate / 100;

        return $calculatedRate;
    }
}