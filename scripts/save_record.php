<?php
use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;

$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory
$pid = $module->getProjectId();

header('Content-Type: application/json');

//$updated_record = json_decode($_POST['record'], true);
$updated_record = $_POST['record'];

if (empty($updated_record)) {
    http_response_code(400);
    echo json_encode(['error' => 'No data supplied. Something went wrong.']);
    exit;
}

// getProjectId() reads pid from the query string only - the pid in the POST
// body is invisible to it - so a request that arrives without one would
// otherwise be handed to saveData with no project at all.
if (empty($pid)) {
    http_response_code(400);
    echo json_encode(['error' => 'No project context for this request.']);
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

// saveData reports trouble in two shapes and raises no exception for either:
// a bare string for what it refuses outright, and an 'errors' list for what it
// rejected field by field. Both used to leave here as "Record saved
// successfully", so a refused save still closed the adjudication modal and
// reloaded the page, looking exactly like a save that worked.
if (is_string($result)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $result]);
    exit;
}

$errors = $result['errors'] ?? [];
if (!is_array($errors)) $errors = [$errors];

if (!empty($errors)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'REDCap rejected this save.',
        'errors' => $errors,
        'result' => $result
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Record saved successfully.',
    'ids' => array_values((array) ($result['ids'] ?? [])),
    'warnings' => $result['warnings'] ?? [],
    'result' => $result
]);
