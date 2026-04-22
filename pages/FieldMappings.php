<?php
/** @var \ExternalModules\AbstractExternalModule $module */
$page = "field-mapping";
$instruments = REDCap::getInstrumentNames();
$csrf = $module->getCSRFToken();

// Will use this value later for batching requests if max_input is hit
$maxInputVars = ini_get('max_input_vars') ?: 1000;

$protocol = $module->getProjectSetting('sample-protocol');
$eirb = $module->getProjectSetting('sample-eirb');
?>

<link rel="stylesheet" href="<?= $module->getUrl('css/field_mappings.css') ?>">

<script>
    // Get max_input from php.ini into a js workable variable
    const MAX_INPUT_VARS = <?= (int)$maxInputVars ?>;
    let displayed = []; // tracks displayed instruments
    let oncore_fields = null;
    const toSave = {}; // this stores adjudicates and saves them to config

    const protocolNo = <?= json_encode($protocol[0]) ?>;
    const eIRBno = <?= json_encode($eirb[0]) ?>;

    if (!protocolNo) {
        alert("CONFIG ERROR: Please visit external module settings and provide a sample Protocol Number.")
    }

    if (!eIRBno) {
        alert("CONFIG ERROR: Please visit external module settings and provide a sample eIRB Number.")
    }

    let mappings = false;
    const contactId = ''; // not sure where I might find this

    // Inject PHP constants into JavaScript
    const REDCAP_WEBROOT = <?= json_encode(APP_PATH_WEBROOT) ?>;
    const PROJECT_ID = <?= json_encode($_GET['pid']) ?>;
</script>

<script>
    // Function gets called to build the tables which showcase the forms
    function buildTables(keys) {
        const inst_list = document.getElementById('instruments_list');

        Object.entries(instruments).forEach(([key, value]) => {
            let table = document.createElement('table');
            table.id = key + '_fields';
            table.classList = "dataTable cell-border no-footer";
            inst_list.appendChild(table);

            let header = document.createElement('thead');
            header.innerHTML =
                `<tr>
                    <th>${value} Fields</th>
                    <th>OnCore Field</th>
                </tr>`;
            table.appendChild(header);

            let tbody = document.createElement('tbody');
            table.appendChild(tbody);

            let i = 1;
            Object.entries(dictionary[key]).forEach(([key2, value2]) => {
                let row = document.createElement('tr');
                row.classList = i % 2 !== 0 ? 'odd' : 'even';

                row.innerHTML =
                    `<td style='width: 50% !important;'>
                        <label for='${key2}'>${key2}</label>
                     </td>
                     <td style='width: 50% !important;'>
                         <select name='${key2}' id='${key2}'>
                            <option value="">None</option>
                         </select>
                     </td>`;

                // populate select with keys
                const select = row.querySelector(`#${key2}`);
                keys.forEach(k => {
                    const option = document.createElement("option");
                    option.value = k;
                    option.textContent = k;
                    select.appendChild(option);
                });

                table.appendChild(row);
                i++;
            });
        });
    }
</script>

<div class="d-flex container" style="flex-direction: column;">
    <!-- Manage and sync buttons -->
    <div class="row selection-btns">
        <div class="col-md-3">
            <a id="manage-forms-btn">
                <div class="center-home-sects">
                    <span><i class="fa fa-plus"></i></span><br>
                    <h5>Manage Forms</h5>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a id="sync-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-arrows-rotate"></i></span><br>
                    <h5>Sync with OnCore</h5>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a id="upload-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-upload"></i></span><br>
                    <h5>Upload Config</h5>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a id="export-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-file-export"></i></span><br>
                    <h5>Export Config</h5>
                </div>
            </a>
        </div>
    </div>
    
    <!-- List of forms and their fields -->
    <div id="instruments_list" class="row" style="flex-direction: column;">
        <div id="forms-loader" class="loader-container">
            <div class="loader"></div>
            <p style="margin-top: 15px;">Loading Saved Mappings...</p>
        </div>
    </div>

    <!-- Manage and sync buttons -->
    <div class="row selection-btns">
        <div class="col-md-3">
            <a id="manage-forms-btn">
                <div class="center-home-sects">
                    <span><i class="fa fa-plus"></i></span><br>
                    <h5>Manage Forms</h5>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a id="sync-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-arrows-rotate"></i></span><br>
                    <h5>Sync with OnCore</h5>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a id="upload-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-upload"></i></span><br>
                    <h5>Upload Config</h5>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a id="export-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-file-export"></i></span><br>
                    <h5>Export Config</h5>
                </div>
            </a>
        </div>
    </div>
</div>

<script>
    /* Create the basic modal structure */
    function buildModal() {
        if (document.getElementById('modal-overlay')) {
            return; // Modal already exists
        }

        const modalOverlay = document.createElement('div');
        modalOverlay.className = 'modal-overlay';
        modalOverlay.id = 'comparison-modal';

        const modalBox = document.createElement('div');
        modalBox.className = 'modal-box';

        return { modalOverlay, modalBox };
    }

    // Creates a modal that lets you select which forms you want to sync and ignore the ones you don't want
    function manageForms(instruments, displayed) {
        const built = buildModal();
        if (!built) return; // The modal already exists

        const { modalOverlay, modalBox } = built;

        // Modal header + form list
        let modalContent = `
            <h2>Manage Forms</h2>
            <p>Select which REDCap forms you want to synchronize.</p>
            <div class="modal-form-selection-grid" style="height: 80vh; overflow-y: auto;">
            <table class="dataTable cell-border no-footer">
        `;

        let i=0;
        Object.entries(instruments).forEach(([key, value]) => {
            const isDisplayed = displayed.includes(key);
            modalContent += `
            <tr class="${i % 2 !== 0 ? 'odd' : 'even'}">
                <td style="width: 100% !important;">
                    <label class="checkbox-option">
                        <input type="checkbox" name="form-select" value="${key}" ${isDisplayed ? "checked" : ""}>
                        ${value}
                    </label>
                </td>
            </tr>
            `;
            i = i + 1; // increment even/odd
        });

        modalContent += `
            </table>
            </div>
            <div class="modal-actions">
                <button id="confirm_btn" class="selectA_btn">Save Changes</button>
                <button id="close_btn" class="close-button">Cancel</button>
            </div>
        `;

        modalBox.innerHTML = modalContent;
        modalOverlay.appendChild(modalBox);
        document.body.appendChild(modalOverlay);

        const closeModal = () => {
            if (modalOverlay && modalOverlay.parentNode) {
                modalOverlay.parentNode.removeChild(modalOverlay);
            }
        };

        // Confirm button logic
        document.getElementById('confirm_btn').addEventListener('click', async () => {
            const selected = Array.from(document.querySelectorAll('input[name="form-select"]:checked'))
                .map(cb => cb.value);

            const toAdd = selected.filter(f => !displayed.includes(f));
            const toRemove = displayed.filter(f => !selected.includes(f));

            // Handle removals with warnings for forms that have mapped data
            for (const formKey of toRemove) {
                const table = document.getElementById(formKey);

                if (table) {
                    const selects = Array.from(table.querySelectorAll('select'));
                    const hasConfiguredData = selects.some(sel => sel.value && sel.value !== "");

                    if (hasConfiguredData) {
                        const confirmRemoval = confirm(
                            `Warning: The form "${instruments[formKey]}" has mapped data. Are you sure you want to remove it?`
                        );
                        if (!confirmRemoval) {
                            continue; // User said no — skip removal entirely
                        }
                    }

                    // Remove from DOM whether or not it had data (user confirmed if needed)
                    table.remove();
                }

                // Remove from displayed regardless of whether table existed in DOM
                const index = displayed.indexOf(formKey);
                if (index !== -1) displayed.splice(index, 1);
            }

            // Handle additions
            toAdd.forEach(selectedForm => {
                displayed.push(selectedForm);
                const table = document.createElement('table');
                table.id = selectedForm;
                table.classList = "dataTable cell-border no-footer";

                const header = document.createElement('thead');
                header.innerHTML = `
            <tr>
                <th style="width: 25%;">${instruments[selectedForm]} Fields</th>
                <th style="width: 35%;">REDCap Field Label</th>
                <th style="width: 25%;">OnCore Field</th>
                <th style="width: 15%;">Include, But Don't Adjudicate</th>
            </tr>`;
                table.appendChild(header);

                const tbody = document.createElement('tbody');
                table.appendChild(tbody);

                let i = 0;
                Object.keys(dictionary[selectedForm]).forEach(redcapField => {
                    const row = document.createElement('tr');
                    row.className = i % 2 === 0 ? 'even' : 'odd';

                    const selectId = `${selectedForm}_${redcapField}`;
                    row.innerHTML = `
                <td style="width:50%"><label for="${selectId}">${redcapField}</label></td>
                <td>
                    ${dictionary[selectedForm][redcapField].field_label.length > 50
                        ? dictionary[selectedForm][redcapField].field_label.slice(0, 50) + '…'
                        : dictionary[selectedForm][redcapField].field_label}
                </td>
                <td style="width:50%">
                    <select name="${redcapField}" id="${selectId}">
                        <option value="">None</option>
                    </select>
                </td>
                <td style="width:15%; text-align:center;">
                    <input type="checkbox" class="include-unmapped" data-field="${redcapField}">
                </td>
            `;

                    const selectEl = row.querySelector('select');
                    if (Array.isArray(oncore_fields)) {
                        oncore_fields.forEach(field => {
                            const option = document.createElement('option');
                            option.value = field;
                            option.textContent = field;
                            selectEl.appendChild(option);
                        });
                    }

                    tbody.appendChild(row);
                    i++;
                });

                document.getElementById('instruments_list').appendChild(table);
            });

            closeModal(true);
            checkpoint();
        });

        // Close modal on Cancel or background click
        document.getElementById('close_btn').addEventListener('click', (event) => {
            closeModal(false); // close modal but no checkpoint   
        });
        modalOverlay.addEventListener('click', (event) => {
            if (event.target === modalOverlay) {
                closeModal(false); // close modal but no checkpoint   
            }
        });
    }

    function resolveMappingModal(type, mapping, formName, offenderField, availableOptions) {
        const built = buildModal();
        if (!built) return;

        const { modalOverlay, modalBox } = built;

        // Set dynamic text based on the type
        const isForm = type === 'form';
        const targetName = isForm ? formName : offenderField;
        const parentText = isForm ? "this REDCap project" : `the form <strong>${formName}</strong>`;

        // Build Dropdown
        let dropdownOptions = `<option value="">-- Select a replacement ${type} --</option>`;
        Object.entries(availableOptions).forEach(([key, value]) => {
            let label = typeof value === 'object' && value.field_label ?
                value.field_label.replace(/(<([^>]+)>)/gi, "").substring(0, 50) + "..." : value;
            dropdownOptions += `<option value="${key}">${key} (${label})</option>`;
        });

        // Modal UI
        modalBox.innerHTML = `
        <div style="padding: 10px;">
            <h3 style="color: #d9534f; margin-top: 0;">Missing ${isForm ? 'Form' : 'Field'} Detected: <strong>${targetName}</strong></h3>
            <p>ROCS detected that a previously mapped ${type} (<strong>${targetName}</strong>) no longer exists in ${parentText}.</p>

            <div style="margin: 20px 0; padding: 15px; border: 1px solid #ccc; background-color: #f9f9f9; border-radius: 5px;">
                <label style="font-weight: bold; display: block; margin-bottom: 8px;">Option 1: Map to a different ${type}</label>
                <select id="replacement-select" style="width: 100%; padding: 6px;">
                    ${dropdownOptions}
                </select>
            </div>

            <div style="margin-bottom: 20px;">
                <p style="margin-bottom: 5px;"><strong>Option 2: Delete this ${type} mapping</strong></p>
            </div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                <button id="update_btn" class="selectA_btn" style="padding: 6px 12px; cursor: pointer;">Update Mapping</button>
                <button id="delete_btn" style="background-color: #d9534f; color: white; border: 1px solid #d43f3a; padding: 6px 12px; border-radius: 4px; cursor: pointer;">Delete Mapping</button>
                <button id="close_btn" class="close-button" style="padding: 6px 12px; cursor: pointer;">Cancel</button>
            </div>
        </div>
    `;

        modalOverlay.appendChild(modalBox);
        document.body.appendChild(modalOverlay);

        const closeModal = () => {
            if (modalOverlay && modalOverlay.parentNode) modalOverlay.parentNode.removeChild(modalOverlay);
        };


        // Selection for Update
        document.getElementById('update_btn').addEventListener('click', () => {
            const selected = document.getElementById('replacement-select').value;
            if (!selected) return alert(`Please select a replacement ${type}.`);

            if (isForm) {
                mapping[selected] = mapping[formName];
                delete mapping[formName];
            } else {
                mapping[formName][selected] = mapping[formName][offenderField];
                delete mapping[formName][offenderField];
            }

            saveMappingAjax(mapping);
        });

        // Selection for Deletion
        document.getElementById('delete_btn').addEventListener('click', () => {
            if (confirm(`Are you sure you want to delete the mapping for ${targetName}?`)) {
                if (isForm) {
                    delete mapping[formName];
                } else {
                    delete mapping[formName][offenderField];
                }
                mapping = sortMappings(mapping, instruments)
                saveMappingAjax(mapping);
            }
        });

        document.getElementById('close_btn').addEventListener('click', closeModal);


        // Save mappings
        function saveMappingAjax(updatedMapping) {
            $.ajax({
                url: "<?= $module->getUrl('scripts/save_mappings.php') ?>",
                method: "POST",
                data: {
                    pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                    redcap_csrf_token: <?= json_encode($csrf) ?>,
                    "mappings": JSON.stringify(updatedMapping),
                    displayed: JSON.stringify(Object.keys(updatedMapping))
                },
                success: () => window.location.reload(),
                error: (xhr, status, error) => console.error("Error:", error)
            });
            closeModal();
        }
    }

    function rebuild_from_mappings(instruments, dictionary, existingMappings) {
        const container = document.getElementById('instruments_list');
        if (!container) return;

        // This checks the saved mappings to ensure they match up with available forms and fields in the project
        validity_check(existingMappings, dictionary, instruments);

        // Create a fragment to build the tables in memory
        const fragment = document.createDocumentFragment();

        // Only build tables for instruments in the displayed array
        displayed.forEach(instrumentKey => {
            const instrumentLabel = instruments[instrumentKey];
            if (!instrumentLabel) return; // Skip if instrument doesn't exist
            
            const mappedFields = existingMappings[instrumentKey] || {};
            const dictFields = dictionary[instrumentKey];

            if (!dictFields) return; // skip if no dictionary

            // Create table
            const table = document.createElement('table');
            table.id = instrumentKey;
            table.className = "dataTable cell-border no-footer";
            
            const header = document.createElement('thead');
            header.innerHTML = `<tr>
                                    <th style="width: 25%;">${instrumentLabel} Fields</th>
                                    <th style="width: 35%;">REDCap Field Label</th>
                                    <th style="width: 25%;">OnCore Field</th>
                                    <th style="width: 15%;">Include, But Don't Adjudicate</th>
                                 </tr>`;
            table.appendChild(header);

            const tbody = document.createElement('tbody');
            table.appendChild(tbody);

            let i = 0;
            Object.keys(dictFields).forEach(redcapField => {
                const row = document.createElement('tr');
                row.className = i % 2 === 0 ? 'even' : 'odd';

                const selectId = `${instrumentKey}_${redcapField}`;
                row.innerHTML = `
                    <td style="width:45%">
                        <label for="${selectId}">${redcapField}</label>
                    </td>
                        <td>
                            ${
                                dictionary[instrumentKey][redcapField].field_label.length > 50
                                    ? dictionary[instrumentKey][redcapField].field_label.slice(0, 50) + '…'
                                    : dictionary[instrumentKey][redcapField].field_label
                            }
                        </td>
                    <td style="width:40%">
                        <select name="${redcapField}" id="${selectId}">
                            <option value="">None</option>
                        </select>
                    </td>
                    <td style="width:15%; text-align:center;">
                        <input type="checkbox"
                               class="include-unmapped"
                               data-field="${redcapField}">
                    </td>
                `;

                tbody.appendChild(row);

                // Populate select with oncore_fields
                const selectEl = row.querySelector('select');
                if (Array.isArray(oncore_fields)) {
                    oncore_fields.forEach(field => {
                        const option = document.createElement('option');
                        option.value = field;
                        option.textContent = field;

                        // mark selected if it matches the saved mapping
                        if (mappedFields[redcapField].mapping === field) option.selected = true;

                        selectEl.appendChild(option);
                    });
                }
                const checkbox = row.querySelector('.include-unmapped');
                if (checkbox) {
                    checkbox.checked = mappedFields[redcapField].include_unmapped;
                }

                fragment.appendChild(table);
                i++;
            });


        });

        // Clear the container (removes the loader) and append all tables
        container.innerHTML = '';
        container.appendChild(fragment);
    }

    function checkpoint() {
        console.log('checkpoint')
        const mapping = {};

        document.querySelectorAll('#instruments_list > table').forEach(table => {
            const instrument = table.id;
            if (!instrument) return;

            const instrumentMapping = {};

            table.querySelectorAll('select').forEach(select => {

                const redcapField = select.name;
                const selectedValue = select.value;

                const row = select.closest('tr');
                const checkbox = row?.querySelector('.include-unmapped');

                const includeUnmapped = checkbox
                    ? checkbox.checked
                    : false;

                console.log(
                    instrument,
                    redcapField,
                    selectedValue,
                    includeUnmapped
                );

                instrumentMapping[redcapField] = {
                    mapping: selectedValue,
                    include_unmapped: includeUnmapped
                };
            });

            mapping[instrument] = instrumentMapping;
        });

        mappings = mapping;
        mappings = sortMappings(mappings, instruments);

        // Send to server - include the displayed array
        $.ajax({
            url: "<?= $module->getUrl('scripts/save_mappings.php') ?>",
            method: "POST",
            data: {
                pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                redcap_csrf_token: <?= json_encode($csrf) ?>,
                "mappings": JSON.stringify(mappings),
                displayed: JSON.stringify(displayed)  // <-- Save which forms should be displayed
            },
            success: function (result) {
                console.log("Checkpoint saved:", result);
            },
            error: function (xhr, status, error) {
                console.error("Error in checkpoint:", error, xhr.responseText);
            }
        });
    }

    function load_checkpoint() {
        $.ajax({
            url: "<?= $module->getUrl('scripts/load_mappings.php') ?>",
            method: "GET",
            dataType: "json",
            success: function (result) {
                console.log('load checkpoint result', result);
                if (result.status !== 'success') {
                    console.error('Failed to load mappings:', result.error || 'Unknown error');
                    const loader = document.getElementById('forms-loader');
                    if (loader) {
                        loader.innerHTML = '<p style="color: red; text-align: center;"><b>Error:</b> Could not load saved field mappings.</p>';
                    }
                    return;
                }

                if (result.data.length === 0) {
                    let list = document.getElementById('instruments_list');
                    list.innerHTML = `<h2>You currently have not selected any Forms for mapping.</h2>
                                      <h2>Select the "Manage Forms" option above to begin mapping your first Form.</h2>`;
                }
                else if (mappings.length === 0) {
                    let list = document.getElementById('instruments_list');
                    list.innerHTML = `<h2>You currently have not selected any Forms for mapping.</h2>
                                      <h2>Select the "Manage Forms" option above to begin mapping your first Form.</h2>`;
                }
                else {
                    mappings = result.data || {};
                    mappings = sortMappings(mappings, instruments);
                    displayed = Object.keys(mappings);
/*
                    const savedDisplayed = result.displayed || []; // <-- Load the saved displayed list

                    displayed.length = 0;
                    // Use the saved displayed list instead of mapping keys
                    savedDisplayed.forEach(instrumentKey => {
                        if (instruments[instrumentKey]) {
                            displayed.push(instrumentKey);
                        }
                    });*/

                    rebuild_from_mappings(instruments, dictionary, mappings);
                }
            },
            error: function (xhr, status, error) {
                console.error('Error loading saved mappings:', error, xhr.responseText);
                const loader = document.getElementById('forms-loader');
                if (loader) {
                    loader.innerHTML = '<p style="color: red; text-align: center;"><b>Error:</b> Could not load saved field mappings.</p>';
                }
            }
        });
    }

    function populateDropdowns(existingMappings = {}) {
        // Loop through all tables (one per instrument)
        document.querySelectorAll("#instruments_list table").forEach(table => {
            const instrument = table.id; // e.g., 'demographics'

            // Skip if no table ID
            if (!instrument) return;

            // Loop through all selects inside this table
            table.querySelectorAll("select").forEach(select => {
                const redcapField = select.name;

                // Clear existing options
                select.innerHTML = '';

                // Add "None" option first
                const noneOption = document.createElement('option');
                noneOption.value = '';
                noneOption.textContent = 'None';
                select.appendChild(noneOption);

                // Determine the pre-selected value (from saved mappings)
                const preSelected = (existingMappings[instrument] || {})[redcapField] || '';
                // If preSelected is empty, "None" remains selected automatically
            });
        });
    }

    // Will eventually handle overwriting data
    function handleRecordSelection(selectedRecord) {
        console.log("The user selected this record:", selectedRecord);
        alert(`You chose the record with id: ${selectedRecord.record_id}`);
    }

    // Sorts a mapping prior to save so that it is displayed in the same order as they appear in REDCap.
    function sortMappings(mappings, instruments) {
        const sortedMappings = {};

        // Iterate through the instruments to enforce the correct order
        for (const instrumentKey of Object.keys(instruments)) {
            // If a mapping exists for this instrument, add it to our new object
            if (mappings.hasOwnProperty(instrumentKey)) {
                sortedMappings[instrumentKey] = mappings[instrumentKey];
            }
        }

        return sortedMappings;
    }

    function getAllFromREDCap() {
        console.log(displayed);
        $.ajax({
            url: '<?= $module->getUrl("scripts/get_all_records.php") ?>',
            data: {
                forms: displayed
            },
            success: function (data) {
                // TODO: finish this looping to save records, then figure out how to run it in the background
                console.log(data);
                data.forEach(record => {
                    console.log(record);
                    getFromOnCoreWithIRBNo(record);
                });
                console.log(toSave);
            },
            error: function (xhr, status, error) {
                console.error('Error fetching REDCap record:', error, xhr.responseText);
            }
        });
    }

    function getAllKeys(obj, prefix = '') {
        let keys = [];

        for (const key in obj) {
            const value = obj[key];
            const fullKey = prefix ? `${prefix}.${key}` : key;

            keys.push(fullKey);

            if (value && typeof value === "object") {
                if (Array.isArray(value)) {
                    value.forEach((item, i) => {
                        if (typeof item === "object") {
                            keys = keys.concat(getAllKeys(item, `${fullKey}[${i}]`));
                        }
                    });
                } else {
                    keys = keys.concat(getAllKeys(value, fullKey));
                }
            }
        }

        return keys;
    }

    // Load the saved checkpoint when the page is initialized
    document.addEventListener('DOMContentLoaded', async () => {
        try {
            // First request for protocolId
            const protocol = await safeFetchOncore('protocols', `&protocolNo=${protocolNo}`);
            const protocolId = protocol.data?.protocolId;

            const api = {
                protocolSponsors: `&protocolId=${protocolId}`,
                protocolStaff: `&protocolId=${protocolId}`,
                protocolManagementDetails: `&protocolId=${protocolId}`,
                protocolPrmcReviews: `&protocolId=${protocolId}`,
                protocolTasks: `&protocolId=${protocolId}`,
                protocolInd: `&protocolId=${protocolId}`,
                contactCredentials: `&contactId=${contactId}`
            };

            // Run all remaining calls in parallel safely
            const requests = Object.entries(api).map(([endpoint, query]) =>
                safeFetchOncore(endpoint, query)
            );

            const results = await Promise.all(requests);

            // Combine successful results only
            const allResponses = [protocol, ...results]
                .filter(r => r.success)
                .map(r => r.data);

            oncore_fields = [
                ...new Set(allResponses.flatMap(obj => getAllKeys(obj)))
            ];

            // get values in alphabetical
            oncore_fields.sort();

            console.log("All OnCore fields (successful only):", oncore_fields);

            load_checkpoint();

        } catch (err) {
            console.error("Unexpected OnCore loading failure:", err);
        }


        document.getElementById('sync-btn').addEventListener('click', () => {
            //getOneFromREDCap('eirb_number', eIRBno); // single record test
            getAllFromREDCap();
        });

        document.getElementById('upload-btn').addEventListener('click', async () => {
            // Remove any existing modal first
            const existing = document.querySelector('.modal-overlay');
            if (existing) existing.remove();

            const built = buildModal();
            const { modalOverlay, modalBox } = built;

            let modalContent = `
                <h1 class="warning">WARNING!</h1>
                <h2 class="disclaimer">Uploading a mapping <strong>will overwrite previously saved mappings.</strong></h2>
                <h2 class="disclaimer">We strongly suggest exporting your current mapping first as a backup.</h2>
                <div>
                    <input type="file" id="jsonFile" accept=".json">
                </div>
                <div style="display:flex; justify-content:center; gap:1rem; margin-top:1.5rem;">
                    <button id="confirm_upload" style="background-color:#28a745; color:white; padding:10px 20px; border:none; border-radius:6px;">Save Choices</button>
                    <button id="cancel_upload" style="background-color:#dc3545; color:white; padding:10px 20px; border:none; border-radius:6px;">Cancel</button>
                </div>
            `

            modalBox.innerHTML = modalContent;
            modalOverlay.appendChild(modalBox);
            document.body.appendChild(modalOverlay);

            const closeModal = () => {
                if (modalOverlay && modalOverlay.parentNode) {
                    modalOverlay.parentNode.removeChild(modalOverlay);
                }
            };

            document.getElementById('confirm_upload').addEventListener('click', async () => {
                const fileInput = document.getElementById("jsonFile");

                if (!fileInput || !fileInput.files.length) {
                    alert("Please select a configuration file first.");
                    return;
                }

                const file = fileInput.files[0];

                try {
                    const text = await file.text();
                    const data = JSON.parse(text);
                    rebuild_from_mappings(instruments, dictionary, data);
                    checkpoint(); // save it after
                    closeModal();
                } catch (err) {
                    console.error(err);
                    alert("Invalid JSON file.");
                }
            });

            document.getElementById('cancel_upload').addEventListener('click', () => {
                closeModal();
            });

            modalOverlay.addEventListener('click', (e) => {
                if (e.target === modalOverlay) closeModal();
            });
        });

        document.getElementById('export-btn').addEventListener('click', () => {
            // export a properly formatted config for upload in another project
            const jsonString = JSON.stringify(mappings, null, 2);

            // Create file blob
            const blob = new Blob([jsonString], { type: "application/json" });

            // Create download link
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");

            a.href = url;
            a.download = `${project_title}_${project_id}_mapping_config.json`;
            a.click();

            URL.revokeObjectURL(url);
        });

        document.getElementById('manage-forms-btn').addEventListener('click', () => {
            if (typeof dictionary === 'undefined') {
                console.error('Dictionary not defined yet.');
                return;
            }
            manageForms(instruments, displayed);
        });

        // When a user changes a field mapping dropdown
        document.addEventListener('change', function(event) {
            if (event.target.matches('#instruments_list select')) {
                checkpoint();
            }
            if (event.target.matches('#instruments_list input' )) {
                checkpoint();
            }
        });
    });

</script>

