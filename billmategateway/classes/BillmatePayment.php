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

    public function getActiveMethods()
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

    public function getMethodOptions()
    {
        $cart = $this->getCart();
        $data = array();

        $methodFiles = new FilesystemIterator(_PS_MODULE_DIR_.'/billmategateway/methods', FilesystemIterator::SKIP_DOTS);
        $paymentMethodsAvailable = $this->getAvailableMethods();

        foreach ($methodFiles as $file) {
            $class = $file->getBasename('.php');
            if ($class == 'index') {
                continue;
            }

            if(!in_array(strtolower($class),$paymentMethodsAvailable))
                continue;

            include_once($file->getPathname());

            $class = "BillmateMethod".$class;
            $method = new $class();
            $result = $method->getPaymentInfo($cart);

            if (!$result)
                continue;
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