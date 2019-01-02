<?php

class BillmategatewayThankyouModuleFrontController extends BaseBmFront
{

    /**
     * @var bool
     */
	public $display_column_left = true;

    /**
     * @var bool
     */
	public $display_column_right = false;

    /**
     * @var bool
     */
	public $ssl = true;

    /**
     * @var null
     */
	public $thankyou_content = null;

	public function initContent()
	{
		parent::initContent();

		$billmateUrl = $this->getSuccessUrl();

		$order_id = $this->thankyou_content['PaymentData']['orderid'];
		$order_id = explode('-',$order_id);
		$result = $this->verifyOrder($order_id[0]);

		$this->context->smarty->assign(array(
			'billmate_thankyou' => $billmateUrl,
			'HOOK_HEADER' => Hook::exec('displayHeader'),
			'order_conf' => $this->displayOrderConfirmation((int) ($result['id_order'])),
		));
		if (version_compare(_PS_VERSION_,'1.7','>=')) {
			$this->setTemplate('module:billmategateway/views/templates/front/checkout/billmate_thankyou17.tpl');
		} else {
			$this->setTemplate('checkout/billmate_thankyou.tpl');
		}
	}

	public function verifyOrder($id_order)
	{
		$sql = 'SELECT id_order FROM '._DB_PREFIX_.'orders WHERE id_cart='.$id_order;
		$result = Db::getInstance()->getRow($sql);
		if(!isset($result['id_order'])){
			sleep(2);
			$this->verifyOrder($id_order);
		}

		return $result;
	}

    /**
     * @return string
     */
	public function getSuccessUrl()
	{
		$billmate = $this->getBillmate();
		$result = $billmate->getCheckout(array('PaymentData' => array('hash' => Tools::getValue('billmate_hash', 0))));
		$this->thankyou_content = $result;
		return $result['PaymentData']['url'];
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
	 * Make sure order is correct
	 * @param $id_order integer
	 * @return bool
	 */

	public function displayOrderConfirmation($id_order)
	{
		if (Validate::isUnsignedId($id_order)) {
			$params = array();
			$order = new Order($id_order);
			$currency = new Currency($order->id_currency);

			if (Validate::isLoadedObject($order)) {
				$params['total_to_pay'] = $order->getOrdersTotalPaid();
				$params['currency'] = $currency->sign;
				$params['objOrder'] = $order;
				$params['currencyObj'] = $currency;

				return Hook::exec('displayOrderConfirmation', $params);
			}
		}

		return false;
	}
}