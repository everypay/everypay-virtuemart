<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */
error_reporting(E_ALL);
defined('_JEXEC') or die('Restricted access');

if (!class_exists('Creditcard')) {
	require_once(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'creditcard.php');
}
if (!class_exists('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

require_once __DIR__ . '/autoload.php';

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

        $this->_loggable    = true;
        $this->_tablepkey   = 'id';
        $this->_tableId     = 'id';
        $this->tableFields  = array_keys($this->getTableSQLFields());
        $varsToPush         = $this->getVarsToPush();

		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    protected function getVmPluginCreateTableSQL()
    {
		return $this->createTableSQL('Payment Everypay Table');
    }

    public function getTableSQLFields()
    {
		$SQLfields = array(
			'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' => 'int(1) UNSIGNED',
			'order_number' => 'char(64)',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
			'payment_name' => 'varchar(5000)',
			'payment_order_total' => 'decimal(15,5) NOT NULL',
			'payment_currency' => 'smallint(1)',
			'return_context' => 'char(255)',
			'cost_per_transaction' => 'decimal(10,2)',
			'cost_percent_total' => 'char(10)',
			'tax_id' => 'smallint(1)',
			'everypay_response_token' => 'char(128)',
			'everypay_response_description' => 'char(255)',
			'everypay_response_status' => 'char(128)',
			'everypay_response_card_type' => 'char(10)',
			'everypay_response_last_four' => 'char(4)',
			'everypay_response_holder_name' => 'char(255)',
		);
		return $SQLfields;
	}

    public static function getCreditCards() {
        return array(
            'Visa',
            'Mastercard'
        );
    }

    public function plgVmDeclarePluginParamsPaymentVM3( &$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    /**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 *
	 */
    public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
		return parent::onStoreInstallPluginTable($jplugin_id);
    }

    /**
	 * This shows the plugin for choosing in the payment list of the checkout process.
	 *
	 * @author Valerie Cartan Isaksen
	 */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
		if ($this->getPluginMethods($cart->vendorId) === 0) {
			if (empty($this->_name)) {
				$app = JFactory::getApplication();
				$app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
				return false;
			} else {
				return false;
			}
		}
		$html = array();
		$method_name = $this->_psType . '_name';

		VmConfig::loadJLang('com_virtuemart', true);
		vmJsApi::jCreditCard();
		$htmla = '';
		$html = array();
        foreach ($this->methods as $_currentMethod) {
            $this->_currentMethod = $_currentMethod;
			if ($this->checkConditions($cart, $this->_currentMethod, $cart->cartPrices)) {
				$cartPrices         = $cart->cartPrices;
                $methodSalesPrice   = $this->setCartPrices($cart, $cartPrices, $this->_currentMethod);

                $this->_currentMethod->$method_name = $this->renderPluginName($this->_currentMethod);

                $html = $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);
                if ($selected == $this->_currentMethod->virtuemart_paymentmethod_id) {
                    $this->populateCreditcard($cart);
                }

				if (empty($this->_currentMethod->creditcards)) {
					$this->_currentMethod->creditcards = self::getCreditCards();
				} elseif (!is_array($this->_currentMethod->creditcards)) {
					$this->_currentMethod->creditcards = (array)$this->_currentMethod->creditcards;
                }

				$sandbox_msg = "";
				if ($this->_currentMethod->sandbox) {
					$sandbox_msg .= '<br />' . vmText::_('VMPAYMENT_EVERYPAY_SANDBOX_TEST_NUMBERS');
				}

                $publicKey = $this->getPublicKey();
				$cvv_images = $this->_displayCVVImages($this->_currentMethod);
				$html .= '<br /><span class="vmpayment_cardinfo">' . vmText::_('VMPAYMENT_EVERYPAY_COMPLETE_FORM') . $sandbox_msg . '
                <script type="text/javascript" src="https://js.everypay.gr/everypay.js"></script>
                <script type="text/javascript" src="'.JURI::root().'plugins/vmpayment/everypay/assets/js/everypay.js"></script>
                <script type="text/javascript">
                    Everypay.setPublicKey("'.$publicKey.'");
                </script>
		    <table border="0" cellspacing="0" cellpadding="2" width="100%">
		    <tr valign="top">
		        <td nowrap width="10%" align="right">
		        	<label for="cc_type">' . vmText::_('VMPAYMENT_EVERYPAY_HOLDER_NAME') . '</label>
                    <input type="hidden" id="everypay-key" value="'.$publicKey.'" />
		        </td>
		        <td>

		        <input type="text" data-card="holder_name" class="inputbox" id="cc_name_' . $this->_currentMethod->virtuemart_paymentmethod_id . '" name="cc_name_' . $this->_currentMethod->virtuemart_paymentmethod_id . '" value="' . self::$_cc_name . '"    autocomplete="off" />
		    </td>
		    </tr>
		    <tr valign="top">
		        <td nowrap width="10%" align="right">
		        	<label for="cc_type">' . vmText::_('VMPAYMENT_EVERYPAY_CCNUM') . '</label>
		        </td>
		        <td>
		        <input type="text" data-card="card_number" class="inputbox" id="cc_number_' . $this->_currentMethod->virtuemart_paymentmethod_id . '" name="cc_number_' . $this->_currentMethod->virtuemart_paymentmethod_id . '" value="' . self::$_cc_number . '"    autocomplete="off" />
		        <div id="cc_cardnumber_errormsg_' . $this->_currentMethod->virtuemart_paymentmethod_id . '"></div>
		    </td>
		    </tr>
		    <tr valign="top">
		        <td nowrap width="10%" align="right">
		        	<label for="cc_cvv">' . vmText::_('VMPAYMENT_EVERYPAY_CVV2') . '</label>
		        </td>
		        <td>
		            <input type="text" c data-card="cvv" lass="inputbox" id="cc_cvv_' . $this->_currentMethod->virtuemart_paymentmethod_id . '" name="cc_cvv_' . $this->_currentMethod->virtuemart_paymentmethod_id . '" maxlength="4" size="5" value="' . self::$_cc_cvv . '" autocomplete="off" />

			<span class="hasTip" title="' . vmText::_('VMPAYMENT_EVERYPAY_WHATISCVV') . '::' . vmText::sprintf("VMPAYMENT_EVERYPAY_WHATISCVV_TOOLTIP", $cvv_images) . ' ">' .
					vmText::_('VMPAYMENT_EVERYPAY_WHATISCVV') . '
			</span></td>
		    </tr>
		    <tr>
		        <td nowrap width="10%" align="right">' . vmText::_('VMPAYMENT_EVERYPAY_EXDATE') . '</td>
		        <td> ';
				$html .= shopfunctions::listMonths('cc_expire_month_' . $this->_currentMethod->virtuemart_paymentmethod_id, self::$_cc_expire_month, " data-card=\"expiration_month\"");
				$html .= " / ";
				$html .= shopfunctions::listYears('cc_expire_year_' . $this->_currentMethod->virtuemart_paymentmethod_id, self::$_cc_expire_year, null, null, " data-card=\"expiration_year\"");
				$html .= '<div id="cc_expiredate_errormsg_' . $this->_currentMethod->virtuemart_paymentmethod_id . '"></div>';
				$html .= '</td>  </tr>  	';

                $html .= '
		    <tr valign="top">
		        <td nowrap width="10%" align="right" colspan="2">
                    <input type="hidden" data-amount="'.($cart->cartPrices['billTotal'] * 100 ).'" />
                ';

                if ($token = $this->getToken()) {
                    $html .= '
                    <input type="hidden" name="everypayToken" value="'.$token.'" />
                    ';
                    } else {
                        $html .= '
                            <a class="btn btn-payment btn-primary" id="btn-payment-cc">'.JText::_ ('VMPAYMENT_EVERYPAY_PAY').'</a>
                        ';
                    }
                $html .= '</td></tr></table></span>';
				$htmla[] = $html;
			}
		}
		$htmlIn[] = $htmla;

		return true;
	}

    /**
	 * This is for adding the input data of the payment method to the cart, after selecting
	 *
	 * @author Valerie Isaksen
	 *
	 * @param VirtueMartCart $cart
	 * @return null if payment not selected; true if card infos are correct; string containing the errors if cc is not valid
	 */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {

		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
			return null; // Another method was selected, do nothing
		}

		if (!($this->_currentMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id))) {
			return false;
		}

        $this->populateCreditcard($cart);

        $isValid = $this->_validate_creditcard_data(true);

        return $isValid;
	}

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
	 * This is for checking the input data of the payment method within the checkout
	 *
	 * @author Valerie Cartan Isaksen
	 */
    function plgVmOnCheckoutCheckDataPayment(VirtueMartCart $cart)
    {
        var_dump(__FUNCTION__);
		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
			return null; // Another method was selected, do nothing
		}

		if (!($this->_currentMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id))) {
			return false;
		}

        if ($token = $this->getToken()) {
            var_dump($token);
            Everypay\Everypay::setApiKey($this->getSecretKey());
            Everypay\Payment::setClientOption(Everypay\Http\Client\CurlClient::SSL_VERIFY_PEER, 0);
            $response = Everypay\Payment::create(array('token' => $token));
            print_r($response);
        }

        return true;
    }

    private function getToken()
    {
        return vRequest::getVar('everypayToken' . $paymentmethod_id, null);
    }

    function _validate_creditcard_data($enqueueMessage = true)
    {
		static $force = true;

        $emptyEssentialFields = empty(self::$_cc_number) || empty(self::$_cc_cvv) || empty(self::$_cc_name);
		if ($emptyEssentialFields) {
			return false;
		}
		$html = '';
		$this->_cc_valid = true;

		if (!Creditcard::validate_credit_card_cvv(self::$_cc_type, self::$_cc_cvv)) {
			$this->_errormessage[] = 'VMPAYMENT_EVERYPAY_CARD_CVV_INVALID';
			$this->_cc_valid = false;
		}
		if (!Creditcard::validate_credit_card_date(self::$_cc_type, self::$_cc_expire_month, self::$_cc_expire_year)) {
			$this->_errormessage[] = 'VMPAYMENT_EVERYPAY_CARD_EXPIRATION_DATE_INVALID';
			$this->_cc_valid = false;
		}
		if (!$this->_cc_valid) {
			//$html.= "<ul>";
			foreach ($this->_errormessage as $msg) {
				//$html .= "<li>" . vmText::_($msg) . "</li>";
				$html .= vmText::_($msg) . "<br/>";
			}
			//$html.= "</ul>";
		}
		if (!$this->_cc_valid && $enqueueMessage && $force) {
			$app = JFactory::getApplication();
			$app->enqueueMessage($html);
			$force=false;
		}

		return $this->_cc_valid;
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
		$this->convert_condition_amount($method);
		$amount = $this->getCartAmount($cart_prices);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		$amount_cond = ($amount >= $method->min_amount and $amount <= $method->max_amount
			or
			($method->min_amount <= $amount and ($method->max_amount == 0)));
		if (!$amount_cond) {
			return false;
		}
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

    protected function renderPluginName($plugin)
    {
		$return = '';
		$plugin_name = $this->_psType . '_name';
		$plugin_desc = $this->_psType . '_desc';

        $description = '';

		$sandboxWarning = '';
		if ($plugin->sandbox) {
			$sandboxWarning .= ' <span style="color:red;font-weight:bold">Sandbox (' . $plugin->virtuemart_paymentmethod_id . ')</span><br />';
		}
		if (!empty($plugin->$plugin_desc)) {
			$description = '<span class="' . $this->_type . '_description">' . $plugin->$plugin_desc . '</span>';
		}
		$extrainfo = "";
		$pluginName = $return . '<span class="' . $this->_type . '_name">' . $plugin->$plugin_name . '</span>' . $description;
        $pluginName .= $sandboxWarning . $extrainfo;

		return $pluginName;
	}

    /**
	 * Creates a Drop Down list of available Creditcards
	 *
	 * @author Valerie Isaksen
	 */
	function _renderCreditCardList($creditCards, $selected_cc_type, $paymentmethod_id, $multiple = false, $attrs = '') {

		$idA = $id = 'cc_type_' . $paymentmethod_id;
		//$options[] = JHTML::_('select.option', '', vmText::_('VMPAYMENT_EVERYPAY_SELECT_CC_TYPE'), 'creditcard_type', $name);
		if (!is_array($creditCards)) {
			$creditCards = (array)$creditCards;
		}
		foreach ($creditCards as $creditCard) {
			$options[] = JHTML::_('select.option', $creditCard, vmText::_('VMPAYMENT_EVERYPAY_' . strtoupper($creditCard)));
		}
		if ($multiple) {
			$attrs = 'multiple="multiple"';
			$idA .= '[]';
		}
		return JHTML::_('select.genericlist', $options, $idA, $attrs, 'value', 'text', $selected_cc_type);
	}

    /**
	 * @param $method
	 * @return html|mixed|string
	 */
	private function _displayCVVImages($method) {

		$cvv_images = $method->cvv_images;
		$img = '';
		if ($cvv_images) {
			$img = $this->displayLogos($cvv_images);
			$img = str_replace('"', "'", $img);
		}
		return $img;
	}

    private function getPublicKey()
    {
        return $this->_currentMethod->sandbox
            ? $this->_currentMethod->sandbox_public_key
            : $this->_currentMethod->public_key;
    }

    private function getSecretKey()
    {
        return $this->_currentMethod->sandbox
            ? $this->_currentMethod->sandbox_secret_key
            : $this->_currentMethod->secret_key;
    }

    function plgVmConfirmedOrder(VirtueMartCart $cart, $order)
    {
        return false;
    }

    private function populateCreditcard(VirtueMartCart $cart)
    {
        $paymentmethod_id = $cart->virtuemart_paymentmethod_id ?: 1;
        //$cart->creditcard_id = vRequest::getVar('creditcard', '0');
        self::$_cc_name = vRequest::getVar('cc_name_' . $paymentmethod_id, '');
        self::$_cc_number = str_replace(" ", "", vRequest::getVar('cc_number_' . $paymentmethod_id, ''));
        self::$_cc_cvv = vRequest::getVar('cc_cvv_' . $paymentmethod_id, '');
        self::$_cc_expire_month = vRequest::getVar('cc_expire_month_' . $paymentmethod_id, '');
        self::$_cc_expire_year = vRequest::getVar('cc_expire_year_' . $paymentmethod_id, '');
    }
}
