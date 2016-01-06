<?php

/**
 * EuPlatesc.ro gateway module for WHMCS
 * version 1.0.0, 2016.01.05
 * Copyright (c) 2016  Stefaniu Criste - https://hangar.hosting
 *
 * based on similar module (version 2.4.5) built by Andrei C. from hetnix.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *******************************************************************************
 * License also available at:      euplatesc/LICENSE
 * Changelog available at:         euplatesc/CHANGELOG
 *******************************************************************************
 */

/**
 * Die if accessed directly
 */
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module related meta data.
 * @return array
 */
function euplatesc_MetaData()
{
    return array(
        'DisplayName'			=> 'Payment module for Euplatesc.ro',
        'APIVersion'			=> '1.1', // Use API Version 1.1
        'DisableLocalCredtCardInput'	=> true,
        'TokenisedStorage' 		=> false,
    );
}

/**
 * Define gateway configuration options.
 * @return array
 */
function euplatesc_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'EuPlatesc.ro',
        ),
        'accountID' => array(
            'FriendlyName' => 'MID',
            'Type' => 'text',
            'Size' => '30',
            'Default' => '',
            'Description' => 'Enter your merchant account ID (MID) here, as received from Euplatesc.ro',
        ),
        'secretKey' => array(
            'FriendlyName' => 'Key',
            'Type' => 'text',
            'Size' => '30',
            'Default' => '',
            'Description' => 'Enter your merchant secret key here, as received from Euplatesc.ro',
        ),
        'allowedIPs' => array(
            'FriendlyName' => 'Allowed IPs',
            'Type' => 'textarea',
            'Rows' => '4',
            'Cols' => '20',
            'Description' => 'Allowed IPs, one per line (no commas)',
        ),
        'transactionFee' => array(
            'FriendlyName' => 'Transaction fee',
            'Type' => 'text',
            'Size' => '4',
            'Default' => '1.8',
            'Description' => '% negotiated transaction fee',
        ),

    );
}


/**
 * Include the HMAC functions
 */
require_once("euplatesc/functions.php");


/**
 * Build the payment link
 * @return string
 */
function euplatesc_link($params) {

	/**
	 * Gateway Configuration Parameters
	 */
	$accountId	= $params['accountID'];
	$secretKey	= htmlspecialchars_decode($params['secretKey']);
	$allowedIPs	= $params['allowedIPs'];
	$transactionFee	= $params['transactionFee'];

	/**
	 * Invoice Parameters
	 * Note that amount to pay will always be calculated in RON
	 */
	$invoiceId	= $params['invoiceid'];
	$description	= $params['description'];
	$amount		= $params['amount'];
	$currencyCode	= $params['currency'];

	$baseAmount	= $params['basecurrencyamount'];	// amount to pay, in client's currency
	$baseCurrency	= $params['basecurrency'];		// client's currency
	$baseExchange	= $amount/$baseAmount;			// exchange rate

	/**
	 * Store the base amount, currency and rate in an array
	 * We will need the array later, at payment confirmation
	 */
	$base			= array(
		'amount'	=> $baseAmount,
		'currency'	=> $baseCurrency,
		'rate'		=> $baseExchange,
	);

	/**
	 * Client Parameters
	 */
	$firstname	= $params['clientdetails']['firstname'];
	$lastname	= $params['clientdetails']['lastname'];
	$email		= $params['clientdetails']['email'];
	$address1	= $params['clientdetails']['address1'];
	$address2	= $params['clientdetails']['address2'];
	$city		= $params['clientdetails']['city'];
	$state		= $params['clientdetails']['state'];
	$postcode	= $params['clientdetails']['postcode'];
	$country	= $params['clientdetails']['country'];
	$phone		= $params['clientdetails']['phonenumber'];
	$companyname	= $params['clientdetails']['companyname'];

	/**
	 * Special Fields (please modify as per your local settings)
	 */
	$isCompany	= $params['clientdetails1'];	// if defined, has value "on"

	/**
	 * System Parameters
	 */
	$companyName		= $params['companyname'];
	$systemUrl		= $params['systemurl'];
	$returnUrl		= $params['returnurl'];
	$langPayNow		= $params['langpaynow'];
	$moduleDisplayName	= $params['name'];
	$moduleName		= $params['paymentmethod'];
	$whmcsVersion		= $params['whmcsVersion'];

	/**
	 * Payment Url, where data should be sent via POST
	 */
	$url			= 'https://secure.euplatesc.ro/tdsprocess/tranzactd.php';


	/**
	 * prepare the array of data whish will be verified by hmac
	 */
	$dataAll		= array();

	$dataAll['amount']	= $amount;		// amount to pay
	$dataAll['curr']	= $currencyCode;	// currency code (EUR, RON, USD)
	$dataAll['invoice_id']	= $invoiceId;		// invoice id, as defined by merchant
	$dataAll['order_desc']	= $description;		// invoice description
	$dataAll['merch_id']	= $accountId;		// merchant id, as defined by EuPlatesc.ro

	// =========== DO NOT MODIFY BELOW ============= //
	$dataAll['timestamp']	= gmdate("YmdHis");		// build the timestamp with instant value
	$dataAll['nonce']	= md5(microtime() . mt_rand());	// build a random string for nonce
	$dataAll['fp_hash']	= strtoupper(euplatesc_mac($dataAll,$secretKey));	// encode all data in a hash string (uppercased)

	// billing
	$dataBill		= array();
	$dataBill['fname']	= $firstname;
	$dataBill['lname']	= $lastname;
	$dataBill['country']	= $country;
	if ('on' == $isCompany) {
		$dataBill['company']	= $companyname;
	}
	$dataBill['city']	= $city;
	$dataBill['add']	= $address1;
	if ($address2 != '') {
		$dataBill['add'].= ', '.$address2;
	}
	$dataBill['email']	= $email;
	$dataBill['phone']	= $phone;

	/**
	 * serialize array with basecurrency invoicing data
	 * also, encode it into base64, in order to survive the transport
	 */
	$dataBill['ExtraData']	= base64_encode(json_encode($base));

	// shipping
	$dataShip		= array();
	$dataShip['sfname']	= $firstname;
	$dataShip['slname']	= $lastname;
	$dataShip['scountry']	= $country;
	if ('on' == $isCompany) {
		$dataShip['scompany']	= $companyname;
	}
	$dataShip['scity']	= $city;
	$dataShip['sadd']	= $address1;
	if ($address2 != '') {
		$dataShip['sadd'].= ', '.$address2;
	}
	$dataShip['semail']	= $email;
	$dataShip['sphone']	= $phone;

	/**
	 * build the payment form
	 * we have added some EOL characters
	 * in order to make the resulted HTML code more readable
	 */

	// open the form
	$htmlOutput = '<form name="gateway" method="post" target="_self" action="' . $url . '">
';
	// add the encoded data
	foreach ($dataAll as $k => $v) {
		$htmlOutput .= '<input type="hidden" name="' . $k . '" value="' . $v . '" />
';
	}

	// add the billing data
	foreach ($dataBill as $k => $v) {
		$htmlOutput .= '<input type="hidden" name="' . $k . '" value="' . $v . '" />
';
	}

	// add the shipping data
	foreach ($dataShip as $k => $v) {
		$htmlOutput .= '<input type="hidden" name="' . $k . '" value="' . $v . '" />
';
	}

	// add EuPlatesc.ro logo near the button
	$htmlOutput .= '<img alt="EuPlatesc.ro" src="https://devel.hangar.hosting/assets/img/euplatesc150.png" />&nbsp;
';

	// add the "pay now" button
	$htmlOutput .= '<input type="submit" value="' . $langPayNow . '" />
';
	// close the form
	$htmlOutput .= '</form>';

	// return the code
	return $htmlOutput;
}


/**
 * Refund transaction - NOT USED
 * Called when a refund is requested for a previously successful transaction.
 *
 * @param array $params Payment Gateway Module Parameters
 * @see http://docs.whmcs.com/Payment_Gateway_Module_Parameters
 * @return array Transaction response status
 */
function euplatesc_refund($params)
{
    // Gateway Configuration Parameters
    $accountId = $params['accountID'];
    $secretKey = $params['secretKey'];
    $testMode = $params['testMode'];
    $dropdownField = $params['dropdownField'];
    $radioField = $params['radioField'];
    $textareaField = $params['textareaField'];

    // Transaction Parameters
    $transactionIdToRefund = $params['transid'];
    $refundAmount = $params['amount'];
    $currencyCode = $params['currency'];

    // Client Parameters
    $firstname = $params['clientdetails']['firstname'];
    $lastname = $params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $address1 = $params['clientdetails']['address1'];
    $address2 = $params['clientdetails']['address2'];
    $city = $params['clientdetails']['city'];
    $state = $params['clientdetails']['state'];
    $postcode = $params['clientdetails']['postcode'];
    $country = $params['clientdetails']['country'];
    $phone = $params['clientdetails']['phonenumber'];

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    // perform API call to initiate refund and interpret result

    return array(
        // 'success' if successful, otherwise 'declined', 'error' for failure
        'status' => 'success',
        // Data to be recorded in the gateway log - can be a string or array
        'rawdata' => $responseData,
        // Unique Transaction ID for the refund transaction
        'transid' => $refundTransactionId,
        // Optional fee amount for the fee value refunded
        'fees' => $feeAmount,
    );
}

/**
 * Cancel subscription - NOT USED
 *
 * If the payment gateway creates subscriptions and stores the subscription
 * ID in tblhosting.subscriptionid, this function is called upon cancellation
 * or request by an admin user.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see http://docs.whmcs.com/Payment_Gateway_Module_Parameters
 *
 * @return array Transaction response status
 */
function euplatesc_cancelSubscription($params)
{
    // Gateway Configuration Parameters
    $accountId = $params['accountID'];
    $secretKey = $params['secretKey'];
    $testMode = $params['testMode'];
    $dropdownField = $params['dropdownField'];
    $radioField = $params['radioField'];
    $textareaField = $params['textareaField'];

    // Subscription Parameters
    $subscriptionIdToCancel = $params['subscriptionID'];

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    // perform API call to cancel subscription and interpret result

    return array(
        // 'success' if successful, any other value for failure
        'status' => 'success',
        // Data to be recorded in the gateway log - can be a string or array
        'rawdata' => $responseData,
    );
}
?>
