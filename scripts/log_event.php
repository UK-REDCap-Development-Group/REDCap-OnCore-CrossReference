<?php
// Manually load REDCap
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/redcap_connect.php';

use ExternalModules\ExternalModules;

// Load the module instance
$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference');

// Log
$action = $_POST['action'] ?? 'Unknown';
$params = json_decode($_POST['params'] ?? '{}', true);
$params['initiated_by'] = defined('USERID') ? USERID : 'system';

$module->log($action, $params);

header('Content-Type: application/json');
echo json_encode(['status' => 'success']);