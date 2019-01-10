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

class BillmategatewayBillmatecheckoutModuleFrontController extends BaseBmFront
{

    public $php_self = 'order-opc';

    public $display_column_left = false;
    public $display_column_right = false;
    public $ssl = true;

    public $totals;
    public $tax;
    public $method = 'invoice';

    const REQUEST_METHOD = 93;

    public function setAddress($customer = array())
    {
        $address = $customer['Billing'];
        $country = isset($customer['Billing']['country']) ? $customer['Billing']['country'] : 'SE';
        $bill_phone = isset($customer['Billing']['phone']) ? $customer['Billing']['phone'] : '';
        $logfile   = _PS_CACHE_DIR_.'Billmate.log';

        $isNewCustomer = false;
        $deliveryOption = $this->getDeliveryOption();

        if($this->context->cart->id_customer == 0) {
            // Create a guest customer
            $customerObject = new Customer();
            $password = Tools::passwdGen(8);
            $customerObject->firstname = !empty($address['firstname']) ? $address['firstname'] : '';
            $customerObject->lastname  = !empty($address['lastname']) ? $address['lastname'] : '';
            $customerObject->company   = isset($address['company']) ? $address['company'] : '';
            $customerObject->passwd = $password;
            $customerObject->id_default_group = (int) (Configuration::get('PS_CUSTOMER_GROUP', null, $this->context->cart->id_shop));
            $customerObject->email = $address['email'];
            $customerObject->active = true;
            $customerObject->is_guest = true;
            $customerObject->add();
            $this->context->customer = $customerObject;
            $this->context->cart->secure_key = $customerObject->secure_key;
            $this->context->cart->id_customer = $customerObject->id;
            $isNewCustomer = true;
        }

        $_customer = new Customer($this->context->cart->id_customer);
        $customer_addresses = $_customer->getAddresses($this->context->language->id);

        if (count($customer_addresses) == 1)
            $customer_addresses[] = $customer_addresses;

        $matched_address_id = false;
        foreach ($customer_addresses as $customer_address)
        {
            if (isset($customer_address['address1']))
            {
                $billing  = new Address($customer_address['id_address']);

                $user_bill = $billing->firstname.' '.$billing->lastname.' '.$billing->company;
                $company = isset($address['company']) ? $address['company'] : '';
                $api_name = $address['firstname']. ' '. $address['lastname'].' '.$company;

                if (Common::matchstr($user_bill,$api_name) && Common::matchstr($customer_address['address1'], $address['street']) &&
                    Common::matchstr($customer_address['postcode'], $address['zip']) &&
                    Common::matchstr($customer_address['city'], $address['city']) &&
                    Common::matchstr(Country::getIsoById($customer_address['id_country']), $address['country']))

                    $matched_address_id = $customer_address['id_address'];
            }
            else
            {
                foreach ($customer_address as $c_address)
                {
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
        if (!$matched_address_id)
        {
            $addressnew              = new Address();
            $addressnew->id_customer = (int)$this->context->cart->id_customer;
            $addressnew->firstname = !empty($address['firstname']) ? $address['firstname'] : $billing->firstname;
            $addressnew->lastname  = !empty($address['lastname']) ? $address['lastname'] : $billing->lastname;
            $addressnew->company   = isset($address['company']) ? $address['company'] : '';
            $addressnew->phone        = $address['phone'];
            $addressnew->phone_mobile = $address['phone'];
            $addressnew->address1 = $address['street'];
            $addressnew->postcode = $address['zip'];
            $addressnew->city     = $address['city'];
            $addressnew->country  = $address['country'];
            $addressnew->alias    = 'Bimport-'.date('Y-m-d');
            $addressnew->id_country = Country::getByIso($address['country']);
            $addressnew->save();
            $matched_address_id = $addressnew->id;
        }
        $billing_address_id = $shipping_address_id = $matched_address_id;

        if (    isset($customer['Shipping']) AND
                is_array($customer['Shipping']) AND
                isset($customer['Shipping']['firstname']) AND
                isset($customer['Shipping']['lastname']) AND
                isset($customer['Shipping']['street']) AND
                isset($customer['Shipping']['zip']) AND
                isset($customer['Shipping']['city']) AND

                $customer['Shipping']['firstname'] != '' AND
                $customer['Shipping']['lastname'] != '' AND
                $customer['Shipping']['street'] != '' AND
                $customer['Shipping']['zip'] != '' AND
                $customer['Shipping']['city'] != ''
        ) {
            $address = $customer['Shipping'];
            file_put_contents($logfile, 'shippingAddress:'.print_r($address,true),FILE_APPEND);
            file_put_contents($logfile, 'customerAddress:'.print_r($customer_addresses,true),FILE_APPEND);
            $matched_address_id = false;
            foreach ($customer_addresses as $customer_address)
            {
                if (isset($customer_address['address1']))
                {
                    $billing  = new Address($customer_address['id_address']);
                    $user_bill = $billing->firstname.' '.$billing->lastname.' '.$billing->company;
                    $company = isset($address['company']) ? $address['company'] : '';
                    $api_name = $address['firstname']. ' '. $address['lastname'].' '.$company;
                    if (Common::matchstr($user_bill,$api_name) && Common::matchstr($customer_address['address1'], $address['street']) &&
                        Common::matchstr($customer_address['postcode'], $address['zip']) &&
                        Common::matchstr($customer_address['city'], $address['city']) &&
                        Common::matchstr(Country::getIsoById($customer_address['id_country']), isset($address['country']) ? $address['country'] : $country))
                        $matched_address_id = $customer_address['id_address'];
                }
                else
                {
                    foreach ($customer_address as $c_address)
                    {
                        $billing  = new Address($c_address['id_address']);
                        $user_bill = $billing->firstname.' '.$billing->lastname.' '.$billing->company;
                        $company = isset($address['company']) ? $address['company'] : '';
                        $api_name = $address['firstname']. ' '. $address['lastname'].' '.$company;
                        if (Common::matchstr($user_bill,$api_name) &&  Common::matchstr($c_address['address1'], $address['street']) &&
                            Common::matchstr($c_address['postcode'], $address['zip']) &&
                            Common::matchstr($c_address['city'], $address['city']) &&
                            Common::matchstr(Country::getIsoById($c_address['id_country']), isset($address['country']) ? $address['country'] : $country)
                        )
                            $matched_address_id = $c_address['id_address'];
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

        $this->context->cart->update();
        $this->context->cart->save();
        CartRule::autoRemoveFromCart($this->context);
        CartRule::autoAddToCart($this->context);

        $this->actionSetShipping();

        if ($isNewCustomer) {
            if (is_array($deliveryOption) && isset($deliveryOption[0])) {
                $deliveryOption = $deliveryOption[0];
            }
            $this->context->cart->setDeliveryOption(array($this->context->cart->id_address_delivery => $deliveryOption));
            $this->context->cart->update();
            $this->context->cart->save();
            CartRule::autoRemoveFromCart($this->context);
            CartRule::autoAddToCart($this->context);
        }
    }

    public function postProcess() {
        $response = array();

        if (CartRule::isFeatureActive()) {
            if (Tools::isSubmit('submitAddDiscount')) {
                if (!($code = trim(Tools::getValue('discount_name')))) {
                    $this->errors[] = Tools::displayError('You must enter a voucher code.');
                } elseif (!Validate::isCleanHtml($code)) {
                    $this->errors[] = Tools::displayError('The voucher code is invalid.');
                } else {
                    if (($cartRule = new CartRule(CartRule::getIdByCode($code))) && Validate::isLoadedObject($cartRule)) {
                        if ($error = $cartRule->checkValidity($this->context, false, true)) {
                            $this->errors[] = $error;
                        } else {
                            $this->context->cart->addCartRule($cartRule->id);
                            CartRule::autoAddToCart($this->context);
                            if (Configuration::get('PS_ORDER_PROCESS_TYPE') == 1) {
                            }
                        }
                    } else {
                        $this->errors[] = Tools::displayError('This voucher does not exists.');
                    }
                }
                $this->context->smarty->assign(array(
                    'errors' => $this->errors,
                    'discount_name' => Tools::safeOutput($code)
                ));
            } elseif (($id_cart_rule = (int)Tools::getValue('deleteDiscount')) && Validate::isUnsignedId($id_cart_rule)) {
                $this->context->cart->removeCartRule($id_cart_rule);
                CartRule::autoAddToCart($this->context);
            }
        }

        $this->ajax = Tools::getValue( "ajax" );
        if( $this->ajax && Tools::getValue('action') == 'setShipping') {
            echo Tools::jsonEncode($this->actionSetShipping());
            die();
        }

        if (
            $this->ajax &&
            Tools::getValue('action') == 'updateCheckout'
        ) {
            if($this->context->cart->nbProducts() == 0){
                echo Tools::jsonEncode(array('success' => false));
                die();
            }
            $values = $this->fetchCheckout();
            $result = $this->updateCheckout($values);
            $result['success'] = true;
            echo Tools::jsonEncode($result);
            die;
            
        }

        if (
            $this->ajax &&
            Tools::getValue('action') == 'setAddress'
        ) {
            if (isset($_POST['Customer'])) {
                $customer = $_POST['Customer'];
            } else {
                $result = $this->fetchCheckout();
                $customer = $result['Customer'];
            }

            $this->setAddress($customer);
            $carrierBlock = $this->_getCarrierList();
            $response['success'] = true;
            $response['carrier_block'] = $carrierBlock['carrier_block'];
            echo Tools::jsonEncode($response);
            die;
        }

        if (
            $this->ajax &&
            Tools::getValue('action') == 'setPaymentMethod'
        ) {
            $checkout = $this->fetchCheckout();
            if (!isset($checkout['code'])) {
                switch ($checkout['PaymentData']['method']) {
                    case '4':
                        $this->method = 'partpay';
                        break;
                    case '8':
                        $this->method = 'cardpay';
                        break;
                    case '16':
                        $this->method = 'bankpay';
                        break;
                    case '1024':
                        $this->method = 'swish';
                        break;
                    default:
                        $this->method = 'invoice';
                        break;

                }
            }
            $checkout['debug'] = 'setPaymentMethod';
            $this->updateCheckout($checkout);
            echo Tools::jsonEncode(array('success' => true));
            die;
        }

        if (
            $this->ajax &&
            Tools::getValue('action') == 'validateOrder'
        ) {
            $checkout = $this->fetchCheckout();
            $this->ajax = true;
            $result = $this->sendResponse($checkout);
            
            echo Tools::jsonEncode($result);
            die();
        }
    }

    public function actionSetShipping() {
        $result = array();
        if (Tools::getIsset('delivery_option')) {
            $validated = false;
            try {
                $delivery_option = Tools::getValue('delivery_option');
                if (!is_array($delivery_option)) {
                    $delivery_option = array(
                        $this->context->cart->id_address_delivery => $delivery_option
                    );
                }

                if ($this->validateDeliveryOption($delivery_option)) {
                    $validated = true;
                    if(version_compare(_PS_VERSION_,'1.7','>=')) {
                        $deliveryOption =  $delivery_option;
                        $realOption = array();
                        foreach ($deliveryOption as $key => $value) {
                            if($this->context->cart->id_customer == 0) {
                                $realOption[$key] = $value . ',';
                            } else {
                                $realOption[$key] = Cart::desintifier($value);
                            }
                        }
                        $this->context->cart->setDeliveryOption($realOption);
                    } else {
                        $this->context->cart->setDeliveryOption($delivery_option);
                    }
                }
                $updated = true;
                $cartUpdateResult = $this->context->cart->update();
                if (!$cartUpdateResult) {
                    $updated = false;
                    $this->context->smarty->assign(array(
                        'vouchererrors' => Tools::displayError('Could not save carrier selection'),
                    ));
                }
                $this->context->cart->save();

                CartRule::autoRemoveFromCart($this->context);
                CartRule::autoAddToCart($this->context);
                $values = $this->fetchCheckout();
                $result = $this->updateCheckout($values);
                $result['validatedDelivery'] = $validated;
                $result['updated'] = $updated;
                $result['success'] = true;
            } catch(Exception $e){
                $result['success'] = false;
                $result['message'] = $e->getMessage();
                $result['trace'] = $e;
            }

        }
        return $result;
    }

    public function sendResponse($result)
    {
        $return = array();
        $billmate = $this->getBillmate();

        $class = "BillmateMethod".Tools::ucfirst($this->method);
        $this->module = new $class;
        switch ($this->method) {
            case 'invoice':
            case 'partpay':
            case 'invoiceservice':
                if (!isset($result['code']) && (isset($result['PaymentData']['order']['number']) && is_numeric($result['PaymentData']['order']['number']) && $result['PaymentData']['order']['number'] > 0)) {

                    $status = ($this->method == 'invoice') ? Configuration::get('BINVOICE_ORDER_STATUS') : Configuration::get('BPARTPAY_ORDER_STATUS');
                    $status = ($this->method == 'invoiceservice') ? Configuration::get('BINVOICESERVICE_ORDER_STATUS') : $status;
                    $status = ($result['PaymentData']['order']['status'] == 'Pending') ? Configuration::get('BILLMATE_PAYMENT_PENDING') : $status;

                    if (Validate::isLoadedObject($this->context->cart) && $this->context->cart->OrderExists() == false) {
                        $extra = array('transaction_id' => $result['PaymentData']['order']['number']);
                        $total = $result['Cart']['Total']['withtax'];
                        $total = $total/100;
                        $customer = new Customer((int)$this->context->cart->id_customer);
                        if ($this->method == 'partpay') {
                            $this->module->validateOrder((int)$this->context->cart->id,
                                $status,
                                $total,
                                $this->module->displayName,
                                null, $extra, null, false, $customer->secure_key);
                        } else {
                            $this->module->validateOrder((int)$this->context->cart->id,
                                $status,
                                $total,
                                $this->module->displayName,
                                null, $extra, null, false, $customer->secure_key);
                        }
                        $values = array();
                        $values['PaymentData'] = array(
                            'number' => $result['PaymentData']['order']['number'],
                            'orderid' => (Configuration::get('BILLMATE_SEND_REFERENCE') == 'reference') ? $this->module->currentOrderReference : $this->module->currentOrder
                        );

                        $billmate->updatePayment($values);
                    }
                    $url = $this->context->link->getModuleLink(
                        'billmategateway',
                        'thankyou',
                        array('billmate_hash' => Common::getCartCheckoutHash())
                    );

                    $return['success'] = true;
                    $return['redirect'] = $url;
                    if (isset($this->context->cookie->billmatepno))
                        unset($this->context->cookie->billmatepno);
                    Common::unsetCartCheckoutHash();
                } else {
                    if (isset($result['code']) AND in_array($result['code'], array(2401, 2402, 2403, 2404, 2405))) {
                        if (is_array($result)) {
                            die(Tools::jsonEncode($result));
                        }
                    }
                    $_message = (isset($result['message'])) ? $result['message'] : '';
                    $return = array('success' => false, 'content' => utf8_encode($_message));
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
                }
                else {
                    $return = array('success' => false, 'content' => utf8_encode($result['message']));
                }

                break;
        }
        return $return;
    }

    public function initContent()
    {
        parent::initContent();
        if($this->context->cart->nbProducts() == 0){
            if(version_compare(_PS_VERSION_,'1.7','>=')){
                $this->setTemplate('module:billmategateway/views/templates/front/checkout/checkout-empty17.tpl');

            } else {
                $this->setTemplate('checkout/checkout-empty.tpl');
            }
        } else {
            CartRule::autoRemoveFromCart($this->context);
            CartRule::autoAddToCart($this->context);

            $billmatecheckouturl = $this->getCheckout();

            $this->context->smarty->assign('billmatecheckouturl', $billmatecheckouturl);
            $carrierBlock = $this->_getCarrierList();
            $this->context->smarty->assign('carrier_block',$carrierBlock['carrier_block']);

            $free_shipping = false;
            foreach ($this->context->cart->getCartRules() as $rule) {
                if ($rule['free_shipping']) {
                    $free_shipping = true;
                    break;
                }
            }

            $delivery_option = $this->getDeliveryOption();
            $delivery_option_list = $this->getDeliveryOptions();

            if(version_compare(_PS_VERSION_,'1.7','>=')) {
                if (intval($this->context->cart->id_customer) > 0) {
                    $delivery_option = Cart::intifier($delivery_option);
                }
            }

            $this->assignSummary();
            $this->context->smarty->assign(array(
                'free_shipping' => $free_shipping,
                'token_cart' => $this->context->cart->secure_key,
                'delivery_option_list' => $delivery_option_list,
                'delivery_option' => $delivery_option,
                'back' => ''
            ));

            $delivery_option_data = $this->getDeliveryOptionData();
            $shipping_price_with_tax = $delivery_option_data['price_with_tax'];

            if ($shipping_price_with_tax > 0) {
                $shipping_price_with_tax = $delivery_option_data['price_with_tax'];
                $shipping_price_without_tax = $delivery_option_data['price_without_tax'];

                if(version_compare(_PS_VERSION_,'1.7','>=')) {
                    $priceFormatter = new PrestaShop\PrestaShop\Adapter\Product\PriceFormatter();
                    $taxConfiguration = new TaxConfiguration();
                    $getTemplateVars = $this->context->smarty->getTemplateVars();
                    $templateCart = $getTemplateVars['cart'];
                    if ($templateCart['subtotals']['shipping']['amount'] < 1) {
                        $templateCart['totals']['total_including_tax']['amount'] += $shipping_price_with_tax;
                        $templateCart['totals']['total_including_tax']['value'] = $priceFormatter->format($templateCart['totals']['total_including_tax']['amount']);
                        $templateCart['totals']['total_excluding_tax']['amount'] += $shipping_price_without_tax;
                        $templateCart['totals']['total_excluding_tax']['value'] = $priceFormatter->format($templateCart['totals']['total_excluding_tax']['amount']);
                        $templateCart['totals']['total']['amount'] = $templateCart['totals']['total_including_tax']['amount'];
                        $templateCart['totals']['total']['value'] = $templateCart['totals']['total_including_tax']['value'];

                        if (!$taxConfiguration->includeTaxes()) {
                            // Show prices excluding tax
                            $templateCart['totals']['total']['amount'] = $templateCart['totals']['total_excluding_tax']['amount'];
                            $templateCart['totals']['total']['value'] = $templateCart['totals']['total_excluding_tax']['value'];
                        }

                        $templateCart['subtotals']['shipping']['amount'] = $shipping_price_with_tax;
                        $templateCart['subtotals']['shipping']['value'] = $priceFormatter->format($shipping_price_with_tax);
                        $this->context->smarty->assign('cart', $templateCart);
                    }
                }
            }

            if (version_compare(_PS_VERSION_,'1.7','>=')) {
                $this->setTemplate('module:billmategateway/views/templates/front/checkout/checkout17.tpl');
            } else {
                $this->setTemplate('checkout/checkout.tpl');
            }
        }
    }

    protected function assignSummary()
    {
        $summary = $this->context->cart->getSummaryDetails();
        $customDates = Product::getAllCustomizedDatas($this->context->cart->id);

        if ($customDates) {
            foreach ($summary['products'] as &$productUpdate) {
                if (isset($productUpdate['id_product'])) {
                    $productId = (int)$productUpdate['id_product'];
                } else {
                    $productId = (int)$productUpdate['product_id'];
                }

                if (isset($productUpdate['id_product_attribute'])) {
                    $productAttributeId = (int)$productUpdate['id_product_attribute'];
                } else {
                    $productAttributeId = (int)$productUpdate['product_attribute_id'];
                }

                if (isset($customDates[$productId][$productAttributeId])) {
                    $productUpdate['tax_rate'] = Tax::getProductTaxRate(
                        $productId,
                        $this->context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')}
                    );
                }
            }

            Product::addCustomizationPrice($summary['products'], $customDates);
        }

        $cart_product_context = Context::getContext()->cloneContext();
        foreach ($summary['products'] as $key => &$product) {

            if ($cart_product_context->shop->id != $product['id_shop']) {
                $cart_product_context->shop = new Shop((int)$product['id_shop']);
            }
            $specific_price_output = null;
            $product['price_without_specific_price'] = Product::getPriceStatic(
                $product['id_product'],
                !Product::getTaxCalculationMethod(),
                $product['id_product_attribute'],
                2,
                null,
                false,
                false,
                1,
                false,
                null,
                null,
                null,
                $specific_price_output,
                true,
                true,
                $cart_product_context
            );

            if (Product::getTaxCalculationMethod()) {
                $product['is_discounted'] = $product['price_without_specific_price'] != $product['price'];
            } else {
                $product['is_discounted'] = $product['price_without_specific_price'] != $product['price_wt'];
            }
        }

        $available_cart_rules = CartRule::getCustomerCartRules(
            $this->context->language->id,
            (isset($this->context->customer->id) ? $this->context->customer->id : 0),
            true,
            true,
            true,
            $this->context->cart
        );

        $cart_cart_rules = $this->context->cart->getCartRules();
        foreach ($available_cart_rules as $key => $available_cart_rule) {
            if (!$available_cart_rule['highlight'] || strpos($available_cart_rule['code'], 'BO_ORDER_') === 0) {
                unset($available_cart_rules[$key]);
                continue;
            }
            foreach ($cart_cart_rules as $cart_cart_rule) {
                if ($available_cart_rule['id_cart_rule'] == $cart_cart_rule['id_cart_rule']) {
                    unset($available_cart_rules[$key]);
                    continue 2;
                }
            }
        }

        $show_option_allow_separate_package = (!$this->context->cart->isAllProductsInStock(true) &&
            Configuration::get('PS_SHIP_WHEN_AVAILABLE'));

        $this->context->smarty->assign($summary);
        $this->context->smarty->assign(array(
            'token_cart' => Tools::getToken(false),
            'isVirtualCart' => $this->context->cart->isVirtualCart(),
            'productNumber' => $this->context->cart->nbProducts(),
            'voucherAllowed' => CartRule::isFeatureActive(),
            'shippingCost' => $this->context->cart->getOrderTotal(true, Cart::ONLY_SHIPPING),
            'shippingCostTaxExc' => $this->context->cart->getOrderTotal(false, Cart::ONLY_SHIPPING),
            'customizedDatas' => $customDates,
            'CUSTOMIZE_FILE' => Product::CUSTOMIZE_FILE,
            'CUSTOMIZE_TEXTFIELD' => Product::CUSTOMIZE_TEXTFIELD,
            'lastProductAdded' => $this->context->cart->getLastProduct(),
            'displayVouchers' => $available_cart_rules,
            'advanced_payment_api' => true,
            'currencySign' => $this->context->currency->sign,
            'currencyRate' => $this->context->currency->conversion_rate,
            'currencyFormat' => $this->context->currency->format,
            'currencyBlank' => $this->context->currency->blank,
            'show_option_allow_separate_package' => $show_option_allow_separate_package,
            'smallSize' => Image::getSize(ImageType::getFormatedName('small')),
        ));

        $this->context->smarty->assign(array(
            'HOOK_SHOPPING_CART' => Hook::exec('displayShoppingCartFooter', $summary),
            'HOOK_SHOPPING_CART_EXTRA' => Hook::exec('displayShoppingCart', $summary),
        ));
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

    private function getCarriers()
    {
        if (intval($this->context->cart->id_customer) > 0) {
            $carriers = $this->context->cart->simulateCarriersOutput();
        } else {
            // Cart have no customer
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
     * @return int|mixed|null|string
     */
    protected function getDeliveryOption()
    {
        return $this->getBMDataCollector()->getDeliveryOption();
    }

    /**
     * @return array
     */
    protected function getDeliveryOptionData()
    {
        return $this->getBMDataCollector()->getDeliveryOptionData();
    }

    /**
     * @return array
     */
    protected function getDeliveryOptions()
    {
        return $this->getBMDataCollector()->getDeliveryOptions();
    }

    protected function _getCarrierList()
    {
        $address_delivery = new Address($this->context->cart->id_address_delivery);

        $cms = new CMS(Configuration::get('PS_CONDITIONS_CMS_ID'), $this->context->language->id);
        $link_conditions = $this->context->link->getCMSLink($cms, $cms->link_rewrite, Configuration::get('PS_SSL_ENABLED'));
        if (!strpos($link_conditions, '?')) {
            $link_conditions .= '?content_only=1';
        } else {
            $link_conditions .= '&content_only=1';
        }

        $carriers = $this->getCarriers();

        $wrapping_fees = $this->context->cart->getGiftWrappingPrice(false);
        $wrapping_fees_tax_inc = $this->context->cart->getGiftWrappingPrice();
        $old_message = Message::getMessageByCartId((int)$this->context->cart->id);

        $free_shipping = false;
        foreach ($this->context->cart->getCartRules() as $rule) {
            if ($rule['free_shipping'] && !$rule['carrier_restriction']) {
                $free_shipping = true;
                break;
            }
        }

        $carriers17 = array();
        foreach ($carriers as $carrier) {
            $temp = $carrier;
            $temp['logo'] = false;
            $temp['id'] = $carrier['id_carrier'];
            $temp['extraContent'] = '';
            $carriers17[$carrier['id_carrier']] = $temp;
        }

        $this->context->smarty->assign('isVirtualCart', $this->context->cart->isVirtualCart());

        $delivery_option = $this->getDeliveryOption();
        $delivery_option_list = $this->getDeliveryOptions();

        if(version_compare(_PS_VERSION_,'1.7','>=')) {
            if (intval($this->context->cart->id_customer) > 0) {
                $delivery_option = Cart::intifier($delivery_option);
            }
        }

        if(version_compare(_PS_VERSION_,'1.7.4.4','>=') && is_array($delivery_option)) {
            $delivery_option = current($delivery_option);
        }

        $vars = array(
            'advanced_payment_api' => (bool)Configuration::get('PS_ADVANCED_PAYMENT_API'),
            'free_shipping' => $free_shipping,
            'checkedTOS' => (int)$this->context->cookie->checkedTOS,
            'recyclablePackAllowed' => (int)Configuration::get('PS_RECYCLABLE_PACK'),
            'giftAllowed' => (int)Configuration::get('PS_GIFT_WRAPPING'),
            'gift' => array('allowed' => (int)Configuration::get('PS_GIFT_WRAPPING')),
            'cms_id' => (int)Configuration::get('PS_CONDITIONS_CMS_ID'),
            'conditions' => (int)Configuration::get('PS_CONDITIONS'),
            'link_conditions' => $link_conditions,
            'recyclable' => (int)$this->context->cart->recyclable,
            'gift_wrapping_price' => (float)$wrapping_fees,
            'total_wrapping_cost' => Tools::convertPrice($wrapping_fees_tax_inc, $this->context->currency),
            'total_wrapping_tax_exc_cost' => Tools::convertPrice($wrapping_fees, $this->context->currency),
            'delivery_option_list' => $delivery_option_list,
            'carriers' => $carriers,
            'checked' => $this->context->cart->simulateCarrierSelectedOutput(),
            'delivery_option' => $delivery_option,
            'address_collection' => $this->context->cart->getAddressCollection(),
            'opc' => true,
            'oldMessage' => isset($old_message['message'])? $old_message['message'] : '',
            'identifier' => 'shipping',
            'step_is_current' => true,
            'step_is_reachable' => true,
            'step_is_complete' => false,
            'position' => 1,
            'title' => 'Frakt',
            'delivery_message' => '',
            'delivery_options' => $carriers17,
            'id_address' => $this->context->cart->id_address_delivery,
            'hookDisplayBeforeCarrier' => Hook::exec('displayBeforeCarrier', array(
                'carriers' => $carriers,
                'delivery_option_list' => $this->context->cart->getDeliveryOptionList(),
                'delivery_option' => $delivery_option
            )),
            'hookDisplayAfterCarrier' => '',
            'HOOK_BEFORECARRIER' => Hook::exec('displayBeforeCarrier', array(
                'carriers' => $carriers,
                'delivery_option_list' => $this->context->cart->getDeliveryOptionList(),
                'delivery_option' => $delivery_option
            ))
        );

        Cart::addExtraCarriers($vars);

        $this->context->smarty->assign($vars);
        if(version_compare(_PS_VERSION_,'1.7','>=')){
            $carrierblock = $this->context->smarty->fetch(_PS_THEME_DIR_.'templates/checkout/_partials/steps/shipping.tpl');
        } else {
            $carrierblock = $this->context->smarty->fetch(_PS_THEME_DIR_.'order-carrier.tpl');

        }
        if (!Address::isCountryActiveById((int)$this->context->cart->id_address_delivery) && $this->context->cart->id_address_delivery != 0) {
            $this->errors[] = Tools::displayError('This address is not in a valid area.');
        } elseif ((!Validate::isLoadedObject($address_delivery) || $address_delivery->deleted) && $this->context->cart->id_address_delivery != 0) {
            $this->errors[] = Tools::displayError('This address is invalid.');
        } else {

            $result = array(
                'HOOK_BEFORECARRIER' => Hook::exec('displayBeforeCarrier', array(
                    'carriers' => $carriers,
                    'delivery_option_list' => $this->context->cart->getDeliveryOptionList(),
                    'delivery_option' => $this->context->cart->getDeliveryOption(null, true)
                )),
                'carrier_block' => $carrierblock
            );

            Cart::addExtraCarriers($result);
            return $result;
        }
        if (count($this->errors)) {
            return array(
                'hasError' => true,
                'errors' => $this->errors,
                'carrier_block' => $carrierblock
            );
        }
    }

    public function fetchCheckout()
    {
        $billmate = $this->getBillmate();

        if ($hash = Common::getCartCheckoutHash()) {
            $result = $billmate->getCheckout(array('PaymentData' => array('hash' => $hash)));
            if(!isset($result['code'])){
                return $result;

            }
        }
    }

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

    /**
     * @return array
     */
    public function initCheckout()
    {
        $billmate = $this->getBillmate();
        $billmateDataCollector = $this->getBMDataCollector();
        $billmateDataCollector->setRequestMethod(self::REQUEST_METHOD);
        $billmateDataCollector->setPaymentMethod($this->method);
        $requestData = $billmateDataCollector->getRequestData();

        $result = $billmate->initCheckout($requestData);
        if(!isset($result['code'])){
            $hash = $this->getHashFromUrl($result['url']);
            Common::setCartCheckoutHash($hash);
        }
        return $result;
    }

    /**
     * @param $orderValues
     *
     * @return array
     */
    public function updateCheckout($orderValues)
    {
        $billmateDataCollector = $this->getBMDataCollector();
        $billmateDataCollector->setRequestMethod(self::REQUEST_METHOD);
        $billmateDataCollector->setPaymentMethod($this->method);
        $billmateDataCollector->setUpdateMode(true);

        $requestData = $billmateDataCollector->getRequestData();
        $requestData['PaymentData']['number'] = $orderValues['PaymentData']['number'];

        $previousTotal = $orderValues['Cart']['Total']['withtax'];

        $billmate = $this->getBillmate();
        $result = $billmate->updateCheckout($requestData);

        if (is_array($result)) {
            $result['update_checkout'] = false;
            if($previousTotal != $requestData['Cart']['Total']['withtax']){
                $result['update_checkout'] = true;
            }
            return $result;
        } else {
            return array('code' => 9510, 'communication error, '.$result);
        }
    }


    /**
     * @return BillMate
     */
    public function getBillmate()
    {
        $testMode = Configuration::get('BILLMATE_CHECKOUT_TESTMODE');
        return $this->getBillmateConnection($testMode);
    }

    /**
     * @param string $url
     *
     * @return mixed
     */
    public function getHashFromUrl($url = '')
    {
        $parts = explode('/',$url);
        $sum = count($parts);
        $hash = ($parts[$sum-1] == 'test') ? str_replace('\\','',$parts[$sum-2]) : str_replace('\\','',$parts[$sum-1]);
        return $hash;
    }
}