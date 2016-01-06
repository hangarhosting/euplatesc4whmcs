<?php

/**
 * Special callback file for EuPlatesc.ro WHMCS gateway module
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
 * Require libraries needed for gateway module functions.
 */

// the directive below is available only for WHMCS 6.*
require_once __DIR__ . '/../../../init.php';

// the directive below is available only for WHMCS 5.*
// include("../../../dbconnect.php");

require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../euplatesc/functions.php';

/**
 * Build the module name
 * usualy is taken from filename.
 * in this particular case is hardcoded
 */
$gatewayModuleName = 'euplatesc';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Fetch the allowed callback IP list from the  parameters
$allowedIPs = $gatewayParams['allowedIPs'];
$ipArray = explode(PHP_EOL,$allowedIPs);
// Die if callback is not from the allowed IP range
if (!in_array($_SERVER['REMOTE_ADDR'], $ipArray)) {
    die("We do not allow callbacks from this IP address");
}

// Retrieve the special key
$secretKey = htmlspecialchars_decode($gatewayParams['secretKey']);


/**
 * read the data sent by POST
 */
$transactionId	= addslashes(trim(@$_POST['cart_id']));		// Euplatesc.ro unique id
$mid		= addslashes(trim(@$_POST['mid']));		// your merchant id
$timestamp	= addslashes(trim(@$_POST['timestamp']));	// meesage timestamp
$sec_status	= addslashes(trim(@$_POST['sec_status']));	// 8 or 9 for success

/**
 * search in the tblgateway for a record with $transactionID as result
 */
$sqlquery	= "SELECT data FROM tblgatewaylog WHERE result = '".$transactionId."'";
$result         = mysql_query($sqlquery) or die("No associated transaction found");
$qry            = mysql_fetch_assoc($result);

/**
 * de-serialize the data read from tblgatewaylog
 */
$base		= json_decode(base64_decode($qry['data']),true);

/**
 * Validate Callback Invoice ID.
 * Checks invoice ID is a valid invoice number. Note it will count an
 * invoice in any status as valid.
 * Performs a die upon encountering an invalid Invoice ID.
 * Returns a normalised invoice ID.
 */
$invoiceId = checkCbInvoiceID($base['invoice_id'], $gatewayParams['name']);

/**
 * Check Callback Transaction ID.
 * Performs a check for any existing transactions with the same given
 * transaction number.
 * Performs a die upon encountering a duplicate.
 */
checkCbTransID($transactionId);



switch($sec_status) {
        case "1":
                $transactionStatus="Valid transaction, pending state";
                break;
        case "2":
                $transactionStatus="Failed transaction";
                break;
        case "3":
                $transactionStatus="Manual verification";
                break;
        case "4":
                $transactionStatus="Suspicious transaction, waiting client response";
                break;
        case "5":
                $transactionStatus="Fraud";
                break;
        case "6":
                $transactionStatus="Suspicious transaction, cancel shipping";
                break;
        case "7":
                $transactionStatus="Insecure transaction";
                break;
        case "8":
                $transactionStatus="Authenticated transaction";
		addInvoicePayment(
			$invoiceId,
			$transactionId,
			$base['paid'],
			$base['fee'],
			$gatewayModuleName
		);
                break;
        case "9":
                $transactionStatus="Verified transaction";
		addInvoicePayment(
			$invoiceId,
			$responseData['ep_id'],
			$base['paid'],
			$base['fee'],
			$gatewayModuleName
		);
                break;
        default:
                $transactionStatus="Failed";
                break;
}

logTransaction($gatewayParams['name'], $_POST, $transactionStatus);
?>
