<?php

defined ('_JEXEC') or die('Restricted access');
if (!class_exists ('vmPSPlugin')) {
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
	static $iframeLoaded = false;

    private $paymentMethodId;

    public function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);

        $jlang = JFactory::getLanguage ();
		$jlang->load('plg_vmpayment_everypay', JPATH_ADMINISTRATOR, NULL, true);
		$this->_loggable = true;
		$this->_debug = true;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';

		$varsToPush = array(
            'sandbox' => array('0', 'int'),
            'sandbox_secret_key' => array('', 'char'),
            'secret_key' => array('', 'char'),
            'sandbox_public_key' => array('', 'char'),
            'public_key'  => array('', 'char'),
            'pay_to_email' => array('', 'char'),
            'product' => array('', 'char'),
            'hide_login' => array(0, 'int'),
            'logourl' => array('', 'char'),
            'secret_word' => array('', 'char'),
            'payment_currency' => array('', 'char'),
            'payment_logos' => array('', 'char'),
            'countries' => array('', 'char'),
            'cost_per_transaction' => array('', 'int'),
            'cost_percent_total' => array('', 'int'),
            'min_amount' => array('', 'int'),
            'max_amount' => array('', 'int'),
            'tax_id' => array(0, 'int'),
            'status_pending' => array('', 'char'),
            'status_success' => array('', 'char'),
            'status_canceled' => array('', 'char')
        );

		$this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);
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

    function _getInternalData ($virtuemart_order_id, $order_number = '') {

        $db = JFactory::getDBO ();
        $q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
        if ($order_number) {
            $q .= " `order_number` = '" . $order_number . "'";
        } else {
            $q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
        }

        $db->setQuery ($q);
        if (!($paymentTable = $db->loadObject ())) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }

        return $paymentTable;
    }

    function plgVmConfirmedOrder(VirtueMartCart $cart, $order)
    {
        if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null;
		} // Another method was selected, do nothing

		if (!$this->selectedThisElement ($method->payment_element)) {
			return false;
		}

		$new_status = '';
        $session = JFactory::getSession ();
		$return_context = $session->getId ();
        $this->logInfo ('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');
        
        if (!class_exists ('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}
		if (!class_exists ('VirtueMartModelCurrency')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'currency.php');
        }

        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total,$method->payment_currency);

        // Prepare data that should be stored in the database
        $dbValues['user_session'] = $return_context;
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName ($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['cost_per_transaction'] = $method->cost_per_transaction;
		$dbValues['cost_percent_total'] = $method->cost_percent_total;
		$dbValues['payment_currency'] = $method->payment_currency;
		$dbValues['payment_order_total'] = $totalInPaymentCurrency['value'];
		$dbValues['tax_id'] = $method->tax_id;

        if ($method->sandbox == '1') {
            Everypay\Everypay::$isTest = true;
        }
        Everypay\Everypay::setApiKey($this->getSecretKey($method));
        $token = $this->getToken();

        $response = Everypay\Payment::create(
                array(
                    'token' => $token,
                    'description' => 'Order #' . $order['details']['BT']->order_number,
                    'amount' => round($cart->cartPrices['billTotal'],2) * 100
                )
        );

        if (isset($response->error)) {
			$this->_handlePaymentCancel($order['details']['BT']->virtuemart_order_id, '' );

			return;
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


        $modelOrder = VmModel::getModel ('orders');
        $order['order_status'] = $new_status;
        $order['customer_notified'] = 1;
        $order['comments'] = '';
        $modelOrder->updateStatusForOneOrder ($order['details']['BT']->virtuemart_order_id, $order, TRUE);

        $orderlink='';
        $tracking = VmConfig::get('ordertracking','guests');
        if ($tracking !='none' and !($tracking =='registered' and empty($order['details']['BT']->virtuemart_user_id) )) {

            $orderlink = 'index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $order['details']['BT']->order_number;
            if ($tracking == 'guestlink' or ($tracking == 'guests' and empty($order['details']['BT']->virtuemart_user_id))) {
                $orderlink .= '&order_pass=' . $order['details']['BT']->order_pass;
            }
        }

        $html = $this->renderByLayout('post_payment', array(
            'order_number' => $order['details']['BT']->order_number,
            'order_pass' =>$order['details']['BT']->order_pass,
            'payment_name' => $dbValues['payment_name'],
            'displayTotalInPaymentCurrency' => $totalInPaymentCurrency['display'],
            'orderlink' => $orderlink
        ));

        //We delete the old stuff
        $cart->emptyCart ();
        vRequest::setVar ('html', $html);


		$session = JFactory::getSession();
		$session->clear('everypay_token', 'vm');

        return TRUE;
    }

    private function hasBillingAddress(VirtueMartCart $cart)
    {
        return is_array($cart->BT) && !empty($cart->BT) && !isset($cart->BT[0]);
    }

    private function displayForm(VirtueMartCart $cart, $isSandbox)
    {
        if ($this->getToken()) {
            return '';
        }

        $amount = (round($cart->cartPrices['billTotal'],2) * 100 );
        $publicKey = $this->getPublicKey();

        $payformUrl = 'https://js.everypay.gr/v3';

        if ($isSandbox == '1') {
            $payformUrl = 'https://sandbox-js.everypay.gr/v3';
        }

        ?>
        <script>
            window.everypayData = {
                pk: "<?php echo $publicKey ?>",
                amount: <?php echo $amount ?>,
                locale: "el",
                txnType: "tds",
                paymentMethodId: '<?php echo $this->_currentMethod->virtuemart_paymentmethod_id ?? '' ?>'
            }
        </script>
        <?php

        JHtml::_('stylesheet', JUri::base() . '/plugins/vmpayment/everypay/everypay/assets/everypay_modal.css');
        vmJsApi::addJScript ( 'payform', $payformUrl);
        vmJsApi::addJScript ( 'everypay', JUri::base() . 'plugins/vmpayment/everypay/everypay/assets/everypay.js');


        return '';
    }

    private function getToken()
    {
        $paymentMethodId = $this->_currentMethod->virtuemart_paymentmethod_id ?? '';
        return vRequest::getVar('everypayToken' . $paymentMethodId, null)
            ?: JFactory::getSession()->get('everypay_token', null, 'vm');
    }

    function plgVmOnShowOrderBEPayment ($virtuemart_order_id, $payment_method_id) {

        if (!$this->selectedThisByMethodId ($payment_method_id)) {
            return NULL;
        } // Another method was selected, do nothing

        if (!($paymentTable = $this->_getInternalData ($virtuemart_order_id))) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }

        $this->getPaymentCurrency ($paymentTable);
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' .
            $paymentTable->payment_currency . '" ';
        $db = JFactory::getDBO ();
        $db->setQuery ($q);
        $html = '<table class="adminlist table">' . "\n";
        $html .= $this->getHtmlHeaderBE ();
        $html .= $this->getHtmlRowBE ('PAYMENT_NAME', $paymentTable->payment_name);

        $code = "mb_";
        foreach ($paymentTable as $key => $value) {
            if (substr ($key, 0, strlen ($code)) == $code) {
                $html .= $this->getHtmlRowBE ($key, $value);
            }
        }
        $html .= '</table>' . "\n";
        return $html;
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
        }

        return $method->secret_key;
    }

    function _handlePaymentCancel($virtuemart_order_id, $html) {

		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		$modelOrder = VmModel::getModel('orders');
		$modelOrder->remove(array('virtuemart_order_id' => $virtuemart_order_id));
		// error while processing the payment
		$mainframe = JFactory::getApplication();
		$mainframe->enqueueMessage($html);
		$mainframe->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart&task=editpayment', FALSE), vmText::_('COM_VIRTUEMART_CART_ORDERDONE_DATA_NOT_VALID'));
    }

	/**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 *
	 * @author Valérie Isaksen
	 *
	 */
	function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

		return $this->onStoreInstallPluginTable ($jplugin_id);
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
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart,  &$msg) {
		return $this->OnSelectCheck ($cart);
	}

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the plugin methods in the cart (edit shipment/payment)
     *
     * @param VirtueMartCart $cart Cart object
     * @param integer $selected ID of the method selected
     * @param $htmlIn
     * @return boolean True on success, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @throws Exception
     * @author Max Milbers
     * @author Valerie Isaksen
     */
	public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {

        if ($this->getPluginMethods ($cart->vendorId) === 0) {
            if (empty($this->_name)) {
                vmAdminInfo ('displayListFE cartVendorId=' . $cart->vendorId);
                $app = JFactory::getApplication ();
                $app->enqueueMessage (vmText::_ ('COM_VIRTUEMART_CART_NO_' . strtoupper ($this->_psType)));
                return false;
            } else {
                return false;
            }
        }

        $method_name = $this->_psType . '_name';
        $idN = 'virtuemart_'.$this->_psType.'method_id';
        $ret = false;

        foreach ($this->methods as $method) {
            $this->_currentMethod = $method;

            if(!isset($htmlIn[$this->_psType][$method->$idN])) {
                if ($this->checkConditions ($cart, $method, $cart->cartPrices)) {

                    // the price must not be overwritten directly in the cart
                    $prices = $cart->cartPrices;
                    $methodSalesPrice = $this->setCartPrices ($cart, $prices ,$method);

                    $method->$method_name = $this->renderPluginName ($method);

                    $sandbox = $this->_currentMethod->sandbox;
                    $html = $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);
                    if ($selected == $this->_currentMethod->virtuemart_paymentmethod_id
                        && $this->hasBillingAddress($cart)
                    ) {
                       $this->displayForm($cart, $sandbox);
                    }

                    $htmlIn[$this->_psType][$method->$idN] = $html;

                    $ret = TRUE;
                }
            } else {
                $ret = TRUE;
            }
        }

        return $ret;
	}


	public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {

		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
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
	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {

		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param $virtuemart_order_id
     * @param $virtuemart_paymentmethod_id
     * @param $payment_name
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
	public function plgVmOnShowOrderFEPayment ($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {

		$this->onShowOrderFE ($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
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
     * This method is fired when showing when printing an Order
     * It displays the payment method-specific data.
     *
     * @param $order_number
     * @param integer $method_id method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
	function plgVmonShowOrderPrintPayment ($order_number, $method_id) {

		return $this->onShowOrderPrint ($order_number, $method_id);
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
	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}

	function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {

		return $this->setOnTablePluginParams ($name, $id, $table);
	}
}
