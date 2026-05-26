<?php
use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;

$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory
$pid = $module->getProjectId();

$project_id = $module->getProjectId();

$irb_field = $module->getProjectSetting('irb-field');

// Filter logic example
$filter = "[".$irb_field."] <> '' AND [sync(1)] <> '1'";

$data = REDCap::getData([
    'project_id' => $pid,
    'return_format' => 'json',
    'filterLogic' => $filter,
    'fields' => [$irb_field]
]);

// Flatten and return as JSON
header('Content-Type: application/json');
//echo $filter;
echo $data;
