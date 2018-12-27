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
     * @var array
     */
    protected $payment_modules = array(
        'billmatebankpay' => 'BillmateMethodBankpay',
        'billmatecardpay' => 'BillmateMethodCardpay',
        'billmatecheckout' => 'BillmateMethodCheckout' ,
        'billmateinvoice' => 'BillmateMethodInvoice',
        'billmateinvoiceservice' => 'BillmateMethodInvoice',
        'billmatepartpay' => 'BillmateMethodPartpay'
    );

    public function __construct()
    {
        $this->billmateMerchantId = Configuration::get('BILLMATE_ID');
        $this->billmateSecret      = Configuration::get('BILLMATE_SECRET');
        parent::__construct();
    }

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
}