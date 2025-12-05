    /* Save a checkpoint of the user's currently configured mappings. */    
    function checkpoint() {
        const mapping = {};

        document.querySelectorAll('table').forEach(table => {
            const instrument = table.id;
            const instrumentMapping = {};
            
            if (!instrument) {
                return;
            }
            
            table.querySelectorAll('select').forEach(select => {
                const redcapField = select.name;
                const selectedValue = select.value;
                instrumentMapping[redcapField] = selectedValue;
            });

            mapping[instrument] = instrumentMapping;
        });

        // Send to server - include the displayed array
        $.ajax({
            url: "<?= $module->getUrl('scripts/save_mappings.php') ?>",
            method: "POST",
            data: {
                pid: project_id,
                redcap_csrf_token: <?php json_encode($csrf) ?>, // this is defined in module context, so gotta figure out a global method? Or call a php fnc?
                mappings: JSON.stringify(mapping),
                displayed: JSON.stringify(displayed)  // <-- Save which forms should be displayed
            },
            success: function (result) {
                console.log("Checkpoint saved:", result);
            },
            error: function (xhr, status, error) {
                console.error("Error in checkpoint:", error, xhr.responseText);
            }
        });
    }

    /* Load the saved checkpoint of mappings from the project's module config. */
    function load_checkpoint() {
        $.ajax({
            url: "<?= $module->getUrl('scripts/load_mappings.php') ?>",
            method: "GET",
            dataType: "json",
            success: function (result) {
                if (result.status !== 'success') {
                    console.error('Failed to load mappings:', result.error || 'Unknown error');
                    const loader = document.getElementById('forms-loader');
                    if (loader) {
                        loader.innerHTML = '<p style="color: red; text-align: center;"><b>Error:</b> Could not load saved field mappings.</p>';
                    }
                    return;
                }

                mappings = result.data || {};
                const savedDisplayed = result.displayed || []; // <-- Load the saved displayed list

                displayed.length = 0;
                // Use the saved displayed list instead of mapping keys
                savedDisplayed.forEach(instrumentKey => {
                    if (instruments[instrumentKey]) {
                        displayed.push(instrumentKey);
                    }
                });

                rebuild_from_mappings(instruments, dictionary, mappings);
            },
            error: function (xhr, status, error) {
                console.error('Error loading saved mappings:', error, xhr.responseText);
                const loader = document.getElementById('forms-loader');
                if (loader) {
                    loader.innerHTML = '<p style="color: red; text-align: center;"><b>Error:</b> Could not load saved field mappings.</p>';
                }
            }
        });
    }