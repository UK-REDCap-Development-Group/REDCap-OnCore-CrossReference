<?php
use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;

$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory

$csrf = $module->getCSRFToken();
?>

<script>
    // Load mappings from config
    function loadMappings() {

    }

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

    // Get data from redcap using a field and value pair.
    function getFromREDCap(field, value, dictionary, all=false) {
        console.log('pressed');
        if (all) {
            $.ajax({
                url: '<?= $module->getUrl("scripts/get_all_records.php") ?>',
                data: {
                },
                success: function (data) {
                    console.log(data);
                },
                error: function (xhr, status, error) {
                    console.error('Error fetching REDCap record:', error, xhr.responseText);
                }
            });
        }
        else {
            $.ajax({
                url: '<?= $module->getUrl("scripts/get_records.php") ?>',
                data: {
                    'field': field,
                    'value': value
                },
                success: function (data) {
                    let record = data[0];
                    getFromOnCoreWithIRBNo(record, dictionary);
                },
                error: function (xhr, status, error) {
                    console.error('Error fetching REDCap record:', error, xhr.responseText);
                }
            });
        }
    }

    // Get data from redcap by ID using a field and value pair.
    function syncByID(field) {
        const urlParams = new URLSearchParams(window.location.search);
        const id = urlParams.get('id');
        console.log(id);
        console.log(dictionary);
        console.log(<?=json_encode($data_dict);?>);

        $.ajax({
            url: '<?= $module->getUrl("scripts/get_records_by_id.php") ?>',
            data: {
                'record_id': id
            },
            success: function (data) {
                console.log(data);
                let record = data[0];
                getFromOnCoreWithIRBNo(record, <?= json_encode($data_dict); ?>);
            },
            error: function (xhr, status, error) {
                console.error('Error fetching REDCap record:', error, xhr.responseText);
            }
        });
    }

    // Simple oncore request for page render, additional query defaults to null
    function fetchOncore(protocol, query='') {
        return $.ajax({
            url: `<?= $module->getUrl("oncore_proxy.php") ?>&action=${protocol}${query}`,
            method: "GET",
            dataType: "json"
        }).then(data => data[0]);
    }

    // Even if a request fails, I still want the site to load
    function safeFetchOncore(protocol, query='') {
        return fetchOncore(protocol, query)
            .then(data => ({ success: true, data }))
            .catch(err => {
                console.error(`Failed endpoint: ${protocol}`, err.responseText || err);
                return { success: false, data: null };
            });
    }

    // Uses the IRB from demographics to request data from OnCore for a given form, we might look for an eIRB method in api instead
    function getFromOnCoreWithIRBNo(record, dictionary) {
        // TODO: Come back and cleanup the references to comparisons, we're using that model moving forward
        const protocol_number = record['irb_number']; // protocol #
        console.log('protocol num: ' + protocol_number);

        $.ajax({
            url: `<?= $module->getUrl("oncore_proxy.php") ?>&action=protocols&protocolNo=${protocol_number}`,
            method: "GET",
            dataType: "json",
            success: function (data) {
                let dict = data[0];
                console.log('OnCore data fetched for protocol:', dict);

                // Collect all mismatches first
                const comparisons = [];
                const experimental = {};

                console.log(mappings);

                Object.entries(mappings).forEach(([form, fields]) => {
                    let form_data = [];
                    Object.entries(fields).forEach(([redcapField, mappingObj]) => {
                        // mappingObj: { mapping: "OnCoreFieldName", include_unmapped: true/false }
                        const includeUnmapped = mappingObj.include_unmapped;
                        const oncoreFieldName = mappingObj.mapping;

                        // skip if mapping is empty and we don't want it included
                        if (!oncoreFieldName) return;

                        const redcapValue = record[redcapField] || '';
                        const oncoreValue = dict[oncoreFieldName] || '';

                        if (includeUnmapped && oncoreValue) {
                            let obj = {
                                'field_name': redcapField,
                                'redcap': { 'value': redcapValue, 'selected': false },
                                'oncore': { 'value': oncoreValue, 'selected': false },
                                'unmapped': true
                            };
                            comparisons.push(obj);
                            form_data.push(obj);
                            return;
                        }
                        else if (includeUnmapped && !oncoreValue) {
                            let obj = {
                                'field_name': redcapField,
                                'redcap': { 'value': redcapValue, 'selected': false },
                                'oncore': { 'value': '', 'selected': false },
                                'unmapped': true
                            };
                            comparisons.push(obj);
                            form_data.push(obj);
                            return;
                        }

                        if (!redcapValue && oncoreValue) {
                            let obj = {
                                'field_name': redcapField,
                                'redcap': { 'value': redcapValue, 'selected': false },
                                'oncore': { 'value': oncoreValue, 'selected': true },
                                'unmapped': false
                            };
                            comparisons.push(obj);
                            form_data.push(obj);
                        }
                        else if (redcapValue === oncoreValue) {
                            let obj = {
                                'field_name': redcapField,
                                'redcap': { 'value': redcapValue, 'selected': true },
                                'oncore': { 'value': oncoreValue, 'selected': true },
                                'unmapped': false
                            };
                            comparisons.push(obj);
                            form_data.push(obj);
                        }
                        else {
                            let obj = {
                                'field_name': redcapField,
                                'redcap': { 'value': redcapValue, 'selected': true },
                                'oncore': { 'value': oncoreValue, 'selected': false },
                                'unmapped': false
                            };
                            comparisons.push(obj);
                            form_data.push(obj);
                        }
                    });
                    let obj = {};
                    obj[form] = form_data;
                    experimental[form] = form_data;
                });

                console.log('New experimental structure:',experimental);
                showComparisonTable(experimental, record, dictionary);

                console.log('Record mapped with OnCore data: ', record);
            },
            error: function (xhr, status, error) {
                console.error('Error fetching protocols:', error, xhr.responseText);
            }
        });

        return record;
    }

    /* Table view for comparing source to REDCap data */
    function showComparisonTable(comparisons, record) {
        console.log(record);
        let selectedValues = record;

        // Replace any existing modal before opening a new one
        const existing = document.querySelector('.modal-overlay');
        if (existing) existing.remove();

        const built = buildModal();
        const { modalOverlay, modalBox } = built;

        let modalContent = `
            <p>Please select the record with the most accurate information.</p>
            <div class="modal-comparison-grid">
                <div class="modal-column">

        `;

        Object.keys(comparisons).forEach(form => {
            console.log(form);
            modalContent += `
                <h2>${instruments[form]}</h2>
                <table class="myDataTable dataTable cell-border no-footer" id="redcap_table">
                    <thead>
                        <tr>
                            <th>Field Name</th>
                            <th>Field Label</th>
                            <th>REDCap Data</th>
                            <th>OnCore Data</th>
                        </tr>
                    </thead>
                    <tbody style='overflow-y: auto;'>
                    <td>record_id</td>
                    <td>Record ID</td>
                    <td>${record.record_id}</td>
                    <td>N/A</td>
`
            comparisons[form].forEach((set, i) => {
                console.log(set)
                const field = set.field_name;
                const redcapValue = set.redcap.value ?? 'N/A';
                const oncoreValue = set.oncore.value ?? 'N/A';
                console.log(set);

                if (!set.unmapped) {
                    if (set.redcap.selected) {
                        selectedValues[field] = redcapValue;
                    }
                    else if (set.oncore.selected) {
                        selectedValues[field] = oncoreValue;
                    }
                }

                modalContent += `
                <tr data-field="${field}">
                    <td>${field}</td>
                    <td>${dictionary[form][field].field_label}</td>
                    ${!set.unmapped
                    ? `<td class="selectable-cell ${set.redcap.selected ? 'selected' : ''}" data-source="${redcapValue}">${redcapValue}</td>
                           <td class="selectable-cell ${set.oncore.selected ? 'selected' : ''}" data-source="${oncoreValue}">${oncoreValue}</td>`
                    : `<td>${redcapValue}</td>
                           <td>${oncoreValue}</td>`
                }
                </tr>
                `;
            });
            modalContent += `
                        </tbody>
                    </table>
                    <button id="overwrite">Save Selected Data to REDCap</button>
                </div>
            `;
        })

        modalBox.innerHTML = modalContent;
        modalOverlay.appendChild(modalBox);
        document.body.appendChild(modalOverlay);

        modalBox.addEventListener('click', (e) => {
            const cell = e.target.closest('.selectable-cell');
            if (!cell) return;

            const row = cell.closest('tr');
            const field = row.dataset.field;
            // store selection
            selectedValues[field] = cell.dataset.source;

            // remove selection ONLY in this row
            row.querySelectorAll('.selectable-cell')
                .forEach(td => td.classList.remove('selected'));

            // highlight chosen cell
            cell.classList.add('selected');
        });

        const closeModal = () => {
            if (modalOverlay && modalOverlay.parentNode) {
                modalOverlay.parentNode.removeChild(modalOverlay);
            }
        };

        modalOverlay.addEventListener('click', (event) => {
            if (event.target === modalOverlay) closeModal();
        });

        document.getElementById('overwrite').addEventListener('click', async () => {
            const ok = confirm("WARNING: This action will overwrite existing REDCap data. Please double-check your selections.\n\nClick OK to proceed.");

            if (!ok) {
                // User cancelled
                return;
            }

            $.ajax({
                url: "<?= $module->getUrl('scripts/save_record.php'); ?>",
                method: "POST",
                data: {
                    pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                    redcap_csrf_token: <?= json_encode($csrf) ?>,
                    record: JSON.stringify([selectedValues]) // REDCap expects array
                },
                success: function (result) {
                    console.log("Checkpoint saved:", result);
                },
                error: function (xhr, status, error) {
                    console.error("Error in checkpoint:", error, xhr.responseText);
                }
            });

            closeModal();
        });
    }
</script>