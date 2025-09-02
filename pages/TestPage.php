<?php
/** @var \ExternalModules\AbstractExternalModule $module */
$page = "test-page";
?>

<div>
    <button id="testAPI" onclick=checkProtocolsAPI('14999')>Test API Connection</button>
    <div id="apiOutput">

    </div>
</div>

<script>
    function checkProtocolsAPI(protocol_id) {
        const url = `<?= $module->getUrl("proxy.php") ?>&action=protocols&parameters[]=${protocol_id}`

        let response = fetch(url)
            .then(data => {
                console.log(data);
            })
    }
</script>