<?php
use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;

$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory
$pid = $module->getProjectId();

$project_id = $module->getProjectId();

// Filter logic example
$filter = "[eirb_number] <> '' AND [sync(1)] <> '1'";

$data = REDCap::getData([
    'project_id' => $pid,
    'return_format' => 'json',
    'filterLogic' => $filter,
    'fields' => ['eirb_number']
]);

// Flatten and return as JSON
header('Content-Type: application/json');
//echo $filter;
echo $data;
