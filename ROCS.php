<?php

namespace UKModules\ROCS;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class ROCS extends AbstractExternalModule
{   

    // Functional proxy to hit from frontend to communicate with external APIs. Used in proxy.php
    // This version is assuming data is included as a json (not using JSON.stringify).
    // Ensure requests to proxyPost will have the csrf token included in the json.
    public function proxyPost($apiPath)
    {
        $client = new Client();
        $tokenUrl = trim($this->getProjectSetting('oncore-token-url') ?: '');
        $baseUrl = trim($this->getProjectSetting('oncore-api-url') ?: '');

        if (empty($tokenUrl)) {
            throw new \Exception("Token URL is not configured.");
        }

        if (empty($baseUrl)) {
            throw new \Exception("API URL is not configured.");
        }

        $apiUrl = rtrim($baseUrl, '/') . '/' . ltrim($apiPath, '/');

        // Get OAuth credentials from your settings or config
        $clientId = $this->getProjectSetting('oncore-client');
        $clientSecret = $this->getProjectSetting('oncore-secret');

        try {
            $token_response = $client->post($tokenUrl, [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'form_params' => [
                        "client_id" => $clientId,
                        "client_secret" => $clientSecret,
                        "grant_type" => "client_credentials"
                    ],
                    'http_errors' => false, // avoid exceptions on 4xx/5xx
                    'verify' => true,       // set to false only if SSL issues
            ]);

            $token_data = json_decode($token_response->getBody()->getContents(), true);
            $access_token = $token_data['access_token'] ?? null;

            error_log('Token Response: ' . $token_response->getBody()->getContents());

            $response = $client->get($apiUrl, [
                    'headers' => [
                        'Authorization' => "Bearer $access_token"
                    ],
            ]);

            http_response_code($response->getStatusCode());
            echo $response->getBody()->getContents();

        } catch (RequestException $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Request failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

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
        // Get the OnCore API base from the project settings
        $oncore_url = $this->getProjectSetting('oncore-base-url');

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
            $apiUrl = APP_PATH_WEBROOT_FULL . 'api/';

            ?>
            <script>
                const instruments = <?= json_encode($instruments) ?>;
                const dictionary = <?= json_encode($data_dict) ?>;
                const selectedForms = <?= json_encode($form) ?>;
                const classifier = <?= json_encode($classifier) ?>;
                const email = <?= json_encode($email) ?>;
                const project_title = <?= json_encode($project_title) ?>;
                const API_URL = <?= json_encode($apiUrl); ?>;
            </script>
            <?php
        }
    }

}
