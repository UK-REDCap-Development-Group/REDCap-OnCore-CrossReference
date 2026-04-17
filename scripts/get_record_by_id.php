<?php
use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;

$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory
$pid = $module->getProjectId();

$record_id = $_GET['record_id'] ?? '';
$forms = $_GET['forms'] ?? false;

if (empty($record_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing REDCap Record ID in request.']);
    exit;
}

$project_id = $module->getProjectId();

// Filter logic example
$filter = "[record_id] = " . $record_id . " AND [sync(1)] <> 1";

if ($forms) {
    $data = REDCap::getData([
        'project_id' => $pid,
        'return_format' => 'json',
        'forms' => $forms,
        'filterLogic' => $filter,
    ]);
}
else {
    $data = REDCap::getData([
        'project_id' => $pid,
        'return_format' => 'json',
        'filterLogic' => $filter,
    ]);
}


// Flatten and return as JSON
header('Content-Type: application/json');
//echo $filter;
echo $data;
