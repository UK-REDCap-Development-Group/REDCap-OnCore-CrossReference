<?php
use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;

$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory
$pid = $module->getProjectId();

$forms = $_GET['forms'] ?? [];

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
$filter = "[oncore_sync_ignore] != 1";

if ($forms == []) {
    $data = REDCap::getData([
        'project_id' => $pid,
        'fields' => array('record_id'),
        'return_format' => 'json',
        'filterLogic' => $filter,
    ]);
}
else {
    $data = REDCap::getData([
        'project_id' => $pid,
        'fields' => 'record_id',
        'forms' => $forms,
        'return_format' => 'json',
        'filterLogic' => $filter,
    ]);
}


// Flatten and return as JSON
header('Content-Type: application/json');
//echo $filter;
echo $data;
