<?php

/**
 * Callback verification file for EuPlatesc.ro WHMCS gateway module
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
 * Require libraries needed for gateway module functions
 */

// use the request below for WHMCS 6.*
require_once __DIR__ . '/../../../init.php';

// if you are using WHMCS 5.*, uncomment line below
// include("../../../dbconnect.php");
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../euplatesc/functions.php';

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Fetch the allowed callback IP list from the  parameters
$allowedIPs	= $gatewayParams['allowedIPs'];
$ipArray	= explode(PHP_EOL,$allowedIPs);
// Die if callback is not from the allowed IP range
if (!in_array($_SERVER['REMOTE_ADDR'], $ipArray)) {
    die("We do not allow callbacks from this IP address");
}

/**
 * read the secret key from the config variables
 */
$secretKey = htmlspecialchars_decode($gatewayParams['secretKey']);

/**
 * read the transaction fee percent
 */
$transactionFee = $gatewayParams['transactionFee'];

/**
 * Retrieve data returned in payment gateway callback
 */
$responseData	= array(
	'amount'	=> addslashes(trim(@$_POST['amount'])),		// original amount
	'curr'		=> addslashes(trim(@$_POST['curr'])),		// original currency
	'invoice_id'	=> addslashes(trim(@$_POST['invoice_id'])),	// original invoice id
	'ep_id'		=> addslashes(trim(@$_POST['ep_id'])),		// Euplatesc.ro unique id
	'merch_id'	=> addslashes(trim(@$_POST['merch_id'])),	// your merchant id
	'action'	=> addslashes(trim(@$_POST['action'])),		// if action ==0 transaction ok
	'message'	=> addslashes(trim(@$_POST['message'])),	// transaction responce message
	'approval'	=> addslashes(trim(@$_POST['approval'])),	// if action!=0 empty
	'timestamp'	=> addslashes(trim(@$_POST['timestamp'])),	// meesage timestamp
	'nonce'		=> addslashes(trim(@$_POST['nonce'])),		// salt'n pepa
	'sec_status'	=> addslashes(trim(@$_POST['sec_status']))	// security status
);

/**
 * Calculate local hash and store both local and received hashes
 * we will compare them later
 */
$responseData['fp_hash']= strtoupper(euplatesc_mac($responseData,$secretKey));
$fp_hash		= addslashes(trim(@$_POST['fp_hash']));

/**
 * Read the ExtraData from the callback POST data
 */
$ExtraData	= base64_decode(addslashes(trim($_POST['ExtraData'])));

/** we need this array to store data on the temporary period of manual verification
 * Build an array with data that needs to be temporarily stored
 * if a payment must be manually verified
 *
 * After a manual verification, EuPlatesc.ro does NOT resend all data (e.q. InvoiceID),
 * and the only common data is the transaction ID
 */
$base			= json_decode($ExtraData,true);
$base['paid']		= $responseData['amount']/$base['rate'];
$base['fee']		= $base['paid']*$transactionFee/100;
$base['invoice_id']	= $responseData['invoice_id'];
$serialBase		= base64_encode(json_encode($base));


/**
 * Validate callback authenticity.
 *
 * Most payment gateways provide a method of verifying that a callback
 * originated from them. In the case of our example here, this is achieved by
 * way of a shared secret which is used to build and compare a hash.
 */

// initialize status variable
$HashIsOK = false;
if ($responseData['fp_hash'] === $fp_hash) {
	// data integrity is confirmed
	$message = 'Hash OK';
	$HashIsOK = true;
} else {
	// data integrity is not confirmed
	$message = 'Hash Failure';
	$HashIsOK = false;
	logTransaction($gatewayParams['name'], $responseData, $message);
}

/**
 * Validate Callback Invoice ID.
 * Checks invoice ID is a valid invoice number. Note it will count an
 * invoice in any status as valid.
 * Performs a die upon encountering an invalid Invoice ID.
 * Returns a normalised invoice ID.
 */
$invoiceId = checkCbInvoiceID($responseData['invoice_id'], $gatewayParams['name']);

/**
 * Check Callback Transaction ID.
 * Performs a check for any existing transactions with the same given
 * transaction number.
 * Performs a die upon encountering a duplicate.
 */
checkCbTransID($responseData['ep_id']);


/**
 * Start the payment response logic process
 *
 * in two cases, payment may be approved directly,
 * but in one case, paymeny may be manually verified (yet not rejected)
 *
 */
if ($HashIsOK) { 					// if hash verification confirms data integrity
    if (0 == intval($responseData['action'])) {		// if action is "0", meaning bank approved transaction
	switch($responseData['sec_status']) {
        	case "1":
                	$message="Valid transaction, pending state";
	                break;
		case "2":
        	        $message="Failed transaction";
                	break;
		case "3":
	                $message="Manual verification";
			/**
			 * we use the tblgatewaylog to store the data until manual verification is OK;
			 *  we'll search after the unique value transaction ID
			*/
			logTransaction($gatewayParams['name'], $serialBase, $responseData['ep_id']);
        	        break;
		case "4":
                	$message="Suspicious transaction, waiting client response";
	                break;
		case "5":
        	        $message="Fraud";
                	break;
		case "6":
	                $message="Suspicious transaction, cancel shipping";
        	        break;
		case "7":
                	$message="Insecure transaction";
	                break;
		case "8":
        	        $message="Authenticated transaction";
			/**
			 * Add Invoice Payment.
			 *
			 * Applies a payment transaction entry to the given invoice ID.
			 *
			 * @param int $invoiceId         Invoice ID
			 * @param string $transactionId  Transaction ID
			 * @param float $paymentAmount   Amount paid (defaults to full balance)
			 * @param float $paymentFee      Payment fee (optional)
			 * @param string $gatewayModule  Gateway module name
			 */
			addInvoicePayment(
				$invoiceId,
				$responseData['ep_id'],
				$base['paid'],
				$base['fee'],
				$gatewayModuleName
			);
                	break;
		case "9":
	                $message="Verified transaction";
			/**
			 * same payment as previous branch
			 */
			addInvoicePayment(
				$invoiceId,
				$responseData['ep_id'],
				$base['paid'],
				$base['fee'],
				$gatewayModuleName
			);
        	        break;
		default:
                	$message="Unknown error";
	                break;
	}
    }
}
logTransaction($gatewayParams['name'], $_POST, $message);
?>
