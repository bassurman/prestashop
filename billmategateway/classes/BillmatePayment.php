<?php
class BillmatePayment
{
    /**
     * @var Context
     */
    public $context;

    /**
     * @var BmConfigHelper
     */
    protected $configHelper;

    public function __construct()
    {
        $this->context = Context::getContext();
        $this->configHelper = new BmConfigHelper();
    }

    public function getActiveMethods($cart)
    {
        $cart = $this->getCart();
        $activeMethods = array();

        $paymentMethodsAvailable = $this->getAvailableMethods();
        $paymentsMethods = $this->configHelper->getPaymentModules();
        foreach ($paymentsMethods as $paymentName => $className) {

            $method = new $className();
            if(!class_exists($className)) {
                continue;
            }

            if (!in_array(strtolower($method->remote_name),$paymentMethodsAvailable)) {
                continue;
            }

            $result = $method->getPaymentInfo($cart);

            if (!$result) {
                continue;
            }

            if ($result['sort_order']) {
                if (array_key_exists($result['sort_order'], $activeMethods)) {
                    $activeMethods[$result['sort_order'] + 1] = $result;
                } else {
                    $activeMethods[$result['sort_order']] = $result;
                }
            } else {
                $activeMethods[] = $result;
            }
        }
        ksort($activeMethods);
        return $activeMethods;
    }

    /**
     * @return CartCore
     */
    public function getCart()
    {
        return $this->context->cart;
    }

    /**
     * @return array
     */
    public function getAvailableMethods()
    {
        return $this->configHelper->getAvailableMethods();
    }
}