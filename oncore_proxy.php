<?php

use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;

$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory

header('Content-Type: application/json');

// FOR TESTING ONLY:
//echo json_encode(['success' => true, 'message' => 'Test successful! The proxy.php file is reachable.']);
//exit(); // Stop the script here

if (!defined('PAGE')) define('PAGE', 'ajax');

header('Content-Type: application/json');

$action = $_GET['action'] ?? ''; // get only the action parameter
$special = $_GET['special'] ?? ''; // only used in cases where format changes

$params = $_GET; // get every parameter from the url

// remove REDCap specific components
unset($params['action']);
unset($params['prefix']);
unset($params['page']);
unset($params['pid']);
unset($params['pnid']);
unset($params['instance']);

$queryString = http_build_query($params); // rebuild parameters into something we can append to a URL below

try {
    switch ($action) {
        case 'protocolManagementDetails':
            // GET
            // Query string should be:  irbNo=
            $response = $module->proxyPost("/protocolManagementDetails?$queryString");
            echo $response;
            break;

        case 'protocols':
            // GET
            // Query string should be: protocolId=
            $queryString = explode('=', $queryString)[1];
            $response = $module->proxyPost("/protocols/$queryString");
            echo $response;
            break;

        case 'protocolSponsors':
            // GET
            // Query string should be: protocolId= or sponsorProtocolNo=
            $response = $module->proxyPost("/protocolSponsors?$queryString");
            echo $response;
            break;

        case 'protocolStaff':
            // GET
            // Query string should be: protocolId=
            $response = $module->proxyPost("/protocolStaff?$queryString");
            echo $response;
            break;

        case 'protocolManagementDetails':
            // GET
            // Query string requires irbNo
            $response = $module->proxyPost("/protocolManagementDetails/".$special);
            echo $response;
            break;

        case 'protocolPrmcReviews':
            // GET
            $response = $module->proxyPost("/protocolPrmcReviews?$queryString");
            echo $response;
            break;

        case 'protocolTasks':
            // GET
            $response = $module->proxyPost("/protocolTasks?$queryString");
            echo $response;
            break;

        case 'protocolInd':
            // GET
            $response = $module->proxyPost("/protocolInd?$queryString");
            echo $response;
            break;

        case 'contactCredentials':
            // GET
            // query string should be contactId=
            $response = $module->proxyPost("/contactCredentials?$queryString");
            echo $response;
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid or missing action.']);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
