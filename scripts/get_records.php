<?php
use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;

$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory
$pid = $module->getProjectId();

$field_name = $_GET['field'] ?? '';
$value = $_GET['value'] ?? '';

if (empty($field_name)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing REDCap Field in request.']);
    exit;
}

if (empty($value)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing an identifying Value for REDCap Field in request.']);
    exit;
}

$project_id = $module->getProjectId();

// Filter logic example
$filter = "[" . $field_name . "] = '" . $value . "'";

$data = REDCap::getData([
    'project_id' => $pid,
    'return_format' => 'json',
    'filterLogic' => $filter,
]);

// Flatten and return as JSON
header('Content-Type: application/json');
//echo $filter;
echo $data;
