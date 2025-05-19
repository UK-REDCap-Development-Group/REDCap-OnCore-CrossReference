<?php
$page = "field-mapping";
$instruments = REDCap::getInstrumentNames();
?>

<div class="d-flex container" style="flex-direction: column;">
    <!--<div class="col" style="max-width:50% !important;">
        <h1>REDCap</h1>
    </div>
    <div class="col" style="max-width:50% !important;">
        <h1>OnCore</h1>
    </div>-->
    <div id="instruments_list" class="row" style="flex-direction: column;">

    </div>
    <div class="row" style="padding-top: 10pt;">
        <button>Sync with OnCore</button>
    </div>
</div>

<script>
    const inst_list = document.getElementById('instruments_list');

    console.log(dictionary);

    // Loop through key value pairs for display on frontend
    Object.entries(instruments).forEach(([key, value]) => {
        let table = document.createElement('table');
        table.id = key + '_fields';
        table.classList = "dataTable cell-border no-footer";
        inst_list.appendChild(table);

        let header = document.createElement('thead');
        header.id = key + '_header';
        header.innerHTML =
            `
                <tr>
                    <th rowspan='1' colspan="1" class='sorting_disabled'>
                        ${value} Fields
                    </th>
                    <th rowspan='1' colspan="1" class='sorting_disabled'>
                        OnCore Field
                    </th>
                </tr>
            `;
        table.appendChild(header);

        let tbody = document.createElement('tbody');
        table.appendChild(tbody);

        // Loop through the fields for each instrument
        let i = 1;
        Object.entries(dictionary[key]).forEach(([key2, value2]) => {
            let row = document.createElement('tr');
            if ( i % 2 !== 0) {
                row.classList = 'odd';
            } else {
                row.classList = 'even';
            }

            row.innerHTML =
                `<td style='width: 50% !important;'>
                    <label for='${key2}'>${key2}</label>
                 </td>
                 <td style='width: 50% !important;'>
                     <select name='${key2}' id='${key2}'>
                     </select>
                 </td>`;

            table.appendChild(row);

            i = i + 1;
        });


    });

</script>