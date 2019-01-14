<?php
/**
 * Created by PhpStorm.* User: jesper* Date: 15-03-17 * Time: 15:09
 *
 * @author    Jesper Johansson jesper@boxedlogistics.se
 * @copyright Billmate AB 2015
 * @license   OpenSource
 */

include_once _PS_MODULE_DIR_.'/billmategateway/helpers/Autoloader.php';
require_once(_PS_MODULE_DIR_.'/billmategateway/library/Common.php');
require_once(_PS_MODULE_DIR_.'/billmategateway/library/pclasses.php');

class BillmateGateway extends PaymentModule
{
    protected $allowed_currencies;

    /**
     * @var
     */
    protected $postValidations;

    /**
     * @var array
     */
    protected $postErrors = array();

    /**
     * @var BmConfigHelper
     */
    protected $configHelper;

    /**
     * @var BillmatePayment
     */
    protected $paymentModel;

    /**
     * @var BillmateOrder
     */
    protected $billmateOrder;

    public function __construct()
    {
        $this->name       = 'billmategateway';
        $this->moduleName = 'billmategateway';
        $this->tab        = 'payments_gateways';
        $this->version    = BILLMATE_PLUGIN_VERSION;
        $this->author     = 'Billmate AB';

        $this->currencies      = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();
        $this->core              = null;
        $this->billmate          = null;
        $this->country           = null;
        $this->limited_countries = array(
            'se',
            'nl',
            'dk',
            'no',
            'fi',
            'gb',
            'us'
        ); //, 'no', 'fi', 'dk', 'de', 'nl'
        $this->verifyEmail       = $this->l('My email %1$s is accurate and can be used for invoicing.').'<a id="terms" style="cursor:pointer!important"> '.$this->l('I confirm the terms for invoice payment').'</a>';
        /* The parent construct is required for translations */
        $this->page                 = basename(__FILE__, '.php');
        $this->displayName          = $this->l('Billmate Payment Gateway');
        $this->description          = $this->l('Accept online payments with Billmate.');
        $this->confirmUninstall     = $this->l('Are you sure you want to delete your settings?');
        $this->billmate_merchant_id = Configuration::get('BILLMATE_ID');
        $this->billmate_secret      = Configuration::get('BILLMATE_SECRET');
        $installedVersion           = Configuration::get('BILLMATE_VERSION');

        // Is the module installed and need to be updated?
        if ($installedVersion && version_compare($installedVersion, $this->version, '<')) {
            $this->update();
        }

        $this->context->smarty->assign('base_dir', __PS_BASE_URI__);
        $this->configHelper = new BmConfigHelper();
        $this->paymentModel = new BillmatePayment();
        $this->billmateOrder = new BillmateOrder();
    }

    /**
     * @return string
     */
    public function getContent()
    {
        $html = '';

        if (!empty($_POST) && Tools::getIsset('billmateSubmit')) {
            $this->_postValidation();
            if (isset($this->postValidations) && is_array($this->postValidations) && $this->postValidations) {
                $html .= $this->displayValidations();
            }

            if (is_array($this->postErrors) && $this->postErrors) {
                $html .= $this->displayErrors();
            }
        }

        $html .= $this->displayAdminTemplate();
        return $html;
    }

    /**
     * @return string
     */
    public function displayAdminTemplate()
    {
        $tab   = array();
        $tab[] = array(
            'title'    => $this->l('Settings'),
            'content'  => $this->getGeneralSettings(),
            'icon'     => '../modules/'.$this->moduleName.'/views/img/icon-settings.gif',
            'tab'      => 1,
            'selected' => true,

        );
        $i     = 2;
        foreach ($this->getMethodSettings() as $setting) {
            $tab[] = array(
                'title'    => $setting['title'],
                'content'  => $setting['content'],
                'icon'     => '../modules/'.$this->moduleName.'/views/img/icon-settings.gif',
                'tab'      => $i++,
                'selected' => false
            );

        }
        $this->smarty->assign('FormCredential', './index.php?tab=AdminModules&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&tab_module='.$this->tab.'&module_name='.$this->name);
        $this->smarty->assign('tab', $tab);
        $this->smarty->assign('moduleName', $this->moduleName);
        $this->smarty->assign($this->moduleName.'Logo', '../modules/'.$this->moduleName.'/views/img/logo.png');
        $this->smarty->assign('js', array('../modules/'.$this->moduleName.'/views/js/billmate.js'));

        $this->smarty->assign('stylecss', '../modules/billmategateway/views/css/billmate.css');

        return $this->display(__FILE__, 'admin.tpl');
    }


    /**
     * The method takes care of Validation and persisting the posted data
     */
    public function _postValidation()
    {
        $responseErrors = $this->configHelper->updateConfig();
        if ($responseErrors) {
            $this->postErrors = $responseErrors;
        }
    }

    public function displayValidations()
    {
        return '';
    }

    public function displayErrors()
    {
        $this->smarty->assign('billmateError', $this->postErrors);
        return $this->display(__FILE__, 'error.tpl');
    }

    private function addState($en, $color)
    {
        $orderState = new OrderState();
        $orderState->name = array();
        foreach (Language::getLanguages() as $language)
            $orderState->name[$language['id_lang']] = $en;
        $orderState->send_email = false;
        $orderState->color = $color;
        $orderState->hidden = false;
        $orderState->delivery = false;
        $orderState->logable = true;
        if ($orderState->add())
            copy(dirname(__FILE__).'/logo.gif', dirname(__FILE__).'/../../img/os/'.(int)$orderState->id.'.gif');
        return $orderState->id;
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!Configuration::get('BILLMATE_PAYMENT_PENDING')) {
            Configuration::updateValue('BILLMATE_PAYMENT_PENDING', $this->addState('Billmate : payment pending', '#DDEEFF'));
        }

        Configuration::updateValue('BPARTPAY_ENABLED', 0);
        Configuration::updateValue('BINVOICE_ENABLED', 0);
        Configuration::updateValue('BCARDPAY_ENABLED', 0);
        Configuration::updateValue('BBANKPAY_ENABLED', 0);
        Configuration::updateValue('BINVOICESERVICE_ENABLED',0);

        Configuration::updateValue('BILLMATE_VERSION', $this->version);
        require_once(_PS_MODULE_DIR_.'/billmategateway/setup/InitInstall.php');
        $installer = new InitInstall(Db::getInstance());
        $installer->install();
        $this->update();
        if (!$this->registerHooks())
            return false;

        if (!function_exists('curl_version')) {
            $this->_errors[] = $this->l(
                'Sorry, this module requires the cURL PHP Extension (http://www.php.net/curl), which is not enabled on your server. Please ask your hosting provider for assistance.'
            );
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        if (!parent::uninstall()) {
            return false;
        }

        $db = Db::getInstance();
        $db->execute('DELETE FROM '._DB_PREFIX_.'module WHERE name = "billmatebankpay";');
        $db->execute('DELETE FROM '._DB_PREFIX_.'module WHERE name = "billmatepartpay";');
        $db->execute('DELETE FROM '._DB_PREFIX_.'module WHERE name = "billmatecardpay";');
        $db->execute('DELETE FROM '._DB_PREFIX_.'module WHERE name = "billmateinvoice";');
        $db->execute('DELETE FROM '._DB_PREFIX_.'module WHERE name = "billmateinvoiceservice";');
        $db->execute('DELETE FROM '._DB_PREFIX_.'module WHERE name = "billmatecheckout";');
        return true;
    }

    /**
     * Function to update if module is installed.
     * Caution need to implement SetupFileInterface to make sure the install function is there
     * */
    public function update()
    {
        $files = new ArrayObject(iterator_to_array(new FilesystemIterator(_PS_MODULE_DIR_.'/billmategateway/setup/updates', FilesystemIterator::SKIP_DOTS)));
        $files->natsort();
        if (count($files) == 0) {
            Configuration::updateValue('BILLMATE_VERSION',$this->version);
            return;
        }

        $installedUpdates = Configuration::get('BILLMATE_UPDATES');
        $installed = array();
        foreach ($files as $file) {
            $class = $file->getBasename('.php');
            if ($installedUpdates) {
                $installed = explode(',',$installedUpdates);
                if(in_array($class,$installed))
                    continue;
            }
            if ($class == 'index') {
                continue;
            }

            include_once($file->getPathname());

            $updater = new $class(Db::getInstance());
            $updater->install();
            $installed[] = $class;
        }

        $this->uninstallOverrides();
        $this->installOverrides();

        Configuration::updateValue('BILLMATE_UPDATES',implode(',',$installed));
        Configuration::updateValue('BILLMATE_VERSION', $this->version);
    }

    /**
     * @return bool
     */
    public function registerHooks()
    {
        $extra = true;
        if(version_compare(_PS_VERSION_,'1.7','>=')) {
            $extra = $this->registerHook('paymentOptions');
        }

        return  $this->registerHook('displayPayment') &&
                $this->registerHook('payment') &&
                $this->registerHook('paymentReturn') &&
                $this->registerHook('orderConfirmation') &&
                $this->registerHook('actionOrderStatusUpdate') &&
                $this->registerHook('displayBackOfficeHeader') &&
                $this->registerHook('displayAdminOrder') &&
                $this->registerHook('displayPDFInvoice') &&
                $this->registerHook('displayCustomerAccountFormTop') &&
                $this->registerHook('actionOrderSlipAdd') &&
                $this->registerHook('orderSlip') &&
                $this->registerHook('displayProductButtons') &&
                $this->registerHook('displayOrderDetail') &&

                /* Billmate Checkout */
                $this->registerHook('displayPayment') &&
                $this->registerHook('payment') &&
                $this->registerHook('paymentReturn') &&
                $this->registerHook('orderConfirmation') &&
                $this->registerHook('actionOrderStatusUpdate') &&
                $this->registerHook('displayBackOfficeHeader') && $this->registerHook('header') && $this->registerHook('adminTemplate') &&
                $extra;
    }


    public function hookHeader()
    {
        $css_file   = __DIR__.'/views/css/checkout/checkout.css';
        $js_file    = __DIR__.'/views/js/checkout/checkout.js';

        if (Configuration::get('BILLMATE_CHECKOUT_ACTIVATE') == 1) {

            $is_billmate_checkout_page = 'no';
            if (Dispatcher::getInstance()->getController() == 'billmatecheckout') {
                $is_billmate_checkout_page = 'yes';
                if (version_compare(_PS_VERSION_,'1.7','>=')) {
                    $this->context->controller->registerStylesheet(
                        'module-billmategateway',
                        'modules/billmategateway/views/css/checkout/checkout.css',
                        ['media' => 'all', 'priority' => 150]
                    );
                } else {
                    $this->context->controller->addCSS($css_file, 'all');
                }
            }

            if (version_compare(_PS_VERSION_,'1.7','>=')) {
                $this->context->controller->registerJavascript(
                    'module-billmategateway',
                    'modules/billmategateway/views/js/checkout/checkout.js',
                    ['position' => 'bottom', 'priority' => 150]
                );
            } else {
                $this->context->controller->addJS($js_file);
            }

            Media::addJsDef(array('billmate_checkout_url' =>
                $this->context->link->getModuleLink('billmategateway', 'billmatecheckout', array(), true)));

            Media::addJsDef(array('is_billmate_checkout_page' => $is_billmate_checkout_page));
            return;
        }

        $styleFile = 'payment-methods-17.css';
        if (
            version_compare(_PS_VERSION_, '1.6', '<') ||
            version_compare(_PS_VERSION_,'1.6.1','=>')
        ) {
            $styleFile = 'payment-methods-16.css';
        }

        $this->context->controller->addCSS(__DIR__.'/views/css/' . $styleFile, 'all');
        $this->context->controller->addCSS(__DIR__.'/views/css/payment-methods.css', 'all');
    }

    public function hookDisplayProductButtons($params)
    {
        if(!is_object($params['product'])){
            return '';
        }
        $cost = (1+($params['product']->tax_rate/100))*$params['product']->base_price;

        $partpay = new BillmateMethodPartpay();
        $plan = $partpay->getCheapestPlan($cost);
        if (    is_array($plan)
                && isset($plan['monthlycost'])
                && intval(Configuration::get('BPARTPAY_ENABLED')) > 0
        ) {
            $this->smarty->assign('icon',$partpay->icon);
            $this->smarty->assign('plan', $plan);
            return $this->display(__FILE__, 'payfrom.tpl');
        }
        return '';

    }

    /** Display handling fee on order details */
    public function hookDisplayOrderDetail($params)
    {
        $order_id = 0;
        if (isset($params['order']) AND is_object($params['order'])) {
            $order_id = $params['order']->id;
        }

        if ($order_id > 0) {
            $order = new Order($order_id);
            $fees = $this->billmateOrder->getPaymentFees($order);

            if ($fees) {
                $this->smarty->assign('invoiceFeeIncl', $fees['invoiceFeeIncl']);
                $this->smarty->assign('invoiceFeeTax', $fees['invoiceFeeTax']);
                $this->smarty->assign('invoiceFeeCurrency', $fees['invoiceFeeCurrency']);
                $this->smarty->assign('order', $order);

                return $this->display(__FILE__, 'invoicefee.tpl');
            }
        }
        return false;
    }

    public function hookDisplayPdfInvoice($params)
    {
        $order = new Order($params['object']->id_order);

        $fees = $this->billmateOrder->getPaymentFees($order);

        if ($fees) {
            $this->smarty->assign('invoiceFeeIncl', $fees['invoiceFeeIncl']);
            $this->smarty->assign('invoiceFeeTax', $fees['invoiceFeeTax']);
            $this->smarty->assign('order', $order);

            return $this->display(__FILE__, 'invoicefeepdf.tpl');
        }
    }

    public function hookDisplayCustomerAccountFormTop($params)
    {
        if (Configuration::get('BILLMATE_GETADDRESS') AND Dispatcher::getInstance()->getController() == 'orderopc') {
            $this->smarty->assign('pno', (isset($this->context->cookie->billmatepno)) ? $this->context->cookie->billmatepno : '');
            return $this->display(__FILE__, 'getaddress.tpl');
        } else {
            return;
        }
    }
    /**
     * This hook displays our invoice Fee in Admin Orders below the client information
     *
     * @param $hook
     */
    public function hookDisplayAdminOrder($hook)
    {
        $order_id = 0;
        if (array_key_exists('id_order', $hook)) {
            $order_id = (int)$hook['id_order'];
        }

        $order = new Order($order_id);
        $fees = $this->billmateOrder->getPaymentFees($order);
        if ($fees) {
            $this->smarty->assign('invoiceFeeIncl', $fees['invoiceFeeIncl']);
            $this->smarty->assign('invoiceFeeTax', $fees['invoiceFeeTax']);
            $this->smarty->assign('invoiceFeeCurrency', $fees['invoiceFeeCurrency']);
            $this->smarty->assign('order', $order);

            return $this->display(__FILE__, 'invoicefee.tpl');
        } else {
            return;
        }
    }

    /**
     * @return array
     */
    public function getAvailableMethods()
    {
        return $this->configHelper->getAvailableMethods();
    }

    public function hookDisplayBackOfficeHeader()
    {
        $listProperties = array(
            'error' => 'error',
            'diff' => 'diff',
            'api_error' => 'api_error',
            'information' => 'information',
            'confirmation' => 'confirmation',
            'credit_confirmation' => 'credit_confirmation',
            'error_credit' => 'credit',
            'error_credit_activation' => 'credit_activate',
        );

        foreach ($listProperties as $_property => $property_orders) {
            if (isset($this->context->cookie->{$_property}) && Tools::strlen($this->context->cookie->{$_property}) > 2) {
                if (get_class($this->context->controller) == 'AdminOrdersController') {
                    $this->context->controller->errors[] = $this->context->cookie->{$_property};
                    unset($this->context->cookie->{$_property});
                    unset($this->context->cookie->{$property_orders . '_orders'});
                }
            }
        }
    }

    /**
     * @param $params Array array('order' => $order, 'productList' => $order_detail_list, 'qtyList' => $full_quantity_list)
     */
    public function hookActionOrderSlipAdd($params)
    {
        $order = $params['order'];
        $testMode      = (boolean) $this->billmateOrder->getMethodInfo($order->module, 'testMode');
        $paymentStatuses = array(
            'Cancelled',
            'Created'
        );
        $billmate = $this->configHelper->getBillmateConnection($testMode);
        $payment = OrderPayment::getByOrderId($order->id);
        $transactionId = $payment[0]->transaction_id;
        $paymentFromBillmate = $billmate->getPaymentinfo(array('number' => $transactionId));
        if (!in_array($paymentFromBillmate['PaymentData']['status'], $paymentStatuses)) {
            $creditPaymentData = $this->billmateOrder->getCreditPaymentData($transactionId, $params['productList']);
            $result = $billmate->creditPayment($creditPaymentData);
        } else {
            $updateData = $this->billmateOrder->getSlipUpdateData($order, $transactionId);
            $result = $billmate->updatePayment($updateData);
        }
        if (isset($result['code'])) {
            $this->context->cookie->api_error = $result['message'];
            $this->context->cookie->api_error_orders = isset($this->context->cookie->api_error_orders) ? $this->context->cookie->api_error_orders . ', ' . $order->id : $order->id;
        }
    }

    /**
     * @param $params
     */
    public function hookActionOrderStatusUpdate($params)
    {
        $this->billmateOrder->updateStatusProcess($params);
    }

    /**
     * @param $params
     *
     * @return string
     */
    public function hookDisplayPayment($params)
    {
        return $this->hookPayment($params);
    }

    /**
     * @param $params
     *
     * @return string
     */
    public function hookPayment($params)
    {
        $methods = $this->paymentModel->getActiveMethods();

        $template = 'new';
        if (version_compare(_PS_VERSION_, '1.6', '<') ||
            version_compare(_PS_VERSION_,'1.6.1','=>')
        ) {
            $template = 'legacy';
        }

        $this->smarty->assign(
            array(
                'var'        => array(
                    'path'          => $this->_path,
                    'this_path_ssl' => (_PS_VERSION_ >= 1.4 ? Tools::getShopDomainSsl(true, true) : '').__PS_BASE_URI__.'modules/'.$this->moduleName.'/'
                ),
                'template' => $template,
                'methods'    => $methods,
                'ps_version' => _PS_VERSION_,
                'eid' => $this->billmate_merchant_id
            )
        );

        return $this->display(__FILE__, 'payment.tpl');

    }

    /**
     * @param $params
     *
     * @return array
     */
    public function hookPaymentOptions($params)
    {
        $methods = $this->getMethodOptions($params['cart']);
        $this->smarty->assign(
            array(
                'var'        => array(
                    'path'          => $this->_path,
                    'this_path_ssl' => (_PS_VERSION_ >= 1.4 ? Tools::getShopDomainSsl(true, true) : '').__PS_BASE_URI__.'modules/'.$this->moduleName.'/'
                ),
                'template' => 'ps17',
                'methods'    => $methods,
                'ps_version' => _PS_VERSION_,
                'eid' => $this->billmate_merchant_id
            )
        );

        return $methods;
    }

    public function getFileName()
    {
        return __FILE__;
    }

    public function getMethodOptions($cart)
    {
        $data = array();

        $paymentMethodsAvailable = $this->getAvailableMethods();
        $paymentsMethods = $this->configHelper->getPaymentModules();

        foreach ($paymentsMethods as $paymentName => $className) {
            if(!class_exists($className)) {
                continue;
            }
            $method = new $className();
            if (!in_array(strtolower($method->remote_name),$paymentMethodsAvailable)) {
                continue;
            }

            $result = $method->getPaymentInfo($cart);

            if (!$result) {
                continue;
            }

            $newOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            try{
                $this->smarty->assign($result);
                $this->smarty->assign(array('eid' => Configuration::get('BILLMATE_ID')));
                $this->smarty->escape_html = false;
                $newOption->setModuleName($this->name)
                    ->setCallToActionText($result['name'])
                    ->setAction($result['controller'])
                    ->setLogo($this->context->link->getBaseLink().'/modules/'.$result['icon'])
                    ->setAdditionalInformation($this->fetch('module:billmategateway/views/templates/front/'.$result['type'].'.tpl'));

            } catch(Exception $e){
                die($e->getMessage()."\r\n".$e->getTraceAsString());
            }
            if ($result['sort_order']) {
                if (array_key_exists($result['sort_order'], $data)) {
                    $data[$result['sort_order'] + 1] = $newOption;
                } else {
                    $data[$result['sort_order']] = $newOption;
                }
            } else {
                $data[] = $newOption;
            }
        }
        ksort($data);
        return $data;
    }

    public function getMethodSettings()
    {
        $settingsData = array();

        $paymentMethodsAvailable = $this->getAvailableMethods();
        $paymentsMethods = $this->configHelper->getPaymentModules();

        foreach ($paymentsMethods as $paymentName => $className) {
            if(!class_exists($className)) {
                continue;
            }

            $method = new $className();
            if (!in_array(strtolower($method->remote_name),$paymentMethodsAvailable)) {
                continue;
            }

            $result = $method->getSettings();
            if (!$result) {
                continue;
            }

            $this->smarty->assign(array('settings' => $result, 'moduleName' => $method->displayName));
            $settingsData[$method->name]['content'] = $this->display(__FILE__, 'settings.tpl');
            $settingsData[$method->name]['title']   = $method->displayName;
        }

        return $settingsData;
    }

    public function getGeneralSettings()
    {
        $statuses_prepared = $this->getStatuses();
        $config_form = new BillmateConfigForm($statuses_prepared);
        $settings = $config_form->getSettingsForm();
        $activate_status      = Configuration::get('BILLMATE_ACTIVATE');
        $this->smarty->assign('activation_status', $activate_status);
        $this->smarty->assign(array('settings' => $settings, 'moduleName' => $this->l('Common Settings')));

        return $this->display(__FILE__, 'settings.tpl');
    }

    /**
     * @return array
     */
    public function getStatuses()
    {
        $statuses       = OrderState::getOrderStates((int)$this->context->language->id);
        $statuses_prepared = array();
        foreach ($statuses as $status) {
            $statuses_prepared[$status['id_order_state']] = $status['name'];
        }
        return $statuses_prepared;
    }

    public function hookPaymentReturn($params)
    {
        return $this->hookOrderConfirmation($params);
    }

    public function hookOrderConfirmation($params)
    {
        if (!isset($params['objOrder']) && isset($params['order'])) {
            $order = $params['order'];
        } else {
            $order = $params['objOrder'];
        }

        $additional_order_info_html = '';
        if (    property_exists($order, 'id_customer')
                && property_exists($order, 'module')
                && $order->id_customer > 0
                && in_array($order->module, array('billmateinvoice', 'billmateinvoiceservice', 'billmatepartpay'))
        ) {
            $customer = new Customer($order->id_customer);
            if (property_exists($customer, 'email') AND $customer->email != '') {

                if ($order->module == 'billmateinvoice' || $order->module == 'billmateinvoiceservice') {
                    $additional_order_info_html = '<br />'.sprintf($this->l('You have selected to pay with invoice. The invoice will be sent from Billmate to you through email, %s, or your billing address.'), $customer->email);
                }

                if ($order->module == 'billmatepartpay') {
                    $additional_order_info_html = '<br />'.sprintf($this->l('You have selected to pay with part payment. Information regarding the part payment will be sent from Billmate to your billing address.'), $customer->email);
                }
            }
        }

        if (version_compare(_PS_VERSION_, '1.7', '>=')) {
            $this->smarty->assign('shop_name', Configuration::get('PS_SHOP_NAME'));
            $this->smarty->assign('additional_order_info_html', $additional_order_info_html);
            return $this->fetch('module:billmategateway/views/templates/hook/orderconfirmation.tpl');
        } else {
            $this->smarty->assign('shop_name', Configuration::get('PS_SHOP_NAME'));
            $this->smarty->assign('additional_order_info_html', $additional_order_info_html);
            return $this->display(__FILE__, '/orderconfirmation.tpl');
        }
    }
}
