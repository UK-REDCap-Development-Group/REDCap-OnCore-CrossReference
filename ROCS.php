<?php

namespace UKModules\ROCS;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class ROCS extends AbstractExternalModule
{   
    public function preconfigure() {
        $init = $this->getProjectSetting('init');

        if (!$init) {
            $host = $_SERVER['HTTP_HOST'];

            // Set the urls automatically to UK OnCore if we are on a UK domain
            if (preg_match("/\.uky\.edu/", $host)) {
                $this->setProjectSetting('oncore-token-url', 'https://uky-oncore-prod.forteresearchapps.com/forte-platform-web/api/oauth/token');
                $this->setProjectSetting('oncore-api-url', 'https://uky-oncore-prod.forteresearchapps.com/oncore-api/rest/');
            }

            $instruments = REDCap::getInstrumentNames();

            if (array_key_exists('demographics', $instruments) && array_key_exists('regulatory', $instruments)) {
                $this->setProjectSetting('sync-page', ['demographics', 'regulatory']);
            }

            $data_dict = REDCap::getDataDictionary();

            global $Proj;

            // $Proj->metadata is an array of all fields
            if (isset($Proj->metadata['eirb_number'])) {
                $this->setProjectSetting('irb-field', 'eirb_number');
            }

            if (isset($Proj->metadata['full_title'])) {
                $this->setProjectSetting('title-field', 'full_title');
            }

            $this->setProjectSetting('init', true);
            $this->setProjectSetting('running', false);
        }
    }

    // Functional proxy to hit from frontend to communicate with external APIs. Used in proxy.php
    // This version is assuming data is included as a json (not using JSON.stringify).
    // Ensure requests to proxyRequest will have the csrf token included in the json.
    public function proxyRequest($apiPath, $method = 'GET', $payload = [])
    {
        //$client = new Client(); // disabled because it didn't work on our test instance despite SSL being enabled on that server
        $client = new Client(['verify' => false]);
        $tokenUrl = trim($this->getProjectSetting('oncore-token-url') ?: '');
        $baseUrl = trim($this->getProjectSetting('oncore-api-url') ?: '');

        if (empty($tokenUrl)) {
            throw new \Exception("Token URL is not configured.");
        }

        if (empty($baseUrl)) {
            throw new \Exception("API URL is not configured.");
        }

        $apiUrl = rtrim($baseUrl, '/') . '/' . ltrim($apiPath, '/');
        $clientId = $this->getProjectSetting('oncore-client');
        $clientSecret = $this->getProjectSetting('oncore-secret');

        try {
            // 1. Fetch Token
            $token_response = $client->post($tokenUrl, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    "client_id" => $clientId,
                    "client_secret" => $clientSecret,
                    "grant_type" => "client_credentials"
                ],
                'verify' => false, // Force bypass on this specific request
                'curl' => [
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false
                ]
            ]);

            // Read the stream exactly once
            $tokenResponseBody = (string) $token_response->getBody();
            $token_data = json_decode($tokenResponseBody, true);

            $access_token = $token_data['access_token'] ?? null;

            // Ensure we actually got a token before proceeding
            if (!$access_token) {
                error_log("OnCore Token Error: " . $tokenResponseBody);
                throw new \Exception("Failed to retrieve access token from OnCore.");
            }

            // 2. Make the actual API Request
            $requestOptions = [
                'headers' => [
                    'Authorization' => "Bearer $access_token",
                    'Accept'        => 'application/json'
                ]
            ];

            // Add payload if it's a POST/PUT request
            if (!empty($payload) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
                $requestOptions['json'] = $payload; // Guzzle handles JSON encoding and headers
            }

            $response = $client->request(strtoupper($method), $apiUrl, $requestOptions);

            http_response_code($response->getStatusCode());
            echo $response->getBody()->getContents();

        } catch (RequestException $e) {
            http_response_code(500);

            // Safely extract the response body if it exists
            $errorBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'No response from server';

            echo json_encode([
                'error' => 'Request failed',
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'oncore_details' => json_decode($errorBody) ?? $errorBody
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

    // Script assumes root level, so include folders
    protected function includeJS($path)
    {
        // Use this function to use your JavaScript files in the frontend
        echo '<script src="' . $this->getUrl($path) . '"></script>';
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

    private static function isDemographicsPage() {
        if ($_GET['page'] === 'demographics') {
            return TRUE;
        }
        return FALSE;
    }

    private static function isInstrumentPage($instrument) {
        if ($_GET['page'] === $instrument) {
            return TRUE;
        }
        return FALSE;
    }

    private static function isRegulatoryPage() {
        if ($_GET['page'] === 'regulatory') {
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

    public static function getRecordStatusDashboard($pid) {
        return $_SERVER['REQUEST_URI'];
    }

    // Collect mapped forms, ignore records that are
    function fullSync() {

    }

    // Checks for which form we are on and includes instructions for mapping data to fiels on that page
    function redcap_every_page_top($project_id) {
        $this->preconfigure();
        // Get the OnCore API base from the project settings
        $oncore_url = $this->getProjectSetting('oncore-base-url');

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

        // Dynamic check for selected pages that get sync buttons
        $sync_pages = $this->getProjectSetting('sync-page');
        $is_configured_sync_page = false;

        if (is_array($sync_pages)) {
            foreach ($sync_pages as $instrument) {
                if (self::isInstrumentPage($instrument)) {
                    $is_configured_sync_page = true;
                    break;
                }
            }
        }

        if (self::isFieldMappingPage()) {
            include 'scripts/scripts.php';
            $project_id = $_GET['pid'];

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
                const project_id = <?= json_encode($_GET['pid']); ?>;
            </script>
            <?php
        }
        // boolean replaced individual functions for each page, allowing config instead of hardcoding
        else if ($is_configured_sync_page) {
            include 'scripts/scripts.php';
            $mappings = $this->getProjectSetting('field-mappings');
            $page = $_GET['page'];
            $mapping_page = $this->getUrl('pages/FieldMappings.php');
            ?>
            <script>
                const dictionary = <?= json_encode($data_dict) ?>;
                const mappings = <?= json_encode($mappings) ?>;
                const instruments = <?= json_encode($instruments) ?>;
                const current_page = <?= json_encode($page) ?>;
                const hyperlink = <?= json_encode($mapping_page) ?>;

                console.log('You are on a configured sync page.');
                document.addEventListener('DOMContentLoaded', () => {
                    const container = document.getElementById('dataEntryTopOptionsButtons');
                    const modify = container.children[1];

                    const sync_button = document.createElement('button');
                    sync_button.type = 'button';
                    sync_button.id = 'sync_button';
                    sync_button.classList = 'jqbuttonmed ui-button ui-corner-all ui-widget';
                    sync_button.style = 'color:#0096FF;';
                    sync_button.innerHTML = `
                    <i class='fas fa-arrows-rotate'></i>
                    <span>Sync Record with OnCore</span>
                `
                    console.log('building ma button')
                    if (modify) {
                        container.insertBefore(sync_button, modify.nextSibling);
                    } else {
                        container.appendChild(sync_button);
                    }

                    sync_button.addEventListener('click', () => {
                        console.log('sync_button clicked');
                        console.log(current_page);
                        console.log(mappings);
                        if (mappings.hasOwnProperty(current_page)) {
                            console.log("Getting ready to run singleRecordSync from scripts.php")
                            singleRecordSync();
                        }
                        else {
                            $(`<div title="Mapping Error">No fields are mapped for this Form. Please visit the <a href='${hyperlink}' target="_blank">Field Mappings</a> page to configure mappings between this Form and OnCore.</div>`).dialog();
                        }
                    });
                });
            </script>
            <?php
        }
    }

}
