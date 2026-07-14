<?php

namespace UKModules\ROCS;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

require_once __DIR__ . '/REDCapHelper.php';

class ROCS extends AbstractExternalModule
{
    // TODO: implement the addition of a module role (or roles?) which can be checked to allow users to see and edit mappings and sync pages
    public function preconfigure($project_id) {
        // url check
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (preg_match("/\.uky\.edu/", $host)) {
            $this->setProjectSetting('oncore-token-url', 'https://uky-oncore-prod.forteresearchapps.com/forte-platform-web/api/oauth/token', $project_id);
            $this->setProjectSetting('oncore-api-url', 'https://uky-oncore-prod.forteresearchapps.com/oncore-api/rest/', $project_id);
        }

        // update data dictionary with helper form
        $data_dict = \REDCap::getDataDictionary($project_id, 'array');
        $current_forms = array_unique(array_column($data_dict, 'form_name'));
        $target_form = 'rocs_helper_form';

        if (!in_array($target_form, $current_forms)) {
            $new_fields = [
                'rocs_sync_desc' => [
                    'field_name' => 'rocs_sync_desc',
                    'form_name' => $target_form,
                    'section_header' => '',
                    'field_type' => 'descriptive',
                    'field_label' => 'This form is used to track records which have been ignored from future OnCore synchronization, as well as store protocol numbers if they are not already provided in your project.',
                    'select_choices_or_calculations' => '',
                    'field_note' => '',
                    'text_validation_type_or_show_slider_number' => '',
                    'text_validation_min' => '',
                    'text_validation_max' => '',
                    'identifier' => '',
                    'branching_logic' => '',
                    'required_field' => '',
                    'custom_alignment' => '',
                    'question_number' => '',
                    'matrix_group_name' => '',
                    'matrix_ranking' => '',
                    'field_annotation' => ''
                ],
                'rocs_sync' => [
                    'field_name' => 'rocs_sync',
                    'form_name' => $target_form,
                    'section_header' => '',
                    'field_type' => 'checkbox',
                    'field_label' => 'Synchronize with OnCore through external module?',
                    'select_choices_or_calculations' => '1, Opt-Out of Synchronization',
                    'field_note' => '',
                    'text_validation_type_or_show_slider_number' => '',
                    'text_validation_min' => '',
                    'text_validation_max' => '',
                    'identifier' => '',
                    'branching_logic' => '',
                    'required_field' => '',
                    'custom_alignment' => '',
                    'question_number' => '',
                    'matrix_group_name' => '',
                    'matrix_ranking' => '',
                    'field_annotation' => ''
                ],
                'rocs_protocol_number' => [
                    'field_name' => 'rocs_protocol_number',
                    'form_name' => $target_form,
                    'section_header' => '',
                    'field_type' => 'text',
                    'field_label' => 'Protocol Number',
                    'select_choices_or_calculations' => '',
                    'field_note' => '',
                    'text_validation_type_or_show_slider_number' => '',
                    'text_validation_min' => '',
                    'text_validation_max' => '',
                    'identifier' => '',
                    'branching_logic' => '',
                    'required_field' => '',
                    'custom_alignment' => '',
                    'question_number' => '',
                    'matrix_group_name' => '',
                    'matrix_ranking' => '',
                    'field_annotation' => ''
                ]
            ];

            // Append new fields
            foreach ($new_fields as $field_name => $field_attributes) {
                $data_dict[$field_name] = $field_attributes;
            }

            try {
                \REDCapHelper::saveDataDictionary($project_id, $data_dict);

                if (in_array('demographics', $current_forms) && in_array('regulatory', $current_forms)) {
                    $this->setProjectSetting('sync-page', ['demographics', 'regulatory'], $project_id);
                }
                if (isset($data_dict['eirb_number'])) {
                    $this->setProjectSetting('irb-field', 'eirb_number', $project_id);
                }
                if (isset($data_dict['full_title'])) {
                    $this->setProjectSetting('title-field', 'full_title', $project_id);
                }

                $this->log("Module Initialized Successfully", ['project_id' => $project_id, 'executed_by' => 'system'], $project_id, 'System');
            } catch (\Exception $e) {
                $this->log("Module Initialization Failed", ['details' => $e->getMessage(), 'executed_by' => 'system'], $project_id, 'System');
            }
        }

        //Auto-Assign Project Creator (Self-Healing)
        $current_users = $this->getProjectSetting('authorized-users', $project_id);
        if (!is_array($current_users)) $current_users = $current_users ? [$current_users] : [];
        $current_users = array_filter($current_users);

        if (empty($current_users)) {
            $creator_sql = "SELECT u.username 
                    FROM redcap_projects p 
                    JOIN redcap_user_information u ON p.created_by = u.ui_id 
                    WHERE p.project_id = ?";
            $creator_result = $this->query($creator_sql, [$project_id]);

            $creator_username = null;
            if ($creator_result->num_rows > 0) {
                $creator_username = $creator_result->fetch_assoc()['username'];
            } else {
                $creator_username = defined('USERID') ? USERID : null;
            }

            if ($creator_username) {
                $this->setProjectSetting('authorized-users', [$creator_username], $project_id);

                // CRITICAL: Seed the cache so the save hook doesn't log this as a manual addition
                $this->setProjectSetting('authorized-users-cache', [$creator_username], $project_id);

                $this->log("User Authorized", [
                    'project_id' => $project_id,
                    'details' => "System auto-assigned $creator_username to authorized users.",
                    'executed_by' => "System"
                ], $project_id, 'System');
            }
        }
    }

    public function redcap_module_project_enable($project_id) {
        $this->preconfigure($project_id);
    }

    public function redcap_module_save_configuration($project_id) {
        // Audit Logging for Authorized Users
        $new_users = $this->getProjectSetting('authorized-users', $project_id);
        if (!is_array($new_users)) $new_users = $new_users ? [$new_users] : [];
        $new_users = array_filter($new_users);

        $old_users = $this->getProjectSetting('authorized-users-cache', $project_id);
        if (!is_array($old_users)) $old_users = $old_users ? [$old_users] : [];
        $old_users = array_filter($old_users);

        $added_users = array_diff($new_users, $old_users);
        $removed_users = array_diff($old_users, $new_users);

        if (!empty($added_users) || !empty($removed_users)) {
            $modifier = defined('USERID') ? USERID : 'System';
            $log_message = "Module Access Updated by $modifier. ";

            if (!empty($added_users)) $log_message .= "Granted to: " . implode(", ", $added_users) . ". ";
            if (!empty($removed_users)) $log_message .= "Revoked from: " . implode(", ", $removed_users) . ".";

            $this->log("Authorized Users Changed", [
                'project_id' => $project_id,
                'initiated_by' => $modifier,
                'details' => trim($log_message)
            ]);

            // Update the shadow cache to match the new configuration
            $this->setProjectSetting('authorized-users-cache', $new_users, $project_id);
        }

        // Run the rest of the self-healing setup
        $this->preconfigure($project_id);
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
            // Fetch Token
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

            // Make the actual API Request
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

    // Checks for which form we are on and includes instructions for mapping data to fiels on that page
    function redcap_every_page_top($project_id) {
        // Check authorization
        $authorized_users = $this->getProjectSetting('authorized-users');
        if (!is_array($authorized_users)) $authorized_users = $authorized_users ? [$authorized_users] : [];

        $is_authorized = (SUPER_USER || in_array(USERID, $authorized_users));

        if ($is_authorized):
        ?>
        <script type="text/javascript">
            $(document).ready(function() {
                // Target the main application menu sidebar
                var $appMenu = $('#app_panel');

                // Check if we already injected these to prevent duplicates
                if ($appMenu.length && !$('#rocs-custom-app-links').length) {
                    var linksHtml = `
                    <div id="rocs-custom-app-links" class="x-panel-header x-panel-header-leftmenu">
                        <div style="float:left">ROCS Tools</div>
                        <div class="x-panel-body">
                            <div class="opacity65 projMenuToggle">
                                <a href="javascript:;">
                                    <img src="/redcap/redcap_v16.1.7/Resources/images/toggle-collapse.png" aria-hidden="true">
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="x-panel-bwrap">
                        <div class="x-panel-body">
                            <div class="menubox">
                                <div class="menubox" style="padding-right:0;">
                                    <div class="hang">
                                        <a href="<?php echo $this->getUrl('pages/FieldMappings.php'); ?>" style="display:block; padding: 3px 0;">
                                            <i class="fas fa-right-left"></i> OnCore Mappings
                                        </a>
                                    </div>
                                    <div class="hang">
                                        <a href="<?php echo $this->getUrl('pages/SyncDashboard.php'); ?>" style="display:block; padding: 3px 0;">
                                            <i class="fas fa-arrows-rotate"></i> Sync Dashboard
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                    $appMenu.append(linksHtml);

                    // Add the click handler for the new element
                    $('#rocs-custom-app-links').find('.projMenuToggle').on('click', function() {
                        var $bwrap = $(this).closest('.x-panel-header').next('.x-panel-bwrap');
                        if ($bwrap.is(':visible')) {
                            $bwrap.slideUp();
                            $(this).find('img').attr('src', '/redcap/redcap_v16.1.7/Resources/images/toggle-expand.png');
                        } else {
                            $bwrap.slideDown();
                            $(this).find('img').attr('src', '/redcap/redcap_v16.1.7/Resources/images/toggle-collapse.png');
                        }
                    });
                }

            });
        </script>
        <?php endif;
        // Generate the URL for your AJAX logging endpoint
        $logAjaxUrl = $this->getUrl('scripts/log_event.php');

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

        if (!is_array($sync_pages)) {
            $sync_pages = $sync_pages ? [$sync_pages] : [];
        }

        $sync_pages = array_filter($sync_pages);

        $current_page = $_GET['page'] ?? '';

        $is_configured_sync_page = (!empty($sync_pages) && in_array($current_page, $sync_pages));

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
        else if (isset($is_configured_sync_page) && $is_configured_sync_page) {
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
        else if (self::isSyncDashboardPage()) {
            include 'scripts/scripts.php';
        }
    }

    // TODO: implement a function that checks for a specific user role and only allows those users to see any of the configuration or sync options
}
