# Overview
The REDCap OnCore Cross Reference (ROCS) tool provides a highly configurable connection between a REDCap project and an OnCore instance. ROCS adds a new page on the left-hand menu of a project called "OnCore Field Mappings" which allows a user to include or disclude forms from a project for mapping.

Once a form has been selected for mapping, the user is able to select a field pulled from OnCore's API in a dropdown. Once a selection is made, it is saved to the module's project configuration. Dropdowns allow for a user to type field names in the event they know what they are looking for and do not want to scroll through the often extensive list of available fields.

ROCS includes an autosave feature which is triggered when critical actions are performed. Critical actions are defined as when a user updates selected forms through the "Manage Forms" interface, and when fields are mapped using the dropdowns.

ROCS provides a ONE-WAY connection allowing data to be pulled from OnCore to REDCap in order to establish a "source of truth".

## OnCore fields and endpoints

ROCS discovers mapping fields from these protocol endpoints: `protocols`, `protocolConsents`, `protocolSponsors`, `protocolStaff`, `protocolEprmsSubmissions`, `protocolPrmcReviews`, `protocolIde`, `protocolInd`, `protocolIrbReviews`, and `protocolInstitutions`. It first uses `protocolManagementDetails` to resolve the protocol ID.

The OnCore Field Mappings page lists scalar fields at every depth of each successful response. Nested object members use dots. ROCS expands `protocolStaff.contactId` through `GET /contacts/{contactId}` and `protocolSponsors.sponsorId` through `GET /sponsors/{sponsorId}`. This makes paths such as `[].contact.displayName`, `[].contact.email`, and `[].sponsor.sponsorName` available for mapping. Existing top-level mappings continue to work.

### Lists, roles, and choosing one value

Endpoints like `protocolStaff` return a list, often a long one. The Field Mappings dropdown offers two ways to read it:

| Path form | Reads | Example |
| --- | --- | --- |
| `[]` | every entry, unique values joined with `; ` | `[].contact.lastName` → every staff member |
| a role segment | only the entries playing that part | `principalInvestigator.contact.lastName` → the PI |

Role segments are what keep the module automatic. A field mapped to `principalInvestigator.contact.lastName` resolves to one person on every protocol and syncs without anyone choosing a name per record, which is the whole point of mapping a list at all. `[]` remains right for genuinely multi-valued fields — a field holding all coordinators, say.

The segment is the entry's role value normalised to camelCase, and both sides of the comparison are normalised, so `Principal Investigator`, `PRINCIPAL_INVESTIGATOR`, and `principal investigator` are the same segment. A saved mapping therefore means the same thing on every protocol regardless of order, length, or punctuation. If a protocol has nobody in that role the field reads as empty rather than falling back to somebody else; if it has two, both values are returned and joined.

ROCS picks the role field automatically. It must be present on every entry as a short non-empty string, and its name must end in `role`, `type`, `category`, `status`, `position`, or `title` — `staffRole` and `consentType` qualify, `firstName` and `contactId` deliberately do not, so a person's name can never become a path segment. `role` wins over the others when a list has more than one candidate. A list with no such field offers only `[]`.

#### How the dropdown displays a path

Full paths run long, so each option is shortened to the two segments that identify it — which entry of the list, and which field of that entry — with anything between them dropped: `principalInvestigator.contact.lastName` displays as `principalInvestigator · lastName`. The first segment is kept rather than simply taking the last two, because `principalInvestigator.contact.lastName` and `studyCoordinator.contact.lastName` would otherwise both read `contact.lastName`.

Options are grouped under their endpoint using `<optgroup>`, so `protocolStaff` appears once as a heading instead of prefixing every line. Hovering an option shows the endpoint and the untruncated path; the saved mapping is always the full path, never the shortened display.

#### Choosing between values during adjudication

A field mapped with `[]` can still come back with several values, and picking one of them is a decision about a single record rather than about the mapping. When that happens, the OnCore column of the adjudication table holds a dropdown: it defaults to "All *n* values" (the joined list) and lists each value individually below, labelled with whatever tells the entries apart — `Principal Investigator: Lovelace`. Whichever is chosen is what gets written to REDCap. The mapping is untouched, so the next sync still compares against the whole list.

The label field is chosen by a slightly broader rule than the role segment: it may also end in `name`, and it must not read the same on every entry. A list with no such field still offers its values, just unlabelled.

#### Long-hand selectors

A path can also be hand-written as `[key=value]` to read only the entry whose `key` equals `value`, e.g. `protocolStaff[staffRole=Principal Investigator].contact.lastName`. Nothing generates that form now that role segments exist, but saved mappings using it still resolve. Inside it a backslash escapes the next character, so a value containing `]` or `\` is written `[label=a\]b]`.

#### Where this lives

Field paths are resolved in two places that must stay in step: `classes/OnCoreFieldPath.php` server-side during a sync, and `discoverOncoreFields`/`oncorePathEntries` in `scripts/scripts.php` in the browser. Both expose the values as `{value, label}` entries; the mapped value is those entries joined, so the comparison and the adjudication choices can never disagree.

The proxy also permits direct `contacts` and `sponsors` lookups, plus `protocolTasks` and `contactCredentials`; task lists and credentials are not part of the default mapping set.

## Reading a background sync

Every entry a single `performFullSync` writes is tagged with a `sync_run` identifier of the form `rocs-{pid}-{YmdHis}-{random}`, so one run reads back as a unit rather than as entries interleaved with every other run in the log:

```php
$module->queryLogs("SELECT timestamp, message, record_id, looked_up, outcome,
                           fields_compared, fields_differing, error
                    WHERE sync_run = ?
                    ORDER BY timestamp", [$runId]);
```

The run identifier appears on the "Background Full Sync Started" entry. Between that and "Background Full Sync Completed" there is one `ROCS Sync Record` entry per record, whose `outcome` is one of:

| Outcome | Meaning |
| --- | --- |
| `matched` | Every mapped field agreed with OnCore |
| `needs attention` | At least one mapped field differed; `fields_differing` says how many |
| `not in OnCore` | OnCore answered, but holds no protocol with that number |
| `oncore error` | The request itself failed; `error` carries the API's message |
| `skipped` | The record has neither an IRB nor a protocol number |

These entries deliberately record **what happened to a record, not what it contains**. Record IDs, protocol numbers and counts are logged; the REDCap and OnCore values behind a mismatch are not, and stay in the adjudication data where the Sync Dashboard reads them under REDCap's own access control.

Entries carrying a `sync_run` tag are pruned at the start of each run once they pass `ROCS::SYNC_LOG_RETENTION_DAYS` (30). Untagged entries — module initialisation, authorisation changes — are never pruned.

# Pages
This module contains two custom pages which are necessary for the function/operation of the project.
### FieldMappings.php
This page is the beating heart of the project. You are able to select which forms in your project you want to be considered for synchronization, and then map the fields in REDCap to fields
in your OnCore instance. The focus of the project at this phase is in administration and tracking of active/inactive IRBs. It is not, by default, set up for storing patient data. The infrastructure is there to expand
and forks of the project or contributions toward it are welcome to support features like this.

### SyncDashboard.php
This page loads saved data from the module config which tracks records that have mismatched data between REDCap and OnCore. This was
separated out into another page in the interest of allowing for easier automation of record checking, as well as allowing a user to be able
to take breaks between records without needing to fully resynchronize each time.

# Usage & Licensure
This module is provided "as-is" and is covered under Apache 2.0. 

Additionally, I request that if you modify the project for an additional purpose that this is shared in the interest of supporting
other REDCap users. This could be done by forking the project and including changes there, or submitting requests to incorporate your changes into the project. This is not required, but
would be in the spirit of this module's development.
