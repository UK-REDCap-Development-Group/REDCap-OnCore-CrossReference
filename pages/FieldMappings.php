<?php
/** @var \ExternalModules\AbstractExternalModule $module */
$page = "field-mapping";
$instruments = REDCap::getInstrumentNames();
$csrf = $module->getCSRFToken();

// Will use this value later for batching requests if max_input is hit
$maxInputVars = ini_get('max_input_vars') ?: 1000;
?>

<link rel="stylesheet" href="<?= $module->getUrl('css/field_mappings.css') ?>">

<script>
    // Get max_input from php.ini into a js workable variable
    const MAX_INPUT_VARS = <?= (int)$maxInputVars ?>;
    const displayed = []; // tracks displayed instruments
    let oncore_fields = null;
    const protocolNo = '15-0927-F1V'; // sample that was arbitrarily set, need a way around this
    //console.log(dictionary)
</script>

<script>
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
    <!-- Add and remove form section -->
    <div class="row selection-btns">
        <div class="col-md-6">
            <a id="manage-forms-btn">
                <div class="center-home-sects">
                    <span><i class="fa fa-plus"></i></span><br>
                    <h5>Manage Forms</h5>
                </div>
            </a>
        </div>
        <div class="col-md-6">
            <a id="sync-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-arrows-rotate"></i></span><br>
                    <h5>Sync with OnCore</h5>
                </div>
            </a>
        </div>
    </div>
        <!-- <div class="col-md-6">
            <a id="save-map-btn">
                <div class="center-home-sects">
                    <span><i class="fa fa-floppy-disk"></i></span><br>
                    <h5>Save Mappings</h5>
                </div>
            </a>
        </div>
    </div> -->
    
    <!-- List of forms and their fields -->
    <div id="instruments_list" class="row" style="flex-direction: column;">
        <div id="forms-loader" class="loader-container">
            <div class="loader"></div>
            <p style="margin-top: 15px;">Loading Saved Mappings...</p>
        </div>
    </div>

    <!-- Save and sync buttons -->
    <div class="row selection-btns">
        <!-- <div class="col-md-6">
            <a id="save-map-btn">
                <div class="center-home-sects">
                    <span><i class="fa fa-floppy-disk"></i></span><br>
                    <h5>Save Mappings</h5>
                </div>
            </a>
        </div> -->
        <div class="col-md-6">
            <a id="manage-forms-btn">
                <div class="center-home-sects">
                    <span><i class="fa fa-plus"></i></span><br>
                    <h5>Manage Forms</h5>
                </div>
            </a>
        </div>
        <div class="col-md-6">
            <a id="sync-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-arrows-rotate"></i></span><br>
                    <h5>Sync with OnCore</h5>
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

        // --- 1. Create Modal Elements ---
        const modalOverlay = document.createElement('div');
        modalOverlay.className = 'modal-overlay';
        modalOverlay.id = 'comparison-modal';

        const modalBox = document.createElement('div');
        modalBox.className = 'modal-box';

        //console.log(modalOverlay, modalBox)

        return { modalOverlay, modalBox };
    }

    function modalTest(recordA, recordB, onSelectCallback) {
        const built = buildModal();
        if (!built) return; // The modal already exists

        const { modalOverlay, modalBox } = built;

        let modalContent = `
            <h2>Record Comparison</h2>
            <p>Please select the record with the most accurate information.</p>
            <div class="modal-comparison-grid">
                <div class="modal-record-header">Record A</div>
                <div class="modal-record-header">Record B</div>
        `;

        // --- 2. Populate Comparison Grid ---
        // Get all unique keys from both records to display all fields
        const allKeys = [...new Set([...Object.keys(recordA), ...Object.keys(recordB)])];

        allKeys.forEach(key => {
            const valueA = recordA[key] || 'N/A';
            const valueB = recordB[key] || 'N/A';
            // Highlight differing values
            const highlightClass = valueA !== valueB ? 'highlight' : '';

            modalContent += `
                <div class="modal-cell ${highlightClass}">${valueA}</div>
                <div class="modal-cell ${highlightClass}">${valueB}</div>
            `;
        });

        modalContent += `
            </div> 
            <div class="modal-actions">
                <button id="selectA_btn">Select Record A</button>
                <button id="selectB_btn">Select Record B</button>
                <button id="close_btn" class="close-button">Cancel</button>
            </div>
        `;

        modalBox.innerHTML = modalContent;
        modalOverlay.appendChild(modalBox);
        document.body.appendChild(modalOverlay);

        // --- 3. Add Event Listeners ---
        const closeModal = () => {
            document.body.removeChild(modalOverlay);
        };

        document.getElementById('selectA_btn').addEventListener('click', () => {
            onSelectCallback(recordA); // Pass selected record to the callback
            closeModal();
        });

        document.getElementById('selectB_btn').addEventListener('click', () => {
            onSelectCallback(recordB);
            closeModal();
        });
        
        // Close modal on 'Cancel' or by clicking the background
        document.getElementById('close_btn').addEventListener('click', closeModal);
        modalOverlay.addEventListener('click', (event) => {
            if (event.target === modalOverlay) {
                closeModal();
            }
        });
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

        // Close modal helper
        const closeModal = (shouldCheckpoint = false) => {
            document.body.removeChild(modalOverlay);
            
            if (shouldCheckpoint) {
                checkpoint();
            }
        };

        // Confirm button logic
        document.getElementById('confirm_btn').addEventListener('click', async () => {
            const selected = Array.from(document.querySelectorAll('input[name="form-select"]:checked'))
                .map(cb => cb.value);

            const toAdd = selected.filter(f => !displayed.includes(f));
            const toRemove = displayed.filter(f => !selected.includes(f));

            // Handle removals first, with warnings
            let safeToRemove = true;
            for (const formKey of toRemove) {
                const table = document.getElementById(formKey);
                if (!table) continue;

                // Check if table has configured data (non-empty selects)
                const selects = Array.from(table.querySelectorAll('select'));
                const hasConfiguredData = selects.some(sel => sel.value && sel.value !== "");

                if (hasConfiguredData) {
                    const confirmRemoval = confirm(
                        `Warning: The form "${instruments[formKey]}" has mapped data. Are you sure you want to remove it?`
                    );
                    if (!confirmRemoval) {
                        safeToRemove = false;
                        continue; // Skip this form
                    }
                }

                // Remove the form from DOM and displayed
                table.remove();
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
                        <th>${instruments[selectedForm]} Fields</th>
                        <th>OnCore Field</th>
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
                        <td style="width:50%">
                            <select name="${redcapField}" id="${selectId}">
                                <option value="">None</option>
                            </select>
                        </td>
                    `;

                    // Populate the select with OnCore fields
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

    function rebuild_from_mappings(instruments, dictionary, existingMappings) {
        const container = document.getElementById('instruments_list');
        if (!container) return;

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
            header.innerHTML = `<tr><th>${instrumentLabel} Fields</th><th>OnCore Field</th></tr>`;
            table.appendChild(header);

            const tbody = document.createElement('tbody');
            table.appendChild(tbody);

            let i = 0;
            Object.keys(dictFields).forEach(redcapField => {
                const row = document.createElement('tr');
                row.className = i % 2 === 0 ? 'even' : 'odd';

                const selectId = `${instrumentKey}_${redcapField}`;
                row.innerHTML = `
                    <td style="width:50%"><label for="${selectId}">${redcapField}</label></td>
                    <td style="width:50%">
                        <select name="${redcapField}" id="${selectId}">
                            <option value="">None</option>
                        </select>
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
                        if (mappedFields[redcapField] === field) option.selected = true;

                        selectEl.appendChild(option);
                    });
                }
                i++;
            });

            fragment.appendChild(table);
        });

        // Clear the container (removes the loader) and append all tables
        container.innerHTML = '';
        container.appendChild(fragment);
    }

    function checkpoint() {
        const mapping = {};

        document.querySelectorAll('table').forEach(table => {
            const instrument = table.id;
            const instrumentMapping = {};
            
            if (!instrument) {
                return;
            }
            
            table.querySelectorAll('select').forEach(select => {
                const redcapField = select.name;
                const selectedValue = select.value;
                instrumentMapping[redcapField] = selectedValue;
            });

            mapping[instrument] = instrumentMapping;
        });

        console.log('Final mapping:', mapping);
        console.log('Displayed instruments:', displayed);

        // Send to server - include the displayed array
        $.ajax({
            url: "<?= $module->getUrl('scripts/save_mappings.php') ?>",
            method: "POST",
            data: {
                pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                redcap_csrf_token: <?= json_encode($csrf) ?>,
                mappings: JSON.stringify(mapping),
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
                if (result.status !== 'success') {
                    console.error('Failed to load mappings:', result.error || 'Unknown error');
                    const loader = document.getElementById('forms-loader');
                    if (loader) {
                        loader.innerHTML = '<p style="color: red; text-align: center;"><b>Error:</b> Could not load saved field mappings.</p>';
                    }
                    return;
                }

                const existingMappings = result.data || {};
                const savedDisplayed = result.displayed || []; // <-- Load the saved displayed list

                displayed.length = 0;
                // Use the saved displayed list instead of mapping keys
                savedDisplayed.forEach(instrumentKey => {
                    if (instruments[instrumentKey]) {
                        displayed.push(instrumentKey);
                    }
                });

                rebuild_from_mappings(instruments, dictionary, existingMappings);
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


    const redcap_record = {
        'record_id': 101,
        'eirb': '10001',
        'coordinator': "John Doe",
        'depart': "Oncology",
        'start_date': '1/12/24',
        'end_date': '1/12/25'
    }

    const oncore_record = {
        'record_id': 101,
        'eirb': '10001',
        'coordinator': "John Doe",
        'depart': "Markey Cancer Center",
        'start_date': '1/12/24',
        'end_date': '1/12/25'
    }

    // Will eventually handle overwriting data
    function handleRecordSelection(selectedRecord) {
        console.log("The user selected this record:", selectedRecord);
        alert(`You chose the record with id: ${selectedRecord.record_id}`);
    }

    // Uses the eIRB from demographics to request data from OnCore for a given form
    function getFromOnCore(eIRB) {
        $.ajax({
            url: `<?= $module->getUrl("proxy.php") ?>&action=protocols&protocolNo=${eIRB}`,
            method: "GET",
            dataType: "json",
            success: function (data) {
                let dict = data[0];
                console.log(dict);
            },
            error: function (xhr, status, error) {
                console.error('Error fetching protocols:', error, xhr.responseText);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        $.ajax({
            url: `<?= $module->getUrl("proxy.php") ?>&action=protocols&protocolNo=${protocolNo}`,
            method: "GET",
            dataType: "json",
            success: function (data) {
                let dict = data[0];
                oncore_fields = Object.keys(dict);
                console.log('OnCore fields fetched:', oncore_fields);
                
                // Load existing mappings
                load_checkpoint();
            },
            error: function (xhr, status, error) {
                console.error('Error fetching protocols:', error, xhr.responseText);
            }
        });

        getFromOnCore(protocolNo);

        document.getElementById('sync-btn').addEventListener('click', () => {
            modalTest(redcap_record, oncore_record, handleRecordSelection);
        });

        document.getElementById('manage-forms-btn').addEventListener('click', () => {
            if (typeof dictionary === 'undefined') {
                console.error('Dictionary not defined yet.');
                return;
            }
            manageForms(instruments, displayed);
        });

        //document.getElementById('save-map-btn').addEventListener('click', checkpoint);

        // When a user changes a field mapping dropdown
        document.addEventListener('change', function(event) {
            if (event.target.matches('#instruments_list select')) {
                checkpoint();
            }
        });
    });
</script>

