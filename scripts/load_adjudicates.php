<?php
namespace UKModules\ROCS;

/** @var \ExternalModules\AbstractExternalModule $module */

$pid = (int)($_GET['pid'] ?? $_POST['pid'] ?? 0);

if (empty($pid)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid project ID']));
}

$module->setProjectId($pid);
$comparisons = $module->getProjectSetting('to-adjudicate') ?? [];

echo json_encode([
    'status' => 'success',
    'data' => $comparisons,
]);