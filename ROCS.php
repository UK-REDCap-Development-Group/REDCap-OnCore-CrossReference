<?php

namespace UKModules\ROCS;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

class ROCS extends AbstractExternalModule
{

    // provided courtesy of Scott J. Pearson
    private static function isExternalModulePage()
    {
        $page = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : "";
        if (preg_match("/ExternalModules\/manager\/project.php/", $page)) {
            return TRUE;
        }
        if (preg_match("/ExternalModules\/manager\/ajax\//", $page)) {
            return TRUE;
        }
        if (preg_match("/external_modules\/manager\/project.php/", $page)) {
            return TRUE;
        }
        if (preg_match("/external_modules\/manager\/ajax\//", $page)) {
            return TRUE;
        }
        return FALSE;
    }

    protected function includeJS($file)
    {
        // Use this function to use your JavaScript files in the frontend
        echo '<script src="' . $this->getUrl($file) . '"></script>';
    }

    protected function variable()
    {
        echo "<script>variable={}</script>";
        $this->includeJS('js/project_settings.js');
    }

    // This function needs more updates before it is finished.
    private static function isSyncDashboardPage()
    {
        $page = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : "";
        if (preg_match("/ExternalModules\/\??prefix=REDCap-OnCore-CrossReference&page=pages%2FSyncDashboard/", $_SERVER['REQUEST_URI'])) {
            return TRUE;
        }
        return FALSE;
    }

    // This function needs more updates before it is finished.
    private static function isFieldMappingPage()
    {
        $page = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : "";
        if (preg_match("/ExternalModules\/\??prefix=REDCap-OnCore-CrossReference&page=pages%2FFieldMapping/", $_SERVER['REQUEST_URI'])) {
            return TRUE;
        }
        return FALSE;
    }

    // Collect a specific form from the project
    function stuff() {
    }

    // Checks for which form we are on and includes instructions for mapping data to fiels on that page
    function redcap_every_page_top($project_id) {
        if (self::isFieldMappingPage()) {
            $project_id = $_GET['pid']; // or however you're getting the project ID
            $instruments = REDCap::getInstrumentNames(); // Get instrument names

            // Organize the data dictionary by form_name
            $raw_dict = REDCap::getDataDictionary('array');
            $data_dict = [];
            foreach ($raw_dict as $field_name => $field_info) {
                $form_name = $field_info['form_name'];
                if (!isset($data_dict[$form_name])) {
                    $data_dict[$form_name] = [];
                }
                $data_dict[$form_name][$field_name] = $field_info;
            }

            // Initialize an empty array to store data by instrument
            $data_by_instrument = [];

            // Loop through each instrument and retrieve only its data
            foreach ($instruments as $instrument_name => $instrument_label) {
                if (!isset($data_dict[$instrument_name])) continue;

                // Get field names for this instrument
                $instrument_fields = array_keys($data_dict[$instrument_name]);

                // Retrieve data for only those fields
                if (!empty($instrument_fields)) {
                    $records = REDCap::getData([
                        'project_id' => $project_id,
                        'return_format' => 'json',
                        'fields' => $instrument_fields
                    ]);

                    $data_by_instrument[$instrument_name] = json_decode($records, true);
                }
            }

            $form = $this->getProjectSetting('form-id');
            $classifier = $this->getProjectSetting('class-field');
            $email = $this->getProjectSetting('classify-email');
            $data = REDCap::getData($project_id, 'csv');
            $project_title = REDCap::getProjectTitle();
            $filename = $this->getProjectSetting('filename');

            ?>
            <script>
                const instruments = <?= json_encode($instruments) ?>;
                const dictionary = <?= json_encode($data_dict) ?>;
                const selectedForms = <?= json_encode($form) ?>;
                const classifier = <?= json_encode($classifier) ?>;
                const email = <?= json_encode($email) ?>;
                const project_title = <?= json_encode($project_title) ?>;
            </script>
            <?php
        }
    }

}
