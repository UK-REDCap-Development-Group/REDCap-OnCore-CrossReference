# Overview
The REDCap OnCore Cross Reference (ROCS) tool provides a highly configurable connection between a REDCap project and an OnCore instance. ROCS adds a new page on the left-hand menu of a project called "OnCore Field Mappings" which allows a user to include or disclude forms from a project for mapping.

Once a form has been selected for mapping, the user is able to select a field pulled from OnCore's API in a dropdown. Once a selection is made, it is saved to the module's project configuration. Dropdowns allow for a user to type field names in the event they know what they are looking for and do not want to scroll through the often extensive list of available fields.

ROCS includes an autosave feature which is triggered when critical actions are performed. Critical actions are defined as when a user updates selected forms through the "Manage Forms" interface, and when fields are mapped using the dropdowns.

ROCS provides a ONE-WAY connection allowing data to be pulled from OnCore to REDCap in order to establish a "source of truth".

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