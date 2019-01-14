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

class BmConfigHelper extends Helper
{
    /**
     * @var array
     */
    protected $errors = array();

    /**
     * @var string
     */
    protected $sbillmateMerchantId;

    /**
     * @var string
     */
    protected $billmateSecret;

    /**
     * @var
     */
    protected $availMethods;

    /**
     * @var array
     */
    protected $billmateErrors = array();

    /**
     * @var array
     */
    protected $payment_modules = array(
        'billmatebankpay'        => 'BillmateMethodBankpay',
        'billmatecardpay'        => 'BillmateMethodCardpay',
        'billmatecheckout'       => 'BillmateMethodCheckout',
        'billmateinvoice'        => 'BillmateMethodInvoice',
        'billmateinvoiceservice' => 'BillmateMethodInvoiceService',
        'billmatepartpay'        => 'BillmateMethodPartpay'
    );


    /**
     * @var array
     */
    protected $mapPaymentMethods = array(
        1  => 'invoice',
        2  => 'invoiceservice',
        4  => 'partpay',
        8  => 'cardpay',
        16 => 'bankpay'
    );

    /**
     * BmConfigHelper constructor.
     */
    public function __construct()
    {
        $this->billmateMerchantId = Configuration::get('BILLMATE_ID');
        $this->billmateSecret     = Configuration::get('BILLMATE_SECRET');
        parent::__construct();
    }

    /**
     * @param bool $testMode
     *
     * @return BillMate
     */
    public function getBillmateConnection($testMode = false)
    {
        $billmateMerchantId = $this->getBmMerchantId();
        $billmateBmSecret   = $this->getBmSecret();

        return Common::getBillmate($billmateMerchantId, $billmateBmSecret, $testMode);
    }

    /**
     * @param bool $onlyKeys
     *
     * @return array
     */
    public function getPaymentModules($onlyKeys = false)
    {
        if ($onlyKeys)
        {
            return array_keys($this->payment_modules);
        }

        return $this->payment_modules;
    }

    public function getAvailableMethods()
    {
        if (is_null($this->availMethods)) {
            $bmConnection = $this->getBillmateConnection();
            if ($bmConnection) {
                $result          = $bmConnection->getAccountinfo(array('time' => time()));
                $mapCodeToMethod = $this->getPaymentMethodsMap();
                $paymentOptions  = array();

                $logfile = _PS_CACHE_DIR_ . 'Billmate.log';
                file_put_contents($logfile, print_r($result['paymentoptions'], true), FILE_APPEND);

                foreach ($result['paymentoptions'] as $option) {
                    if ($option['method'] == '2' && !isset($paymentOptions['1'])) {
                        $mapCodeToMethod['2'] = 'invoice';
                    }

                    if (
                        isset($mapCodeToMethod[$option['method']]) &&
                        !in_array($mapCodeToMethod[$option['method']], $paymentOptions)
                    ) {
                        $paymentOptions[$option['method']] = $mapCodeToMethod[$option['method']];
                    } else {
                        continue;
                    }
                }

                if (isset($result['checkout']) && $result['checkout']) {
                    $paymentOptions['checkout'] = 'checkout';
                }

                /**
                 * @param int 1|2 The mehtod that will be used in addPayment request when customer pay with invoice
                 *            Method 1 = Invoice , 2 = Invoice service
                 *            Is affected by available payment methods in result from getaccountinfo
                 *            - Default method 1
                 *            - Invoice available, use method 1
                 *            - Invoice and invoiceservice available, use method 1
                 *            - Invoice service availavble and invoice unavailable, use method 2
                 */
                $invoiceMethod = (!isset($paymentOptions[1]) && isset($paymentOptions[2])) ? 2 : 1;
                Configuration::updateValue('BINVOICESERVICE_METHOD', $invoiceMethod);

                $this->availMethods = $paymentOptions;
            } else {
                $this->availMethods = array();
            }
        }

        return $this->availMethods;
    }

    /**
     * @return array
     */
    public function getBMActivateStatuses()
    {
        $bmActivateStatuses = Configuration::get('BILLMATE_ACTIVATE_STATUS');
        $activateStatuses   = unserialize($bmActivateStatuses);

        if (!$activateStatuses) {
            return [];
        }

        return is_array($activateStatuses) ? $activateStatuses : [$activateStatuses];
    }

    /**
     * @return string
     */
    public function getBmMerchantId()
    {
        return $this->billmateMerchantId;
    }

    /**
     * @return string
     */
    public function getBmSecret()
    {
        return $this->billmateSecret;
    }

    /**
     * @return array
     */
    public function getPaymentMethodsMap()
    {
        return $this->mapPaymentMethods;
    }

    /**
     * @return bool
     */
    public function isEnabledBMMessage()
    {
        return Configuration::get('BILLMATE_MESSAGE');
    }

    /**
     * @return string
     */
    public function getTermsPageUrl()
    {
        $cms = new CMS(
            (int)(Configuration::get('PS_CONDITIONS_CMS_ID')),
            (int)($this->context->cookie->id_lang)
        );
        $termsUrl = $this->context->link->getCMSLink($cms, $cms->link_rewrite, true);
        return $termsUrl;
    }

    /**
     * @return string
     */
    public function getPrivacyUrl()
    {
        $privacyPolicyPageId = (int)Configuration::get('BILLMATE_CHECKOUT_PRIVACY_POLICY');
        if($privacyPolicyPageId) {
            $cms = new CMS(
                (int) ($privacyPolicyPageId),
                (int) ($this->context->cookie->id_lang)
            );
            $termsUrl = $this->context->link->getCMSLink($cms, $cms->link_rewrite, true);
            if ($termsUrl) {
                return $termsUrl;
            }
        }

        return '';
    }

    /**
     * @return string
     */
    public function getBillmateLogo()
    {
        return (Configuration::get('BILLMATE_LOGO')) ? Configuration::get('BILLMATE_LOGO') : '';
    }

    public function updateConfig()
    {
        $billmateId     = Tools::getValue('billmateId');
        $billmateSecret = Tools::getValue('billmateSecret');

        $isValid = false;
        if ($this->validateCredentials($billmateId, $billmateSecret)) {
            $isValid = true;
            Configuration::updateValue('BILLMATE_ID', $billmateId);
            Configuration::updateValue('BILLMATE_SECRET', $billmateSecret);
            $this->billmate_merchant_id = $billmateId;
            $this->billmate_secret      = $billmateSecret;
        }

        $this->updateGeneralSettings();
        $this->updateBankpaySettings();
        $this->updateCardpaySettings();
        $this->updateInvoiceSettings();
        $this->updateInvoiceServiceSettings();
        $this->updatePartpaySettings();
        $this->updateInvoiceServiceSettings();

        $this->updateCheckoutSettings();

        Configuration::updateValue('BSWISH_ORDER_STATUS', Tools::getValue('swishBillmateOrderStatus'));
        if (Configuration::get('BPARTPAY_ENABLED') == 1 && $isValid) {
            $pclasses  = new pClasses();
            $languages = Language::getLanguages();
            foreach ($languages as $language) {
                $pclasses->Save($this->billmate_merchant_id, $this->billmate_secret, 'se', $language['iso_code'], 'SEK');
            }
        }
        return $this->billmateErrors;
    }

    /**
     * @param $eid
     * @param $secret
     *
     * @return bool
     */
    public function validateCredentials($eid, $secret)
    {
        if (empty($eid)) {
            $this->billmateErrors[] = $this->l('You must insert a Billmate ID', 'billmategateway');
            return false;
        }

        if (empty($secret)) {
            $this->billmateErrors[] = $this->l('You must insert a Billmate Secret', 'billmategateway');
            return false;
        }

        $billmate            = Common::getBillmate($eid, $secret, false);
        $data                = array();
        $data['PaymentData'] = array(
            'currency' => 'SEK',
            'language' => 'sv',
            'country'  => 'se'
        );
        $result = $billmate->getPaymentplans($data);

        if (isset($result['code'])&&
            ($result['code'] == '9010' || $result['code'] == '9012' || $result['code'] == '9013')) {
            $this->billmateErrors[] = utf8_encode($result['message']);
            return false;
        }

        return true;
    }

    protected function updateGeneralSettings()
    {
        Configuration::updateValue('BILLMATE_ACTIVATE', Tools::getIsset('activate') ? 1 : 0);
        Configuration::updateValue('BILLMATE_ACTIVATE_STATUS', serialize(Tools::getValue('activateStatuses')));
        Configuration::updateValue('BILLMATE_CANCEL', Tools::getIsset('credit') ? 1 : 0);
        Configuration::updateValue('BILLMATE_CANCEL_STATUS', serialize(Tools::getValue('creditStatuses')));
        Configuration::updateValue('BILLMATE_MESSAGE', Tools::getIsset('message') ? 1 : 0);
        Configuration::updateValue('BILLMATE_SEND_REFERENCE', Tools::getValue('sendOrderReference'));
        Configuration::updateValue('BILLMATE_GETADDRESS', Tools::getIsset('getaddress') ? 1 : 0);
        Configuration::updateValue('BILLMATE_LOGO', Tools::getValue('logo'));
    }

    /**
     * @return $this
     */
    protected function updateBankpaySettings()
    {
        // Bankpay Settings
        Configuration::updateValue('BBANKPAY_ENABLED', (Tools::getIsset('bankpayActivated')) ? 1 : 0);
        Configuration::updateValue('BBANKPAY_MOD', (Tools::getIsset('bankpayTestmode')) ? 1 : 0);
        //Configuration::updateValue('BBANKPAY_AUTHORIZATION_METHOD', Tools::getValue('bankpayAuthorization'));
        Configuration::updateValue('BBANKPAY_ORDER_STATUS', Tools::getValue('bankpayBillmateOrderStatus'));
        Configuration::updateValue('BBANKPAY_MIN_VALUE', Tools::getValue('bankpayBillmateMinimumValue'));
        Configuration::updateValue('BBANKPAY_MAX_VALUE', Tools::getValue('bankpayBillmateMaximumValue'));
        Configuration::updateValue('BBANKPAY_SORTORDER', Tools::getValue('bankpayBillmateSortOrder'));
        return $this;
    }

    /**
     * @return $this
     */
    public function updateCardpaySettings()
    {
        Configuration::updateValue('BCARDPAY_ENABLED', (Tools::getIsset('cardpayActivated')) ? 1 : 0);
        Configuration::updateValue('BCARDPAY_MOD', (Tools::getIsset('cardpayTestmode')) ? 1 : 0);
        Configuration::updateValue('BCARDPAY_ORDER_STATUS', Tools::getValue('cardpayBillmateOrderStatus'));
        Configuration::updateValue('BCARDPAY_AUTHORIZATION_METHOD', Tools::getValue('cardpayAuthorization'));
        Configuration::updateValue('BCARDPAY_MIN_VALUE', Tools::getValue('cardpayBillmateMinimumValue'));
        Configuration::updateValue('BCARDPAY_MAX_VALUE', Tools::getValue('cardpayBillmateMaximumValue'));
        Configuration::updateValue('BCARDPAY_SORTORDER', Tools::getValue('cardpayBillmateSortOrder'));
        return $this;
    }

    /**
     * @return $this
     */
    protected function updateInvoiceSettings()
    {
        Configuration::updateValue('BINVOICE_ENABLED', (Tools::getIsset('invoiceActivated')) ? 1 : 0);
        Configuration::updateValue('BINVOICE_MOD', (Tools::getIsset('invoiceTestmode')) ? 1 : 0);
        Configuration::updateValue('BINVOICE_FEE', Tools::getValue('invoiceFee'));
        Configuration::updateValue('BINVOICE_FEE_TAX', Tools::getValue('invoiceFeeTax'));
        Configuration::updateValue('BINVOICE_ORDER_STATUS', Tools::getValue('invoiceBillmateOrderStatus'));
        Configuration::updateValue('BINVOICE_MIN_VALUE', Tools::getValue('invoiceBillmateMinimumValue'));
        Configuration::updateValue('BINVOICE_MAX_VALUE', Tools::getValue('invoiceBillmateMaximumValue'));
        Configuration::updateValue('BINVOICE_SORTORDER', Tools::getValue('invoiceBillmateSortOrder'));
        return $this;
    }

    /**
     * @return $this
     */
    protected function updateInvoiceServiceSettings()
    {
        Configuration::updateValue('BINVOICESERVICE_ENABLED', (Tools::getIsset('invoiceserviceActivated')) ? 1 : 0);
        Configuration::updateValue('BINVOICESERVICE_MOD', (Tools::getIsset('invoiceserviceTestmode')) ? 1 : 0);
        Configuration::updateValue('BINVOICESERVICE_FEE', Tools::getValue('invoiceserviceFee'));
        Configuration::updateValue('BINVOICESERVICE_FEE_TAX', Tools::getValue('invoiceserviceFeeTax'));
        Configuration::updateValue('BINVOICESERVICE_ORDER_STATUS', Tools::getValue('invoiceserviceBillmateOrderStatus'));
        Configuration::updateValue('BINVOICESERVICE_MIN_VALUE', Tools::getValue('invoiceserviceBillmateMinimumValue'));
        Configuration::updateValue('BINVOICESERVICE_MAX_VALUE', Tools::getValue('invoiceserviceBillmateMaximumValue'));
        Configuration::updateValue('BINVOICESERVICE_SORTORDER', Tools::getValue('invoiceserviceBillmateSortOrder'));
        Configuration::updateValue('BINVOICESERVICE_FALLBACK', Tools::getIsset('fallbackWhenDifferentAddress') ? 1 : 0);
        return $this;
    }

    /**
     * @return $this
     */
    protected function updatePartpaySettings()
    {
        Configuration::updateValue('BPARTPAY_ENABLED', (Tools::getIsset('partpayActivated')) ? 1 : 0);
        Configuration::updateValue('BPARTPAY_MOD', (Tools::getIsset('partpayTestmode')) ? 1 : 0);
        Configuration::updateValue('BPARTPAY_ORDER_STATUS', Tools::getValue('partpayBillmateOrderStatus'));
        Configuration::updateValue('BPARTPAY_MAX_VALUE', Tools::getValue('partpayBillmateMaximumValue'));
        Configuration::updateValue('BPARTPAY_SORTORDER', Tools::getValue('partpayBillmateSortOrder'));

        /** Min amount for partpayment cant be less than lowest minamount found in pclasses */
        $partpayBillmateMinimumValue = Tools::getValue('partpayBillmateMinimumValue');
        $partpayLowestMinAmount      = pClasses::getLowestMinAmount();
        if ($partpayLowestMinAmount >= $partpayBillmateMinimumValue) {
            $partpayBillmateMinimumValue = floor($partpayLowestMinAmount);
        }
        Configuration::updateValue('BPARTPAY_MIN_VALUE', $partpayBillmateMinimumValue);
        return $this;
    }

    /**
     * @return $this
     */
    protected function updateCheckoutSettings()
    {
        Configuration::updateValue('BILLMATE_CHECKOUT_ACTIVATE', Tools::getIsset('billmate_checkout_active') ? 1 : 0);
        Configuration::updateValue('BILLMATE_CHECKOUT_TESTMODE', Tools::getIsset('billmate_checkout_testmode') ? 1 : 0);
        Configuration::updateValue('BILLMATE_CHECKOUT_ORDER_STATUS', Tools::getValue('billmate_checkout_order_status'));
        Configuration::updateValue('BILLMATE_CHECKOUT_PRIVACY_POLICY', Tools::getValue('billmate_checkout_privacy_policy'));

        return $this;
    }

    /**
     * @return string
     */
    public function getBpartOrderStatus()
    {
        return Configuration::get('BPARTPAY_ORDER_STATUS');
    }

    /**
     * @return string
     */
    public function getBInvoiceOrderStatus()
    {
        return Configuration::get('BINVOICE_ORDER_STATUS');
    }

    /**
     * @return string
     */
    public function getBInvoiceServiceOrderStatus()
    {
        return Configuration::get('BINVOICESERVICE_ORDER_STATUS');
    }

    /**
     * @return string
     */
    public function getBCheckoutOrderStatus()
    {
        return Configuration::get('BILLMATE_CHECKOUT_ORDER_STATUS');
    }

    /**
     * @return string
     */
    public function getBPaymentPendingStatus()
    {
        return Configuration::get('BILLMATE_PAYMENT_PENDING');
    }
}