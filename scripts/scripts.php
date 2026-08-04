<?php
use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;

$module = ExternalModules::getModuleInstance('REDCap-OnCore-CrossReference'); // replace with your module directory

$csrf = $module->getCSRFToken();

$webroot = APP_PATH_WEBROOT . 'redcap_v' . REDCAP_VERSION . '/';

// Generate the URL for your AJAX logging endpoint
$logAjaxUrl = $this->getUrl('scripts/log_event.php');

$user_rights = \REDCap::getUserRights(USERID);
$can_adjudicate = (SUPER_USER || ($user_rights[USERID]['data_entry'] >= 1));
?>
<link rel="stylesheet" href="<?= $module->getUrl('css/field_mappings.css') ?>">
<script>
    const canAdjudicate = <?= json_encode($can_adjudicate) ?>;

    // REDCap sets a global CSRF token we will need later
    window.EM_LOG_URL = '<?= $module->getUrl('scripts/log_event.php') ?>';
    window.redcap_csrf_token = '<?= $csrf ?>';

    const irb_field = <?= json_encode($module->getProjectSetting('irb-field') ?: 'eirb_number') ?>;
    const protocol_field = <?= json_encode($module->getProjectSetting('protocol-field') ?: 'rocs_protocol_number') ?>;
    const title_field = <?= json_encode($module->getProjectSetting('title-field') ?: 'full_title') ?>;
    const dashboard_fields = <?= json_encode(array_values(array_unique(array_merge(
        (array) ($module->getProjectSetting('dashboard-fields') ?: []),
        [$module->getProjectSetting('title-field') ?: 'full_title']
    )))) ?>;

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

    // Nested object members are written with dots and arrays are written with
    // [], e.g. staff[].contact.firstName. A mapping is deliberately the broadest
    // reading of the API: [] returns every value the endpoint gave back, joined
    // with "; ". Narrowing a multi-value field down to one entry is a per-record
    // decision and is made in the adjudication view, not here.
    //
    // A path may also be hand-written as [key=value] to read only the element
    // whose "key" equals "value", e.g.
    // staff[staffRole=Principal Investigator].contact.firstName. Nothing
    // generates that form, but saved mappings using it still resolve.
    //
    // Keep this in sync with classes/OnCoreFieldPath.php, which reads the same
    // saved paths server-side during a sync.

    // A key whose last word is one of these identifies a record rather than
    // describing it, so it reads poorly as a label. contactId, protocolNo and
    // staff_code are all rejected.
    const ONCORE_IDENTIFIER_WORDS = new Set(['id','ids','guid','uuid','key','code','no','num','number']);
    // A key whose last word is one of these describes the role an element plays
    // and is preferred over any other candidate.
    const ONCORE_PREFERRED_WORDS = new Set(['role','type','category','status','name','position','title']);
    const ONCORE_LABEL_MAX_LENGTH = 100;

    // Last word of a field name, lowercased. Handles camelCase and snake_case,
    // so staffRole, staff_role and StaffRole all yield "role".
    function oncoreKeyLastWord(key) {
        const words = String(key)
            .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
            .split(/[^A-Za-z0-9]+/)
            .filter(word => word !== '');

        return words.length ? words[words.length - 1].toLowerCase() : '';
    }

    // Choose the field that best says which entry of a list is which, so the
    // adjudication view can label "Lovelace" as "Principal Investigator".
    // A usable key is present on every entry, holds a short non-empty string, is
    // not an identifier, and does not read the same on every entry.
    function oncoreLabelKey(list) {
        if (!Array.isArray(list) || list.length < 2) return null;

        const first = list[0];
        if (!first || typeof first !== 'object' || Array.isArray(first)) return null;

        let best = null;
        let bestScore = null;

        Object.keys(first).forEach(key => {
            if (ONCORE_IDENTIFIER_WORDS.has(oncoreKeyLastWord(key))) return;

            const seen = new Set();
            const usable = list.every(element => {
                if (!element || typeof element !== 'object' || Array.isArray(element)) return false;
                if (!Object.prototype.hasOwnProperty.call(element, key)) return false;

                const value = element[key];
                if (typeof value !== 'string') return false;
                if (value.trim() === '' || value.length > ONCORE_LABEL_MAX_LENGTH) return false;

                seen.add(value);
                return true;
            });

            // A key reading the same on every entry tells the two apart no
            // better than no label at all.
            if (!usable || seen.size < 2) return;

            const score = ONCORE_PREFERRED_WORDS.has(oncoreKeyLastWord(key)) ? 0 : 1;
            if (bestScore === null || score < bestScore) {
                best = key;
                bestScore = score;
            }
        });

        return best;
    }

    function discoverOncoreFields(data, path = '') {
        if (Array.isArray(data)) {
            return data.flatMap(item => discoverOncoreFields(item, `${path}[]`));
        }

        if (data && typeof data === 'object') {
            return Object.entries(data).flatMap(([key, value]) => {
                const childPath = path ? `${path}.${key}` : key;
                return discoverOncoreFields(value, childPath);
            });
        }

        // A null field is still a field the API exposes and should be mappable.
        return path ? [path] : [];
    }

    // Split a path into tokens of {type:'key',name} | {type:'all'} |
    // {type:'filter',key,value}. Inside a selector a backslash escapes the next
    // character, so "]" and "\" can appear in a value.
    function oncorePathTokens(path) {
        const tokens = [];
        let buffer = '';

        const flush = () => {
            if (buffer !== '') {
                tokens.push({ type: 'key', name: buffer });
                buffer = '';
            }
        };

        for (let position = 0; position < path.length; position++) {
            const character = path[position];

            if (character === '.') {
                flush();
                continue;
            }

            if (character === '[') {
                flush();

                let contents = '';
                position++;
                while (position < path.length && path[position] !== ']') {
                    if (path[position] === '\\' && position + 1 < path.length) position++;
                    contents += path[position];
                    position++;
                }

                const separator = contents.indexOf('=');
                if (contents === '' || separator === -1) {
                    // Unparseable selector: fall back to every element rather
                    // than silently dropping the mapping.
                    tokens.push({ type: 'all' });
                } else {
                    tokens.push({
                        type: 'filter',
                        key: contents.slice(0, separator),
                        value: contents.slice(separator + 1)
                    });
                }

                continue;
            }

            buffer += character;
        }

        flush();

        return tokens;
    }

    // Read a saved path as the individual values behind it, each tagged with a
    // label saying which entry of the list it came from. Returns
    // [{value, label}] with blanks dropped and duplicate values collapsed.
    // oncorePathValue is this joined with "; ", so the two can never disagree.
    function oncorePathEntries(data, path) {
        if (!path) return [];

        // Keep existing top-level mappings working, including mappings created
        // when a list response was previously reduced to its first item.
        if (!Array.isArray(data) && data && Object.prototype.hasOwnProperty.call(data, path)) {
            const value = stringifyOncoreValue(data[path]);
            return value === '' ? [] : [{ value, label: '' }];
        }

        const normalizedPath = Array.isArray(data) && !/[.\[\]]/.test(path)
            ? `[].${path}`
            : path;
        const tokens = oncorePathTokens(normalizedPath);
        if (!tokens.length) return [];

        const joinLabels = (...parts) => parts.filter(part => part !== null && part !== undefined && part !== '').join(' / ');

        let entries = [{ value: data, label: '' }];
        tokens.forEach(token => {
            entries = entries.flatMap(entry => {
                const value = entry.value;

                if (token.type === 'all') {
                    if (!Array.isArray(value)) return [];

                    // Label each entry by whichever field best tells the
                    // entries of this particular list apart.
                    const labelKey = oncoreLabelKey(value);
                    return value.map(item => ({
                        value: item,
                        label: joinLabels(entry.label, labelKey && item && typeof item === 'object'
                            ? item[labelKey]
                            : '')
                    }));
                }

                if (token.type === 'filter') {
                    if (!Array.isArray(value)) return [];
                    return value
                        .filter(item => item
                            && typeof item === 'object'
                            && Object.prototype.hasOwnProperty.call(item, token.key)
                            && stringifyOncoreScalar(item[token.key]) === token.value)
                        .map(item => ({ value: item, label: joinLabels(entry.label, token.value) }));
                }

                return value && typeof value === 'object' && Object.prototype.hasOwnProperty.call(value, token.name)
                    ? [{ value: value[token.name], label: entry.label }]
                    : [];
            });
        });

        const seen = new Set();
        return entries
            .map(entry => {
                const value = stringifyOncoreScalar(entry.value);
                // A label repeating the value it sits above says nothing.
                return { value, label: entry.label === value ? '' : entry.label };
            })
            .filter(entry => {
                if (entry.value === '' || seen.has(entry.value)) return false;
                seen.add(entry.value);
                return true;
            });
    }

    function oncorePathValue(data, path) {
        return oncorePathEntries(data, path).map(entry => entry.value).join('; ');
    }

    function stringifyOncoreValue(value) {
        return value && typeof value === 'object' ? JSON.stringify(value) : stringifyOncoreScalar(value);
    }

    function stringifyOncoreScalar(value) {
        if (value === null || value === undefined) return '';
        if (value === true) return 'true';
        if (value === false) return 'false';
        return typeof value === 'object' ? '' : String(value);
    }

    function hasOncoreValue(value) {
        return value !== null && value !== undefined && value !== '';
    }

    function firstOncoreRecord(data) {
        return Array.isArray(data) ? (data.find(item => item && typeof item === 'object') || null) : data;
    }

    // Protocol staff and sponsors contain IDs, while the displayable fields
    // live in separate API resources. Cache lookups for the duration of the
    // page so a contact/sponsor shared by multiple records is only requested once.
    const relatedOncoreRecordCache = new Map();

    function asOncoreRecords(data) {
        if (Array.isArray(data)) return data.filter(item => item && typeof item === 'object');
        return data && typeof data === 'object' ? [data] : [];
    }

    function mapOncoreRecords(data, mapper) {
        if (Array.isArray(data)) return data.map(item => item && typeof item === 'object' ? mapper(item) : item);
        return data && typeof data === 'object' ? mapper(data) : data;
    }

    function contactWithDisplayName(contact) {
        const displayName = [contact.firstName, contact.middleName, contact.lastName]
            .filter(value => value !== null && value !== undefined && value !== '')
            .join(' ');
        return displayName ? { ...contact, displayName } : contact;
    }

    async function fetchRelatedOncoreRecords(action, parameter, ids) {
        const uniqueIds = [...new Set(ids
            .filter(id => id !== null && id !== undefined && id !== '')
            .map(id => String(id)))];

        await Promise.all(uniqueIds.map(async id => {
            const cacheKey = `${action}:${id}`;
            if (relatedOncoreRecordCache.has(cacheKey)) return;

            const response = await safeFetchOncore(action, `&${parameter}=${encodeURIComponent(id)}`);
            relatedOncoreRecordCache.set(cacheKey, response.success ? firstOncoreRecord(response.data) : null);
        }));

        return new Map(uniqueIds.map(id => [id, relatedOncoreRecordCache.get(`${action}:${id}`) || null]));
    }

    async function enrichRelatedOncoreData(oncoreDataByEndpoint) {
        const enrichedData = { ...oncoreDataByEndpoint };
        const staff = asOncoreRecords(enrichedData.protocolStaff);
        const protocolSponsors = asOncoreRecords(enrichedData.protocolSponsors);

        const [contactsById, sponsorsById] = await Promise.all([
            fetchRelatedOncoreRecords('contacts', 'contactId', staff.map(member => member.contactId)),
            fetchRelatedOncoreRecords('sponsors', 'sponsorId', protocolSponsors.map(sponsor => sponsor.sponsorId))
        ]);

        enrichedData.protocolStaff = mapOncoreRecords(enrichedData.protocolStaff, staffMember => {
            const contact = contactsById.get(String(staffMember.contactId));
            return contact ? { ...staffMember, contact: contactWithDisplayName(contact) } : staffMember;
        });
        enrichedData.protocolSponsors = mapOncoreRecords(enrichedData.protocolSponsors, protocolSponsor => {
            const sponsor = sponsorsById.get(String(protocolSponsor.sponsorId));
            return sponsor ? { ...protocolSponsor, sponsor } : protocolSponsor;
        });

        return enrichedData;
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
        let selectedValues = { ...record };

        const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#039;',
            '"': '&quot;'
        })[character]);
        const encodedValue = value => encodeURIComponent(String(value ?? ''));

        // Replace any existing modal before opening a new one
        const existing = document.querySelector('.modal-overlay');
        if (existing) existing.remove();

        const built = buildModal();
        const { modalOverlay, modalBox } = built;
        modalBox.classList.add('modal-comparison-box');

        let webroot = <?= json_encode($webroot)?>;
        let modalContent = `
            <h1>Adjudication for Record <a href="${webroot}DataEntry/record_home.php?pid=${pid}&id=${encodeURIComponent(record.record_id)}" target="_blank" class="hyperlink">${escapeHtml(record.record_id)}</a></h1>
            <p class="modal-comparison-intro">Choose the source to save for each highlighted field. Where OnCore returned several values, pick one of them or keep the whole list.</p>
            <div class="modal-comparison-table-wrap">
                <table class="myDataTable dataTable cell-border no-footer modal-comparison-table">
                    <thead>
                        <tr>
                            <th>Field Name</th>
                            <th>Field Label</th>
                            <th>REDCap Data</th>
                            <th>OnCore Data</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        Object.keys(comparisons).forEach(form => {
            const formName = instruments[form] || form;
            modalContent += `
                <tr class="modal-form-section">
                    <th colspan="4">${escapeHtml(formName)}</th>
                </tr>
            `;

            comparisons[form].forEach(set => {
                const field = set.field_name;
                const redcapValue = set.redcap.value ?? 'N/A';
                const oncoreValue = set.oncore.value ?? 'N/A';
                const fieldLabel = dictionary?.[form]?.[field]?.field_label || field;

                // A field mapped to a list arrives with its individual entries.
                // The mapping itself stays broad; choosing one entry is a
                // decision about this record and is made here.
                const options = Array.isArray(set.oncore?.options) ? set.oncore.options : [];
                const hasChoice = options.length > 1;

                // Define  visual and interactive states
                const isUnmapped = set.unmapped;
                const isMatched = redcapValue === oncoreValue;

                // Interactive if it is mapped AND either the values differ or
                // OnCore returned several entries to pick from.
                const isInteractive = !isUnmapped && (!isMatched || hasChoice);

                if (!isUnmapped) {
                    if (set.redcap.selected) {
                        selectedValues[field] = redcapValue;
                    } else if (set.oncore.selected) {
                        selectedValues[field] = oncoreValue;
                    }
                }

                /*const oncoreOptionsCell = () => `
                    <td class="oncore-options-cell">
                        <ul class="oncore-option-list">
                            <li class="selectable-cell oncore-option oncore-option-all ${set.oncore.selected ? 'selected' : ''}" data-source="${encodedValue(oncoreValue)}">
                                <span class="oncore-option-label">All ${options.length} values</span>
                                <span class="oncore-option-value">${escapeHtml(oncoreValue)}</span>
                            </li>
                            ${options.map(option => `
                                <li class="selectable-cell oncore-option" data-source="${encodedValue(option.value)}">
                                    ${option.label ? `<span class="oncore-option-label">${escapeHtml(option.label)}</span>` : ''}
                                    <span class="oncore-option-value">${escapeHtml(option.value)}</span>
                                </li>
                            `).join('')}
                        </ul>
                    </td>`;*/
                console.log(options);
                const oncoreOptionsCell = () => `
                    <td class="oncore-options-cell">
                        <div>${options.length} possible values detected</div>
                        <select name="${fieldLabel}-options"  id="${fieldLabel}-options" class="oncore-option-list">
                            ${options.map(option => `
                                <option class="selectable-cell oncore-option" data-source="${encodedValue(option.value)}">
                                    ${option.label ? `<span class="oncore-option-label">${escapeHtml(option.label)}</span>` : ''}
                                    <span class="oncore-option-value">${escapeHtml(option.value)}</span>
                                </option>
                            `).join('')}
                        </select>
                    </td>`;


                const oncoreSingleCell = () => `
                    <td class="selectable-cell ${set.oncore.selected ? 'selected' : ''}" data-source="${encodedValue(oncoreValue)}">${escapeHtml(oncoreValue)}</td>`;

                // Inject into the table, using `isInteractive` to decide the HTML
                modalContent += `
                    <tr data-field="${field}">
                        <td>${escapeHtml(field)}</td>
                        <td>${escapeHtml(fieldLabel)}</td>
                        ${isInteractive
                    ? `<td class="selectable-cell ${set.redcap.selected ? 'selected' : ''}" data-source="${encodedValue(redcapValue)}">${escapeHtml(redcapValue)}</td>
                               ${hasChoice ? oncoreOptionsCell() : oncoreSingleCell()}`
                    : `<td class="disabled-cell">${escapeHtml(redcapValue)}</td>
                               <td class="disabled-cell">${escapeHtml(oncoreValue)}</td>`
                }
                    </tr>
                    `;
            });
        });

        modalContent += `
                    </tbody>
                </table>
            </div>
            <div class="modal-comparison-actions">
                <button type="button" id="overwrite" class="adj-button">Save Selected Data to REDCap</button>
                <button type="button" id="cancel-adjudication" class="close-button">Cancel</button>
            </div>
        `;

        modalBox.innerHTML = modalContent;
        modalOverlay.appendChild(modalBox);
        document.body.appendChild(modalOverlay);

        modalBox.addEventListener('click', (e) => {
            const cell = e.target.closest('.selectable-cell');
            if (!cell) return;

            const row = cell.closest('tr');
            const field = row.dataset.field;
            // store selection
            selectedValues[field] = decodeURIComponent(cell.dataset.source || '');

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

        modalBox.querySelector('#cancel-adjudication').addEventListener('click', closeModal);

        modalBox.querySelector('#overwrite').addEventListener('click', async () => {
            const ok = confirm("WARNING: This action will overwrite existing REDCap data. Please double-check your selections.\n\nClick OK to proceed.");

            if (!ok) return; // user cancelled

            let jsonPayload;
            try {
                jsonPayload = JSON.stringify([selectedValues]);
            } catch (e) {
                console.error("Payload contains circular references or invalid data:", selectedValues);
                alert("System error: Could not process save data.");
                return;
            }

            $.ajax({
                url: "<?= $module->getUrl('scripts/save_record.php'); ?>",
                method: "POST",
                data: {
                    pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                    redcap_csrf_token: <?= json_encode($csrf) ?>,
                    record: jsonPayload // REDCap expects array
                },
                success: async function (result) {
                    console.log("Checkpoint saved:", result);

                    try {
                        await logModuleEvent("Data saved to REDCap Database", {
                            record_id: record.record_id,
                            saved_record: jsonPayload
                        });
                        console.log("Log sent successfully.");
                    } catch (err) {
                        console.error("Log failed, but data was saved:", err);
                    }

                    closeModal();
                    window.location.reload();
                },
                error: async function (xhr, status, error) {
                    try {
                        await logModuleEvent("Error occured saving record", {
                            error: error,
                            xhr: xhr.responseText
                        });
                        console.log("Log sent successfully.");
                    } catch (err) {
                        console.error("Log failed, but data was saved:", err);
                        console.error("Error in checkpoint:", error, xhr.responseText);
                    }
                }
            });
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

                if ((!record[irb_field] || record[irb_field] === "") && (!record[protocol_field] || record[protocol_field] === "")) {
                    alert('Please ensure to populate the Protocol Number or IRB Number field and SAVE the record before attempting to synchronize with OnCore.');
                    return;
                }

                let details;
                if (record[protocol_field] && record[protocol_field] !== "") {
                    details = await safeFetchOncore('protocolManagementDetails', `&protocolNo=${record[protocol_field]}`);
                } else {
                    details = await safeFetchOncore('protocolManagementDetails', `&irbNo=${record[irb_field]}`);
                }

                if (details.success && details.data) {
                    protocolId = details.data['protocolId'];
                    if ((!record[protocol_field] || record[protocol_field] === "") && details.data['protocolNo']) {
                        let savePayload = {};
                        savePayload['record_id'] = record.record_id;
                        savePayload[protocol_field] = details.data['protocolNo'];
                        $.ajax({
                            url: "<?= $module->getUrl('scripts/save_record.php'); ?>",
                            method: "POST",
                            data: {
                                pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                                redcap_csrf_token: <?= json_encode($csrf) ?>,
                                record: JSON.stringify([savePayload])
                            }
                        });
                        record[protocol_field] = details.data['protocolNo'];
                    }
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

                const enrichedOncoreData = await enrichRelatedOncoreData(oncoreDataByEndpoint);
                console.log('singleRecordSync', enrichedOncoreData);

                // Run the comparison logic once we have ALL the data
                runMappingComparison(record, enrichedOncoreData, show);
            },
            error: async function (xhr, status, error) {
                console.error('Error fetching REDCap record:', error, xhr.responseText);

                await logModuleEvent("Error fetching REDCap record", {
                    error: error,
                    xhr: xhr.responseText
                });
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
                // TODO: figure out how to run it in the background
                let toSave = [];
                let protocolId = null;

                let matched = 0;
                for (const record of data) {
                    console.log(record);

                    let details;
                    if (record[protocol_field] && record[protocol_field] !== "") {
                        details = await safeFetchOncore('protocolManagementDetails', `&protocolNo=${record[protocol_field]}`);
                    } else {
                        details = await safeFetchOncore('protocolManagementDetails', `&irbNo=${record[irb_field]}`);
                    }
                    console.log(details);

                    if (details.success && details.data) {
                        protocolId = details.data['protocolId'];
                        if ((!record[protocol_field] || record[protocol_field] === "") && details.data['protocolNo']) {
                            let savePayload = {};
                            savePayload['record_id'] = record.record_id;
                            savePayload[protocol_field] = details.data['protocolNo'];
                            $.ajax({
                                url: "<?= $module->getUrl('scripts/save_record.php'); ?>",
                                method: "POST",
                                data: {
                                    pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                                    redcap_csrf_token: <?= json_encode($csrf) ?>,
                                    record: JSON.stringify([savePayload])
                                }
                            });
                            record[protocol_field] = details.data['protocolNo'];
                        }
                    } else {
                        console.warn("Could not find a protocol with that eIRB number.");
                        
                        let custom_fields = {};
                        dashboard_fields.forEach(df => {
                            custom_fields[df] = record[df] || '';
                        });

                        toSave.push({
                            'record_id': record.record_id, 
                            'eirb_number': record[irb_field] || record[protocol_field], 
                            'title': record[title_field],
                            'custom_fields': custom_fields,
                            status: 'not in OnCore', 
                            message: 'The Protocol/IRB was not found in OnCore.'
                        });
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

                    const enrichedOncoreData = await enrichRelatedOncoreData(oncoreDataByEndpoint);

                    // Run the comparison! (Passing false so it doesn't trigger the modal UI)
                    const isPerfectMatch = runMappingComparison(record, enrichedOncoreData, false);

                    if (isPerfectMatch) {
                        // Increment the counter for your metadata payload
                        matched++;
                    } else {
                        let custom_fields = {};
                        dashboard_fields.forEach(df => {
                            custom_fields[df] = record[df] || '';
                        });

                        // Only push to 'toSave' if it actually needs adjudication
                        toSave.push({
                            'record_id': record.record_id,
                            'eirb_number': record[irb_field] || record[protocol_field],
                            'title': record[title_field],
                            'custom_fields': custom_fields,
                            'results': results,
                            status: 'needs attention',
                            message: 'OnCore data does not match data in REDCap.'
                        });
                    }

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
                try {
                    await logModuleEvent("Adjudicates collected", {});
                    console.log("Log sent successfully.");
                } catch (err) {
                    console.error("Log failed, but data was saved:", err);
                }
            },
            error: async function (xhr, status, error) {
                console.error('Error fetching REDCap record:', error, xhr.responseText);
                try {
                    await logModuleEvent("Error collecting adjudicates.", {
                        error: error,
                        xhr: xhr.responseText
                    });
                    console.log("Log sent successfully.");
                } catch (err) {
                    console.error("Log failed, but data was saved:", err);
                }
            }
        });
    }

    // Simple oncore request for page render, additional query defaults to null
    function fetchOncore(protocol, query='') {
        return $.ajax({
                url: `<?= $module->getUrl("oncore_proxy.php") ?>&action=${protocol}${query}`,
                method: "GET",
                dataType: "json",
            }).then(data => data === undefined || data === null ? {} : data);
    }

    // Even if a request fails, I still want the site to load
    function safeFetchOncore(protocol, query='') {
        return fetchOncore(protocol, query)
            .then(data => {
                // protocolManagementDetails is used to find the protocol ID;
                // every other endpoint must retain its complete response so
                // nested fields across all returned records are discoverable.
                const responseData = protocol === 'protocolManagementDetails'
                    ? firstOncoreRecord(data)
                    : data;
                const success = protocol === 'protocolManagementDetails'
                    ? Boolean(responseData && responseData.protocolId)
                    : !(responseData && !Array.isArray(responseData)
                        && typeof responseData === 'object'
                        && (responseData.error || responseData.success === false));
                return {success, data: responseData, message:"data successfully retrieved"};
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

        const dataByEndpoint = {};
        results.forEach(({ endpoint, response }) => {
            if (response.success && response.data !== null) {
                dataByEndpoint[endpoint] = response.data;
            }
        });
        const enrichedDataByEndpoint = await enrichRelatedOncoreData(dataByEndpoint);

        // Keep the endpoint with each path: common names such as "status"
        // appear in more than one endpoint and must remain separately mappable.
        const fieldRegistry = new Map();

        Object.entries(enrichedDataByEndpoint).forEach(([endpoint, data]) => {
            if (data !== null) {
                discoverOncoreFields(data).forEach(field => {
                    fieldRegistry.set(`${endpoint}\u0000${field}`, { field, endpoint });
                });
            }
        });

        const oncore_fields = [...fieldRegistry.values()]
            .sort((left, right) => left.endpoint.localeCompare(right.endpoint) || left.field.localeCompare(right.field));

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

                const redcapValue = record[redcapField] ?? '';

                // The mapping reads the whole list; the individual entries ride
                // along so the adjudication view can offer them one at a time.
                const oncoreEntries = oncorePathEntries(dict, oncoreFieldName);
                const oncoreValue = oncoreEntries.map(entry => entry.value).join('; ');

                const comparison = (oncoreShown, redcapSelected, oncoreSelected, unmapped) => {
                    const set = {
                        'field_name': redcapField,
                        'redcap': { 'value': redcapValue, 'selected': redcapSelected },
                        'oncore': { 'value': oncoreShown, 'selected': oncoreSelected },
                        'unmapped': unmapped
                    };

                    // Only worth carrying when there is a choice to make.
                    if (oncoreShown !== '' && oncoreEntries.length > 1) {
                        set.oncore.options = oncoreEntries;
                    }

                    form_data.push(set);
                };

                if (includeUnmapped && hasOncoreValue(oncoreValue)) {
                    comparison(oncoreValue, false, false, true);
                } else if (includeUnmapped && !hasOncoreValue(oncoreValue)) {
                    comparison('', false, false, true);
                } else if (!redcapValue && hasOncoreValue(oncoreValue)) {
                    comparison(oncoreValue, false, true, false);
                } else if (redcapValue === oncoreValue) {
                    comparison(oncoreValue, false, false, false);
                    matched++;
                } else {
                    comparison(oncoreValue, true, false, false);
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
            success: async function (data) {
                console.log(data.message);
                console.log(data.data);
                await logModuleEvent("Adjudicates added", {});
            },
            error: async function (xhr, status, error) {
                console.error('Error saving comparisons:', error, xhr.responseText);
                await logModuleEvent("Adjudicated failed to be added.", {
                    error: error,
                    xhr: xhr.responseText
                });
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

    /**
     * Triggers an external module log entry via AJAX
     * @param {string} actionName - The main log message
     * @param {object} paramsObject - The key/value parameters to log
     */
    async function logModuleEvent(actionName, paramsObject) {
        // Use the window-scoped variables
        const formData = new FormData();
        formData.append('action', actionName);
        formData.append('params', JSON.stringify(paramsObject));
        formData.append('redcap_csrf_token', window.redcap_csrf_token);

        try {
            const response = await fetch(window.EM_LOG_URL, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            console.log("Logged: ", result);
        } catch (error) {
            console.error("Log failed:", error);
        }
    }

    // Example Usage:
    // logModuleEvent("Manual Record Sync Initiated", {
    //      record_id: "405",
    //      direction: "push_to_oncore"
    // });

</script>
