<?php
use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;

$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory
$pid = $module->getProjectId();

// Safely capture and format the forms input
$forms_input = $_GET['forms'] ?? [];

if (is_string($forms_input) && !empty($forms_input)) {
    $forms = explode(',', $forms_input);
} else if (is_array($forms_input)) {
    $forms = $forms_input;
} else {
    $forms = [];
}

$filter = "[eirb_number] != '' AND [irb_number] != '' AND [sync(1)] <> 1";

// Define the base fields you always want
$requested_fields = ['record_id', 'eirb_number', 'irb_number'];

// If forms were passed, grab all their fields and merge them
if (!empty($forms)) {
    foreach ($forms as $form) {
        // This returns an array of all variable names on the specified instrument
        $form_fields = REDCap::getFieldNames($form);

        if (!empty($form_fields)) {
            $requested_fields = array_merge($requested_fields, $form_fields);
        }
    }
}

// Remove any duplicate fields (like record_id, which gets pulled with the form)
$requested_fields = array_unique($requested_fields);

// Run the query using ONLY the comprehensive fields array
$data = REDCap::getData([
    'project_id' => $pid,
    'fields' => $requested_fields, // Now contains base fields + all form fields
    'return_format' => 'json',
    'filterLogic' => $filter,
]);

// Flatten and return as JSON
header('Content-Type: application/json');
echo $data;
