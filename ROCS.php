<?php

namespace UKModules\ROCS;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

require_once __DIR__ . '/REDCapHelper.php';
require_once __DIR__ . '/classes/OnCoreFieldPath.php';

class ROCS extends AbstractExternalModule
{
    /** The form ROCS adds to a project to hold its own bookkeeping fields. */
    const HELPER_FORM = 'rocs_helper_form';

    /**
     * The module's own fields. The sync filters on rocs_sync and writes protocol
     * numbers back to rocs_protocol_number, so a project without them does not
     * sync at all. That is why these are restored on every run while everything
     * else preconfigure() writes is only ever a starting point.
     */
    private function helperFormFields()
    {
        $blank = [
            'section_header' => '',
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
        ];

        return [
            'rocs_sync_desc' => array_merge($blank, [
                'field_name' => 'rocs_sync_desc',
                'form_name' => self::HELPER_FORM,
                'field_type' => 'descriptive',
                'field_label' => 'This form is used to track records which have been ignored from future OnCore synchronization, as well as store protocol numbers if they are not already provided in your project.'
            ]),
            'rocs_sync' => array_merge($blank, [
                'field_name' => 'rocs_sync',
                'form_name' => self::HELPER_FORM,
                'field_type' => 'checkbox',
                'field_label' => 'Synchronize with OnCore through external module?',
                'select_choices_or_calculations' => '1, Opt-Out of Synchronization'
            ]),
            'rocs_protocol_number' => array_merge($blank, [
                'field_name' => 'rocs_protocol_number',
                'form_name' => self::HELPER_FORM,
                'field_type' => 'text',
                'field_label' => 'Protocol Number'
            ])
        ];
    }

    /**
     * Put back any of the module's own fields that have gone missing, keeping
     * the helper form one contiguous block: REDCap rejects a dictionary whose
     * forms are interleaved, so a single deleted field cannot simply be appended
     * to the end while the rest of its form is still in place.
     *
     * Returns the dictionary to save. $restored is filled with the names that
     * were added, and is empty when there was nothing to do.
     */
    private function restoreHelperFormFields(array $data_dict, array &$restored)
    {
        $required = $this->helperFormFields();
        $missing = array_diff_key($required, $data_dict);
        $restored = array_keys($missing);

        if (empty($missing)) {
            return $data_dict;
        }

        // Where the form still has fields, the restored ones follow the last of
        // them; where it has none, they start the form at the end.
        $anchor = null;
        foreach ($data_dict as $field_name => $attributes) {
            if (($attributes['form_name'] ?? '') === self::HELPER_FORM) {
                $anchor = $field_name;
            }
        }

        if ($anchor === null) {
            return array_merge($data_dict, $missing);
        }

        $rebuilt = [];
        foreach ($data_dict as $field_name => $attributes) {
            $rebuilt[$field_name] = $attributes;
            if ($field_name === $anchor) {
                foreach ($missing as $name => $definition) {
                    $rebuilt[$name] = $definition;
                }
            }
        }

        return $rebuilt;
    }

    /**
     * Write a default only into a setting that has nothing usable in it.
     *
     * preconfigure() runs on every configuration save, so a setting written
     * unconditionally is one the admin cannot change: their edit would be undone
     * by the same save that made it. Seeding leaves any existing value alone.
     */
    private function seedProjectSetting($key, $value, $project_id)
    {
        $current = $this->getProjectSetting($key, $project_id);

        if (is_array($current)) {
            $current = array_filter($current, static function ($entry) {
                return $entry !== null && trim((string) $entry) !== '';
            });
            $is_empty = empty($current);
        } else {
            $is_empty = ($current === null || trim((string) $current) === '');
        }

        if (!$is_empty) {
            return false;
        }

        $this->setProjectSetting($key, $value, $project_id);
        return true;
    }

    /**
     * Keep a setting that names a REDCap field pointing at a field that exists.
     *
     * An admin's choice is honoured for as long as it resolves. A setting naming
     * a field that has since been deleted is the one case worth overruling: it
     * reads as empty on every record, so the sync silently compares nothing and
     * reports every protocol as missing from OnCore.
     */
    private function repairFieldSetting($key, $default, array $data_dict, $project_id)
    {
        $current = $this->getProjectSetting($key, $project_id);
        if (is_array($current)) {
            $current = reset($current);
        }
        $current = trim((string) $current);

        if ($current !== '' && isset($data_dict[$current])) {
            return;
        }

        if (!isset($data_dict[$default])) {
            // Nothing to fall back to. Said once, with the field named, rather
            // than left to surface as an empty sync.
            if ($current !== '') {
                $this->log("ROCS Field Setting Unresolved", [
                    'project_id' => $project_id,
                    'setting' => $key,
                    'configured' => $current,
                    'details' => "The configured field no longer exists in this project and the default '$default' is not present either. Choose a field in the module configuration.",
                    'executed_by' => 'system'
                ], $project_id, 'System');
            }
            return;
        }

        $this->setProjectSetting($key, $default, $project_id);

        if ($current !== '') {
            $this->log("ROCS Field Setting Repaired", [
                'project_id' => $project_id,
                'setting' => $key,
                'configured' => $current,
                'details' => "The configured field no longer exists in this project; reset to '$default'.",
                'executed_by' => 'system'
            ], $project_id, 'System');
        }
    }

    // TODO: implement the addition of a module role (or roles?) which can be checked to allow users to see and edit mappings and sync pages

    /**
     * Fill in what a project needs to work and nothing else.
     *
     * This runs on every configuration save, not only on enable, so most of what
     * it writes is seeded rather than set: an admin who points the module at a
     * different OnCore instance, or picks their own IRB field, meant it, and a
     * hook that argued with them would make those settings unchangeable.
     *
     * Three things are restored regardless, because without them the module does
     * not work at all rather than working differently: the helper form's fields,
     * a field setting naming a field that has been deleted, and an empty
     * authorised-user list.
     */
    public function preconfigure($project_id)
    {
        // A blank URL leaves the module unable to reach OnCore, so it is worth a
        // default. A URL the admin has changed is not ours to overwrite.
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (preg_match("/\.uky\.edu/", $host)) {
            $this->seedProjectSetting('oncore-token-url', 'https://uky-oncore-prod.forteresearchapps.com/forte-platform-web/api/oauth/token', $project_id);
            $this->seedProjectSetting('oncore-api-url', 'https://uky-oncore-prod.forteresearchapps.com/oncore-api/rest/', $project_id);
        }

        $data_dict = \REDCap::getDataDictionary($project_id, 'array');
        $current_forms = array_unique(array_column($data_dict, 'form_name'));

        // Distinguishes "this project has never had ROCS on it" from "somebody
        // deleted a field", which decides whether the optional defaults below
        // are offered again.
        $first_run = !in_array(self::HELPER_FORM, $current_forms, true);

        $restored = [];
        $repaired_dict = $this->restoreHelperFormFields($data_dict, $restored);

        if (!empty($restored)) {
            try {
                \REDCapHelper::saveDataDictionary($project_id, $repaired_dict);
                $data_dict = $repaired_dict;

                $this->log($first_run ? "Module Initialized Successfully" : "ROCS Helper Fields Restored", [
                    'project_id' => $project_id,
                    'fields' => implode(', ', $restored),
                    'executed_by' => 'system'
                ], $project_id, 'System');
            } catch (\Exception $e) {
                $this->log($first_run ? "Module Initialization Failed" : "ROCS Helper Field Restore Failed", [
                    'project_id' => $project_id,
                    'fields' => implode(', ', $restored),
                    'details' => $e->getMessage(),
                    'executed_by' => 'system'
                ], $project_id, 'System');
            }
        }

        // The two settings the sync reads to find a protocol.
        $this->repairFieldSetting('protocol-field', 'rocs_protocol_number', $data_dict, $project_id);
        $this->repairFieldSetting('irb-field', 'eirb_number', $data_dict, $project_id);

        // Display-only conveniences, offered once when the module first lands on
        // a project and never again. An admin who clears a dashboard column or a
        // sync page wants it cleared, and both fall back safely when unset.
        if ($first_run) {
            if (in_array('demographics', $current_forms, true) && in_array('regulatory', $current_forms, true)) {
                $this->seedProjectSetting('sync-page', ['demographics', 'regulatory'], $project_id);
            }
            if (isset($data_dict['full_title'])) {
                $this->seedProjectSetting('title-field', 'full_title', $project_id);
                $this->seedProjectSetting('dashboard-fields', ['full_title'], $project_id);
            }
        }

        //Auto-Assign Project Creator (Self-Healing)
        $current_users = $this->getProjectSetting('authorized-users', $project_id);
        if (!is_array($current_users))
            $current_users = $current_users ? [$current_users] : [];
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

    public function redcap_module_project_enable($project_id)
    {
        $this->preconfigure($project_id);
    }

    public function redcap_module_save_configuration($project_id)
    {
        // Audit Logging for Authorized Users
        $new_users = $this->getProjectSetting('authorized-users', $project_id);
        if (!is_array($new_users))
            $new_users = $new_users ? [$new_users] : [];
        $new_users = array_filter($new_users);

        $old_users = $this->getProjectSetting('authorized-users-cache', $project_id);
        if (!is_array($old_users))
            $old_users = $old_users ? [$old_users] : [];
        $old_users = array_filter($old_users);

        $added_users = array_diff($new_users, $old_users);
        $removed_users = array_diff($old_users, $new_users);

        if (!empty($added_users) || !empty($removed_users)) {
            $modifier = defined('USERID') ? USERID : 'System';
            $log_message = "Module Access Updated by $modifier. ";

            if (!empty($added_users))
                $log_message .= "Granted to: " . implode(", ", $added_users) . ". ";
            if (!empty($removed_users))
                $log_message .= "Revoked from: " . implode(", ", $removed_users) . ".";

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
                    'Accept' => 'application/json'
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

    private static function isDemographicsPage()
    {
        if ($_GET['page'] === 'demographics') {
            return TRUE;
        }
        return FALSE;
    }

    private static function isInstrumentPage($instrument)
    {
        if ($_GET['page'] === $instrument) {
            return TRUE;
        }
        return FALSE;
    }

    private static function isRegulatoryPage()
    {
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

    public static function getRecordStatusDashboard($pid)
    {
        return $_SERVER['REQUEST_URI'];
    }

    // Checks for which form we are on and includes instructions for mapping data to fiels on that page
    function redcap_every_page_top($project_id)
    {
        // Check authorization
        $authorized_users = $this->getProjectSetting('authorized-users');
        if (!is_array($authorized_users))
            $authorized_users = $authorized_users ? [$authorized_users] : [];

        $is_authorized = (SUPER_USER || in_array(USERID, $authorized_users));

        // REDCap serves its resources from a version-stamped directory that
        // changes on every upgrade, so the path has to be asked for rather than
        // written down. APP_PATH_IMAGES is REDCap's own answer; the fallback
        // builds it the way REDCap does, in case the constant is ever absent.
        $image_path = defined('APP_PATH_IMAGES')
            ? APP_PATH_IMAGES
            : rtrim(APP_PATH_WEBROOT, '/') . '/Resources/images/';
        $collapse_icon = $image_path . 'toggle-collapse.png';
        $expand_icon = $image_path . 'toggle-expand.png';

        if ($is_authorized):
            ?>
            <script type="text/javascript">
                $(document).ready(function () {
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
                                    <img src="<?php echo htmlspecialchars($collapse_icon, ENT_QUOTES); ?>" aria-hidden="true">
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
                        $('#rocs-custom-app-links').find('.projMenuToggle').on('click', function () {
                            var $bwrap = $(this).closest('.x-panel-header').next('.x-panel-bwrap');
                            if ($bwrap.is(':visible')) {
                                $bwrap.slideUp();
                                $(this).find('img').attr('src', <?= json_encode($expand_icon) ?>);
                            } else {
                                $bwrap.slideDown();
                                $(this).find('img').attr('src', <?= json_encode($collapse_icon) ?>);
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
        $raw_dict = \REDCap::getDataDictionary($project_id, 'array');
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
            if (!isset($data_dict[$instrument_name]))
                continue;

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

        $user_rights = \REDCap::getUserRights(USERID);
        $can_adjudicate = (SUPER_USER || ($user_rights[USERID]['data_entry'] >= 1));

        // TODO: go through and implement checks against the above variable to ensure that users without write permissions can't perform adjudications
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

                    <?php if ($can_adjudicate): ?>
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
                    <?php endif; ?>
                    });
                </script>
            <?php
        } else if (self::isSyncDashboardPage()) {
            include 'scripts/scripts.php';
            // The shared sync script uses these values when the dashboard opens an
            // adjudication comparison.  Configured record pages define the same
            // context below, but the dashboard previously omitted it.
            $mappings = $this->getProjectSetting('field-mappings') ?: [];
            if (is_string($mappings)) {
                $mappings = json_decode($mappings, true) ?: [];
            }
            ?>
            <script>
                const dictionary = <?= json_encode($data_dict) ?>;
                const mappings = <?= json_encode($mappings) ?>;
                const instruments = <?= json_encode($instruments) ?>;
            </script>
            <?php
        }
    }

    // TODO: implement a function that checks for a specific user role and only allows those users to see any of the configuration or sync options

    /**
     * Invoked by REDCap about once a minute (see "crons" in config.json). Each
     * tick works out whether a project is due rather than assuming it is: the
     * frequency setting says which days qualify, and the time setting says how
     * early in the day a run may start.
     *
     * A tick at or after the target time runs, not only one landing inside the
     * target hour, so a stalled system cron or an overrunning job makes a sync
     * late rather than skipping the day outright.
     *
     * The day is claimed in "last-cron-run" before the sync begins, because a
     * full sync easily outlives the minute it started in and the next tick must
     * not start a second copy. That setting is deliberately separate from
     * "adj-metadata": scripts/save_metadata.php writes the latter after a manual
     * sync from the dashboard, which should not cancel the day's scheduled run.
     *
     * Nothing is logged on an ordinary not-yet-due tick. At one tick a minute,
     * per project, anything logged here would bury the log it is written to.
     *
     * "cron-testing-mode" sets the once-a-day limit and the configured weekday
     * aside so a run can be triggered repeatedly while testing. It is not a way
     * to run more often on a schedule: each configured time still runs once, and
     * another run means setting another time. See the branch below for why it
     * cannot be "run whenever the clock is past the target".
     */
    public function rocsCronFullSync()
    {
        $now = new \DateTime('now', $this->reportingTimezone());
        $today = $now->format('Y-m-d');

        foreach ($this->getProjectsWithModuleEnabled() as $pid) {
            if (!$this->getProjectSetting('enable-cron', $pid)) {
                continue;
            }

            $testing = (bool) $this->getProjectSetting('cron-testing-mode', $pid);

            // Checked first: on all but one of the day's ticks this is why we
            // stop. Testing mode is the one way past it.
            if (!$testing && $this->getProjectSetting('last-cron-run', $pid) === $today) {
                continue;
            }

            $frequency = $this->getProjectSetting('cron-frequency', $pid) ?: 'weekly';

            // Testing runs off the clock alone, so the configured weekday would
            // only get in the way of trying a Saturday schedule on a Tuesday.
            if (!$testing && $frequency !== 'daily') {
                $day = $this->getProjectSetting('cron-day', $pid) ?: 'Saturday';
                if ($now->format('l') !== $day) {
                    continue;
                }
            }

            $target = $this->scheduledRunTime($now, $this->getProjectSetting('cron-time', $pid), $pid);
            if ($now < $target) {
                continue;
            }

            if ($testing) {
                // The cron ticks every minute, so "past the target" on its own
                // would start a sync a minute for the rest of the day. Keying on
                // the target itself gives one run per configured time: set a new
                // time to get another run, and setting one already past today
                // runs at the next tick.
                $targetKey = $target->format('Y-m-d H:i');
                if ($this->getProjectSetting('last-cron-test-target', $pid) === $targetKey) {
                    continue;
                }

                $this->setProjectSetting('last-cron-test-target', $targetKey, $pid);
            } else {
                // Claim the day before starting, not after.
                $this->setProjectSetting('last-cron-run', $today, $pid);
            }

            $this->log("ROCS Scheduled Sync Starting", [
                'project_id' => $pid,
                'frequency' => $frequency,
                'scheduled_for' => $target->format('Y-m-d H:i T'),
                'started_at' => $now->format('Y-m-d H:i T'),
                'triggered_by' => $testing ? 'cron (testing mode)' : 'cron'
            ]);

            $this->performFullSync($pid);
        }
    }

    /**
     * The time zone the schedule is read in. An admin who sets "11:00" means
     * 11:00 as REDCap reports it, which is not necessarily what the container's
     * PHP is configured to.
     */
    private function reportingTimezone()
    {
        $configured = $GLOBALS['timezone'] ?? null;

        if (is_string($configured) && $configured !== '') {
            try {
                return new \DateTimeZone($configured);
            } catch (\Exception $e) {
                // An unusable value falls through to PHP's own setting, which
                // REDCap sets from its configuration during initialisation.
            }
        }

        return new \DateTimeZone(date_default_timezone_get());
    }

    /**
     * Today's run time, taken from the "cron-time" setting. Accepts 2:00, 02:00
     * and 0200. Anything unreadable falls back to 02:00 and says so in the log,
     * rather than quietly running at a time nobody asked for.
     */
    private function scheduledRunTime(\DateTime $now, $time, $pid)
    {
        $hour = 2;
        $minute = 0;
        $raw = trim((string) $time);

        if ($raw !== '') {
            if (preg_match('/^(\d{1,2})\D?(\d{2})?$/', $raw, $match)) {
                $parsedHour = (int) $match[1];
                $parsedMinute = isset($match[2]) ? (int) $match[2] : 0;

                if ($parsedHour <= 23 && $parsedMinute <= 59) {
                    $hour = $parsedHour;
                    $minute = $parsedMinute;
                } else {
                    $this->log("ROCS Cron Time Out Of Range", [
                        'project_id' => $pid,
                        'cron_time' => $raw,
                        'using' => '02:00'
                    ]);
                }
            } else {
                $this->log("ROCS Cron Time Unreadable", [
                    'project_id' => $pid,
                    'cron_time' => $raw,
                    'expected' => '24-hour time such as 02:00',
                    'using' => '02:00'
                ]);
            }
        }

        $target = clone $now;
        $target->setTime($hour, $minute, 0);

        return $target;
    }

    public function fetchOncoreData($apiPath, $pid, $method = 'GET', $payload = [])
    {
        $client = new Client(['verify' => false]);
        $tokenUrl = trim($this->getProjectSetting('oncore-token-url', $pid) ?: '');
        $baseUrl = trim($this->getProjectSetting('oncore-api-url', $pid) ?: '');

        if (empty($tokenUrl) || empty($baseUrl)) {
            return ['success' => false, 'message' => "Token URL or API URL is not configured."];
        }

        $apiUrl = rtrim($baseUrl, '/') . '/' . ltrim($apiPath, '/');
        $clientId = $this->getProjectSetting('oncore-client', $pid);
        $clientSecret = $this->getProjectSetting('oncore-secret', $pid);

        try {
            $token_response = $client->post($tokenUrl, [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'form_params' => ["client_id" => $clientId, "client_secret" => $clientSecret, "grant_type" => "client_credentials"],
                'verify' => false,
                'curl' => [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false]
            ]);

            $token_data = json_decode((string) $token_response->getBody(), true);
            $access_token = $token_data['access_token'] ?? null;

            if (!$access_token) {
                return ['success' => false, 'message' => 'Failed to retrieve access token'];
            }

            $requestOptions = [
                'headers' => ['Authorization' => "Bearer $access_token", 'Accept' => 'application/json']
            ];

            if (!empty($payload) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
                $requestOptions['json'] = $payload;
            }

            $response = $client->request(strtoupper($method), $apiUrl, $requestOptions);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!$data)
                $data = [];

            return ['success' => true, 'data' => $data, 'message' => 'data successfully retrieved'];
        } catch (RequestException $e) {
            $errorBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
            return ['success' => false, 'data' => null, 'message' => $errorBody];
        } catch (\Exception $e) {
            return ['success' => false, 'data' => null, 'message' => $e->getMessage()];
        }
    }

    /**
     * Add related names to the protocol responses used by background sync.
     * ProtocolStaff has a contactId and ProtocolSponsor has a sponsorId; the
     * human-readable data is available from their own API resources.
     */
    private function enrichRelatedOncoreData(array $oncoreDataByEndpoint, $pid)
    {
        $staff = $this->asOncoreRecords($oncoreDataByEndpoint['protocolStaff'] ?? []);
        $protocolSponsors = $this->asOncoreRecords($oncoreDataByEndpoint['protocolSponsors'] ?? []);

        $contactIds = array_column($staff, 'contactId');
        $sponsorIds = array_column($protocolSponsors, 'sponsorId');
        $contactsById = $this->fetchRelatedOncoreRecords('contacts', $contactIds, $pid);
        $sponsorsById = $this->fetchRelatedOncoreRecords('sponsors', $sponsorIds, $pid);

        if (isset($oncoreDataByEndpoint['protocolStaff'])) {
            $oncoreDataByEndpoint['protocolStaff'] = $this->mapOncoreRecords(
                $oncoreDataByEndpoint['protocolStaff'],
                function ($staffMember) use ($contactsById) {
                    $contactId = $staffMember['contactId'] ?? null;
                    $contact = $contactId === null ? null : ($contactsById[(string) $contactId] ?? null);
                    if (!$contact) {
                        return $staffMember;
                    }

                    $nameParts = array_filter([
                        $contact['firstName'] ?? null,
                        $contact['middleName'] ?? null,
                        $contact['lastName'] ?? null
                    ], function ($value) {
                        return $value !== null && $value !== '';
                    });
                    if (!empty($nameParts)) {
                        $contact['displayName'] = implode(' ', $nameParts);
                    }

                    $staffMember['contact'] = $contact;
                    return $staffMember;
                }
            );
        }

        if (isset($oncoreDataByEndpoint['protocolSponsors'])) {
            $oncoreDataByEndpoint['protocolSponsors'] = $this->mapOncoreRecords(
                $oncoreDataByEndpoint['protocolSponsors'],
                function ($protocolSponsor) use ($sponsorsById) {
                    $sponsorId = $protocolSponsor['sponsorId'] ?? null;
                    $sponsor = $sponsorId === null ? null : ($sponsorsById[(string) $sponsorId] ?? null);
                    if ($sponsor) {
                        $protocolSponsor['sponsor'] = $sponsor;
                    }
                    return $protocolSponsor;
                }
            );
        }

        return $oncoreDataByEndpoint;
    }

    private function fetchRelatedOncoreRecords($endpoint, array $ids, $pid)
    {
        $recordsById = [];
        foreach (array_unique(array_filter($ids, function ($id) {
            return $id !== null && $id !== '';
        })) as $id) {
            $response = $this->fetchOncoreData($endpoint . '/' . rawurlencode((string) $id), $pid);
            if ($response['success'] ?? false) {
                $record = $response['data'] ?? null;
                if (is_array($record) && $this->isOncoreRecordList($record)) {
                    $record = $record[0] ?? null;
                }
                if (is_array($record) && !empty($record)) {
                    $recordsById[(string) $id] = $record;
                }
            }
        }

        return $recordsById;
    }

    private function asOncoreRecords($data)
    {
        if (!is_array($data) || empty($data)) {
            return [];
        }

        return $this->isOncoreRecordList($data) ? $data : [$data];
    }

    private function mapOncoreRecords($data, callable $mapper)
    {
        if (!is_array($data) || empty($data)) {
            return $data;
        }
        if ($this->isOncoreRecordList($data)) {
            return array_map(function ($record) use ($mapper) {
                return is_array($record) ? $mapper($record) : $record;
            }, $data);
        }

        return $mapper($data);
    }

    /** How long a sync's log entries are kept. */
    const SYNC_LOG_RETENTION_DAYS = 30;

    /**
     * Ceiling for the JSON report, under the 65,535 bytes a MySQL TEXT column
     * holds, with room for the rest of the entry.
     */
    const SYNC_LOG_MAX_BYTES = 60000;

    private function isOncoreRecordList(array $data)
    {
        return !empty($data) && array_keys($data) === range(0, count($data) - 1);
    }

    /**
     * Reduce a response to a single record. Several OnCore endpoints answer a
     * lookup that can only match once with a list of one, so a caller after a
     * single record has to unwrap it before reading a field off it. Mirrors
     * firstOncoreRecord() in scripts/scripts.php.
     */
    private function firstOncoreRecord($data)
    {
        if (!is_array($data)) {
            return null;
        }

        if (!$this->isOncoreRecordList($data)) {
            return $data;
        }

        foreach ($data as $record) {
            if (is_array($record) && !empty($record)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Tag every entry a single sync writes, so one run can be read back as a
     * unit: queryLogs("... WHERE sync_run = 'rocs-20260804-110002-a1b2c3'")
     * returns that run and nothing else, the way a per-run log file would.
     */
    private function syncRunId($pid)
    {
        return sprintf('rocs-%d-%s-%s', $pid, date('Ymd-His'), substr(md5(uniqid('', true)), 0, 6));
    }

    /**
     * What happened to each record this run, collected in memory and written as
     * one entry at the end rather than a row per record. Reset at the start of
     * every performFullSync.
     */
    private $syncRecords = [];

    /**
     * Note what happened to a record: what it holds is deliberately absent, so
     * record IDs, protocol numbers and counts only. The clinical values behind a
     * mismatch stay in the adjudication data.
     */
    private function recordSyncOutcome($recordId, $lookedUp, $outcome, array $detail = [])
    {
        $this->syncRecords[] = array_merge([
            'record_id' => (string) $recordId,
            'looked_up' => (string) $lookedUp,
            'outcome' => $outcome
        ], $detail);
    }

    /**
     * Write the run as a single entry. The record list is JSON in one parameter,
     * because a log parameter holds a string and the point of this shape is one
     * row per run rather than one per record.
     *
     * That parameter is a MySQL TEXT column, so a large project could otherwise
     * push the JSON past 64KB and have it truncated - or rejected - without
     * saying so. Anything close to the limit sheds its matched records first
     * (the bulk, and the least interesting), then truncates outright, and either
     * way says in the entry what it dropped.
     */
    private function logSyncReport($runId, $pid, array $summary)
    {
        $payload = ['summary' => $summary, 'records' => $this->syncRecords];
        $encoded = json_encode($payload);

        if (strlen($encoded) > self::SYNC_LOG_MAX_BYTES) {
            $interesting = array_values(array_filter($this->syncRecords, function ($record) {
                return ($record['outcome'] ?? '') !== 'matched';
            }));

            $payload['records'] = $interesting;
            $payload['matched_records_omitted'] = count($this->syncRecords) - count($interesting);
            $encoded = json_encode($payload);
        }

        while (strlen($encoded) > self::SYNC_LOG_MAX_BYTES && !empty($payload['records'])) {
            array_pop($payload['records']);
            $payload['records_truncated'] = true;
            $encoded = json_encode($payload);
        }

        $this->log("ROCS Sync Report", [
            'sync_run' => $runId,
            'project_id' => $pid,
            'records_logged' => count($payload['records']),
            'report' => $encoded
        ]);
    }

    /**
     * Drop sync entries older than the retention window. Scoped to entries
     * carrying a sync_run tag, so the sparse records of who was authorised and
     * when the module initialised are left alone.
     */
    private function pruneSyncLogs()
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::SYNC_LOG_RETENTION_DAYS . ' days'));

        try {
            $this->removeLogs("sync_run IS NOT NULL AND timestamp < ?", [$cutoff]);
        } catch (\Throwable $e) {
            // Never let housekeeping stop a sync.
            $this->log("ROCS Sync Log Prune Failed", [
                'cutoff' => $cutoff,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function performFullSync($pid)
    {
        $runId = $this->syncRunId($pid);
        $this->syncRecords = [];
        $startedAt = date('Y-m-d H:i:s');

        try {
            $this->pruneSyncLogs();
            $this->setProjectSetting('running', true, $pid);

            $irb_field = $this->getProjectSetting('irb-field', $pid) ?: 'eirb_number';
            $protocol_field = $this->getProjectSetting('protocol-field', $pid) ?: 'rocs_protocol_number';
            $title_field = $this->getProjectSetting('title-field', $pid) ?: 'full_title';
            $raw_dashboard_fields = $this->getProjectSetting('dashboard-fields', $pid);

            // Ensure it is an array
            if (!is_array($raw_dashboard_fields)) {
                $raw_dashboard_fields = $raw_dashboard_fields ? [$raw_dashboard_fields] : [];
            }

            // Filter out any empty strings or nulls saved by the UI
            $dashboard_fields = array_filter($raw_dashboard_fields);
            if (!in_array($title_field, $dashboard_fields, true)) {
                $dashboard_fields[] = $title_field;
            }
            
            $filter = "([$irb_field] <> '' OR [$protocol_field] <> '') AND [rocs_sync(1)] <> '1'";

            $records = \REDCap::getData([
                'project_id' => $pid,
                'return_format' => 'array',
                'filterLogic' => $filter,
            ]);

            $mappings = $this->getProjectSetting('field-mappings', $pid) ?: [];
            if (is_string($mappings))
                $mappings = json_decode($mappings, true) ?: [];

            $toSave = [];
            $matchedCount = 0;
            $checkedCount = 0;

            foreach ($records as $record_id => $event_data) {
                $checkedCount++;
                $event_id = array_key_first($event_data);
                $record = $event_data[$event_id];

                $eirb = $record[$irb_field] ?? null;
                $protocol_number = $record[$protocol_field] ?? null;
                $title = $record[$title_field] ?? '';
                
                $custom_fields = [];
                foreach ($dashboard_fields as $df) {
                    $custom_fields[$df] = $record[$df] ?? '';
                }

                // The filter above should have excluded these; logged rather
                // than dropped silently so a run's entries account for every
                // record it counted as checked.
                if (!$eirb && !$protocol_number) {
                    $this->recordSyncOutcome($record_id, '', 'skipped', [
                        'reason' => 'No IRB or protocol number on the record'
                    ]);
                    continue;
                }

                if (!empty($protocol_number)) {
                    $details = $this->fetchOncoreData('protocolManagementDetails?protocolNo=' . urlencode($protocol_number), $pid);
                } else {
                    $details = $this->fetchOncoreData('protocolManagementDetails?irbNo=' . urlencode($eirb), $pid);
                }

                // A lookup that can only match one protocol still answers with a
                // list of one, so unwrap before reading the ID off it.
                $protocolDetails = $details['success'] ? $this->firstOncoreRecord($details['data']) : null;

                // An unreachable or unauthorised API is not the same thing as a
                // protocol OnCore does not hold, and must not read as one.
                if (!$details['success']) {
                    $this->recordSyncOutcome($record_id, $protocol_number ?: $eirb, 'oncore error', [
                        'error' => $details['message'] ?? 'Unknown error'
                    ]);

                    $toSave[] = [
                        'record_id' => (string) $record_id,
                        'eirb_number' => $eirb ?: $protocol_number,
                        'title' => $title,
                        'custom_fields' => $custom_fields,
                        'status' => 'oncore error',
                        'message' => 'OnCore could not be reached: ' . ($details['message'] ?? 'Unknown error')
                    ];
                    continue;
                }

                // Anything else worth noting about this record, carried onto its
                // entry in the report rather than logged on its own.
                $recordNotes = [];

                if (isset($protocolDetails['protocolId'])) {
                    $protocolId = $protocolDetails['protocolId'];
                    $fetchedProtocolNo = $protocolDetails['protocolNo'] ?? '';

                    // IF protocol_number is empty, and we got a protocolNo from OnCore, auto-save it to REDCap!
                    if (empty($protocol_number) && !empty($fetchedProtocolNo)) {
                        $saveData = [
                            [
                                \REDCap::getRecordIdField($pid) => $record_id,
                                $protocol_field => $fetchedProtocolNo
                            ]
                        ];
                        $response = \REDCap::saveData($pid, 'json', json_encode($saveData));
                        if (empty($response['errors'])) {
                            $protocol_number = $fetchedProtocolNo;
                            $recordNotes['protocol_number_saved'] = $fetchedProtocolNo;
                        } else {
                            $recordNotes['protocol_save_errors'] = $response['errors'];
                        }
                    }
                } else {
                    $this->recordSyncOutcome($record_id, $protocol_number ?: $eirb, 'not in OnCore');

                    $toSave[] = [
                        'record_id' => (string) $record_id,
                        'eirb_number' => $eirb ?: $protocol_number,
                        'title' => $title,
                        'custom_fields' => $custom_fields,
                        'status' => 'not in OnCore',
                        'message' => 'The Protocol/IRB was not found in OnCore.'
                    ];
                    continue;
                }

                $endpoints = [
                    'protocols',
                    'protocolConsents',
                    'protocolSponsors',
                    'protocolStaff',
                    'protocolEprmsSubmissions',
                    'protocolPrmcReviews',
                    'protocolIde',
                    'protocolInd',
                    'protocolIrbReviews',
                    'protocolInstitutions'
                ];

                // "protocols" addresses a single record by path segment, the way
                // contacts and sponsors do; the rest filter a collection by
                // query parameter. oncore_proxy.php draws the same distinction
                // for the browser in $pathEndpoints.
                $pathEndpoints = ['protocols'];

                $oncoreDataByEndpoint = [];
                $results = [];
                $endpointErrors = [];
                foreach ($endpoints as $protocol) {
                    $apiPath = in_array($protocol, $pathEndpoints, true)
                        ? $protocol . '/' . rawurlencode((string) $protocolId)
                        : $protocol . '?protocolId=' . urlencode((string) $protocolId);

                    $res = $this->fetchOncoreData($apiPath, $pid);

                    // Carried on the record's own entry rather than logged
                    // separately, so one record stays one line of the report.
                    if (!($res['success'] ?? false)) {
                        $endpointErrors[$protocol] = $res['message'] ?? 'Unknown error';
                    }

                    $oncoreDataByEndpoint[$protocol] = $res['data'] ?? [];
                    $results[] = ['protocol' => $protocol, 'response' => $res];
                }
                $oncoreDataByEndpoint = $this->enrichRelatedOncoreData($oncoreDataByEndpoint, $pid);

                $experimental = [];
                $totalMappedFields = 0;
                $matchedFields = 0;

                foreach ($mappings as $form => $fields) {
                    $form_data = [];
                    foreach ($fields as $redcapField => $mappingObj) {
                        $includeUnmapped = $mappingObj['include_unmapped'] ?? false;
                        $oncoreFieldName = $mappingObj['mapping'] ?? null;
                        $endpointOrigin = $mappingObj['protocol'] ?? null;

                        if (!$oncoreFieldName)
                            continue;
                        $totalMappedFields++;

                        $dict = $oncoreDataByEndpoint[$endpointOrigin] ?? [];
                        $redcapValue = $record[$redcapField] ?? '';

                        // The mapping reads the whole list; the individual
                        // entries ride along so the adjudication view can offer
                        // them one at a time.
                        $oncoreEntries = OnCoreFieldPath::entries($dict, $oncoreFieldName);
                        $oncoreValue = implode('; ', array_column($oncoreEntries, 'value'));

                        $isUnmapped = false;
                        $redcapSelected = false;
                        $oncoreSelected = false;

                        if ($includeUnmapped && OnCoreFieldPath::hasValue($oncoreValue)) {
                            $isUnmapped = true;
                        } else if ($includeUnmapped && !OnCoreFieldPath::hasValue($oncoreValue)) {
                            $oncoreValue = '';
                            $isUnmapped = true;
                        } else if (empty($redcapValue) && OnCoreFieldPath::hasValue($oncoreValue)) {
                            $oncoreSelected = true;
                        } else if ($redcapValue == $oncoreValue) {
                            $matchedFields++;
                        } else {
                            $redcapSelected = true;
                        }

                        $oncore = ['value' => $oncoreValue, 'selected' => $oncoreSelected];

                        // Only worth carrying when there is a choice to make.
                        if ($oncoreValue !== '' && count($oncoreEntries) > 1) {
                            $oncore['options'] = $oncoreEntries;
                        }

                        $form_data[] = [
                            'field_name' => $redcapField,
                            'redcap' => ['value' => $redcapValue, 'selected' => $redcapSelected],
                            'oncore' => $oncore,
                            'unmapped' => $isUnmapped
                        ];
                    }
                    $experimental[$form] = $form_data;
                }

                // Counts only - which fields differed is in the adjudication
                // data, where the values belong.
                $recordDetail = array_merge([
                    'protocol_id' => (string) $protocolId,
                    'fields_compared' => $totalMappedFields,
                    'fields_differing' => $totalMappedFields - $matchedFields
                ], $recordNotes);

                if (!empty($endpointErrors)) {
                    $recordDetail['endpoint_errors'] = $endpointErrors;
                }

                if ($totalMappedFields > 0 && $matchedFields == $totalMappedFields) {
                    $matchedCount++;
                    $this->recordSyncOutcome($record_id, $protocol_number ?: $eirb, 'matched', $recordDetail);
                } else {
                    $this->recordSyncOutcome($record_id, $protocol_number ?: $eirb, 'needs attention', $recordDetail);

                    $toSave[] = [
                        'record_id' => (string) $record_id,
                        'eirb_number' => $eirb ?: $protocol_number,
                        'title' => $title,
                        'custom_fields' => $custom_fields,
                        'results' => $results,
                        'status' => 'needs attention',
                        'message' => 'OnCore data does not match data in REDCap.',
                        'comparisons' => $experimental
                    ];
                }
            }

            $date = date('m/d/Y');
            $time = date('H:i');
            $metadata = [
                'date' => $date,
                'time' => $time,
                'checked' => $checkedCount,
                'matched' => $matchedCount
            ];

            // Save settings as native arrays (REMOVE json_encode here)
            $this->setProjectSetting('adj-metadata', $metadata, $pid);
            $this->setProjectSetting('to-adjudicate', $toSave, $pid);
            $this->setProjectSetting('running', false, $pid);

            $adjudicated_count = count($toSave);

            // One entry for the whole run: counts, plus what happened to each
            // record, as a single JSON object.
            $this->logSyncReport($runId, $pid, [
                'outcome' => 'completed',
                'executed_by' => 'System',
                'started_at' => $startedAt,
                'finished_at' => date('Y-m-d H:i:s'),
                'checked' => $checkedCount,
                'matched' => $matchedCount,
                'adjudicated_records' => $adjudicated_count
            ]);

            // Emailing Feature
            if ($adjudicated_count > 0) {
                $emails = $this->getProjectSetting('adjudicate_email', $pid);
                if (!empty($emails)) {
                    if (!is_array($emails)) {
                        $emails = [$emails];
                    }

                    $valid_emails = array_filter($emails, function ($email) {
                        return !empty(trim($email));
                    });

                    if (!empty($valid_emails)) {
                        $to = implode(', ', $valid_emails);

                        // Use a generic no-reply from address, or REDCap system email if accessible, resorting to default placeholder
                        $from = 'no-reply@uky.edu';

                        $subject = "REDCap OnCore Sync (ROCS) - Action Required";
                        $message = "The ROCS Background Full Sync has completed for Project ID $pid. <br><br>";
                        $message .= "There are currently <b>$adjudicated_count</b> records requiring adjudication.<br>";
                        $message .= "Please log in to REDCap and review the Sync Dashboard to resolve these discrepancies.";

                        \REDCap::email($to, $from, $subject, $message);

                        $this->log("Adjudication Notification Email Sent", [
                            'sync_run' => $runId,
                            'project_id' => $pid,
                            'recipients' => $to
                        ]);
                    }
                }
            }

        } catch (\Throwable $e) {
            $this->setProjectSetting('running', false, $pid);

            // Still write the report. A run that died part way through is
            // exactly when knowing which records it reached is worth having.
            $this->logSyncReport($runId, $pid, [
                'outcome' => 'errored',
                'executed_by' => 'System',
                'started_at' => $startedAt,
                'finished_at' => date('Y-m-d H:i:s'),
                'records_reached' => count($this->syncRecords),
                'error' => $e->getMessage(),
                'thrown_at' => $e->getFile() . ':' . $e->getLine()
            ]);
        }
    }
}
