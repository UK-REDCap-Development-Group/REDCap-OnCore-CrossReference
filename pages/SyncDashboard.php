<?php
/** @var \ExternalModules\AbstractExternalModule $module */
$page = "field-mapping";
$instruments = REDCap::getInstrumentNames();
$csrf = $module->getCSRFToken();

// Will use this value later for batching requests if max_input is hit
$maxInputVars = ini_get('max_input_vars') ?: 1000;
?>
<link rel="stylesheet" href="<?= $module->getUrl('css/sync-dashboard.css') ?>">
<link rel="stylesheet" href="<?= $module->getUrl('css/modals.css') ?>">

<script>
    function buildTables(records) {
        let body = document.getElementById('adjudicates_body')
        let i = 1;

        records.forEach((record) => {
            let row = document.createElement('tr');
            row.classList = i % 2 !== 0 ? 'odd' : 'even';

            row.innerHTML =
                `<td style='width: 50% !important;'>
                    ${record.record_id}
                 </td>
                 <td style='width: 50% !important;'>
                    ${record.full_title}
                 </td>
                 <td style='width: 50% !important;' class='btn-panel'>
                     <a id='fix-btn' class='fix-btn'>
                        <span><i class="fas fa-wrench"></i></span>
                        fix
                     </a>
                     <a id='ignore-btn' class='ignore-btn'>
                        <span><i class="fas fa-ban"></i></span>
                        ignore
                     </a>
                 </td>`;
            i++;

            body.appendChild(row);

            // Implement functionality of the btns
            document.getElementById('fix-btn').addEventListener('click', () => {
                adjudication(record);
            });

            document.getElementById('ignore-btn').addEventListener('click', () => {
                ignore(record);
            });
        });
    }
</script>

<div class="d-flex container" style="flex-direction: column;">
    <div id="instruments_list" class="row" style="flex-direction: column;">

    </div>
    <div class="row" style="padding-top: 10pt;">
        <div class="center-home-sects">
            <table id="adjudicates_table" class="dataTable cell-border no-footer">
                <thead>
                <tr>
                    <th class="record_id">Record ID</th>
                    <th class="title">Title</th>
                    <th class="options">Options</th>
                </tr>
                </thead>
                <tbody id="adjudicates_body">

                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    // provides a modal to click which fields should be kept from which source
    function adjudication(record) {
        console.log(record);
        comparisonModal(record);
    }

    // ignore record from adjudication process. gives the option to skip it this time, or mark it off from being synced.
    function ignore(record_id) {

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

    /* Build out the comparison Modal when adjudication is required */
    function comparisonModal(record) {
        let selections = {};

        // Replace any existing modal before opening a new one
        const existing = document.querySelector('.modal-overlay');
        if (existing) existing.remove();

        const built = buildModal();
        const { modalOverlay, modalBox } = built;

        let modalContent = `
            <h2>Record Comparison</h2>
            <p>Please select the entry with the most accurate information.</p>
            <div class="modal-comparison-grid">
                <div class="modal-column">
                    <div class="modal-record-header">REDCap</div>
                    <table class="myDataTable dataTable cell-border no-footer" id="redcap_table">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>REDCap Value</th>
                                <th>OnCore Value</th>
                            </tr>
                        </thead>
                        <tbody>
        `;

        let i=1;
        Object.entries(record).forEach(([key, value]) => {
            if (typeof value === "object") {
                modalContent += `
                <tr class="${i % 2 !== 0 ? 'odd' : 'even'}">
                    <td><a>${key}</a></td>
                    <td><a id="option-btn">${value.REDCapValue}</a></td>
                    <td><a id="option-btn">${value.OnCoreValue}</a></td>
                </tr>
            `;
            } else {
                modalContent += `
                <tr class="${i % 2 !== 0 ? 'odd' : 'even'}">
                    <td>${key}</td>
                    <td>${value}</td>
                    <td>No mapped data</td>
                </tr>
            `;
            }

            i++;

            /*// When one is selected, insert that value into a masterlist which is saved at the end
            document.getElementById('option-btn').addEventListener('click', () => {

            });*/
        });

        modalContent += `
                        </tbody>
                    </table>
                    <button id="confirm-choices">Save Selected Data</button>
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

        document.getElementById('confirm-choices').addEventListener('click', () => {
            confirmationModal(selections);
            closeModal();
        });

        //document.getElementById('close_btn').addEventListener('click', closeModal);
        modalOverlay.addEventListener('click', (event) => {
            if (event.target === modalOverlay) closeModal();
        });
    }

    function confirmationModal(choices) {

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

    document.addEventListener('DOMContentLoaded', () => {
        // Load the things that need adjudicated
        $.ajax({
            url: "<?= $module->getUrl('scripts/load_adjudicates.php') ?>",
            method: "GET",
            dataType: "json",
            success: function (result) {
                console.log(result);
                console.log(result.status);
                if (result.status !== 'success') {
                    console.error('Failed to load mismatches:', result.error || 'Unknown error');
                    const loader = document.getElementById('forms-loader');
                    if (loader) {
                        loader.innerHTML = '<p style="color: red; text-align: center;"><b>Error:</b> Could not load tracked records.</p>';
                    }
                    return;
                }

                //console.log(result.data);
                buildTables(result.data); // load the records directly into the table builder
            },
            error: function (xhr, status, error) {
                console.error('Error loading saved mismatches:', error, xhr.responseText);
                const loader = document.getElementById('forms-loader');
                if (loader) {
                    loader.innerHTML = '<p style="color: red; text-align: center;"><b>Error:</b> Could not load saved record mismatches.</p>';
                }
            }
        });
    })

</script>