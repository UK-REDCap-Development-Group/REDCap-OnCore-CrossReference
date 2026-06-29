<?php
use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;

$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory

$csrf = $module->getCSRFToken();

$webroot = APP_PATH_WEBROOT . 'redcap_v' . REDCAP_VERSION . '/';
?>
<link rel="stylesheet" href="<?= $module->getUrl('css/field_mappings.css') ?>">
<script>
    const irb_field = <?= json_encode($module->getProjectSetting('irb_field') ?? 'eirb_number') ?>;

    const buildAPI = (protocolId) => ({
        protocols: `&protocolId=${protocolId}`,
        protocolConsents: `&protocolId=${protocolId}`,
        protocolSponsors: `&protocolId=${protocolId}`,
        protocolStaff: `&protocolId=${protocolId}`,
        protocolEprmsSubmissions: `&protocolId=${protocolId}`,
        protocolPrmcReviews: `&protocolId=${protocolId}`,
        protocolIde: `&protocolId=${protocolId}`,
        protocolInd: `&protocolId=${protocolId}`,
        protocolIrbReviews: `&protocolId=${protocolId}`,
        protocolInstitutions: `&protocolId=${protocolId}`,
    });

    // TODO: review what needs to be done to implement contactCredentials

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

    // Check the mapping against the dictionary and ensure that forms and fields are all still valid
    function validity_check(mappings, dictionary, instruments) {
        // Use a flag to stop checking once we find the first error
        let errorFound = false;

        // for ... of lets us break the loop
        for (const form of Object.keys(mappings)) {
            if (errorFound) break;

            if (dictionary[form]) {
                // The form exists, now check its fields
                for (const field of Object.keys(mappings[form])) {
                    if (errorFound) break;

                    if (!dictionary[form][field]) {
                        console.warn(`Missing Field Detected: ${field} in form ${form}`);

                        // Call the unified modal for a missing FIELD
                        resolveMappingModal('field', mappings, form, field, dictionary[form]);

                        errorFound = true; // Trigger the breaks
                    }
                }
            } else {
                console.warn(`Missing Form Detected: ${form}`);

                // Call the unified modal for a missing FORM
                resolveMappingModal('form', mappings, form, null, instruments);

                errorFound = true; // Trigger the break
            }
        }

        if (!errorFound) {
            console.log("All mappings are valid!");
        }
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

        let webroot = <?= json_encode($webroot)?>;
        let modalContent = `
            <h1>Adjudication for Record <a href="${webroot}DataEntry/record_home.php?pid=${pid}&id=${record.record_id}" target="_blank" class="hyperlink">${record.record_id}</a></h1>
            <h3>Please select which data you would like to save: REDCap or OnCore.</h3>
            <hr>
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
                    <td><a href="${webroot}DataEntry/record_home.php?pid=${pid}&id=${record.record_id}" target="_blank" class="hyperlink">${record.record_id}</a></td>
                    <td>N/A</td>
            `;

            comparisons[form].forEach((set, i) => {
                // console.log(set)
                const field = set.field_name;
                const redcapValue = set.redcap.value ?? 'N/A';
                const oncoreValue = set.oncore.value ?? 'N/A';

                // Define  visual and interactive states
                const isUnmapped = set.unmapped;
                const isMatched = redcapValue === oncoreValue;

                // Only interactive if it is mapped AND the values actually differ
                const isInteractive = !isUnmapped && !isMatched;

                if (!isUnmapped) {
                    if (set.redcap.selected) {
                        selectedValues[field] = redcapValue;
                    } else if (set.oncore.selected) {
                        selectedValues[field] = oncoreValue;
                    }
                }

                // Inject into the table, using `isInteractive` to decide the HTML
                modalContent += `
                    <tr data-field="${field}">
                        <td>${field}</td>
                        <td>${dictionary[form][field].field_label}</td>
                        ${isInteractive
                    ? `<td class="selectable-cell ${set.redcap.selected ? 'selected' : ''}" data-source="${redcapValue}">${redcapValue}</td>
                               <td class="selectable-cell ${set.oncore.selected ? 'selected' : ''}" data-source="${oncoreValue}">${oncoreValue}</td>`
                    : `<td class="disabled-cell">${redcapValue}</td>
                               <td class="disabled-cell">${oncoreValue}</td>`
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
        });

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

            // Force a reload so that we see updated record info
            window.location.reload();
        });
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

    async function singleRecordSync(id = false, show = true) {
        console.log('singleRecordSync Ran');
        if (!id) {
            const urlParams = new URLSearchParams(window.location.search);
            id = urlParams.get('id');
        }

        $.ajax({
            url: '<?= $module->getUrl("scripts/get_record_by_id.php") ?>',
            data: { 'record_id': id },
            success: async function (data) {
                let protocolId = null;
                let record = data[0];

                if (!record[irb_field] || record[irb_field] === "") {
                    alert('Please ensure to populate the IRB Number field and SAVE the record before attempting to synchronize with OnCore.');
                    return;
                }

                let details = await safeFetchOncore('protocolManagementDetails', `&irbNo=${record[irb_field]}`);

                if (details.success && details.data) {
                    protocolId = details.data['protocolId'];
                } else {
                    console.warn("Could not find a protocol with that eIRB number.");
                    return; // Stop execution if no protocol is found
                }

                // Fire all endpoint requests in parallel
                const apiEndpoints = buildAPI(protocolId);
                const fetchPromises = Object.entries(apiEndpoints).map(async ([protocol, query]) => {
                    const response = await safeFetchOncore(protocol, query);
                    return {protocol: protocol, response: response};
                });

                // Wait for all of them to finish
                const results = await Promise.all(fetchPromises);

                console.log('results', results)

                // Build a dictionary organized by endpoint: { "protocolConsents": {...}, "protocolStaff": {...} }
                const oncoreDataByEndpoint = {};
                results.forEach(res => {
                    oncoreDataByEndpoint[res.protocol] = res.response.data;
                });

                console.log('singleRecordSync', oncoreDataByEndpoint);

                // Run the comparison logic once we have ALL the data
                runMappingComparison(record, oncoreDataByEndpoint, show);
            },
            error: function (xhr, status, error) {
                console.error('Error fetching REDCap record:', error, xhr.responseText);
            }
        });
    }

    // Looping version of above fnc
    function fullSync() {
        $.ajax({
            url: '<?= $module->getUrl("scripts/get_eirbs.php") ?>',
            data: {
                forms: displayed
            },
            success: async function (data) {
                console.log(<?= json_encode($module->getProjectSetting('running')) ?>)
                // TODO: finish this looping to save records, then figure out how to run it in the background
                let toSave = [];
                let protocolId = null;

                let matched = 0;
                for (const record of data) {
                    console.log(record);

                    let details = await safeFetchOncore('protocolManagementDetails', `&irbNo=${record[irb_field]}`);
                    console.log(details);

                    if (details.success && details.data) {
                        protocolId = details.data['protocolId'];
                    } else {
                        console.warn("Could not find a protocol with that eIRB number.");
                        toSave.push({'record_id': record.record_id, 'eirb_number': record.eirb_number, 'title': record.full_title, status: 'not in OnCore', message: 'The eIRB/IRB was not found in OnCore.'});
                        continue; // Stop execution if no protocol is found
                    }

                    // Fire all endpoint requests in parallel
                    const apiEndpoints = buildAPI(protocolId);
                    const fetchPromises = Object.entries(apiEndpoints).map(async ([protocol, query]) => {
                        const response = await safeFetchOncore(protocol, query);
                        return {protocol: protocol, response: response};
                    });

                    // Wait for all of them to finish
                    const results = await Promise.all(fetchPromises);

                    console.log('results', results)

                    // Build a dictionary organized by endpoint: { "protocolConsents": {...}, "protocolStaff": {...} }
                    const oncoreDataByEndpoint = {};
                    results.forEach(res => {
                        oncoreDataByEndpoint[res.protocol] = res.response.data;
                    });

                    // Run the comparison! (Passing false so it doesn't trigger the modal UI)
                    const isPerfectMatch = runMappingComparison(record, oncoreDataByEndpoint, false);

                    if (isPerfectMatch) {
                        // Increment the counter for your metadata payload
                        matched++;
                    } else {
                        // Only push to 'toSave' if it actually needs adjudication
                        toSave.push({
                            'record_id': record.record_id,
                            'eirb_number': record.eirb_number,
                            'title': record.full_title,
                            'results': results,
                            status: 'needs attention',
                            message: 'OnCore data does not match data in REDCap.'
                        });
                    }

                    //toSave.push({'record_id': record.record_id, 'eirb_number': record.eirb_number, 'title': record.full_title, 'results': results, status: 'adjudicate', message: 'OnCore data does not match data in REDCap.'});

                    console.log(results);
                }

                // Build and save metadata
                const now = new Date();
                const date = now.toLocaleDateString();
                const time = now.toLocaleTimeString();
                const checked = data.length;
                const metadata = {
                    'date': date,
                    'time': time,
                    'checked': checked,
                    'matched': matched
                }
                save_metadata(metadata);
                track_adjudicates(toSave); // save adjudicates to the config file so that they can be referenced elsewhere
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
                dataType: "json",
            }).then(data => {
                if (Array.isArray(data)) {
                    return data.length > 0 ? data[0] : {};
                }

                return data || {};
            });
    }

    // Even if a request fails, I still want the site to load
    function safeFetchOncore(protocol, query='') {
        return fetchOncore(protocol, query)
            .then(data => {
                if (data['protocolId']) {
                    return {success: true, data: data, message:"data successfully retrieved"};
                }
                else {
                    return {success: false, message:"no data for eIRB/IRB provided"};
                }

            })
            .catch(err => {
                console.error(`Failed endpoint: ${protocol}`, err.responseText || err);
                return { success: false, data: null };
            });
    }

    // Use above function to hit multiple endpoints in a loop
    async function safeFetchOncoreAll(protocolId, api=buildAPI(protocolId)) {
        // Map over the API object, but keep the 'endpoint' attached to the result
        let requests = Object.entries(api).map(async ([endpoint, query]) => {
            let response = await safeFetchOncore(endpoint, query);
            return { endpoint, response };
        });

        let results = await Promise.all(requests);

        // Build a registry mapping each field to its endpoint
        let fieldRegistry = {};

        results.forEach(({ endpoint, response }) => {
            if (response.success && response.data) {
                Object.keys(response.data).forEach(field => {
                    // Map the field name to its origin endpoint
                    fieldRegistry[field] = endpoint;
                });
            }
        });

        // Create an alphabetized array of objects for easy UI rendering/referencing
        let oncore_fields = Object.keys(fieldRegistry)
            .sort()
            .map(field => ({
                field: field,
                endpoint: fieldRegistry[field]
            }));

        return oncore_fields;
    }

    function runMappingComparison(record, oncoreDataByEndpoint, show=false) {
        console.log("Running Mapping Comparison");
        console.log(oncoreDataByEndpoint);
        const experimental = {};

        let matched = 0;
        let totalMappedFields = 0; // NEW: Track the total number of evaluated fields

        Object.entries(mappings).forEach(([form, fields]) => {
            let form_data = [];

            Object.entries(fields).forEach(([redcapField, mappingObj]) => {
                const includeUnmapped = mappingObj.include_unmapped;
                const oncoreFieldName = mappingObj.mapping;
                const endpointOrigin = mappingObj.protocol; // e.g., 'protocolConsents'

                if (!oncoreFieldName) return;
                totalMappedFields++;

                // Grab the specific dictionary for this mapping's endpoint
                const dict = oncoreDataByEndpoint[endpointOrigin] || {};

                const redcapValue = record[redcapField] || '';
                const oncoreValue = dict[oncoreFieldName] || '';

                // Your exact comparison logic remains unchanged here
                if (includeUnmapped && oncoreValue) {
                    form_data.push({
                        'field_name': redcapField,
                        'redcap': { 'value': redcapValue, 'selected': false },
                        'oncore': { 'value': oncoreValue, 'selected': false },
                        'unmapped': true
                    });
                } else if (includeUnmapped && !oncoreValue) {
                    form_data.push({
                        'field_name': redcapField,
                        'redcap': { 'value': redcapValue, 'selected': false },
                        'oncore': { 'value': '', 'selected': false },
                        'unmapped': true
                    });
                } else if (!redcapValue && oncoreValue) {
                    form_data.push({
                        'field_name': redcapField,
                        'redcap': { 'value': redcapValue, 'selected': false },
                        'oncore': { 'value': oncoreValue, 'selected': true },
                        'unmapped': false
                    });
                } else if (redcapValue === oncoreValue) {
                    form_data.push({
                        'field_name': redcapField,
                        'redcap': { 'value': redcapValue, 'selected': false },
                        'oncore': { 'value': oncoreValue, 'selected': false },
                        'unmapped': false
                    });
                    matched++;
                } else {
                    form_data.push({
                        'field_name': redcapField,
                        'redcap': { 'value': redcapValue, 'selected': true },
                        'oncore': { 'value': oncoreValue, 'selected': false },
                        'unmapped': false
                    });
                }
            });

            experimental[form] = form_data;
        });

        if (totalMappedFields > 0 && matched === totalMappedFields) {
            return true;
        }

        if (show) {
            showComparisonTable(experimental, record);
        } else {
            toSave[record.record_id] = experimental;
        }
    }

    function get_eIRBs() {
        $.ajax({
            url: '<?= $module->getUrl("scripts/get_eirbs.php") ?>',
            success: function (data) {
                console.log(data);
            },
            error: function (xhr, status, error) {
                console.error('Error fetching REDCap record:', error, xhr.responseText);
            }
        });
    }

    // Runs a request to a script saving comparisons to the config
    function track_adjudicates(adjudicates) {
        $.ajax({
            url: '<?= $module->getUrl("scripts/track_adjudicates.php") ?>',
            method: 'POST',
            data: {
                pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                redcap_csrf_token: <?= json_encode($csrf) ?>,
                'to-adjudicate': JSON.stringify(adjudicates)
            },
            success: function (data) {
                console.log(data.message);
                console.log(data.data);
            },
            error: function (xhr, status, error) {
                console.error('Error saving comparisons:', error, xhr.responseText);
            }
        });
    }

    function save_metadata(metadata) {
        $.ajax({
            url: '<?= $module->getUrl("scripts/save_metadata.php") ?>',
            method: 'POST',
            data: {
                pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                redcap_csrf_token: <?= json_encode($csrf) ?>,
                'adj-metadata': JSON.stringify(metadata)
            },
            success: function (data) {
                console.log(data.message);
                console.log(data.data);
            },
            error: function (xhr, status, error) {
                console.error('Error saving comparisons:', error, xhr.responseText);
            }
        });
    }

    function load_adjudicates() {
        $.ajax({
            url: '<?= $module->getUrl("scripts/load_adjudicates.php") ?>',
            method: 'GET',
            success: function (data) {
                console.log(data.message);
                console.log(data.data);
                return data;
            },
            error: function (xhr, status, error) {
                console.error('Error saving comparisons:', error, xhr.responseText);
            }
        });
    }

</script>