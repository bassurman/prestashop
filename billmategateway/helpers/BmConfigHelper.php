<?php
class BmConfigHelper
{
    /**
     * @var array
     */
    protected $errors = [];

    public function __construct()
    {
    }

    public function validateCredentials($eid, $secret)
    {
        if (empty($eid)) {
            $this->errors[] = $this->l('You must insert a Billmate ID');
            return false;
        }

        if (empty($secret)) {
            $this->errors[] = $this->l('You must insert a Billmate Secret');
            return false;
        }


        $billmate = Common::getBillmate($eid, $secret, false);
        $data = array();
        $data['PaymentData'] = array(
            'currency' => 'SEK',
            'language' => 'sv',
            'country'  => 'se'
        );
        $result = $billmate->getPaymentplans($data);

        if (isset($result['code']) && ($result['code'] == '9010' || $result['code'] == '9012' || $result['code'] == '9013')) {
            $this->errors[] = utf8_encode($result['message']);
        }

        return $this->errors;
    }
}