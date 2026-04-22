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
    </div>
</div>

<script>
    // TODO: fnc that builds records_list table from the flagged records saved in the config
    // TODO: fnc that loops the checker code from FieldMappings.php
    // TODO: fnc that fires when a record is selected to allow a user to adjudicate

    /* Save an instance of adjudication for review */
    function trackInstance() {

    }

    document.getElementById('sync-btn').addEventListener('click', () => {
        //modalTest(redcap_record, oncore_record, handleRecordSelection);
        //let oncore = getFromOnCore(11, protocolNo); // manual test using Saltzman record
        getFromREDCap('eirb_number', eIRBno);
        trackInstance();
    });

</script>