<?php

namespace UKModules\ROCS;

/** @var \ExternalModules\AbstractExternalModule $module */

$pid = (int)$_POST['pid'];
$mismatches = json_decode($_POST['mappings'], true);

if (empty($pid) || !is_array($mismatches)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid input']));
}

$module->setProjectId($pid);
$module->setProjectSetting('to-adjudicate', $mismatches);

echo json_encode([
    'status' => 'success',
    'message' => 'All mappings saved successfully',
    'data' => $mismatches,
]);