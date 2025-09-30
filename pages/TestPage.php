<?php
/** @var \ExternalModules\AbstractExternalModule $module */
$page = "test-page";
?>

<div>
<!--    <button id="testAPI" onclick=checkProtocolsAPI('IMID-22-BNT162-21')>Test API Connection</button>-->
    <button id="testAPI" onclick=checkProtocolsAPI('01-BMT-131')>Test API Connection</button>
    <div id="apiOutput">

    </div>
</div>

<script>
    function checkProtocolsAPI(protocol_id) {
        const url = `<?= $module->getUrl("proxy.php") ?>&action=protocols&protocolNo=${protocol_id}`

        fetch(url)
            .then(response => response.json())
            .then(data => {
                let output_div = document.getElementById('apiOutput');
                output_div.innerHTML = `<pre style="
                background:#f4f4f4;
                padding:10px;
                border-radius:6px;
                font-family:monospace;
                white-space:pre-wrap;
                word-break:break-word;
            ">${JSON.stringify(data, null, 2)}</pre>`;
            })
            .catch(error => {
                console.error('Error fetching protocols:', error);
            });
    }
</script>