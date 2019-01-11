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

class BMDataCollector
{
    /**
     * @var Context
     */
    public $context;

    /**
     * @var BmConfigHelper
     */
    protected $configHelper;

    /**
     * @var int
     */
    protected $requestMethod = 93;

    /**
     * @var array
     */
    protected $requestData = [];

    /**
     * @var float
     */
    public $totals;

    /**
     * @var float
     */
    public $tax;

    /**
     * @var string
     */
    public $paymentMethod = '';

    /**
     * @var
     */
    protected $prepareDiscount;

    /**
     * @var bool
     */
    protected $isUpdate = false;

    public function __construct()
    {
        $this->context = Context::getContext();
        $this->configHelper = new BmConfigHelper();
    }

    /**
     * @return array
     */
    public function getRequestData()
    {
        if ($this->isUpdateMode()) {
            $this->prepareUpdCheckout();
        } else {
            $this->prepareCheckout();
        }
        $this->collectCartTotals();
        return $this->requestData;
    }

    /**
     * @return array
     */
    public function collectCartTotals()
    {
        $this->prepareArticles();
        $this->prepareDiscounts();
        $this->prepareTotals();
        return $this->requestData;
    }

    /**
     * @return $this
     */
    protected function prepareUpdCheckout()
    {
        $this->requestData['PaymentData'] = [
            'currency'      => Tools::strtoupper($this->context->currency->iso_code),
            'number' => ''
        ];
        return $this;
    }

    /**
     * @return $this
     */
    protected function prepareCheckout()
    {
        $this->requestData['PaymentData'] = array(
            'method'        => $this->requestMethod,
            'currency'      => Tools::strtoupper($this->context->currency->iso_code),
            'language'      => Tools::strtolower($this->context->language->iso_code),
            'country'       => Tools::strtoupper($this->context->country->iso_code),
            'orderid'       => Tools::substr($this->context->cart->id.'-'.time(), 0, 10),
            'logo' 			=> $this->configHelper->getBillmateLogo(),
            'accepturl'    => $this->context->link->getModuleLink('billmategateway', 'accept',      array('method' => 'checkout', 'checkout' => true), true),
            'cancelurl'    => $this->context->link->getModuleLink('billmategateway', 'cancel',      array('method' => 'checkout', 'checkout' => true), true),
            'callbackurl'  => $this->context->link->getModuleLink('billmategateway', 'callback',    array('method' => 'checkout', 'checkout' => true), true),
            'returnmethod' => $this->getReturnMethod()
        );

        $this->requestData['CheckoutData'] = array(
            'terms'             => $this->configHelper->getTermsPageUrl(),
            'windowmode'        => 'iframe',
            'sendreciept'       => 'yes',
            'redirectOnSuccess' => 'true'
        );

        $privacyUrl = $this->configHelper->getPrivacyUrl();
        if ($privacyUrl) {
            $this->requestData['CheckoutData']['privacyPolicy'] = $privacyUrl;
        }

        return $this;
    }

    /**
     * @return array
     */
    public function prepareArticles()
    {
        $cartItems    = $this->context->cart->getProducts();
        foreach ($cartItems as $cartItem) {
            $taxrate = 0;
            if($cartItem['price_wt'] != $cartItem['price']) {
                $taxrate = $cartItem['rate'];
            }

            $roundedArticle = round($cartItem['price'], 2);
            $totalArticle = $this->toCents($roundedArticle * $cartItem['cart_quantity']);

            $this->requestData['Articles'][] = array(
                'quantity'   => $cartItem['cart_quantity'],
                'title'      => $this->getItemTitle($cartItem),
                'artnr'      => $cartItem['reference'],
                'aprice'     => $this->toCents($roundedArticle),
                'taxrate'    => $taxrate,
                'discount'   => 0,
                'withouttax' => $this->toCents($roundedArticle * $cartItem['cart_quantity']),
                'total_article' => $totalArticle

            );
            if (!isset($this->prepareDiscount[$taxrate])) {
                $this->prepareDiscount[$taxrate] = $totalArticle;
            } else {
                $this->prepareDiscount[$taxrate] += $totalArticle;
            }

            $this->totals += $totalArticle;
            $this->tax += round($totalArticle * ($taxrate / 100));
        }

        //$this->prepareCartMessages();

        return $this;
    }

    /**
     * @return array
     */
    protected function prepareCartMessages()
    {
        if ($this->configHelper->isEnabledBMMessage()) {
            $cartMessage = Message::getMessageByCartId($this->context->cart->id);

            if (is_array($cartMessage) && isset($cartMessage['message']) && strlen($cartMessage['message']) > 0) {
                $this->requestData['Articles'][] = array(
                    'quantity'   => 0,
                    'title'      => ' ',
                    'artnr'      => '--freetext--',
                    'aprice'     => 0,
                    'taxrate'    => 0,
                    'discount'   => 0,
                    'withouttax' => 0

                );
                $this->requestData['Articles'][] = array(
                    'quantity'   => 0,
                    'title'      => html_entity_decode($cartMessage['message']),
                    'artnr'      => '--freetext--',
                    'aprice'     => 0,
                    'taxrate'    => 0,
                    'discount'   => 0,
                    'withouttax' => 0

                );
            }
        }
        return $this;
    }

    protected function prepareDiscounts()
    {
        if (!isset($this->coremodule) || !is_object($this->coremodule)) {
            $this->coremodule = new BillmateGateway();
        }
        $details = $this->getCartDetails();

        if (!empty($details['total_discounts'])) {
            $cartRules = $this->context->cart->getCartRules();
            $title = '';
            if (count($cartRules) > 0) {
                foreach ($cartRules as $cartRule) {
                    $title .= $cartRule['name'].' ';
                }
            }
            $totalTemp = $this->totals;
            foreach ($this->prepareDiscount as $key => $value) {
                $percent_discount = $value / ($totalTemp);
                $discount_value = $percent_discount * $details['total_discounts'];
                $discountAmount = round($discount_value / (1 + ($key / 100)),2);

                $this->requestData['Articles'][]    = array(
                    'quantity'   => 1,
                    'artnr'      => 'discount-'.$key,
                    'title'      => $title . sprintf($this->coremodule->l('Discount %s%% VAT'), $key),
                    'aprice'     => $this->toCents(-($discountAmount)),
                    'taxrate'    => $key,
                    'discount'   => 0,
                    'withouttax' => $this->toCents((-$discountAmount))
                );

                $this->totals -= $this->toCents($discountAmount);
                $this->tax -= $discountAmount * ($key / 100) * 100;
            }
        }

        if (!empty($details['gift_products'])) {
            foreach ($details['gift_products'] as $gift) {
                $discountAmount = 0;
                $taxrate        = 0;
                foreach ($this->context->cart->getProducts() as $product) {
                    $taxrate        = ($product['price_wt'] == $product['price']) ? 0 : $product['rate'];
                    $discountAmount = $product['price'];
                }
                $cartQuantity = $gift['cart_quantity'];
                $price          = $gift['price'] / $cartQuantity;
                $discountAmount = round($discountAmount / $cartQuantity,2);
                $total          = $this->toCents(-($discountAmount * $cartQuantity));
                $this->requestData['Articles'][]    = array(
                    'quantity'   => $cartQuantity,
                    'artnr'      => $this->coremodule->l('Discount'),
                    'title'      => $gift['name'],
                    'aprice'     => $price - round($this->toCents($discountAmount), 0),
                    'taxrate'    => $taxrate,
                    'discount'   => 0,
                    'withouttax' => $total
                );

                $this->totals += $total;
                $this->tax += $total * ($taxrate / 100);
            }
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function prepareTotals()
    {
        $order_total = $this->context->cart->getOrderTotal();
        $shippingCostData = $this->getShippingCostData();

        if ($shippingCostData) {
            $totalShippingCost = $shippingCostData['total_shipping_cost'];
            $taxrate = $shippingCostData['taxrate'];
            $this->requestData['Cart']['Shipping'] = array(
                'withouttax' => $this->toCents($totalShippingCost),
                'taxrate'    => $taxrate
            );
            $this->totals += $this->toCents($totalShippingCost);
            if ($taxrate > 0) {
                $this->tax += $this->toCents($totalShippingCost * ($taxrate / 100));
                $order_total += $totalShippingCost;
            }
        }

        $paymentMethod = $this->getPaymentMethod();

        $invoice_fee_tax = 0;
        $fee = 0;

        if (Configuration::get('BINVOICE_FEE') > 0 && $paymentMethod == 'invoice') {
            $fee = Configuration::get('BINVOICE_FEE');
            $invoice_fee_tax = Configuration::get('BINVOICE_FEE_TAX');
        }

        if (Configuration::get('BINVOICESERVICE_FEE') > 0 && $paymentMethod == 'invoiceservice') {
            $fee           = Configuration::get('BINVOICESERVICE_FEE');
            $invoice_fee_tax = Configuration::get('BINVOICESERVICE_FEE_TAX');
        }

        if ($invoice_fee_tax && $fee) {
            $tax                = new Tax($invoice_fee_tax);
            $tax_calculator      = new TaxCalculator(array($tax));
            $tax_rate            = $tax_calculator->getTotalRate();
            $fee = Tools::convertPriceFull($fee,null,$this->context->currency);
            $fee = round($fee,2);
            $this->requestData['Cart']['Handling'] = array(
                'withouttax' => $fee * 100,
                'taxrate'    => $tax_rate
            );
            $this->handling_fee = $fee;
            $this->handling_taxrate = $tax_rate;
            $order_total += $fee * (1 + ($tax_rate / 100));
            $this->totals += $fee * 100;
            $this->tax += (($tax_rate / 100) * $fee) * 100;
        }

        $rounding = round($order_total * 100) - round($this->tax + $this->totals);
        $this->requestData['Cart']['Total']  = array(
            'withouttax' => round($this->totals),
            'tax'        => round($this->tax),
            'rounding'   => round($rounding),
            'withtax'    => round($this->totals + $this->tax + $rounding)
        );

        $this->paid_amount = $this->requestData['Cart']['Total']['withtax'];

        return $this;
    }

    /**
     * @return array
     */
    protected function getShippingCostData()
    {
        $details    = $this->getCartDetails();
        $isFree    = (isset($details['free_ship']) && $details['free_ship'] == 1);
        $carrier    = $details['carrier'];
        $shippingCostData = [];
        if ($carrier->active && !$isFree) {
            $total_shipping_cost  = round($this->context->cart->getTotalShippingCost(null, false),2);
            $taxrate = 0;
            if (intval($carrier->id_reference) > 0) {
                $carrier_obj = new Carrier($this->context->cart->id_carrier, $this->context->cart->id_lang);
                $taxrate    = $carrier_obj->getTaxesRate(new Address($this->context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')}));

                if ($taxrate == 0) {
                    $selected_deliver_option_id = (int)current($this->context->cart->getDeliveryOption());
                    $carrier = new Carrier($selected_deliver_option_id, $this->context->cart->id_lang);
                    $taxrate = $carrier->getTaxesRate(Address::initialize(0));
                }

                $totalShippingCostIncTax = 0;
                if ($taxrate == 0) {
                    $totalShippingCostIncTax  = round($this->context->cart->getTotalShippingCost(null, true),2);
                    if ($total_shipping_cost < $totalShippingCostIncTax && $total_shipping_cost > 0 && $totalShippingCostIncTax > 0) {
                        $taxrate = round((($totalShippingCostIncTax - $total_shipping_cost) / $total_shipping_cost) * 100);
                    }
                }

                if (
                    $totalShippingCostIncTax == 0
                    && $taxrate > 0
                    && $total_shipping_cost > 0
                    && intval($total_shipping_cost) == $total_shipping_cost
                ) {
                    if ($totalShippingCostIncTax == 0) {
                        $totalShippingCostIncTax  = round($this->context->cart->getTotalShippingCost(null, true) ,2);
                    }
                    if ($total_shipping_cost < $totalShippingCostIncTax && $total_shipping_cost > 0 && $totalShippingCostIncTax > 0) {
                        $total_shipping_cost = ($totalShippingCostIncTax / (1 + ($taxrate/100)));
                        $total_shipping_cost = round($total_shipping_cost ,2);
                    }
                }
            } elseif ($total_shipping_cost == 0) {
                $deliveryOptionData = $this->getDeliveryOptionData();
                $total_shipping_cost = $deliveryOptionData['price_without_tax'];
                $totalShippingCostIncTax = $deliveryOptionData['price_with_tax'];
                if ($taxrate == 0) {
                    if ($total_shipping_cost < $totalShippingCostIncTax && $total_shipping_cost > 0 && $totalShippingCostIncTax > 0) {
                        $taxrate = round((($totalShippingCostIncTax - $total_shipping_cost) / $total_shipping_cost) * 100);
                    }
                }
            }
            $shippingCostData['total_shipping_cost'] = $total_shipping_cost;
            $shippingCostData['taxrate'] = $taxrate;
        }
        return $shippingCostData;
    }

    /**
     * @return int|mixed|null|string
     */
    public function getDeliveryOption()
    {

        $delivery_option = null;

        if (intval($this->context->cart->id_customer) > 0) {
            $delivery_option = $this->context->cart->getDeliveryOption(null, false, false);
            if (version_compare(_PS_VERSION_,'1.7','>=')) {
                $delivery_option = $this->context->cart->simulateCarrierSelectedOutput();
                $delivery_option = Cart::desintifier($delivery_option);
            }
        } else {
            $delivery_option = $this->context->cart->getDeliveryOption(null, false, false);
            if (version_compare(_PS_VERSION_,'1.7','>=')) {
                $delivery_option_seriaized = $this->context->cart->delivery_option;
                if (version_compare(_PS_VERSION_,'1.7.4.4','>=')) {
                    $delivery_option_unserialized = json_decode($delivery_option_seriaized);
                } else {
                    $delivery_option_unserialized = unserialize($delivery_option_seriaized);
                }
                if (is_array($delivery_option_unserialized) && isset($delivery_option_unserialized[0])) {
                    $delivery_option = $delivery_option_unserialized[0];
                }
            }
        }

        if (!$delivery_option) {
            $delivery_option_list = $this->getDeliveryOptions();
            foreach ($delivery_option_list AS $i => $packages) {
                foreach ($packages AS $iii => $carriers) {
                    if ($carriers['is_best_price']) {
                        $delivery_option = $iii;
                        break;
                    }
                }
            }
            if (version_compare(_PS_VERSION_,'1.7','<')) {
                $delivery_option = array(
                    0 => $delivery_option
                );
            }
        }
        return $delivery_option;
    }

    /**
     * @return array
     */
    public function getDeliveryOptionData()
    {
        $delivery_option = $this->getDeliveryOption();
        if (is_array($delivery_option)) {
            $delivery_option = current($delivery_option);
        }
        $delivery_option_list = $this->getDeliveryOptions();
        foreach ($delivery_option_list AS $i => $packages) {
            foreach ($packages AS $iii => $carriers) {
                if ($iii == $delivery_option) {
                    foreach ($carriers['carrier_list'] AS $iv => $carrier) {
                        return $carrier;
                    }
                    break;
                }
            }
        }
        return array();
    }

    /**
     * @return array
     */
    public function getDeliveryOptions()
    {
        if (intval($this->context->cart->id_customer) > 0) {
            if ($this->context->cart->id_address_delivery > 0) {
                $delivery_address = new Address($this->context->cart->id_address_delivery);
                if(isset($delivery_address->id_country)) {
                    $delivery_option_list = $this->context->cart->getDeliveryOptionList(
                        new Country($delivery_address->id_country),
                        true
                    );
                } else {
                    $delivery_option_list = $this->context->cart->getDeliveryOptionList();
                }
            } else {
                $delivery_option_list = $this->context->cart->getDeliveryOptionList();
            }

            foreach ($delivery_option_list AS $i => $packages) {
                foreach ($packages AS $iii => $carriers) {
                    foreach ($carriers['carrier_list'] AS $iv => $carrier) {
                        if (isset($delivery_option_list[$i][$iii]['carrier_list'][$iv]['instance'])) {
                            unset($delivery_option_list[$i][$iii]['carrier_list'][$iv]['instance']);
                        }
                        if (isset($delivery_option_list[$i][$iii]['carrier_list'][$iv]['package_list'])) {
                            unset($delivery_option_list[$i][$iii]['carrier_list'][$iv]['package_list']);
                        }
                        if (isset($delivery_option_list[$i][$iii]['carrier_list'][$iv]['product_list'])) {
                            unset($delivery_option_list[$i][$iii]['carrier_list'][$iv]['product_list']);
                        }
                        $delivery_option_list[$i][$iii]['carrier_list'][$iv]['instance'] = new Carrier($iv);
                    }
                }
            }
            return $delivery_option_list;
        }

        $carriers = $this->getCarriers();

        $delivery_option_list_from_carriers =  array(
            0 => array()
        );
        $grades = array();  // id => grade
        $prices = array();  // id => price
        $instances = array(); // id => instance
        foreach ($carriers AS $carrier) {
            $id_carrier = $carrier['id_carrier'];
            $instance = new Carrier($id_carrier);
            $instances[$id_carrier] = $instance;
            $grades[$id_carrier] = $instance->grade;
            $prices[$id_carrier] = $carrier['price'];
        }

        $best_grade     = null;
        $best_grade_id  = null;
        $best_price     = null;
        $best_price_id  = null;

        foreach ($carriers AS $carrier) {
            $id_carrier = $carrier['id_carrier'];
            $grade = $grades[$id_carrier];
            $price_with_tax = $prices[$id_carrier];
            if (is_null($best_price) || $price_with_tax < $best_price) {
                $best_price = $price_with_tax;
                $best_price_carrier = $id_carrier;
            }
            if (is_null($best_grade) || $grade > $best_grade) {
                $best_grade = $grade;
                $best_grade_carrier = $id_carrier;
            }
        }

        foreach ($carriers AS $carrier) {
            $id_carrier = $carrier['id_carrier'];
            $key = $id_carrier . ',';
            $delivery_option_list_from_carriers[0][$key] = array(
                'carrier_list' => array(
                    $id_carrier => array(
                        'price_with_tax' => $carrier['price'],
                        'price_without_tax' => $carrier['price_tax_exc'],
                        'logo' => $carrier['img'],
                        'instance' => $instances[$id_carrier]
                    )
                ),
                'is_best_price' => ($best_price_carrier == $id_carrier),
                'is_best_grade' => ($best_grade_carrier == $id_carrier),
                'unique_carrier' => true,
                'total_price_with_tax' => $carrier['price'],
                'total_price_without_tax' => $carrier['price_tax_exc'],
                'is_free' => null,
                'position' => $carrier['position'],
            );
        }
        return $delivery_option_list_from_carriers;
    }

    public function getCarriers()
    {
        if (intval($this->context->cart->id_customer) > 0) {
            $carriers = $this->context->cart->simulateCarriersOutput();
        } else {
            $country_id = Country::getByIso('SE');
            $country = new Country($country_id);
            $id_zone = $country->id_zone;
            $ps_guest_group = Configuration::get('PS_GUEST_GROUP');
            $groups = array($ps_guest_group);
            $carriers = Carrier::getCarriersForOrder($id_zone, $groups);
        }
        return $carriers;
    }

    /**
     * @param int $requestMethod
     */
    public function setRequestMethod($requestMethod)
    {
        $this->requestMethod = $requestMethod;
    }

    /**
     * @param string $paymentMethod
     */
    public function setPaymentMethod($paymentMethod)
    {
        $this->paymentMethod = $paymentMethod;
    }

    /**
     * @return string
     */
    public function getPaymentMethod()
    {
        return $this->paymentMethod;
    }

    /**
     * @return string
     */
    protected function getReturnMethod()
    {
        return (array_key_exists('HTTPS', $_SERVER) && $_SERVER['HTTPS'] == "on") ?'POST' : 'GET';
    }

    protected function getItemTitle($cartItem)
    {
        if (isset($cartItem['attributes']) && !empty($cartItem['attributes'])) {
            return $cartItem['name'] . ' - ' . $cartItem['attributes'];
        }
        return $cartItem['name'];
    }

    /**
     * @return array
     */
    protected function getCartDetails()
    {
        return $this->context->cart->getSummaryDetails(null, true);
    }

    /**
     * @param $value
     *
     * @return float
     */
    protected function toCents($value)
    {
        return $value * 100;
    }

    /**
     * @param $value
     */
    public function setUpdateMode($value)
    {
        $this->isUpdate = (bool)$value;
    }

    /**
     * @return bool
     */
    public function isUpdateMode()
    {
        return $this->isUpdate;
    }
}