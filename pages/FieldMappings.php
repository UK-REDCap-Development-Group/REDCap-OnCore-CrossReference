<?php
/** @var \ExternalModules\AbstractExternalModule $module */
$page = "field-mapping";
$instruments = REDCap::getInstrumentNames();
$csrf = $module->getCSRFToken();

// Will use this value later for batching requests if max_input is hit
$maxInputVars = ini_get('max_input_vars') ?: 1000;
?>
<script>
    // Get max_input from php.ini into a js workable variable
    const MAX_INPUT_VARS = <?= (int)$maxInputVars ?>;
    const displayed = []; // tracks displayed instruments
    let oncore_fields = null;
    //console.log(dictionary)
</script>

<style>
/* Modal Overlay: Dark background behind the popup */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.6);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

/* Modal Box: The main content container */
.modal-box {
    background-color: #ffffff;
    padding: 25px;
    border-radius: 8px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    width: 90%;
    max-width: 600px;
    text-align: center;
}

/* Comparison Grid: Displays records side-by-side */
.modal-comparison-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin: 20px 0;
    text-align: left;
}

.modal-record-header {
    font-weight: bold;
    padding-bottom: 5px;
    border-bottom: 2px solid #ccc;
}

.modal-cell {
    padding: 8px;
    background-color: #f9f9f9;
    border-radius: 4px;
}

/* Center the table within the modal */
.modal-form-selection-grid table {
    margin: 0 auto;
    border-collapse: collapse;
    width: auto;
}

/* Ensure left alignment for checkbox + label */
.modal-form-selection-grid td {
    text-align: left;
    padding: 6px 12px;
}

/* Keep checkbox + label nicely spaced */
.modal-form-selection-grid .checkbox-option {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 6px;
    font-size: 13px;
}

/* Optional: limit modal height and make it scrollable for long lists */
.modal-form-selection-grid {
    max-height: 80vh;
    overflow-y: auto;
}

/* Highlight class for cells with different data */
.highlight {
    background-color: #fffbe0;
    font-weight: bold;
}

/* Action Buttons */
.modal-actions {
    margin-top: 20px;
    display: flex;
    justify-content: space-around;
}

.modal-actions button {
    padding: 10px 20px;
    border-radius: 5px;
    border: none;
    cursor: pointer;
    font-size: 16px;
}

.modal-actions button:hover {
    opacity: 0.8;
}

#selectA_btn, #selectB_btn {
    background-color: #28a745;
    color: white;
}

.close-button {
    background-color: #dc3545;
    color: white;
}

.center-home-sects span {
    font-size: 5vw;
    color: #606060;
    -webkit-transition: background-color 100ms linear;
    -ms-transition: background-color 100ms linear;
    transition: background-color 100ms linear;
}

.selection-btns {
    margin: 0 10% 0 10%;
}

.selection-btns a {
    text-decoration: none;
}

.center-home-sects {
    text-align:center;
    border-radius: 5%;
    padding: 5% 0 5% 0;
    margin:0;
    color: #606060;
    -webkit-transition: background-color 100ms linear;
    -ms-transition: background-color 100ms linear;
    transition: background-color 100ms linear;
}

.center-home-sects:hover {
    color: #fff;
    background-color: #606060;
}

.center-home-sects:hover span{
    color: #fff;
}

.hide {
    display: none;
}
</style>

<script>
    checkProtocolsAPI('01-BMT-131'); // It would be better to not have some sort of hardcoded value.
    
    let keys = null;
    function checkProtocolsAPI(protocol_id) {
        const url = `<?= $module->getUrl("proxy.php") ?>&action=protocols&protocolNo=${protocol_id}`

        fetch(url)
            .then(response => response.json())
            .then(data => {
                let dict = data[0];
                oncore_fields = Object.keys(dict);
                //buildTables(keys);   // ⬅️ call table builder once keys are ready
            })
            .catch(error => {
                console.error('Error fetching protocols:', error);
            });
    }

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

    // hitting max input vars, how can we solve that?
    function saveMappings() {
        const ExternalModules = window.ExternalModules || {};
        ExternalModules.CSRF_TOKEN = '<?= $module->getCSRFToken() ?>';
        
        const mapping = {};

        // Collect mappings
        document.querySelectorAll("#instruments_list select").forEach(select => {
            const redcapField = select.name;
            const selectedValue = select.value;
            mapping[redcapField] = selectedValue;
        });

        // Send JSON to the server
        $.ajax({
            url: "<?= $module->getUrl('save_mappings.php')?>",
            method: "POST",
            data: {
                redcap_csrf_token: ExternalModules.CSRF_TOKEN,
                mappings: JSON.stringify(mapping),
            },
            success: function (result) {
                alert("Mappings saved: " + result.message);
            },
            error: function (xhr, status, error) {
                console.error("Error saving mappings:", error);
            }
        })
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
            <a id="save-map-btn">
                <div class="center-home-sects">
                    <span><i class="fa fa-floppy-disk"></i></span><br>
                    <h5>Save Mappings</h5>
                </div>
            </a>
        </div>
    </div>
    
    <!-- List of forms and their fields -->
    <div id="instruments_list" class="row" style="flex-direction: column;">

    </div>

    <!-- Save and sync buttons -->
    <div class="row selection-btns">
        <div class="col-md-6">
            <a id="save-map-btn">
                <div class="center-home-sects">
                    <span><i class="fa fa-floppy-disk"></i></span><br>
                    <h5>Save Mappings</h5>
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
        const closeModal = () => {
            document.body.removeChild(modalOverlay);
            checkpoint();
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
                // Handle additions — maintain consistent order from instruments
                Object.keys(instruments).forEach(selectedForm => {
                    if (!selected.includes(selectedForm)) return;  // Only process selected forms

                    // If already displayed, skip (already exists)
                    if (displayed.includes(selectedForm)) return;

                    // Add to displayed and build table
                    displayed.push(selectedForm);
                    const table = document.createElement('table');
                    table.id = selectedForm;
                    table.classList = "dataTable cell-border no-footer";
                    document.getElementById('instruments_list').appendChild(table);

                    const header = document.createElement('thead');
                    header.innerHTML = `
                        <tr>
                            <th>${instruments[selectedForm]} Fields</th>
                            <th>OnCore Field</th>
                        </tr>`;
                    table.appendChild(header);

                    const tbody = document.createElement('tbody');
                    table.appendChild(tbody);

                    let i = 1;
                    Object.keys(dictionary[selectedForm]).forEach(key => {
                        const row = document.createElement('tr');
                        row.classList = i % 2 !== 0 ? 'odd' : 'even';

                        row.innerHTML = `
                            <td style='width: 50% !important;'>
                                <label for='${key}'>${key}</label>
                            </td>
                            <td style='width: 50% !important;'>
                                <select name='${key}' id='${key}'>
                                    <option value="">None</option>
                                </select>
                            </td>`;

                        // Populate select with OnCore fields
                        const selectField = row.querySelector(`#${key}`);
                        oncore_fields.forEach(k => {
                            const option = document.createElement("option");
                            option.value = k;
                            option.textContent = k;
                            selectField.appendChild(option);
                        });

                        table.appendChild(row);
                        i++;
                    });
                });

                // After all additions, re-sort the DOM tables by the instrument order
                const container = document.getElementById('instruments_list');
                const tables = Array.from(container.querySelectorAll('table'));
                tables.sort((a, b) => {
                    const keys = Object.keys(instruments);
                    return keys.indexOf(a.id) - keys.indexOf(b.id);
                });
                tables.forEach(t => container.appendChild(t));
            });

            closeModal();
        });

        // Close modal on Cancel or background click
        document.getElementById('close_btn').addEventListener('click', closeModal);
        modalOverlay.addEventListener('click', (event) => {
            if (event.target === modalOverlay) closeModal();
        });
    }


    function checkpoint() {
        const mapping = {};

        console.log(document.querySelectorAll('table'));
        // Loop through each instrument table
        document.querySelectorAll(table).forEach(table => {
            const instrument = table.id;  // use table ID as the instrument name
            const instrumentMapping = {};
            
            if (!table.id) {
                return;
            }
            // Get only the <select> elements inside *this* table
            table.querySelectorAll('select').forEach(select => {
                const redcapField = select.name;
                const selectedValue = select.value;
                instrumentMapping[redcapField] = selectedValue;
            });

            // Save this instrument's mapping under its ID
            mapping[instrument] = instrumentMapping;
        });

        console.log('Final mapping:', mapping);

        // Send to server
        $.ajax({
            url: "<?= $module->getUrl('scripts/save_mappings.php') ?>",
            method: "POST",
            data: {
                pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                redcap_csrf_token: <?= json_encode($csrf) ?>,
                mappings: JSON.stringify(mapping)
            },
            success: function (result) {
                console.log("Checkpoint saved:", result);
            },
            error: function (xhr, status, error) {
                console.error("Error in checkpoint:", error, xhr.responseText);
            }
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

    document.addEventListener('DOMContentLoaded', () => {
        // Load existing mappings
        fetch("<?= $module->getUrl('scripts/load_mappings.php') ?>?pid=<?= $_GET['pid'] ?? 0 ?>")
            .then(r => r.json())
            .then(existingMappings => {
                console.log(existingMappings);
                if (!existingMappings) return;
                for (const [field, oncoreField] of Object.entries(existingMappings)) {
                    const select = document.querySelector(`#instruments_list select[name='${field}']`);
                    if (select) select.value = oncoreField || "";
                }
            })
            .catch(err => console.error('Error loading saved mappings:', err));

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

        document.getElementById('save-map-btn').addEventListener('click', checkpoint);

        // When a user changes a field mapping dropdown
        document.addEventListener('change', function(event) {
            if (event.target.matches('#instruments_list select')) {
                checkpoint();
            }
        });
    });
</script>

