<?php global $project_id;
/** @var \ExternalModules\AbstractExternalModule $module */
$page = "field-mapping";
$instruments = REDCap::getInstrumentNames();
$csrf = $module->getCSRFToken();

// Will use this value later for batching requests if max_input is hit
$maxInputVars = ini_get('max_input_vars') ?: 1000;

$protocol = $module->getProjectSetting('sample-protocol');
$eirb = $module->getProjectSetting('sample-eirb');

require_once "../../redcap_connect.php";

$authorized_users = $module->getProjectSetting('authorized-users');
if (!is_array($authorized_users)) $authorized_users = $authorized_users ? [$authorized_users] : [];

// If they aren't on the list, stop right here.
// They can see the link, but they cannot see the page.
if (!SUPER_USER && !in_array(USERID, $authorized_users)) {
    // Show a clean error, not a REDCap "Module Forbidden" screen
    header('HTTP/1.0 403 Forbidden');
    echo "<h3>Access Denied</h3>
      <p>You do not have permission to access ROCS tools. Please contact the project administrator. 
      <a href='" . APP_PATH_WEBROOT . "index.php?pid=" . $project_id . "'>Return to project home.</a></p>";
    exit;
}

// Now you can safely render your page
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
?>

<link rel="stylesheet" href="<?= $module->getUrl('css/field_mappings.css') ?>">

<script>
    // Get max_input from php.ini into a js workable variable
    const MAX_INPUT_VARS = <?= (int)$maxInputVars ?>;
    let displayed = []; // tracks displayed instruments
    let oncore_fields = null;
    const toSave = {}; // this stores adjudicates and saves them to config

    const eIRBno = <?= json_encode($eirb[0] ?? null) ?>;
    const protocolNoConfig = <?= json_encode($protocol[0] ?? null) ?>;
    const protocol_field_name = <?= json_encode($module->getProjectSetting('protocol-field') ?: 'rocs_protocol_number') ?>;
    const irb_field_name = <?= json_encode($module->getProjectSetting('irb-field') ?: 'eirb_number') ?>;

    let mappings = false;
    const contactId = ''; // not sure where I might find this

    // Inject PHP constants into JavaScript
    const REDCAP_WEBROOT = <?= json_encode(APP_PATH_WEBROOT) ?>;
    const PROJECT_ID = <?= json_encode($_GET['pid']) ?>;
</script>

<script>
    // A discovered path can run long - principalInvestigator.contact.lastName -
    // and a select full of them is hard to read. Split one into its segments so
    // the display can be shortened. Brackets are kept whole, since a hand
    // written [staffRole=Ph.D. Advisor] may contain dots of its own.
    function oncorePathSegments(path) {
        const segments = [];
        let buffer = '';

        for (let position = 0; position < path.length; position++) {
            const character = path[position];

            if (character === '.') {
                if (buffer !== '') segments.push(buffer);
                buffer = '';
                continue;
            }

            if (character === '[') {
                if (buffer !== '') segments.push(buffer);
                buffer = character;

                position++;
                while (position < path.length && path[position] !== ']') {
                    if (path[position] === '\\' && position + 1 < path.length) {
                        buffer += path[position];
                        position++;
                    }
                    buffer += path[position];
                    position++;
                }

                segments.push(buffer + ']');
                buffer = '';
                continue;
            }

            buffer += character;
        }

        if (buffer !== '') segments.push(buffer);

        return segments;
    }

    // ---- Plain-language labels ----
    // A raw path such as [].contact.lastName means nothing to someone opening the module for the
    // first time. Labels say the same thing in words - "All staff › Last name" - and the raw path
    // stays as secondary text and in the tooltip. Labels are display only: the saved mapping is
    // always the raw path.

    // What a list response from each endpoint holds, for "All ..." labels
    const ONCORE_ENDPOINT_NOUNS = {
        protocols: 'protocols',
        protocolConsents: 'consents',
        protocolSponsors: 'sponsors',
        protocolStaff: 'staff',
        protocolEprmsSubmissions: 'ePRMS submissions',
        protocolPrmcReviews: 'PRMC reviews',
        protocolIde: 'IDE records',
        protocolInd: 'IND records',
        protocolIrbReviews: 'IRB reviews',
        protocolInstitutions: 'institutions',
    };

    // Words written differently in a label than in a camelCase key
    const ONCORE_LABEL_WORDS = {
        id: 'ID', ids: 'IDs', no: 'number', num: 'number', irb: 'IRB', eirb: 'eIRB', nct: 'NCT',
        ind: 'IND', ide: 'IDE', prmc: 'PRMC', eprms: 'ePRMS', pi: 'PI', fda: 'FDA', nci: 'NCI',
        dsmb: 'DSMB', ctep: 'CTEP', ctsu: 'CTSU', url: 'URL', mrn: 'MRN', crc: 'CRC', dob: 'DOB',
    };

    // Objects the module itself nests into a response when it fetches related records (see
    // enrichRelatedOncoreData). They add nothing to a label: "All staff › Last name" rather
    // than "All staff › Contact › Last name".
    const ONCORE_LABEL_WRAPPERS = new Set(['contact', 'sponsor']);

    // principalInvestigator -> "principal investigator", irbNo -> "IRB number"
    function oncoreLabelWords(key) {
        return String(key)
            .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
            .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
            .split(/[^A-Za-z0-9]+/)
            .filter(Boolean)
            .map(word => ONCORE_LABEL_WORDS[word.toLowerCase()] || word.toLowerCase())
            .join(' ');
    }

    function capitalizeFirst(text) {
        return text ? text.charAt(0).toUpperCase() + text.slice(1) : text;
    }

    function oncoreEndpointNoun(endpoint) {
        if (ONCORE_ENDPOINT_NOUNS[endpoint]) return ONCORE_ENDPOINT_NOUNS[endpoint];
        const rest = String(endpoint || '').replace(/^protocol(?=[A-Z])/, '');
        return oncoreLabelWords(rest) || 'entries';
    }

    // A field reads every entry of a list - and so arrives as several values joined with "; " -
    // whenever its path passes through [].
    function oncoreFieldIsList(path) {
        return oncorePathSegments(path).includes('[]');
    }

    // The words for a path. keepWrappers is for the rare case where dropping a wrapper would
    // give two fields of one endpoint the same label.
    function oncoreFieldLabel(endpoint, path, keepWrappers = false) {
        const segments = oncorePathSegments(path);
        const parts = [];
        let previousIsKey = false;

        segments.forEach((segment, index) => {
            if (segment === '[]') {
                // "All" and whatever the list holds: the key it sits under, or the endpoint's
                // records when the whole response is the list
                const noun = previousIsKey ? parts.pop() : oncoreEndpointNoun(endpoint);
                parts.push(`all ${noun}`);
                previousIsKey = false;
                return;
            }

            if (segment.startsWith('[')) {
                // Long-hand role filter, [staffRole=Principal Investigator]: just the role
                const inner = segment.slice(1, -1).replace(/\\(.)/g, '$1');
                const equals = inner.indexOf('=');
                parts.push(equals === -1 ? inner : inner.slice(equals + 1));
                previousIsKey = false;
                return;
            }

            const isLast = index === segments.length - 1;
            const nextIsList = segments[index + 1] === '[]';
            if (!keepWrappers && !isLast && !nextIsList && ONCORE_LABEL_WRAPPERS.has(segment)) {
                previousIsKey = false;
                return;
            }

            parts.push(oncoreLabelWords(segment));
            previousIsKey = true;
        });

        return parts.map(capitalizeFirst).join(' › ') || path;
    }

    // The label shown for a field: from the index when it's there, since that has settled any
    // two fields sharing a label
    function oncoreFieldLabelFor(obj) {
        const indexed = pickerIndex().byKey.get(pickerKey(obj.endpoint, obj.field));
        return indexed ? indexed.label : oncoreFieldLabel(obj.endpoint, obj.field);
    }

    const ONCORE_LIST_NOTE = 'OnCore returns a list here. Every entry is pulled and joined with "; ", '
        + 'so this field will need someone to adjudicate it each time it is pulled. '
        + 'To keep one person in sync automatically, map a role instead (e.g. Principal investigator).';

    // Build one <option>, shortened for display and carrying the full path in
    // its value, its tooltip and data-protocol (which save/load both read).
    function oncoreFieldOption(obj) {
        const option = document.createElement('option');
        option.value = obj.field;
        option.textContent = oncoreFieldLabelFor(obj);
        option.setAttribute('data-protocol', obj.endpoint);
        option.title = oncoreFieldTitle(obj);

        return option;
    }

    // [] takes every value in a list; a role segment such as
    // principalInvestigator takes only that person's, which is what lets
    // the field sync without adjudication.
    function oncoreFieldTitle(obj) {
        const note = oncoreFieldIsList(obj.field) ? `\n\n${ONCORE_LIST_NOTE}` : '';
        return `${oncoreFieldLabelFor(obj)}\n${obj.endpoint}: ${obj.field}${note}`;
    }

    // ---- OnCore field picker ----
    // Each mapping row keeps a hidden <select name> as the record of its choice, since checkpoint(),
    // Manage Forms and the saved mapping format all read a mapping from one. It only ever holds
    // "None" and the chosen field. Choosing happens in one shared, searchable popover instead of a
    // native dropdown that repeats every discovered OnCore path on every row.
    //
    // A trimmed-down version of the ADIF mapping picker: OnCore is the only source, so there are no
    // source filters, table/API badges or lookup hints - just search, suggestions, and the results
    // grouped under the OnCore endpoint they came from.

    // The most results drawn at once. Search narrows past this.
    const PICKER_RESULT_LIMIT = 150;

    // Words that say nothing about which field is meant, in labels or in paths
    const PICKER_STOP_WORDS = new Set(['the', 'and', 'for', 'with', 'from', 'of', 'in', 'an', 'to', 'is', 'id', 'no']);

    // Static markup only - nothing from OnCore or the dictionary goes through innerHTML
    const PICKER_ICONS = {
        search: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>',
        chevron: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"></path></svg>',
    };

    function pickerWords(text) {
        return String(text || '').replace(/<[^>]*>/g, ' ')
            // protocolStaff -> protocol Staff, so camelCase OnCore names split into words
            .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
            .toLowerCase()
            .split(/[^a-z0-9]+/)
            .filter(word => word.length > 1 && !PICKER_STOP_WORDS.has(word));
    }

    // Identifies a field across endpoints, since two endpoints can offer the same path ("status")
    function pickerKey(endpoint, field) {
        return `${endpoint || ''}\u0000${field}`;
    }

    // Every OnCore field, with what search and suggestions need worked out once. oncore_fields only
    // arrives after the OnCore requests finish, so this is built the first time a row needs it.
    const PICKER_NO_FIELDS = [];
    let pickerIndexCache = null;
    function pickerIndex() {
        const source = Array.isArray(oncore_fields) ? oncore_fields : PICKER_NO_FIELDS;
        if (pickerIndexCache && pickerIndexCache.source === source) return pickerIndexCache;

        const fields = source.map(obj => ({
            obj,
            key: pickerKey(obj.endpoint, obj.field),
            label: oncoreFieldLabel(obj.endpoint, obj.field),
            isList: oncoreFieldIsList(obj.field),
            words: new Set(pickerWords(obj.field)),
        }));

        // Two fields of one endpoint with the same label get the wrapper segments back
        const seenLabels = new Map();
        fields.forEach(f => {
            const labelKey = pickerKey(f.obj.endpoint, f.label);
            seenLabels.set(labelKey, (seenLabels.get(labelKey) || 0) + 1);
        });
        fields.forEach(f => {
            if (seenLabels.get(pickerKey(f.obj.endpoint, f.label)) > 1) {
                f.label = oncoreFieldLabel(f.obj.endpoint, f.obj.field, true);
            }
            f.haystack = `${f.label} ${f.obj.field} ${f.obj.endpoint}`.toLowerCase();
        });

        // Within each endpoint, single values (including roles) before "All ..." lists, so the
        // fields that sync without adjudication are the ones met first
        const endpointOrder = new Map();
        fields.forEach(f => {
            if (!endpointOrder.has(f.obj.endpoint)) endpointOrder.set(f.obj.endpoint, endpointOrder.size);
        });
        fields.sort((a, b) => endpointOrder.get(a.obj.endpoint) - endpointOrder.get(b.obj.endpoint)
            || a.isList - b.isList
            || a.label.localeCompare(b.label));

        // How many fields each word appears in, so a word most paths share counts for little and a
        // distinctive one (irbNo, lastName) for a lot
        const wordCounts = new Map();
        fields.forEach(f => f.words.forEach(word => {
            wordCounts.set(word, (wordCounts.get(word) || 0) + 1);
        }));

        pickerIndexCache = {source, fields, wordCounts, byKey: new Map(fields.map(f => [f.key, f]))};
        return pickerIndexCache;
    }

    // Words most of a form's fields share - a naming prefix, not a clue to which OnCore field is
    // meant. Worked out once per form.
    const pickerFormWords = new Map();
    function pickerCommonFormWords(formKey) {
        if (pickerFormWords.has(formKey)) return pickerFormWords.get(formKey);

        const names = Object.keys(dictionary[formKey] || {});
        const counts = new Map();
        names.forEach(name => new Set(pickerWords(name)).forEach(word => {
            counts.set(word, (counts.get(word) || 0) + 1);
        }));
        const common = new Set(names.length >= 3
            ? [...counts].filter(([, count]) => count / names.length >= 0.5).map(([word]) => word)
            : []);

        pickerFormWords.set(formKey, common);
        return common;
    }

    // Up to three fields whose paths share distinctive words with a REDCap field's name and label:
    // pi_last_name, "PI Last Name" -> principalInvestigator.lastName.
    // Ties go to the shorter path, which tends to be the main value rather than a detail of it.
    function pickerSuggestions(redcapField, formKey) {
        const {fields, wordCounts} = pickerIndex();
        const info = (dictionary[formKey] || {})[redcapField] || {};
        const common = pickerCommonFormWords(formKey);
        const wanted = [...new Set([...pickerWords(redcapField), ...pickerWords(info.field_label)])]
            .filter(word => !common.has(word));
        const total = fields.length;
        if (!wanted.length || !total) return [];

        // Enough that one distinctive shared word counts and a loosely common one doesn't, scaled
        // down when there are too few fields for any word to be very rare
        const threshold = Math.min(2, Math.log(total) / 2);
        // Whole words, or one starting the other: "irb" in a name finds irbNumber
        const prefixOf = (a, b) => a.length >= 3 && b.startsWith(a);
        const scored = [];
        fields.forEach(f => {
            let score = 0;
            f.words.forEach(word => {
                const count = wordCounts.get(word);
                if (count / total >= 0.5) return;
                if (wanted.some(w => w === word || prefixOf(w, word) || prefixOf(word, w))) {
                    score += Math.log(total / count);
                }
            });
            if (score >= threshold) scored.push({f, score});
        });

        return scored
            .sort((a, b) => b.score - a.score || a.f.obj.field.length - b.f.obj.field.length)
            .slice(0, 3)
            .map(entry => entry.f);
    }

    // Puts a field (or nothing) into a row's hidden select, the way a choice in the old dropdown did
    function setPickerValue(select, obj) {
        select.textContent = '';
        const none = document.createElement('option');
        none.value = '';
        none.textContent = 'None';
        none.setAttribute('data-protocol', '');
        select.appendChild(none);

        if (obj) {
            const option = oncoreFieldOption(obj);
            option.selected = true;
            select.appendChild(option);
        } else {
            none.selected = true;
        }
    }

    function selectedPickerKey(select) {
        const option = select.options[select.selectedIndex];
        return option && option.value ? pickerKey(option.dataset.protocol, option.value) : '';
    }

    // The field a row's hidden select holds. A saved mapping OnCore didn't return this time (the
    // protocol has no such data, or a request failed) is kept as it was rather than dropped to
    // None, so the next save doesn't quietly erase it.
    function selectedPickerField(select) {
        const key = selectedPickerKey(select);
        if (!key) return null;

        const found = pickerIndex().byKey.get(key);
        if (found) return found;

        const option = select.options[select.selectedIndex];
        const obj = {field: option.value, endpoint: option.dataset.protocol || ''};
        return {obj, key, label: oncoreFieldLabel(obj.endpoint, obj.field),
            isList: oncoreFieldIsList(obj.field), missing: true};
    }

    function pickerBadge(endpoint) {
        const badge = document.createElement('span');
        badge.className = 'rocs-picker-badge';
        badge.textContent = endpoint;
        badge.title = endpoint;
        return badge;
    }

    // Marks a field that arrives as several values and so needs adjudicating each time it's pulled
    function pickerListTag(compact) {
        const tag = document.createElement('span');
        tag.className = 'rocs-picker-list-tag';
        tag.textContent = compact ? 'List' : 'List';
        // On the row button the native tooltip is all there is. In the picker the whole entry
        // shows the note instead (see showPickerTip), which is quicker than a native tooltip.
        if (compact) tag.title = ONCORE_LIST_NOTE;
        return tag;
    }

    // Appends text with each search term wrapped in <mark>, built from text nodes so a path or
    // endpoint name is never read as markup
    function appendHighlighted(parent, text, tokens) {
        const lower = text.toLowerCase();
        const ranges = [];
        tokens.forEach(token => {
            let from = 0;
            let at;
            while ((at = lower.indexOf(token, from)) !== -1) {
                ranges.push([at, at + token.length]);
                from = at + token.length;
            }
        });
        ranges.sort((a, b) => a[0] - b[0]);

        let position = 0;
        ranges.forEach(([start, end]) => {
            if (end <= position) return;
            start = Math.max(start, position);
            if (start > position) parent.append(text.slice(position, start));
            const mark = document.createElement('mark');
            mark.textContent = text.slice(start, end);
            parent.appendChild(mark);
            position = end;
        });
        if (position < text.length) parent.append(text.slice(position));
    }

    // The button that stands in for a row's dropdown: which endpoint, and which field
    function renderPickerTrigger(trigger, select) {
        trigger.textContent = '';
        const field = selectedPickerField(select);
        trigger.classList.toggle('is-empty', !field);
        trigger.classList.toggle('is-missing', Boolean(field && field.missing));

        if (!field) {
            trigger.insertAdjacentHTML('beforeend', PICKER_ICONS.search);
            const text = document.createElement('span');
            text.className = 'rocs-picker-value';
            text.textContent = 'Search OnCore fields…';
            trigger.appendChild(text);
            trigger.title = '';
            trigger.setAttribute('aria-label', `OnCore field for ${select.name}: not mapped`);
            return;
        }

        if (field.obj.endpoint) trigger.appendChild(pickerBadge(field.obj.endpoint));
        const value = document.createElement('span');
        value.className = 'rocs-picker-value';
        value.textContent = field.label;
        trigger.appendChild(value);
        if (field.isList) trigger.appendChild(pickerListTag(true));
        trigger.insertAdjacentHTML('beforeend', PICKER_ICONS.chevron);

        trigger.title = field.missing
            ? `${oncoreFieldTitle(field.obj)}\n\nOnCore didn't return this field for the current protocol. The saved mapping is kept; choose another field or "No mapping" to change it.`
            : oncoreFieldTitle(field.obj);
        trigger.setAttribute('aria-label',
            `OnCore field for ${select.name}: ${field.label}${field.isList ? ' (list, needs adjudication)' : ''}${field.missing ? ' (not returned by OnCore)' : ''}`);
    }

    // Turns a row's <select name> into the picker. savedMapping is { mapping, protocol } as saved;
    // protocol (the endpoint) disambiguates common paths, while old mappings without one still load.
    function attachOncoreFieldPicker(select, formKey, savedMapping) {
        let saved = null;
        if (savedMapping && savedMapping.mapping) {
            const fields = Array.isArray(oncore_fields) ? oncore_fields : [];
            // The last match, as the old dropdown's selected option was
            saved = fields.filter(obj => obj.field === savedMapping.mapping
                    && (!savedMapping.protocol || savedMapping.protocol === obj.endpoint)).pop()
                || {field: savedMapping.mapping, endpoint: savedMapping.protocol || ''};
        }
        setPickerValue(select, saved);
        select.hidden = true;
        select.dataset.form = formKey;

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'rocs-picker-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        // The row's <label for> now points at the button
        if (select.id) {
            trigger.id = select.id;
            select.id = `${select.id}__value`;
        }
        select.after(trigger);
        renderPickerTrigger(trigger, select);

        trigger.addEventListener('click', () => openOncoreFieldPicker(select, trigger));
        trigger.addEventListener('keydown', event => {
            // Arrow down opens it; typing a letter opens it already searching for that letter
            const typed = event.key.length === 1 && event.key !== ' '
                && !event.ctrlKey && !event.metaKey && !event.altKey;
            if (event.key === 'ArrowDown' || typed) {
                event.preventDefault();
                openOncoreFieldPicker(select, trigger, typed ? event.key : '');
            }
        });
    }

    let picker = null;

    function buildPicker() {
        if (picker) return picker;

        const el = document.createElement('div');
        el.className = 'rocs-picker';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-label', 'Choose an OnCore field');
        el.hidden = true;

        const search = document.createElement('label');
        search.className = 'rocs-picker-search';
        search.insertAdjacentHTML('beforeend', PICKER_ICONS.search);
        const input = document.createElement('input');
        input.type = 'text';
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-controls', 'rocs-picker-list');
        search.appendChild(input);

        const list = document.createElement('div');
        list.className = 'rocs-picker-list';
        list.id = 'rocs-picker-list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', 'OnCore fields');

        const footer = document.createElement('div');
        footer.className = 'rocs-picker-footer';
        const keys = document.createElement('span');
        keys.textContent = '↑ ↓ to move · Enter to choose · Esc to close';
        const count = document.createElement('span');
        footer.append(keys, count);

        el.append(search, list, footer);
        document.body.appendChild(el);

        picker = {el, input, list, count, select: null, trigger: null, suggestions: [], items: [], active: -1, tipIndex: -1};

        // Off the list, the note goes; while the list scrolls it follows its entry
        list.addEventListener('mouseleave', hidePickerTip);
        list.addEventListener('scroll', () => {
            const option = picker.tipIndex >= 0 ? document.getElementById(`rocs-picker-option-${picker.tipIndex}`) : null;
            if (option && option.getAttribute('aria-describedby')) positionPickerTip(option);
        });

        input.addEventListener('input', () => renderPicker(false));
        input.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (!picker.items.length) return;
                const step = event.key === 'ArrowDown' ? 1 : -1;
                const next = Math.min(picker.items.length - 1, Math.max(0, picker.active + step));
                setPickerActive(next, true, true);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                choosePickerItem(picker.active);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                closeOncoreFieldPicker(true);
            } else if (event.key === 'Tab') {
                closeOncoreFieldPicker(false);
            }
        });

        return picker;
    }

    function openOncoreFieldPicker(select, trigger, initialQuery = '') {
        const p = buildPicker();
        if (p.trigger === trigger) {
            closeOncoreFieldPicker(true);
            return;
        }
        closeOncoreFieldPicker(false);

        p.select = select;
        p.trigger = trigger;
        p.suggestions = pickerSuggestions(select.name, select.dataset.form);
        p.input.value = initialQuery;
        p.input.placeholder = `Search ${pickerIndex().fields.length.toLocaleString()} OnCore fields`;
        p.input.setAttribute('aria-label', `Search OnCore fields for ${select.name}`);
        trigger.setAttribute('aria-expanded', 'true');
        trigger.classList.add('is-open');
        p.el.hidden = false;

        renderPicker(true);
        positionPicker();
        p.input.focus();
    }

    function closeOncoreFieldPicker(returnFocus) {
        const p = picker;
        if (!p || !p.trigger) return;

        hidePickerTip();
        p.el.hidden = true;
        p.trigger.setAttribute('aria-expanded', 'false');
        p.trigger.classList.remove('is-open');
        if (returnFocus && p.trigger.isConnected) p.trigger.focus();
        p.trigger = null;
        p.select = null;
    }

    function renderPicker(onOpen) {
        const p = picker;
        const {fields} = pickerIndex();
        const query = p.input.value.trim().toLowerCase();
        const tokens = query.split(/\s+/).filter(Boolean);
        const shown = tokens.length
            ? fields.filter(f => tokens.every(token => f.haystack.includes(token)))
            : fields;
        const shownSet = new Set(shown);
        const currentKey = selectedPickerKey(p.select);

        p.list.textContent = '';
        p.items = [];

        const group = (label, count) => {
            const wrapper = document.createElement('div');
            wrapper.setAttribute('role', 'group');
            const heading = document.createElement('div');
            heading.className = 'rocs-picker-group-label';
            heading.id = `rocs-picker-group-${p.list.children.length}`;
            heading.textContent = count === undefined ? label : `${label} · ${count.toLocaleString()}`;
            wrapper.setAttribute('aria-labelledby', heading.id);
            wrapper.appendChild(heading);
            p.list.appendChild(wrapper);
            return wrapper;
        };

        const addOption = (field, parent, withBadge) => {
            const index = p.items.length;
            const option = document.createElement('div');
            option.id = `rocs-picker-option-${index}`;
            option.className = 'rocs-picker-option';
            option.setAttribute('role', 'option');

            const main = document.createElement('span');
            main.className = 'rocs-picker-option-main';
            option.appendChild(main);

            if (!field) {
                option.classList.add('is-clear');
                option.setAttribute('aria-selected', 'false');
                main.textContent = 'No mapping';
            } else {
                const isCurrent = field.key === currentKey;
                option.setAttribute('aria-selected', isCurrent ? 'true' : 'false');
                if (!field.isList) option.title = oncoreFieldTitle(field.obj);
                if (withBadge) main.appendChild(pickerBadge(field.obj.endpoint));
                const display = document.createElement('span');
                display.className = 'rocs-picker-option-display';
                appendHighlighted(display, field.label, tokens);
                main.appendChild(display);
                if (field.isList) main.appendChild(pickerListTag(false));
                if (isCurrent) {
                    const current = document.createElement('span');
                    current.className = 'rocs-picker-current';
                    current.textContent = 'Current';
                    main.appendChild(current);
                }

                // The raw path the label stands for, for anyone who knows OnCore's API
                if (field.label !== field.obj.field) {
                    const path = document.createElement('span');
                    path.className = 'rocs-picker-option-path';
                    appendHighlighted(path, field.obj.field, tokens);
                    option.appendChild(path);
                }
            }

            // mousedown would move focus out of the search box and close the picker
            option.addEventListener('mousedown', event => event.preventDefault());
            option.addEventListener('click', () => choosePickerItem(index));
            option.addEventListener('mousemove', () => {
                const needsTip = field && field.isList && p.tipIndex !== index;
                if (p.active !== index || needsTip) setPickerActive(index, false, true);
            });

            p.items.push(field);
            parent.appendChild(option);
        };

        if (!tokens.length && currentKey) {
            addOption(null, p.list, false);
        }

        const suggested = p.suggestions.filter(f => shownSet.has(f));
        if (suggested.length) {
            const suggestedGroup = group(`Suggested for ${p.select.name}`);
            suggested.forEach(f => addOption(f, suggestedGroup, true));
        }

        // Everything else grouped under its endpoint, so the endpoint name appears once as a heading
        const suggestedSet = new Set(suggested);
        const byEndpoint = new Map();
        shown.forEach(f => {
            if (suggestedSet.has(f)) return;
            if (!byEndpoint.has(f.obj.endpoint)) byEndpoint.set(f.obj.endpoint, []);
            byEndpoint.get(f.obj.endpoint).push(f);
        });

        let drawn = suggested.length;
        for (const [endpoint, endpointFields] of byEndpoint) {
            if (drawn >= PICKER_RESULT_LIMIT) break;
            const endpointGroup = group(endpoint, endpointFields.length);
            for (const f of endpointFields) {
                if (drawn >= PICKER_RESULT_LIMIT) break;
                addOption(f, endpointGroup, false);
                drawn++;
            }
        }

        if (!shown.length) {
            const empty = document.createElement('div');
            empty.className = 'rocs-picker-empty';
            empty.textContent = tokens.length
                ? `No OnCore fields match “${p.input.value.trim()}”.`
                : 'OnCore returned no fields for this protocol.';
            p.list.appendChild(empty);
        }

        if (drawn < shown.length) {
            p.count.textContent = `Showing ${drawn.toLocaleString()} of ${shown.length.toLocaleString()} — type to narrow`;
        } else if (tokens.length) {
            p.count.textContent = `${shown.length.toLocaleString()} of ${fields.length.toLocaleString()} fields`;
        } else {
            p.count.textContent = `${fields.length.toLocaleString()} fields`;
        }

        // Start on the current mapping when opening, else the best suggestion; while typing,
        // on the first result. "No mapping" is only ever chosen on purpose.
        const firstField = p.items.findIndex(f => f !== null);
        let active = firstField;
        if (onOpen) {
            const current = p.items.findIndex(f => f && f.key === currentKey);
            if (current !== -1) active = current;
        }
        setPickerActive(active === -1 ? (p.items.length ? 0 : -1) : active, onOpen);
        if (!onOpen) p.list.scrollTop = 0;
    }

    function setPickerActive(index, scroll, showTip = false) {
        const p = picker;
        p.list.querySelectorAll('.rocs-picker-option.is-active').forEach(el => el.classList.remove('is-active'));
        p.active = index;

        const option = index >= 0 ? document.getElementById(`rocs-picker-option-${index}`) : null;
        if (option) {
            option.classList.add('is-active');
            p.input.setAttribute('aria-activedescendant', option.id);
            if (scroll) option.scrollIntoView({block: 'nearest'});
        } else {
            p.input.removeAttribute('aria-activedescendant');
        }

        if (showTip) {
            showPickerTip(index);
        } else {
            hidePickerTip();
        }
    }

    // ---- List note tooltip ----
    // Explains a "List" entry while the pointer is anywhere on it, or while the arrow keys rest on
    // it. A native title tooltip takes a second or so to appear and only on the small tag, which
    // made it easy to miss. Sits beside the picker so it covers none of the other entries.
    const PICKER_TIP_DELAY = 120;
    let pickerTipTimer = null;

    function pickerTipElement() {
        let tip = document.getElementById('rocs-picker-tip');
        if (!tip) {
            tip = document.createElement('div');
            tip.id = 'rocs-picker-tip';
            tip.className = 'rocs-picker-tip';
            tip.setAttribute('role', 'tooltip');
            tip.hidden = true;
            document.body.appendChild(tip);
        }
        return tip;
    }

    function showPickerTip(index) {
        const p = picker;
        clearTimeout(pickerTipTimer);
        const field = index >= 0 ? p.items[index] : null;
        if (!field || !field.isList) {
            hidePickerTip();
            return;
        }

        p.tipIndex = index;
        pickerTipTimer = setTimeout(() => {
            const option = document.getElementById(`rocs-picker-option-${index}`);
            if (!option || picker.active !== index || !picker.trigger) return;

            const tip = pickerTipElement();
            tip.textContent = ONCORE_LIST_NOTE;
            option.setAttribute('aria-describedby', tip.id);
            tip.hidden = false;
            positionPickerTip(option);
        }, PICKER_TIP_DELAY);
    }

    function hidePickerTip() {
        clearTimeout(pickerTipTimer);
        if (picker) picker.tipIndex = -1;
        const tip = document.getElementById('rocs-picker-tip');
        if (tip) tip.hidden = true;
        document.querySelectorAll('.rocs-picker-option[aria-describedby]')
            .forEach(el => el.removeAttribute('aria-describedby'));
    }

    // Left of the picker, level with the entry; under the entry when there's no room on the left
    function positionPickerTip(option) {
        const tip = pickerTipElement();
        const margin = 8;
        const width = 300;
        const entry = option.getBoundingClientRect();
        const list = picker.list.getBoundingClientRect();
        const panel = picker.el.getBoundingClientRect();

        // Scrolled out of the list's view
        if (entry.bottom < list.top || entry.top > list.bottom) {
            tip.hidden = true;
            return;
        }
        tip.hidden = false;

        tip.style.width = `${width}px`;
        let left;
        let top;
        if (panel.left - width - margin >= margin) {
            left = panel.left - width - margin;
            top = Math.max(margin, Math.min(entry.top, window.innerHeight - tip.offsetHeight - margin));
        } else {
            left = Math.max(margin, Math.min(entry.left, window.innerWidth - width - margin));
            top = entry.bottom + 4;
        }
        tip.style.left = `${left + window.scrollX}px`;
        tip.style.top = `${top + window.scrollY}px`;
    }

    function choosePickerItem(index) {
        const p = picker;
        if (!p || !p.select || index < 0 || index >= p.items.length) return;

        const field = p.items[index];
        const select = p.select;
        const trigger = p.trigger;
        const before = selectedPickerKey(select);

        setPickerValue(select, field ? field.obj : null);
        renderPickerTrigger(trigger, select);
        closeOncoreFieldPicker(true);

        // The page's change handler saves (checkpoint)
        if (selectedPickerKey(select) !== before) {
            select.dispatchEvent(new Event('change', {bubbles: true}));
        }
    }

    // Under the trigger with right edges lined up, since the OnCore column sits toward the right;
    // above it when there isn't room below
    function positionPicker() {
        const p = picker;
        if (!p || !p.trigger) return;

        const margin = 8;
        const rect = p.trigger.getBoundingClientRect();
        const width = Math.min(Math.max(rect.width, 520), window.innerWidth - margin * 2);
        let left = Math.min(rect.right - width, window.innerWidth - margin - width);
        left = Math.max(margin, left);

        const below = window.innerHeight - rect.bottom - margin;
        const above = rect.top - margin;
        const openUp = below < 280 && above > below;
        p.el.style.width = `${width}px`;
        p.el.style.left = `${left + window.scrollX}px`;
        p.el.style.maxHeight = `${Math.max(220, Math.min(480, (openUp ? above : below) - 6))}px`;
        p.el.style.top = openUp
            ? `${rect.top + window.scrollY - 6 - p.el.offsetHeight}px`
            : `${rect.bottom + window.scrollY + 6}px`;
    }

    document.addEventListener('mousedown', event => {
        if (!picker || !picker.trigger) return;
        if (picker.el.contains(event.target) || picker.trigger.contains(event.target)) return;
        closeOncoreFieldPicker(false);
    });
    window.addEventListener('resize', positionPicker);

    // Function gets called to build the tables which showcase the forms
    function buildTables(keys) {
        const inst_list = document.getElementById('instruments_list');

        Object.entries(instruments).forEach(([key, value]) => {
            let table = document.createElement('table');
            table.id = key + '_fields';
            table.classList = "dataTable cell-border no-footer";
            inst_list.appendChild(table);

            let header = document.createElement('thead');
            header.innerHTML =
                `<tr>
                    <th>${value} Fields</th>
                    <th>OnCore Field</th>
                </tr>`;
            table.appendChild(header);

            let tbody = document.createElement('tbody');
            table.appendChild(tbody);

            let i = 1;
            Object.entries(dictionary[key]).forEach(([key2, value2]) => {
                let row = document.createElement('tr');
                row.classList = i % 2 !== 0 ? 'odd' : 'even';

                row.innerHTML =
                    `<td style='width: 50% !important;'>
                        <label for='${key2}'>${key2}</label>
                     </td>
                     <td style='width: 50% !important;'>
                         <select name='${key2}' id='${key2}'>
                            <option value="" data-protocol="">None</option>
                         </select>
                     </td>`;

                // populate select with keys
                const select = row.querySelector(`#${key2}`);
                keys.forEach(k => {
                    const option = document.createElement("option");
                    option.value = k;
                    option.textContent = k;
                    select.appendChild(option);
                });

                table.appendChild(row);
                i++;
            });
        });
    }
</script>

<div class="d-flex container" style="flex-direction: column;">
    <!-- Manage and sync buttons -->
    <div class="row selection-btns">
        <div class="col-md-3">
            <a id="manage-forms-btn">
                <div class="center-home-sects">
                    <span><i class="fa fa-plus"></i></span><br>
                    <h5>Manage Forms</h5>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a id="upload-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-upload"></i></span><br>
                    <h5>Upload Config</h5>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a id="export-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-file-export"></i></span><br>
                    <h5>Export Config</h5>
                </div>
            </a>
        </div>
    </div>
    
    <!-- List of forms and their fields -->
    <div id="instruments_list" class="row" style="flex-direction: column;">
        <div id="forms-loader" class="loader-container">
            <div class="loader"></div>
            <p style="margin-top: 15px;">Loading Saved Mappings...</p>
        </div>
    </div>

    <!-- Manage and sync buttons -->
    <div class="row selection-btns">
        <div class="col-md-3">
            <a id="manage-forms-btn">
                <div class="center-home-sects">
                    <span><i class="fa fa-plus"></i></span><br>
                    <h5>Manage Forms</h5>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a id="upload-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-upload"></i></span><br>
                    <h5>Upload Config</h5>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a id="export-btn" class="center-home-sects">
                <div class="center-home-sects">
                    <span><i class="fas fa-file-export"></i></span><br>
                    <h5>Export Config</h5>
                </div>
            </a>
        </div>
    </div>
</div>

<script>
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

    // TODO: add a logging call in here to track when forms are added or removed
    // Creates a modal that lets you select which forms you want to sync and ignore the ones you don't want
    function manageForms(instruments, displayed) {
        const built = buildModal();
        if (!built) return; // The modal already exists

        const { modalOverlay, modalBox } = built;

        // Modal header + form list
        let modalContent = `
            <h2>Manage Forms</h2>
            <p>Select which REDCap forms you want to synchronize.</p>
            <div class="modal-form-selection-grid" style="height: 80vh; overflow-y: auto;">
            <table class="dataTable cell-border no-footer">
        `;

        let i=0;
        Object.entries(instruments).forEach(([key, value]) => {
            const isDisplayed = displayed.includes(key);
            modalContent += `
            <tr class="${i % 2 !== 0 ? 'odd' : 'even'}">
                <td style="width: 100% !important;">
                    <label class="checkbox-option">
                        <input type="checkbox" name="form-select" value="${key}" ${isDisplayed ? "checked" : ""}>
                        ${value}
                    </label>
                </td>
            </tr>
            `;
            i = i + 1; // increment even/odd
        });

        modalContent += `
            </table>
            </div>
            <div class="modal-actions">
                <button id="confirm_btn" class="selectA_btn">Save Changes</button>
                <button id="close_btn" class="close-button">Cancel</button>
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

        // Confirm button logic
        document.getElementById('confirm_btn').addEventListener('click', async () => {
            const selected = Array.from(document.querySelectorAll('input[name="form-select"]:checked'))
                .map(cb => cb.value);

            const toAdd = selected.filter(f => !displayed.includes(f));
            const toRemove = displayed.filter(f => !selected.includes(f));

            // Handle removals with warnings for forms that have mapped data
            for (const formKey of toRemove) {
                const table = document.getElementById(formKey);

                if (table) {
                    const selects = Array.from(table.querySelectorAll('select'));
                    const hasConfiguredData = selects.some(sel => sel.value && sel.value !== "");

                    if (hasConfiguredData) {
                        const confirmRemoval = confirm(
                            `Warning: The form "${instruments[formKey]}" has mapped data. Are you sure you want to remove it?`
                        );
                        if (!confirmRemoval) {
                            continue; // User said no — skip removal entirely
                        }
                    }

                    // Remove from DOM whether or not it had data (user confirmed if needed)
                    table.remove();
                }

                // Remove from displayed regardless of whether table existed in DOM
                const index = displayed.indexOf(formKey);
                if (index !== -1) displayed.splice(index, 1);
            }

            // Handle additions
            toAdd.forEach(selectedForm => {
                displayed.push(selectedForm);
                const table = document.createElement('table');
                table.id = selectedForm;
                table.classList = "dataTable cell-border no-footer";

                const header = document.createElement('thead');
                header.innerHTML = `
            <tr>
                <th style="width: 25%;">${instruments[selectedForm]} Fields</th>
                <th style="width: 35%;">REDCap Field Label</th>
                <th style="width: 25%;">OnCore Field</th>
                <th style="width: 15%;">Include, But Don't Adjudicate</th>
            </tr>`;
                table.appendChild(header);

                const tbody = document.createElement('tbody');
                table.appendChild(tbody);

                let i = 0;
                Object.keys(dictionary[selectedForm]).forEach(redcapField => {
                    const row = document.createElement('tr');
                    row.className = i % 2 === 0 ? 'even' : 'odd';

                    const selectId = `${selectedForm}_${redcapField}`;
                    row.innerHTML = `
                <td style="width:50%"><label for="${selectId}">${redcapField}</label></td>
                <td>
                    ${dictionary[selectedForm][redcapField].field_label.length > 50
                        ? dictionary[selectedForm][redcapField].field_label.slice(0, 50) + '…'
                        : dictionary[selectedForm][redcapField].field_label}
                </td>
                <td style="width:50%">
                    <select name="${redcapField}" id="${selectId}">
                        <option value="">None</option>
                    </select>
                </td>
                <td style="width:15%; text-align:center;">
                    <input type="checkbox" class="include-unmapped" data-field="${redcapField}">
                </td>
            `;

                    attachOncoreFieldPicker(row.querySelector('select'), selectedForm, null);

                    tbody.appendChild(row);
                    i++;
                });

                document.getElementById('instruments_list').appendChild(table);
            });

            closeModal(true);
            checkpoint();
        });

        // Close modal on Cancel or background click
        document.getElementById('close_btn').addEventListener('click', (event) => {
            closeModal(false); // close modal but no checkpoint   
        });
        modalOverlay.addEventListener('click', (event) => {
            if (event.target === modalOverlay) {
                closeModal(false); // close modal but no checkpoint   
            }
        });
    }

    function resolveMappingModal(type, mapping, formName, offenderField, availableOptions) {
        const built = buildModal();
        if (!built) return;

        const { modalOverlay, modalBox } = built;

        // Set dynamic text based on the type
        const isForm = type === 'form';
        const targetName = isForm ? formName : offenderField;
        const parentText = isForm ? "this REDCap project" : `the form <strong>${formName}</strong>`;

        // Build Dropdown
        let dropdownOptions = `<option value="">-- Select a replacement ${type} --</option>`;
        Object.entries(availableOptions).forEach(([key, value]) => {
            let label = typeof value === 'object' && value.field_label ?
                value.field_label.replace(/(<([^>]+)>)/gi, "").substring(0, 50) + "..." : value;
            dropdownOptions += `<option value="${key}">${key} (${label})</option>`;
        });

        // Modal UI
        modalBox.innerHTML = `
        <div style="padding: 10px;">
            <h3 style="color: #d9534f; margin-top: 0;">Missing ${isForm ? 'Form' : 'Field'} Detected: <strong>${targetName}</strong></h3>
            <p>ROCS detected that a previously mapped ${type} (<strong>${targetName}</strong>) no longer exists in ${parentText}.</p>

            <div style="margin: 20px 0; padding: 15px; border: 1px solid #ccc; background-color: #f9f9f9; border-radius: 5px;">
                <label style="font-weight: bold; display: block; margin-bottom: 8px;">Option 1: Map to a different ${type}</label>
                <select id="replacement-select" style="width: 100%; padding: 6px;">
                    ${dropdownOptions}
                </select>
            </div>

            <div style="margin-bottom: 20px;">
                <p style="margin-bottom: 5px;"><strong>Option 2: Delete this ${type} mapping</strong></p>
            </div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                <button id="update_btn" class="selectA_btn" style="padding: 6px 12px; cursor: pointer;">Update Mapping</button>
                <button id="delete_btn" style="background-color: #d9534f; color: white; border: 1px solid #d43f3a; padding: 6px 12px; border-radius: 4px; cursor: pointer;">Delete Mapping</button>
                <button id="close_btn" class="close-button" style="padding: 6px 12px; cursor: pointer;">Cancel</button>
            </div>
        </div>
    `;

        modalOverlay.appendChild(modalBox);
        document.body.appendChild(modalOverlay);

        const closeModal = () => {
            if (modalOverlay && modalOverlay.parentNode) modalOverlay.parentNode.removeChild(modalOverlay);
        };


        // Selection for Update
        document.getElementById('update_btn').addEventListener('click', () => {
            const selected = document.getElementById('replacement-select').value;
            if (!selected) return alert(`Please select a replacement ${type}.`);

            if (isForm) {
                mapping[selected] = mapping[formName];
                delete mapping[formName];
            } else {
                mapping[formName][selected] = mapping[formName][offenderField];
                delete mapping[formName][offenderField];
            }

            saveMappingAjax(mapping);
        });

        // Selection for Deletion
        document.getElementById('delete_btn').addEventListener('click', () => {
            if (confirm(`Are you sure you want to delete the mapping for ${targetName}?`)) {
                if (isForm) {
                    delete mapping[formName];
                } else {
                    delete mapping[formName][offenderField];
                }
                mapping = sortMappings(mapping, instruments)
                saveMappingAjax(mapping);
            }
        });

        document.getElementById('close_btn').addEventListener('click', closeModal);


        // Save mappings
        function saveMappingAjax(updatedMapping) {
            $.ajax({
                url: "<?= $module->getUrl('scripts/save_mappings.php') ?>",
                method: "POST",
                data: {
                    pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                    redcap_csrf_token: <?= json_encode($csrf) ?>,
                    "mappings": JSON.stringify(updatedMapping),
                    displayed: JSON.stringify(Object.keys(updatedMapping))
                },
                success: () => window.location.reload(),
                error: (xhr, status, error) => console.error("Error:", error)
            });
            closeModal();
        }
    }

    function rebuild_from_mappings(instruments, dictionary, existingMappings) {
        const container = document.getElementById('instruments_list');
        if (!container) return;

        // This checks the saved mappings to ensure they match up with available forms and fields in the project
        validity_check(existingMappings, dictionary, instruments);

        // Create a fragment to build the tables in memory
        const fragment = document.createDocumentFragment();

        // Only build tables for instruments in the displayed array
        displayed.forEach(instrumentKey => {
            const instrumentLabel = instruments[instrumentKey];
            if (!instrumentLabel) return; // Skip if instrument doesn't exist
            
            const mappedFields = existingMappings[instrumentKey] || {};
            const dictFields = dictionary[instrumentKey];

            if (!dictFields) return; // skip if no dictionary

            // Create table
            const table = document.createElement('table');
            table.id = instrumentKey;
            table.className = "dataTable cell-border no-footer";
            
            const header = document.createElement('thead');
            header.innerHTML = `<tr>
                                    <th style="width: 25%;">${instrumentLabel} Fields</th>
                                    <th style="width: 35%;">REDCap Field Label</th>
                                    <th style="width: 25%;">OnCore Field Path</th>
                                    <th style="width: 15%;">Include, But Don't Adjudicate</th>
                                 </tr>`;
            table.appendChild(header);

            const tbody = document.createElement('tbody');
            table.appendChild(tbody);

            let i = 0;
            Object.keys(dictFields).forEach(redcapField => {
                const row = document.createElement('tr');
                row.className = i % 2 === 0 ? 'even' : 'odd';

                const selectId = `${instrumentKey}_${redcapField}`;
                row.innerHTML = `
                    <td style="width:45%">
                        <label for="${selectId}">${redcapField}</label>
                    </td>
                        <td>
                            ${
                                dictionary[instrumentKey][redcapField].field_label.length > 50
                                    ? dictionary[instrumentKey][redcapField].field_label.slice(0, 50) + '…'
                                    : dictionary[instrumentKey][redcapField].field_label
                            }
                        </td>
                    <td style="width:40%">
                        <select name="${redcapField}" id="${selectId}">
                            <option value="">None</option>
                        </select>
                    </td>
                    <td style="width:15%; text-align:center;">
                        <input type="checkbox"
                               class="include-unmapped"
                               data-field="${redcapField}">
                    </td>
                `;

                tbody.appendChild(row);

                attachOncoreFieldPicker(row.querySelector('select'), instrumentKey, mappedFields[redcapField] || null);
                const checkbox = row.querySelector('.include-unmapped');
                if (checkbox) {
                    checkbox.checked = Boolean((mappedFields[redcapField] || {}).include_unmapped);
                }

                fragment.appendChild(table);
                i++;
            });
        });

        // Clear the container (removes the loader) and append all tables
        container.innerHTML = '';
        container.appendChild(fragment);
    }

    // TODO: somehow we want to log changes made, but it runs on every individual change currently. Refactor so that we
    //TODO: cont. ^ benefit from it saving often but that it is only logged by change session.

    // Save a snapshot of configurations whenever you want, currently runs on any change
    function checkpoint() {
        console.log('checkpoint')
        const mapping = {};

        document.querySelectorAll('#instruments_list > table').forEach(table => {
            const instrument = table.id;
            if (!instrument) return;

            const instrumentMapping = {};

            table.querySelectorAll('select').forEach(select => {

                const redcapField = select.name;
                const selectedValue = select.value;

                // Grab the protocol from the selected option
                const selectedOption = select.options[select.selectedIndex];
                const protocol = selectedOption ? selectedOption.dataset.protocol : null;

                const row = select.closest('tr');
                const checkbox = row?.querySelector('.include-unmapped');

                const includeUnmapped = checkbox
                    ? checkbox.checked
                    : false;

                console.log(
                    instrument,
                    redcapField,
                    selectedValue,
                    protocol,
                    includeUnmapped
                );

                instrumentMapping[redcapField] = {
                    mapping: selectedValue,
                    include_unmapped: includeUnmapped,
                    protocol: protocol, // This will now save correctly
                };
            });

            mapping[instrument] = instrumentMapping;
        });

        mappings = mapping;
        mappings = sortMappings(mappings, instruments);

        // Send to server - include the displayed array
        $.ajax({
            url: "<?= $module->getUrl('scripts/save_mappings.php') ?>",
            method: "POST",
            data: {
                pid: <?= json_encode($_GET['pid'] ?? $project_id ?? 0) ?>,
                redcap_csrf_token: <?= json_encode($csrf) ?>,
                "mappings": JSON.stringify(mappings),
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

    function load_checkpoint() {
        $.ajax({
            url: "<?= $module->getUrl('scripts/load_mappings.php') ?>",
            method: "GET",
            dataType: "json",
            success: function (result) {
                console.log('load checkpoint result', result);
                if (result.status !== 'success') {
                    console.error('Failed to load mappings:', result.error || 'Unknown error');
                    const loader = document.getElementById('forms-loader');
                    if (loader) {
                        loader.innerHTML = '<p style="color: red; text-align: center;"><b>Error:</b> Could not load saved field mappings.</p>';
                    }
                    return;
                }

                if (result.data.length === 0) {
                    let list = document.getElementById('instruments_list');
                    list.innerHTML = `<h2>You currently have not selected any Forms for mapping.</h2>
                                      <h2>Select the "Manage Forms" option above to begin mapping your first Form.</h2>`;
                }
                else if (mappings.length === 0) {
                    let list = document.getElementById('instruments_list');
                    list.innerHTML = `<h2>You currently have not selected any Forms for mapping.</h2>
                                      <h2>Select the "Manage Forms" option above to begin mapping your first Form.</h2>`;
                }
                else {
                    mappings = result.data || {};
                    mappings = sortMappings(mappings, instruments);
                    displayed = Object.keys(mappings);
/*
                    const savedDisplayed = result.displayed || []; // <-- Load the saved displayed list

                    displayed.length = 0;
                    // Use the saved displayed list instead of mapping keys
                    savedDisplayed.forEach(instrumentKey => {
                        if (instruments[instrumentKey]) {
                            displayed.push(instrumentKey);
                        }
                    });*/

                    rebuild_from_mappings(instruments, dictionary, mappings);
                }
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

    function populateDropdowns(existingMappings = {}) {
        // Loop through all tables (one per instrument)
        document.querySelectorAll("#instruments_list table").forEach(table => {
            const instrument = table.id; // e.g., 'demographics'

            // Skip if no table ID
            if (!instrument) return;

            // Loop through all selects inside this table
            table.querySelectorAll("select").forEach(select => {
                const redcapField = select.name;

                // Clear existing options
                select.innerHTML = '';

                // Add "None" option first
                const noneOption = document.createElement('option');
                noneOption.value = '';
                noneOption.textContent = 'None';
                select.appendChild(noneOption);

                // Determine the pre-selected value (from saved mappings)
                const preSelected = (existingMappings[instrument] || {})[redcapField] || '';
                // If preSelected is empty, "None" remains selected automatically
            });
        });
    }

    // Will eventually handle overwriting data
    function handleRecordSelection(selectedRecord) {
        console.log("The user selected this record:", selectedRecord);
        alert(`You chose the record with id: ${selectedRecord.record_id}`);
    }

    // Sorts a mapping prior to save so that it is displayed in the same order as they appear in REDCap.
    function sortMappings(mappings, instruments) {
        const sortedMappings = {};

        // Iterate through the instruments to enforce the correct order
        for (const instrumentKey of Object.keys(instruments)) {
            // If a mapping exists for this instrument, add it to our new object
            if (mappings.hasOwnProperty(instrumentKey)) {
                sortedMappings[instrumentKey] = mappings[instrumentKey];
            }
        }

        return sortedMappings;
    }

    // Load the saved checkpoint when the page is initialized
    document.addEventListener('DOMContentLoaded', async () => {
        try {
            console.log(eIRBno)
            let details = null;

            let protocolId = null;
            let protocol = null; // Storing the base protocol data if needed later
            let protocolNo = protocolNoConfig;

            if (protocolNo) {
                details = await safeFetchOncore('protocolManagementDetails', `&protocolNo=${protocolNo}`);
                console.log(details);

                if (details.success && details.data) {
                    console.log("Protocol details fetched by protocolNo:", details.data);
                    protocol = details;
                    protocolId = details.data['protocolId'];
                    console.log("Found Protocol ID:", protocolId);
                } else {
                    console.warn("Could not find a protocol with that protocol number.");
                }
            }
            
            if (!protocolId && eIRBno) {
                /* Query the protocols endpoint using irbNo */
                details = await safeFetchOncore('protocolManagementDetails', `&irbNo=${eIRBno}`);
                console.log(details);

                if (details.success && details.data) {
                    console.log("Protocol details fetched by eIRB:", details.data);
                    protocol = details;
                    protocolId = details.data['protocolId'];
                    console.log("Found Protocol ID:", protocolId);
                } else {
                    console.warn("Could not find a protocol with that eIRB number.");
                }
            }
            
            if (!protocolId) {
                /* If they didn't provide eIRBno in settings, loop until we get a hit */
                // TODO: check records until we get one that actually replies with something
                let eirb_records = get_eIRBs();

                for (const test_record of eirb_records) {
                    let test_protocol = test_record[protocol_field_name];
                    let test_eirb = test_record[irb_field_name];
                    console.log(`Trying record: ${test_record.record_id}...`);

                    if (test_protocol) {
                        details = await safeFetchOncore('protocolManagementDetails', `&protocolNo=${test_protocol}`);
                    } else if (test_eirb) {
                        details = await safeFetchOncore('protocolManagementDetails', `&irbNo=${test_eirb}`);
                    }

                    // Check if it succeeded AND actually returned data
                    if (details && details.success && details.data) {
                        console.log(`Success! Protocol details fetched for eIRB ${test_eirb}:`, details.data);

                        protocol = details;
                        // Assuming data is an object with protocolId. Adjust index if data is an array.
                        protocolId = details.data['protocolId'];
                        console.log("Found Protocol ID:", protocolId);

                        // Break out of the loop immediately
                        break;
                    } else {
                        console.warn(`No valid protocol found for eIRB ${test_eirb}. Moving to next...`);
                    }
                }

                if (!protocolId) {
                    console.error("Searched all eIRB records but could not find a valid protocol.");
                }
            }

            // Optional field object this is a protocol/query pair. Default value suits our case, you can see it in scripts.php
            oncore_fields = await safeFetchOncoreAll(protocolId, buildAPI(protocolId));

            console.log("All OnCore fields (successful only):", oncore_fields);

            load_checkpoint();
        } catch (err) {
            console.error("Unexpected OnCore loading failure:", err);
        }

        document.getElementById('upload-btn').addEventListener('click', async () => {
            // Remove any existing modal first
            const existing = document.querySelector('.modal-overlay');
            if (existing) existing.remove();

            const built = buildModal();
            const { modalOverlay, modalBox } = built;

            let modalContent = `
                <h1 class="warning">WARNING!</h1>
                <h2 class="disclaimer">Uploading a mapping <strong>will overwrite previously saved mappings.</strong></h2>
                <h2 class="disclaimer">We strongly suggest exporting your current mapping first as a backup.</h2>
                <div>
                    <input type="file" id="jsonFile" accept=".json">
                </div>
                <div style="display:flex; justify-content:center; gap:1rem; margin-top:1.5rem;">
                    <button id="confirm_upload" style="background-color:#28a745; color:white; padding:10px 20px; border:none; border-radius:6px;">Save Choices</button>
                    <button id="cancel_upload" style="background-color:#dc3545; color:white; padding:10px 20px; border:none; border-radius:6px;">Cancel</button>
                </div>
            `

            modalBox.innerHTML = modalContent;
            modalOverlay.appendChild(modalBox);
            document.body.appendChild(modalOverlay);

            const closeModal = () => {
                if (modalOverlay && modalOverlay.parentNode) {
                    modalOverlay.parentNode.removeChild(modalOverlay);
                }
            };

            document.getElementById('confirm_upload').addEventListener('click', async () => {
                const fileInput = document.getElementById("jsonFile");

                if (!fileInput || !fileInput.files.length) {
                    alert("Please select a configuration file first.");
                    return;
                }

                const file = fileInput.files[0];

                try {
                    const text = await file.text();
                    const data = JSON.parse(text);
                    rebuild_from_mappings(instruments, dictionary, data);
                    checkpoint(); // save it after
                    closeModal();
                } catch (err) {
                    console.error(err);
                    alert("Invalid JSON file.");
                }
            });

            document.getElementById('cancel_upload').addEventListener('click', () => {
                closeModal();
            });

            modalOverlay.addEventListener('click', (e) => {
                if (e.target === modalOverlay) closeModal();
            });
        });

        document.getElementById('export-btn').addEventListener('click', () => {
            // export a properly formatted config for upload in another project
            const jsonString = JSON.stringify(mappings, null, 2);

            // Create file blob
            const blob = new Blob([jsonString], { type: "application/json" });

            // Create download link
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");

            a.href = url;
            a.download = `${project_title}_${project_id}_mapping_config.json`;
            a.click();

            URL.revokeObjectURL(url);
        });

        document.getElementById('manage-forms-btn').addEventListener('click', () => {
            if (typeof dictionary === 'undefined') {
                console.error('Dictionary not defined yet.');
                return;
            }
            manageForms(instruments, displayed);
        });

        // When a user changes a field mapping dropdown
        document.addEventListener('change', function(event) {
            if (event.target.matches('#instruments_list select')) {
                checkpoint();
            }
            if (event.target.matches('#instruments_list input' )) {
                checkpoint();
            }
        });
    });

</script>

