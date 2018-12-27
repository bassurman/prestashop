<?php
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
    protected $payment_modules = array(
        'billmatebankpay' => 'BillmateMethodBankpay',
        'billmatecardpay' => 'BillmateMethodCardpay',
        'billmatecheckout' => 'BillmateMethodCheckout' ,
        'billmateinvoice' => 'BillmateMethodInvoice',
       // 'billmateinvoiceservice' => 'BillmateMethodInvoice',
        'billmatepartpay' => 'BillmateMethodPartpay'
    );


    /**
     * @var array
     */
    protected $mapPaymentMethods = array(
        1 => 'invoice',
        2 => 'invoiceservice',
        4 => 'partpay',
        8 => 'cardpay',
        16 => 'bankpay'
    );

    /**
     * BmConfigHelper constructor.
     */
    public function __construct()
    {
        $this->billmateMerchantId = Configuration::get('BILLMATE_ID');
        $this->billmateSecret      = Configuration::get('BILLMATE_SECRET');
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
        $billmateBmSecret = $this->getBmSecret();
        return Common::getBillmate($billmateMerchantId, $billmateBmSecret, $testMode);
    }

    /**
     * @param bool $onlyKeys
     *
     * @return array
     */
    public function getPaymentModules($onlyKeys = false)
    {
        if ($onlyKeys) {
            return array_keys($this->payment_modules);
        }

        return $this->payment_modules;
    }

    public function getAvailableMethods()
    {
        if(is_null($this->availMethods)) {
            $bmConnection = $this->getBillmateConnection();
            if ($bmConnection) {
                $result = $bmConnection->getAccountinfo(array('time' => time()));
                $mapCodeToMethod = $this->getPaymentMethodsMap();
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

                    if (isset($mapCodeToMethod[$option['method']]) && !in_array($mapCodeToMethod[$option['method']], $paymentOptions)) {
                        $paymentOptions[$option['method']] = $mapCodeToMethod[$option['method']];
                    } else {
                        continue;
                    }
                }
                // Add checkout as payment option if available
                if (isset($result['checkout']) && $result['checkout']) {
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
        $activateStatuses = unserialize($bmActivateStatuses);

        if(!$activateStatuses) {
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
}