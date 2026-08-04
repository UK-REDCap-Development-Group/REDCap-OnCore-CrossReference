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
    $queryEndpoints = [
        'protocolManagementDetails',
        'protocolConsents',
        'protocolSponsors',
        'protocolStaff',
        'protocolEprmsSubmissions',
        'protocolPrmcReviews',
        'protocolIde',
        'protocolInd',
        'protocolIrbReviews',
        'protocolInstitutions',
        'protocolTasks',
        'contactCredentials'
    ];

    // These API resources use their ID as a path segment rather than a query
    // parameter. They are used to enrich protocol sponsor/staff records.
    $pathEndpoints = [
        'protocols' => 'protocolId',
        'contacts' => 'contactId',
        'sponsors' => 'sponsorId'
    ];

    if (isset($pathEndpoints[$action])) {
        $parameter = $pathEndpoints[$action];
        $resourceId = $params[$parameter] ?? '';
        if ($resourceId === '') {
            throw new \InvalidArgumentException($parameter . ' is required for the ' . $action . ' endpoint.');
        }
        echo $module->proxyRequest('/' . $action . '/' . rawurlencode($resourceId));
    } elseif (in_array($action, $queryEndpoints, true)) {
        echo $module->proxyRequest('/' . $action . ($queryString === '' ? '' : '?' . $queryString));
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing action.']);
    }
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'code' => $e->getCode(), 'message' => 'Server error: ' . $e->getMessage()]);
}
