<?php
use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;

$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory
$pid = $module->getProjectId();

//$updated_record = json_decode($_POST['record'], true);
$updated_record = $_POST['record'];

if (empty($updated_record)) {
    http_response_code(400);
    echo json_encode(['error' => 'No data supplied. Something went wrong.']);
    exit;
}

$result = REDCap::saveData([
    'project_id' => $pid,
    'dataFormat' => 'json',
    'data' => $updated_record,
    'overwriteBehavior' => 'overwrite',
    'type' => 'flat', // currently built for flat data only
    'dataLogging' => true,
    'performAutoCalc' => true,
    'commitData' => true // if set to false, it does a test run and 
                         // returns what would have been saved.
]);


echo json_encode([
    'success' => true,
    'message' => 'Record saved successfully.',
    'result' => $result
]);
