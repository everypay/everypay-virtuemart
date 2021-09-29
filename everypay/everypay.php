<?php

defined('_JEXEC') or die('Restricted access');
if (!class_exists('vmPSPlugin')) {
    require(VMPATH_PLUGINLIBS . DS . 'vmpsplugin.php');
}

require_once __DIR__ . DS . 'autoload.php';

class plgVmpaymentEverypay extends vmPSPlugin
{
    public static $_cc_name           = '';
    public static $_cc_type           = '';
    public static $_cc_number         = '';
    public static $_cc_cvv            = '';
    public static $_cc_expire_month   = '';
    public static $_cc_expire_year    = '';
    public static $_cc_valid          = false;
    private $_errormessage      = array();

    public function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);

        $jlang = JFactory::getLanguage();
        $jlang->load('plg_vmpayment_everypay', JPATH_ADMINISTRATOR, null, true);
        $this->_loggable = true;
        $this->_debug = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';

        $varsToPush = array(
                            'sandbox'        => array('0', 'int'),
                            'sandbox_secret_key'        => array('', 'char'),
                            'secret_key'        => array('', 'char'),
                            'sandbox_public_key'        => array('', 'char'),
                            'public_key'        => array('', 'char'),
                            'pay_to_email'        => array('', 'char'),
                            'product'          => array('', 'char'),
                            'hide_login'          => array(0, 'int'),
                            'logourl'             => array('', 'char'),
                            'secret_word'         => array('', 'char'),
                            'payment_currency'    => array('', 'char'),
                            'payment_logos'       => array('', 'char'),
                            'countries'           => array('', 'char'),
                            'cost_per_transaction'
                                                  => array('', 'int'),
                            'cost_percent_total'
                                                  => array('', 'int'),
                            'min_amount'          => array('', 'int'),
                            'max_amount'          => array('', 'int'),
                            'tax_id'              => array(0, 'int'),
                            'status_pending'      => array('', 'char'),
                            'status_success'      => array('', 'char'),
                            'status_canceled'     => array('', 'char'));

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    protected function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Everypay Table');
    }

    public function getTableSQLFields()
    {
        $SQLfields = array(
            'id'                            => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'           => 'int(1) UNSIGNED',
            'order_number'                  => 'char(64)',
            'virtuemart_paymentmethod_id'   => 'mediumint(1) UNSIGNED',
            'payment_name'                  => 'varchar(5000)',
            'payment_order_total'           => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency'              => 'char(3)',
            'cost_per_transaction'          => 'decimal(10,2)',
            'cost_percent_total'            => 'decimal(10,2)',
            'tax_id'                        => 'smallint(1)',
            
            'user_session'            => 'varchar(255)',

            'everypay_response_token'       => 'char(128)',
            'everypay_response_description' => 'char(255)',
            'everypay_response_status'      => 'char(128)',
            'everypay_response_card_type'   => 'char(10)',
            'everypay_response_last_four'   => 'char(4)',
            'everypay_response_holder_name' => 'char(255)',
        );
        return $SQLfields;
    }

    public function _getInternalData($virtuemart_order_id, $order_number = '')
    {
        $db = JFactory::getDBO();
        $q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
        if ($order_number) {
            $q .= " `order_number` = '" . $order_number . "'";
        } else {
            $q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
        }

        $db->setQuery($q);
        if (!($paymentTable = $db->loadObject())) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }
        return $paymentTable;
    }

    public function plgVmConfirmedOrder(VirtueMartCart $cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null;
        } // Another method was selected, do nothing

        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }


        $new_status = '';

        $session = JFactory::getSession();
        $return_context = $session->getId();
        $this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');
        
        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }
        if (!class_exists('VirtueMartModelCurrency')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'currency.php');
        }
        

        $usrBT = $order['details']['BT'];
        $address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);
        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $method->payment_currency);
        $cartCurrency = CurrencyDisplay::getInstance($cart->pricesCurrency);


        // Prepare data that should be stored in the database
        $dbValues['user_session'] = $return_context;
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['payment_name'] = $this->renderPluginName($method, $order);
        $dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
        $dbValues['cost_per_transaction'] = $method->cost_per_transaction;
        $dbValues['cost_percent_total'] = $method->cost_percent_total;
        $dbValues['payment_currency'] = $method->payment_currency;
        $dbValues['payment_order_total'] = $totalInPaymentCurrency['value'];
        $dbValues['tax_id'] = $method->tax_id;
        //var_dump($dbValues);
        if ($method->sandbox == '1') {
            Everypay\Everypay::$isTest = true;
        }
        Everypay\Everypay::setApiKey($this->getSecretKey($method));
        $token = $this->getToken();
        $response = Everypay\Payment::create(array('token' => $token, 'description' => 'Order #' . $order['details']['BT']->order_number, 'amount' => round($cart->cartPrices['billTotal'], 2) * 100 ));

//        $response = new stdClass();
//
//        $response->token = 'pmt_payment';
//        $response->description = 'desc  '. $this->getSecretKey($method);
//        $response->amount = 10099;
//        $response->status = 'OK';
//        $response->last_four = '1234';
//        $response->holder_name = 'Sotiris';


//        var_dump($response);

        if (isset($response->error)) {
            $new_status = $method->status_canceled;
            $this->_handlePaymentCancel($order['details']['BT']->virtuemart_order_id, $html);
            return; // will not process the order
        }

        $new_status = $method->status_success;

        $dbValues['everypay_response_token'] = $response->token;
        $dbValues['everypay_response_description'] = $response->description;
        $dbValues['everypay_response_status'] = $response->status;
        $dbValues['everypay_response_last_four'] = $response->card->last_four;
        $dbValues['everypay_response_holder_name'] = $response->card->holder_name;
        $dbValues['everypay_response_card_type'] = $response->card->type;
        $dbValues['payment_order_total'] = number_format($response->amount / 100, 2);
        $this->storePSPluginInternalData($dbValues);


        $modelOrder = VmModel::getModel('orders');
        $order['order_status'] = $new_status;
        $order['customer_notified'] = 1;
        $order['comments'] = '';
        $modelOrder->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, true);

        $orderlink='';
        $tracking = VmConfig::get('ordertracking', 'guests');
        if ($tracking !='none' and !($tracking =='registered' and empty($order['details']['BT']->virtuemart_user_id))) {
            $orderlink = 'index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $order['details']['BT']->order_number;
            if ($tracking == 'guestlink' or ($tracking == 'guests' and empty($order['details']['BT']->virtuemart_user_id))) {
                $orderlink .= '&order_pass=' . $order['details']['BT']->order_pass;
            }
        }

        $html = $this->renderByLayout('post_payment', array(
            'order_number' =>$order['details']['BT']->order_number,
            'order_pass' =>$order['details']['BT']->order_pass,
            'payment_name' => $dbValues['payment_name'],
            'displayTotalInPaymentCurrency' => $totalInPaymentCurrency['display'],
            'orderlink' =>$orderlink
        ));

        //We delete the old stuff
        $cart->emptyCart();
        vRequest::setVar('html', $html);


        $session = JFactory::getSession();
        $session->clear('everypay_token', 'vm');

        return true;
    }

    


   
    private function hasBillingAddress(VirtueMartCart $cart)
    {
        return is_array($cart->BT) && !isset($cart->BT[0]);
    }

    private function displayForm(VirtueMartCart $cart, $sandbox)
    {
        if ($this->getToken()) {
            return '';
        }
        $publicKey = $this->getPublicKey();
        return '<style>
                    .everypay-button{display: none !important}
                    .button-holder{text-align: right;}
                </style>
                <script type="text/javascript" src="https://button.everypay.gr/js/button.js"></script>
                <script type="text/javascript">
                    var EVERYPAY_DATA = {
                        amount: "'.(round($cart->cartPrices['billTotal'], 2) * 100).'",
                        key: "'.$publicKey.'",
                        callback: "handleTokenResponse",
                        sandbox: "'.$sandbox.'"
                    };

                    var loadButton = setInterval(function () {
                        try {
                        EverypayButton.jsonInit(EVERYPAY_DATA, jQuery(\'#checkoutForm\'));
                        clearInterval(loadButton);
                        } catch (err) {}
                    }, 100);
                    jQuery(\'#checkoutForm\').on(\'submit\', function (e) {
                        var data = jQuery(\'#checkoutForm\').serializeArray();
                        console.log(data);
                        var $is = isCheckout(data);
                        //var $is = true;
                        console.log(e);
                        if ($is) {
                            e.preventDefault();
                            e.stopPropagation();
                            jQuery(\'.everypay-button\').trigger(\'click\');
                            return false;
                        }
                        });
                    function handleTokenResponse(response) {
                        var $form = jQuery("#checkoutForm");

                        if (response.error) {
                            alert(response.error.message);
                        } else {
                            console.log(response.token);
                            var token = response.token;
                            $form.append(jQuery(\'<input type="hidden" name="everypayToken"/>\').val(token));
                        }
                        $form.unbind(\'submit\');
                        $form.submit();
                    }
                    
                    function isCheckout(data) {
                        var everypay = false;
                        var confirm = false;
                        var hasToken = false;
                         
                        for (var i=0; i< data.length; i++) {
                            if (data[i].name == \'virtuemart_paymentmethod_id\'
                                && data[i].value == "'.$cart->virtuemart_paymentmethod_id.'"
                            ) {
                               everypay = true;
                            }
                            if (data[i].name == \'confirm\'
                                && data[i].value == \'1\'
                            ) {
                               confirm = true;
                            }
                            if (data[i].name == \'everypayToken\') {
                               hasToken = true;
                            }
                            
                        }

                        return everypay && confirm && hasToken == false;
                    }
                </script>
';
    }

    

    /**
     * This is for checking the input data of the payment method within the checkout
     *
     * @author Valerie Cartan Isaksen
     */
//    function plgVmOnCheckoutCheckDataPayment(VirtueMartCart $cart)
//    {
    //		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
    //			return null; // Another method was selected, do nothing
    //		}
//
//
//
//        if ($token = $this->getToken()) {
    //	    	$session = JFactory::getSession();
//    		$session->set('everypay_token', $token, 'vm');
//            return true;
//        }
//
//        return false;
//    }

    private function getToken()
    {
        return vRequest::getVar('everypayToken', null)
            ?: JFactory::getSession()->get('everypay_token', null, 'vm');
    }



    public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id)
    {
        if (!$this->selectedThisByMethodId($payment_method_id)) {
            return null;
        } // Another method was selected, do nothing

        if (!($paymentTable = $this->_getInternalData($virtuemart_order_id))) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }

        $this->getPaymentCurrency($paymentTable);
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' .
            $paymentTable->payment_currency . '" ';
        $db = JFactory::getDBO();
        $db->setQuery($q);
        $currency_code_3 = $db->loadResult();
        $html = '<table class="adminlist table">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('PAYMENT_NAME', $paymentTable->payment_name);

        $code = "mb_";
        foreach ($paymentTable as $key => $value) {
            if (substr($key, 0, strlen($code)) == $code) {
                $html .= $this->getHtmlRowBE($key, $value);
            }
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @author: Valerie Isaksen
     *
     * @param $cart_prices: cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);


        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }

        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
            return true;
        }

        return false;
    }




    private function getPublicKey()
    {
        return $this->_currentMethod->sandbox
            ? $this->_currentMethod->sandbox_public_key
            : $this->_currentMethod->public_key;
    }

    private function getSecretKey($method)
    {
        if ($method->sandbox == '1') {
            return $method->sandbox_secret_key;
        } else {
            return $method->secret_key;
        }
    }

    

    public function _handlePaymentCancel($virtuemart_order_id, $html)
    {
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }
        $modelOrder = VmModel::getModel('orders');
        $modelOrder->remove(array('virtuemart_order_id' => $virtuemart_order_id));
        // error while processing the payment
        $mainframe = JFactory::getApplication();
        $mainframe->enqueueMessage($html);
        $mainframe->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart&task=editpayment', false), vmText::_('COM_VIRTUEMART_CART_ORDERDONE_DATA_NOT_VALID'));
    }
    



    /**
     * We must reimplement this triggers for joomla 1.7
     */

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     * @author Valérie Isaksen
     *
     */
    public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     * @param VirtueMartCart $cart: the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not valid
     *
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object  $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on success, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        if ($this->getPluginMethods($cart->vendorId) === 0) {
            if (empty($this->_name)) {
                vmAdminInfo('displayListFE cartVendorId=' . $cart->vendorId);
                $app = JFactory::getApplication();
                $app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
                return false;
            } else {
                return false;
            }
        }

        $mname = $this->_psType . '_name';
        $idN = 'virtuemart_'.$this->_psType.'method_id';

        $ret = false;
        foreach ($this->methods as $method) {
            $this->_currentMethod = $method;
            //var_dump($cart);
            if (!isset($htmlIn[$this->_psType][$method->$idN])) {
                if ($this->checkConditions($cart, $method, $cart->cartPrices)) {

                    // the price must not be overwritten directly in the cart
                    $prices = $cart->cartPrices;
                    $methodSalesPrice = $this->setCartPrices($cart, $prices, $method);

                    //This makes trouble, because $method->$mname is used in  renderPluginName to render the Name, so it must not be called twice!
                    $method->$mname = $this->renderPluginName($method);

                    $sandbox = $this->_currentMethod->sandbox;
                    $html = $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);

                    if ($selected == $this->_currentMethod->virtuemart_paymentmethod_id
                        && $this->hasBillingAddress($cart)
                    ) {
                        $html .= $this->displayForm($cart, $sandbox);
                    }

                    $htmlIn[$this->_psType][$method->$idN] = $html;

                    $ret = true;
                }
            } else {
                $ret = true;
            }
        }

        return $ret;
    }


    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @author Valerie Isaksen
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     */
    public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * This event is fired during the checkout process. It can be used to validate the
     * method data as entered by the user.
     *
     * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
     * @author Max Milbers

    public function plgVmOnCheckoutCheckDataPayment($psType, VirtueMartCart $cart) {
    return null;
    }
     */

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id  method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
    public function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * Save updated order data to the method specific table
     *
     * @param array $_formData Form data
     * @return mixed, True on success, false on failures (the rest of the save-process will be
     * skipped!), or null when this method is not activated.

    public function plgVmOnUpdateOrderPayment(  $_formData) {
    return null;
    }
     */
    /**
     * Save updated orderline data to the method specific table
     *
     * @param array $_formData Form data
     * @return mixed, True on success, false on failures (the rest of the save-process will be
     * skipped!), or null when this method is not actived.

    public function plgVmOnUpdateOrderLine(  $_formData) {
    return null;
    }
     */
    /**
     * plgVmOnEditOrderLineBE
     * This method is fired when editing the order line details in the backend.
     * It can be used to add line specific package codes
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     * @return mixed Null for method that aren't active, text (HTML) otherwise

    public function plgVmOnEditOrderLineBE(  $_orderId, $_lineId) {
    return null;
    }
     */

    /**
     * This method is fired when showing the order details in the frontend, for every orderline.
     * It can be used to display line specific package codes, e.g. with a link to external tracking and
     * tracing systems
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     * @return mixed Null for method that aren't active, text (HTML) otherwise

    public function plgVmOnShowOrderLineFE(  $_orderId, $_lineId) {
    return null;
    }
     */
    public function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }
}
