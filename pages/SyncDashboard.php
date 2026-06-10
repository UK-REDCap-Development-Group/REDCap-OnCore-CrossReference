<?php
/** @var \ExternalModules\AbstractExternalModule $module */
$page = "field-mapping";
$instruments = REDCap::getInstrumentNames();

include "scripts/scripts.php";
?>

<link rel="stylesheet" href="<?= $module->getUrl('css/field_mappings.css') ?>">
<script type="text/javascript" src="<?= $module->getUrl("js/requests.js") ?>"></script>

<div class="d-flex container" style="flex-direction: column;">
    <div class="row selection-btns">
        <div class="col-md-9">
            <a id="sync-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-arrows-rotate"></i></span><br>
                    <h5>Sync with OnCore</h5>
                    <div id="records_list"></div>
                </div>
            </a>
        </div>
    </div>
    <div id="sync_list" class="row" style="flex-direction: column;">
        <div id="msg"></div>
        <select name="filter" id="filter">
            <option value="all">All</option>
            <option value="adjudicate">Need Attention</option>
            <option value="missing">Not in OnCore</option>
        </select>
        <table class="dataTable cell-border no-footer">
            <thead>
                <tr>
                    <th>Record ID</th>
                    <th>eIRB No.</th>
                    <th>Title (in REDCap)</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="sync_list_body">
            </tbody>
        </table>
    </div>
</div>

<script>
    // TODO: fnc that builds records_list table from the flagged records saved in the config
    // TODO: fnc that loops the checker code from FieldMappings.php
    // TODO: fnc that fires when a record is selected to allow a user to adjudicate

    /* Save an instance of adjudication for review */
    function trackInstance() {

    }

    document.addEventListener('DOMContentLoaded', async () => {
        let running = <?= json_encode($module->getProjectSetting('running')); ?>;
        const adjudicates = <?= json_encode($module->getProjectSetting('to-adjudicate')); ?>;

        console.log(running);

        if (running) {
            $(`<div title="System is Currently Running">Records are currently being checked against the OnCore database. The current state is based on data from the last synchronization. It is recommended that you come back in a little while to use the most current information.</div>`).dialog();
        }

        console.log(adjudicates);


        const tbody = document.getElementById('sync_list_body');
        let i = 1;
        for (const each of adjudicates) {
            console.log(each)
            let row = document.createElement('tr');
            row.classList = i % 2 !== 0 ? 'odd' : 'even';
            row.innerHTML = `
            <td>${each.record_id}</td>
            <td>${each.eirb_number}</td>
            <td><p class="citation" data-full="${each.title}">
                                            ${each.title.length > 100 ? each.title.slice(0, 100) + '...' : each.title}
                                            ${each.title.length > 100 ? '<span class="toggle" style="z-index:9999;"> more</span>' : ''}
                                        </p></td>
            <td>${each.status}</td>
            <td><button>Adjudicate</button><button>Ignore this time</button></td>
            `;
            tbody.appendChild(row);
            i++;
        }
        const msg = document.getElementById('msg');
        let records = 0;
        let successes = 0;
        let last_sync = "1/11/1111 @ 1:11 PM";
        msg.innerHTML = `<h3>${records} synced. ${successes} matched data in OnCore. Last sync completed: ${last_sync}</h3>`



        const syncBtn = document.getElementById('sync-btn');
        if (syncBtn) {
            syncBtn.addEventListener('click', () => {
                console.log(adjudicates);
                trackInstance();
            });
        }
    });

    document.addEventListener("click", function(e) {
        if (!e.target.classList.contains("toggle")) return;

        // STOP the click from bubbling up to the <label> and triggering the checkbox
        e.preventDefault();
        e.stopPropagation();

        const p = e.target.closest(".citation");
        const full = p.dataset.full;

        if (e.target.textContent.trim() === "more") {
            p.innerHTML = `${full} <span class="toggle" style="z-index:9999;"> less</span>`;
        } else {
            p.innerHTML = `${full.slice(0,100)}... <span class="toggle" style="z-index:9999;"> more</span>`;
        }
    });

</script>