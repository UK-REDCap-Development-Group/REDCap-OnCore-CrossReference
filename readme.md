# Overview
The REDCap OnCore Cross Reference (ROCS) tool provides a highly configurable connection between a REDCap project and an OnCore instance. ROCS adds a new page on the left-hand menu of a project called "OnCore Field Mappings" which allows a user to include or disclude forms from a project for mapping.

Once a form has been selected for mapping, the user is able to select a field pulled from OnCore's API in a dropdown. Once a selection is made, it is saved to the module's project configuration. Dropdowns allow for a user to type field names in the event they know what they are looking for and do not want to scroll through the often extensive list of available fields.

ROCS includes an autosave feature which is triggered when critical actions are performed. Critical actions are defined as when a user updates selected forms through the "Manage Forms" interface, and when fields are mapped using the dropdowns.

ROCS provides a ONE-WAY connection allowing data to be pulled from OnCore to REDCap in order to establish a "source of truth".

## OnCore fields and endpoints

ROCS discovers mapping fields from these protocol endpoints: `protocols`, `protocolConsents`, `protocolSponsors`, `protocolStaff`, `protocolEprmsSubmissions`, `protocolPrmcReviews`, `protocolIde`, `protocolInd`, `protocolIrbReviews`, and `protocolInstitutions`. It first uses `protocolManagementDetails` to resolve the protocol ID.

The OnCore Field Mappings page lists scalar fields at every depth of each successful response. Nested object members use dots. ROCS expands `protocolStaff.contactId` through `GET /contacts/{contactId}` and `protocolSponsors.sponsorId` through `GET /sponsors/{sponsorId}`. This makes paths such as `[].contact.displayName`, `[].contact.email`, and `[].sponsor.sponsorName` available for mapping. Existing top-level mappings continue to work.

### Lists, and choosing one value out of one

A mapping is deliberately the broadest reading of the API. When an endpoint returns a list, the mapped path uses `[]` and reads *every* value, with unique values joined by `; ` before being compared with the REDCap field. The Field Mappings page offers nothing narrower, because a mapping has to hold for every record it is applied to.

Choosing one value out of a list is a decision about a single record, so it is made in the adjudication view. When a mapped field comes back with more than one value, the OnCore column of the adjudication table lists them individually above the REDCap value, along with an "All *n* values" choice that keeps the joined list. Whichever is clicked is what gets written to REDCap. The mapping is untouched, so the next sync still compares against the whole list.

Each value is labelled with whichever field best tells the entries of that list apart — a staff list mapped to `[].contact.lastName` shows `Lovelace` under "Principal Investigator". ROCS picks that label field automatically: it must be present on every entry, hold a short non-empty string, not be an identifier (`contactId`, `protocolNo`, `staff_code` and similar are skipped), and not read the same on every entry. Fields whose name ends in `role`, `type`, `category`, `status`, `name`, `position`, or `title` are preferred. A list with no such field still offers its values, just unlabelled.

A path can also be hand-written as `[key=value]` to read only the entry whose `key` equals `value`, e.g. `protocolStaff[staffRole=Principal Investigator].contact.lastName`. Nothing generates that form, but saved mappings using it still resolve. Inside it a backslash escapes the next character, so a value containing `]` or `\` is written `[label=a\]b]`.

Field paths are resolved in two places that must stay in step: `classes/OnCoreFieldPath.php` server-side during a sync, and `discoverOncoreFields`/`oncorePathEntries` in `scripts/scripts.php` in the browser. Both expose the values as `{value, label}` entries; the mapped value is those entries joined, so the comparison and the adjudication choices can never disagree.

The proxy also permits direct `contacts` and `sponsors` lookups, plus `protocolTasks` and `contactCredentials`; task lists and credentials are not part of the default mapping set.

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
