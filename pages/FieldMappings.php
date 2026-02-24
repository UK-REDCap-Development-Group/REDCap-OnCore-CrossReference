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
    const eIRBno = '46000'; // sample that was arbitrarily set, need a way around this
    let mappings = false;
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

    function comparisonModal(redcap, oncore, index, count, onSelectCallback) {
        // Replace any existing modal before opening a new one
        const existing = document.querySelector('.modal-overlay');
        if (existing) existing.remove();

        const built = buildModal();
        const { modalOverlay, modalBox } = built;

        let modalContent = `
            <h2>Record Comparison (${index} of ${count})</h2>
            <p>Please select the record with the most accurate information.</p>
            <div class="modal-comparison-grid">
                <div class="modal-column">
                    <div class="modal-record-header">REDCap</div>
                    <table class="myDataTable dataTable cell-border no-footer" id="redcap_table">
                        <thead>
                            <tr>
                                <th>Field Name</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
        `;

        Object.keys(redcap).forEach((key, i) => {
            const redcapValue = redcap[key] ?? 'N/A';
            const oncoreValue = oncore[key] ?? 'N/A';
            const highlightClass = redcapValue !== oncoreValue ? 'highlight' : '';

            modalContent += `
                <tr class="${i % 2 !== 0 ? 'odd' : 'even'} ${highlightClass}">
                    <td>${key}</td>
                    <td>${redcapValue}</td>
                </tr>
            `;
        });

        modalContent += `
                        </tbody>
                    </table>
                    <button id="select_redcap">Save REDCap Data</button>
                </div>

                <div class="modal-column">
                    <div class="modal-record-header">OnCore</div>
                    <table class="myDataTable dataTable cell-border no-footer" id="oncore_table">
                        <thead>
                            <tr>
                                <th>Field Name</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
        `;

        Object.keys(oncore).forEach((key, i) => {
            const redcapValue = redcap[key] ?? 'N/A';
            const oncoreValue = oncore[key] ?? 'N/A';
            const highlightClass = redcapValue !== oncoreValue ? 'highlight' : '';

            modalContent += `
                <tr class="${i % 2 !== 0 ? 'odd' : 'even'} ${highlightClass}">
                    <td>${key}</td>
                    <td>${oncoreValue}</td>
                </tr>
            `;
        });

        modalContent += `
                        </tbody>
                    </table>
                    <button id="select_oncore">Save OnCore Data</button>
                </div>
            </div> <!-- end modal-comparison-grid -->
        `;


        modalBox.innerHTML = modalContent;
        modalOverlay.appendChild(modalBox);
        document.body.appendChild(modalOverlay);

            const closeModal = () => {
                if (modalOverlay && modalOverlay.parentNode) {
                    modalOverlay.parentNode.removeChild(modalOverlay);
                }
            };

        document.getElementById('select_redcap').addEventListener('click', () => {
            onSelectCallback(redcap);
            closeModal();
        });

        document.getElementById('select_oncore').addEventListener('click', () => {
            onSelectCallback(oncore);
            closeModal();
        });

        //document.getElementById('close_btn').addEventListener('click', closeModal);
        modalOverlay.addEventListener('click', (event) => {
            if (event.target === modalOverlay) closeModal();
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
                        <th>Include Unmapped in Adjudication</th>
                    </tr>`;
                table.appendChild(header);

                const tbody = document.createElement('tbody');
                table.appendChild(tbody);

                let i = 0;
                Object.keys(dictionary[selectedForm]).forEach(redcapField => {
                    const row = document.createElement('tr');
                    row.className = i % 2 === 0 ? 'even' : 'odd';

                    const selectId = `${selectedForm}_${redcapField}`;
                    const checkId = `display_${redcapField}`;
                    row.innerHTML = `
                        <td style="width:50%"><label for="${selectId}">${redcapField}</label></td>
                        <td style="width:50%">
                            <select name="${redcapField}" id="${selectId}">
                                <option value="">None</option>
                            </select>
                        </td>
                        <td>
                            <input id='${checkId}' type='checkbox'>
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

                mappings = result.data || {};
                const savedDisplayed = result.displayed || []; // <-- Load the saved displayed list

                displayed.length = 0;
                // Use the saved displayed list instead of mapping keys
                savedDisplayed.forEach(instrumentKey => {
                    if (instruments[instrumentKey]) {
                        displayed.push(instrumentKey);
                    }
                });

                rebuild_from_mappings(instruments, dictionary, mappings);
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

    /* Table view for comparing source to REDCap data */
    function showComparisonTable(comparisons, record) {
        // Replace any existing modal before opening a new one
        const existing = document.querySelector('.modal-overlay');
        if (existing) existing.remove();

        const built = buildModal();
        const { modalOverlay, modalBox } = built;

        let modalContent = `
            <p>Please select the record with the most accurate information.</p>
            <div class="modal-comparison-grid">
                <div class="modal-column">
                    <table class="myDataTable dataTable cell-border no-footer" id="redcap_table">
                        <thead>
                            <tr>
                                <th>Field Name</th>
                                <th>REDCap</th>
                                <th>OnCore</th>
                            </tr>
                        </thead>
                        <tbody style='overflow-y: auto;'>
                            <tr>
                                <td>ccts_record</td>
                                <td>${record.ccts_record}</td>
                                <td>N/A</td>
                            </tr>
                            <tr>
                                <td>full_title</td>
                                <td>${record.full_title}</td>
                                <td>N/A</td>
                            </tr>
        `;

        comparisons.forEach((set, i) => {
            console.log(set);
            const field = set['field_name']
            const redcapValue = set['redcap'] ?? 'N/A';
            const oncoreValue = set['oncore'] ?? 'N/A';
            const highlightClass = redcapValue !== oncoreValue ? 'highlight' : '';

            modalContent += `
                <tr class="${i % 2 !== 0 ? 'odd' : 'even'} ${highlightClass}">
                    <td>${field}</td>
                    <td id='keep'>${redcapValue}</td>
                    <td id='keep'>${oncoreValue}</td>
                </tr>
            `;
        });

        modalContent += `
                        </tbody>
                    </table>
                    <button id="select_redcap">Save REDCap Data</button>
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

        /*document.getElementById('select_redcap').addEventListener('click', () => {
            //onSelectCallback(redcap);
            closeModal();
        });

        document.getElementById('select_oncore').addEventListener('click', () => {
            //onSelectCallback(oncore);
            closeModal();
        });*/

        //document.getElementById('close_btn').addEventListener('click', closeModal);
        modalOverlay.addEventListener('click', (event) => {
            if (event.target === modalOverlay) closeModal();
        });
    }

    // Uses the IRB from demographics to request data from OnCore for a given form, we might look for an eIRB method in api instead
    function getFromOnCoreWithIRBNo(record) {
        const protocol_number = record['irb_number']; // protocol #
        const originals = {}; // keep a copy of original value of conflicting records
        const updates = {}; // copy of selected conflicting values
        $.ajax({
            url: `<?= $module->getUrl("oncore_proxy.php") ?>&action=protocols&protocolNo=${protocol_number}`,
            method: "GET",
            dataType: "json",
            success: function (data) {
                let dict = data[0];
                console.log('OnCore data fetched for protocol:', dict);
                
                // Collect all mismatches first
                const comparisons = [];

                Object.entries(mappings).forEach(([form, fields]) => {
                    Object.entries(fields).forEach(([redcapField, oncoreField]) => {
                        if (oncoreField === '') return;

                        const redcapValue = record[redcapField];
                        const oncoreValue = dict[oncoreField];

                        originals[redcapField] = record[redcapField];

                        console.log('originals', originals);
                        console.log('record', record);

                        if (redcapValue !== oncoreValue) {
                            comparisons.push({
                                'field_name': redcapField,
                                'redcap': redcapValue,
                                'oncore': oncoreValue });
                        } /*else if (!redcapValue && oncoreValue) {
                            record[redcapField] = oncoreValue;
                        }*/
                    });
                });

                showComparisonTable(comparisons, record);

                /*// Sequentially show comparison modals
                const showNextComparison = (index = 0) => {
                    if (index >= comparisons.length) {
                        // Get user confirmation before saving
                        confirmSaveModal(originals, updates, () => {
                            console.log('User confirmed save.');
                            saveRecord(record); // Call your save function here
                        }, () => {
                            console.log('User canceled save.');
                        });
                        return;
                    }

                    const { redcapField, oncoreField, redcapValue, oncoreValue } = comparisons[index];

                    const redcapObj = {
                        record_id: record['record_id'],
                        irb_number: record['irb_number'],
                        eirb_number: record['eirb_number'],
                        [redcapField]: redcapValue
                    };

                    const oncoreObj = {
                        record_id: record['record_id'],
                        irb_number: record['irb_number'],
                        eirb_number: record['eirb_number'],
                        [redcapField]: oncoreValue
                    };

                    comparisonModal(redcapObj, oncoreObj, (index+1), comparisons.length, (selected) => {
                        // merge selected choice into record
                        updates[redcapField] = selected[redcapField];
                        Object.assign(record, selected);

                        // then show next modal
                        showNextComparison(index + 1);
                    });
                };

                // Start the sequence if we have mismatches
                if (comparisons.length > 0) {
                    showNextComparison();
                } else {
                    console.log('No mismatches found.');
                }*/

                console.log('Record mapped with OnCore data: ', record);
            },
            error: function (xhr, status, error) {
                console.error('Error fetching protocols:', error, xhr.responseText);
            }
        });
        
        return record;
    }

    function confirmSaveModal(original, updated, onConfirm, onCancel) {
        console.log('original:', original);
        console.log('updated:', updated);
        // Remove any existing modal first
        const existing = document.querySelector('.modal-overlay');
        if (existing) existing.remove();

        const built = buildModal();
        const { modalOverlay, modalBox } = built;

        let modalContent = `
            <h2>Confirm Save</h2>
            <p>
                You have finished reviewing all mismatches.<br>
                Saving will overwrite existing REDCap data where you chose OnCore values.
            </p>
            <table class="dataTable cell-border no-footer" style="margin-top: 1rem; width:100%;">
                <thead>
                    <tr>
                        <th>Field</th>
                        <th>Original Value</th>
                        <th>New Value</th>
                    </tr>
                </thead>
                <tbody>
        `;

        // Build diff rows
        Object.keys(updated).forEach((key, i) => {
            const originalValue = original[key] ?? 'N/A';
            const newValue = updated[key] ?? 'N/A';

            if (originalValue !== newValue) {
                const rowClass = i % 2 === 0 ? 'even' : 'odd';
                modalContent += `
                    <tr class="${rowClass}">
                        <td>${key}</td>
                        <td>${originalValue}</td>
                        <td class="highlight">${newValue}</td>
                    </tr>
                `;
            }
        });

        modalContent += `
                </tbody>
            </table>
            <div style="display:flex; justify-content:center; gap:1rem; margin-top:1.5rem;">
                <button id="confirm_save" style="background-color:#28a745; color:white; padding:10px 20px; border:none; border-radius:6px;">Save Choices</button>
                <button id="cancel_save" style="background-color:#dc3545; color:white; padding:10px 20px; border:none; border-radius:6px;">Cancel</button>
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

        document.getElementById('confirm_save').addEventListener('click', () => {
            closeModal();
            if (onConfirm) onConfirm();
        });

        document.getElementById('cancel_save').addEventListener('click', () => {
            closeModal();
            if (onCancel) onCancel();
        });

        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) closeModal();
        });
    }

    function saveRecord(rec) {
        const wrapped = {};
        wrapped[rec.record_id] = rec; // wrap by record_id

        $.ajax({
            url: '<?= $module->getUrl("scripts/save_record.php") ?>',
            method: "POST",
            data: { 
                record: JSON.stringify(wrapped), // wrap in array for REDCap saveData format
                redcap_csrf_token: <?= json_encode($csrf) ?>
            },
            success: function (data) {
                alert('Record was saved successfully.');
                console.log('Record saved successfully:', data);
            },
            error: function (xhr, status, error) {
                console.error('Error saving record:', error, xhr.responseText);
            }
        });
    }

    function getFromREDCap(field, value) {
        console.log('pressed');
        $.ajax({
            url: '<?= $module->getUrl("scripts/get_records.php") ?>',
            data: { 
                'field': field,
                'value': value
            },
            success: function (data) {
                let record = data[0];
                getFromOnCoreWithIRBNo(record);
            },
            error: function (xhr, status, error) {
                console.error('Error fetching REDCap record:', error, xhr.responseText);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        $.ajax({
            url: `<?= $module->getUrl("oncore_proxy.php") ?>&action=protocols&protocolNo=${protocolNo}`,
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

        document.getElementById('sync-btn').addEventListener('click', () => {
            //modalTest(redcap_record, oncore_record, handleRecordSelection);
            //let oncore = getFromOnCore(11, protocolNo); // manual test using Saltzman record
            getFromREDCap('eirb_number', eIRBno);
        });

        document.getElementById('upload-btn').addEventListener('click', () => {
            // import a specifically formatted config for upload in another project
        });

        document.getElementById('export-btn').addEventListener('click', () => {
            // export a properly formatted config for upload in another project
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

