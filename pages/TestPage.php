<?php
/** @var \ExternalModules\AbstractExternalModule $module */
$pid = $module->getProjectId();

// confirm that PID is set
if (empty($pid)) {
    throw new Exception("No project context found for this module");
}

// make sure filter syntax is correct
$filter = "[irb_number] = '15-0927-F1V'";

// simplest working call
$data = REDCap::getData([
    'project_id' => $pid,
    'return_format' => 'json',
    'filterLogic' => $filter,
]);

echo $data;
