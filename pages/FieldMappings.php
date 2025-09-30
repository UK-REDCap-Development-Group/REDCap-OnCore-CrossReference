<?php
/** @var \ExternalModules\AbstractExternalModule $module */
$page = "field-mapping";
$instruments = REDCap::getInstrumentNames();
$csrf = $module->getCSRFToken();
$maxInputVars = ini_get('max_input_vars') ?: 1000;
?>
<script>
    const MAX_INPUT_VARS = <?= (int)$maxInputVars ?>;
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
</style>

<script>
    let keys = null;
    function checkProtocolsAPI(protocol_id) {
        const url = `<?= $module->getUrl("proxy.php") ?>&action=protocols&protocolNo=${protocol_id}`

        fetch(url)
            .then(response => response.json())
            .then(data => {
                let dict = data[0];
                const keys = Object.keys(dict);
                buildTables(keys);   // ⬅️ call table builder once keys are ready
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
    checkProtocolsAPI('01-BMT-131'); // It would be better to not have some sort of hardcoded value.

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
                mappings: mapping
            }
        })
        .then(response => response.json())
        .then(result => {
            alert("Mappings saved: " + result.message);
        })
        .catch(error => {
            console.error("Error saving mappings:", error);
        });
    }
</script>

<div class="d-flex container" style="flex-direction: column;">
    <div id="instruments_list" class="row" style="flex-direction: column;">

    </div>
    <div class="row" style="padding-top: 10pt;">
        <button onClick="saveMappings()">Save Mappings</button>
        <button id="compareBtn">Sync with OnCore</button>
    </div>
</div>

<script>
        function modalTest(recordA, recordB, onSelectCallback) {
        // Prevent creating multiple modals
        if (document.getElementById('comparison-modal')) {
            return;
        }

        // --- 1. Create Modal Elements ---
        const modalOverlay = document.createElement('div');
        modalOverlay.className = 'modal-overlay';
        modalOverlay.id = 'comparison-modal';

        const modalBox = document.createElement('div');
        modalBox.className = 'modal-box';

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

    document.getElementById('compareBtn').addEventListener('click', () => {
        modalTest(redcap_record, oncore_record, handleRecordSelection);
    });
</script>

