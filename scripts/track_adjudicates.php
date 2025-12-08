<?php
namespace UKModules\ROCS;

/** @var \ExternalModules\AbstractExternalModule $module */

$pid = (int) $_POST['pid'];
$comparisons = json_decode($_POST['comparisons'], true);

if (empty($pid) || !is_array($comparisons)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid input', 'pid' => $pid, 'comparisons' => $comparisons]));
}

$module->setProjectId($pid);
$module->setProjectSetting('to-adjudicate', $comparisons);

echo json_encode([
    'status' => 'success',
    'message' => 'All comparisons saved successfully',
    'data' => $comparisons,
]);