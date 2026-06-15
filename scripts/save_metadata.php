<?php
namespace UKModules\ROCS;

/** @var \ExternalModules\AbstractExternalModule $module */

$pid = (int) $_POST['pid'];
$metadata = json_decode($_POST['adj-metadata'], true);

if (empty($pid) || !is_array($metadata)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid input', 'pid' => $pid, 'metadata' => $metadata]));
}

$module->setProjectId($pid);
$module->setProjectSetting('adj-metadata', $metadata);

echo json_encode([
    'status' => 'success',
    'message' => 'Sync metadata saved successfully!',
    'data' => $metadata,
]);