<?php
/** @var \ExternalModules\AbstractExternalModule $module */
$page = "field-mapping";
$instruments = REDCap::getInstrumentNames();
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
                </div>
            </a>
        </div>
    </div>
    <div id="sync_list" class="row" style="flex-direction: column;">
    </div>
</div>

<script>
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

                        if (redcapValue != oncoreValue) {
                            comparisons.push({ redcapField, oncoreField, redcapValue, oncoreValue });
                        } else if (!redcapValue && oncoreValue) {
                            record[redcapField] = oncoreValue;
                        }
                    });
                });

                console.log('Record mapped with OnCore data: ', record);
            },
            error: function (xhr, status, error) {
                console.error('Error fetching protocols:', error, xhr.responseText);
            }
        });

        return record;
    }

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