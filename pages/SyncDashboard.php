<?php
/** @var \ExternalModules\AbstractExternalModule $module */
$page = "field-mapping";
$instruments = REDCap::getInstrumentNames();
$csrf = $module->getCSRFToken();

// Will use this value later for batching requests if max_input is hit
$maxInputVars = ini_get('max_input_vars') ?: 1000;
?>

<script type="text/javascript" src="<?= $module->getUrl("js/requests.js") ?>"></script>
<script>
    // When this page is initialized, we want to pull in the records requiring adjudication ASAP
    document.addEventListener('load', () => {
        $.ajax({
            url: "<?= $module->getUrl('scripts/load_adjudicates.php') ?>",
            method: "GET",
            dataType: "json",
            success: function (result) {
                if (result.status !== 'success') {
                    console.error('Failed to load mismatches:', result.error || 'Unknown error');
                    const loader = document.getElementById('forms-loader');
                    if (loader) {
                        loader.innerHTML = '<p style="color: red; text-align: center;"><b>Error:</b> Could not load tracked records.</p>';
                    }
                    return;
                }

                console.log(result.data);
                buildTables(result.data.records); // load the records directly into the table builder
            },
            error: function (xhr, status, error) {
                console.error('Error loading saved mismatches:', error, xhr.responseText);
                const loader = document.getElementById('forms-loader');
                if (loader) {
                    loader.innerHTML = '<p style="color: red; text-align: center;"><b>Error:</b> Could not load saved record mismatches.</p>';
                }
            }
        });
    });

    function buildTables(records) {
        let body = document.getElementById('adjudicates_body')
        let i = 1;
        Object.entries(records).forEach((record) => {
            let row = document.createElement('tr');
            row.classList = i % 2 !== 0 ? 'odd' : 'even';

            row.innerHTML =
                `<td style='width: 50% !important;'>
                    <label for='${record.record_id}'>${record.title}</label>
                 </td>
                 <td style='width: 50% !important;'>
                     <button onclick="adjudication(record.record_id)">fix</button>
                     <button onclick="ignore(record.record_id)">ignore</button>
                 </td>`;
            i++;

            body.appendChild(row);
        });
    }
</script>

<div class="d-flex container" style="flex-direction: column;">
    <div id="instruments_list" class="row" style="flex-direction: column;">

    </div>
    <div class="row" style="padding-top: 10pt;">
        <div class="col-md-6">
            <a id="sync-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <table id="adjudicates_table" class="dataTable cell-border no-footer">
                        <thead>
                            <tr>
                                <th>Record Details</th>
                                <th>Options</th>
                            </tr>
                        </thead>
                        <tbody id="adjudicates_body">

                        </tbody>
                    </table>
                </div>
            </a>
        </div>
    </div>
</div>

<script>
    // provides a modal to click which fields should be kept from which source
    function adjudication(record_id) {

    }

    // ignore record from adjudication process. gives the option ti skip it this time, or mark it off from being synced.
    function ignore(record_id) {

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

                Object.entries(mismatches).forEach(([form, fields]) => {
                    Object.entries(fields).forEach(([redcapField, oncoreField]) => {
                        if (oncoreField === '') return;

                        const redcapValue = record[redcapField];
                        const oncoreValue = dict[oncoreField];

                        originals[redcapField] = record[redcapField];

                        if (redcapValue != oncoreValue) {
                            comparisons.push({ redcapField, oncoreField, redcapValue, oncoreValue });
                        } else if (!redcapValue && oncoreValue) {
                            record[redcapField] = oncoreValue;
                        }
                    });
                });

                // Sequentially show comparison modals
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
                }

                console.log('Record mapped with OnCore data: ', record);
            },
            error: function (xhr, status, error) {
                console.error('Error fetching protocols:', error, xhr.responseText);
            }
        });

        return record;
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

                // Load existing mismatches
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