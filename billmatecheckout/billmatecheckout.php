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

require_once(_PS_MODULE_DIR_.'/billmategateway/library/Common.php');

class BillmateCheckout extends PaymentModule{

    public function __construct()
    {
        parent::__construct();

        $this->name         = 'billmatecheckout';
        $this->moduleName   = 'billmatecheckout';
        $this->displayName  = $this->l('Billmate Checkout');
        $this->description  = 'Support plugin - No install needed!';

        $this->version      = BILLMATE_PLUGIN_VERSION;
        $this->author       = 'Billmate AB';
        $this->page         = basename(__FILE__, '.php');

        $this->context->smarty->assign('base_dir', __PS_BASE_URI__);
        $this->ps_versions_compliancy = array('min' => '1.5', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        $db = Db::getInstance();
        $db->execute('DELETE FROM '._DB_PREFIX_.'module WHERE name = "billmatecheckout";');
        $db->execute('INSERT INTO '._DB_PREFIX_.'module (name,active,version) VALUES("billmatecheckout",1,"2.0.0");');
    }

}
