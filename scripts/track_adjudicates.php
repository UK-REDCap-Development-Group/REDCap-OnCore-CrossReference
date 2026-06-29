<?php
namespace UKModules\ROCS;

/** @var \ExternalModules\AbstractExternalModule $module */

$pid = (int) $_POST['pid'];
$adjudicates = json_decode($_POST['to-adjudicate'], true);

if (empty($pid) || !is_array($adjudicates)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid input', 'pid' => $pid, 'comparisons' => $adjudicates]));
}

$module->setProjectId($pid);
$module->setProjectSetting('to-adjudicate', $adjudicates);
$module->setProjectSetting('running', false);
echo json_encode([
    'status' => 'success',
    'message' => 'All comparisons saved successfully',
    'data' => $adjudicates,
]);