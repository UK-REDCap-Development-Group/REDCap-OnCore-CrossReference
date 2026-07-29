<?php
use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;

$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory
$module->setProjectSetting('running', true);
$pid = $module->getProjectId();

$project_id = $module->getProjectId();

$irb_field = $module->getProjectSetting('irb-field') ?: 'eirb_number';
$protocol_field = $module->getProjectSetting('protocol-field') ?: 'rocs_protocol_number';
$title_field = $module->getProjectSetting('title-field') ?: 'full_title';
$dashboard_fields = $module->getProjectSetting('dashboard-fields', $pid) ?: [];
if (!is_array($dashboard_fields)) $dashboard_fields = [];

// Filter logic example
$filter = "([".$irb_field."] <> '' OR [".$protocol_field."] <> '') AND [rocs_sync(1)] <> '1'";

$fields = array_unique(array_merge(['record_id', $irb_field, $protocol_field, $title_field], $dashboard_fields));

$data = REDCap::getData([
    'project_id' => $pid,
    'return_format' => 'json',
    'filterLogic' => $filter,
    'fields' => $fields
]);

// Flatten and return as JSON
header('Content-Type: application/json');
//echo $filter;
echo $data;
