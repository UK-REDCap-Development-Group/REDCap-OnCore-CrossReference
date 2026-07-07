<?php
$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory

// Ensure this is a valid REDCap request
if (!defined('REDCap')) exit;

// Retrieve the POST data
$action = $_POST['action'] ?? 'Unknown Frontend Action';
$paramsRaw = $_POST['params'] ?? '{}';

// Decode the JSON parameters back into a PHP array
$params = json_decode($paramsRaw, true);
if (!is_array($params)) {
    $params = [];
}

// Automatically append the user who triggered the JS function
$params['initiated_by'] = defined('USERID') ? USERID : 'system';

// Write to the External Modules log!
$module->log($action, $params);

// Send a success response back to the JavaScript
header('Content-Type: application/json');
echo json_encode(['status' => 'success']);
?>