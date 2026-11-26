# Transcript queue assignments

Use **Transcript requests** in the staff workspace. The existing queue now supports current work assignment; it does not create a separate queue for each processing step.

## Find the office work

**Assigned work** offers **All**, **My work** and **Unassigned**. **Request status** starts with **Active requests** (pending or prepared); choose **Pending**, **Prepared**, **Finished requests** or **All** when needed. Finished includes delivered and cancelled requests. The ordinary search matches request references, delivery methods and status. Filters apply before the twenty-row page limit; **Load more** keeps the accepted filters.

**Assigned to** displays the current colleague name. A missing row means unassigned. Names are current labels, not the identity printed on an earlier issued transcript. If the account is disabled, loses a required duty or changes its person link, it displays **Assigned account unavailable**. The recorded assignment remains; it does not become Unassigned or transfer to a replacement person. Office staff can inspect it under All and explicitly clear or reroute active work.

## Claim, delegate or clear

Open a request. Its **Work assignment** card sits beside the existing preparation and delivery tasks:

1. **Assign to me** selects your current canonical account/person. Alternatively, enter at least two characters in **Search eligible colleagues**, page the matching results and choose a colleague. The chooser searches names and institutional IDs, without displaying email addresses.
2. **Assignment to save** shows the proposed selection. **Clear assignment** explicitly selects Unassigned.
3. Choose **Save assignment**. Selecting a colleague or using Assign to me/Clear does not save by itself. A successful save refreshes the existing queue while keeping its filters.

A colleague must have an enabled linked account and both **`sis:transcript:manage`** and **`sis:student:read`**. These are also required of the operator. Assignment grants no permissions, does not create employment/student capabilities and is not an exclusive processing lock. Other authorized office staff can still help with fulfillment. Replacing an account's person link does not inherit earlier My work assignments; selected identities are checked again when saving.

Only pending or prepared requests can change assignment. Delivered/cancelled requests retain their assignment for inspection, with no reassignment controls. Completing a request removes it from the default active view without erasing its documents or working routing.

## Resolve a changed request

Another colleague's edit, a newly prepared document or a finished request can make an open assignment editor stale. The server refuses that save and keeps the selected colleague in the drawer. Use **Reload assignment**, review the current assignment/status and deliberately save again if the request remains active. An old revision cannot overwrite a newer assignment. Reloading keeps the proposed selection; closing the drawer discards its draft.

A no-op save against the current revision does not increment the assignment counter or add a duplicate audit. If the reply to a save is lost, reload current assignment before trying again. This working CRUD does not promise the separate original-result receipt behavior of transcript preparation/delivery.

Access loss clears the request/editor content and disables the old task context. Delayed or detached colleague/context results cannot restore it. Bootstrap controls/icons, labelled fields, keyboard access, visible status/errors and narrow/enlarged-text reflow are supported. Nothing is saved in local/session storage.

## Records and authorization

Assignments are current operational routing, separate from protected transcript artifacts, release checks, delivery evidence, personal notifications and academic/financial facts. Saving/clearing does not change request status/version, prepare or deliver a document, send a message, recreate a notification or grant access. Existing fulfillment and owner workflows keep their own authorization and review rules.

The portable ULID table retains one current request-keyed assignment counter, optional account/canonical-person pin and update time; clearing retains the counter and removes both assignee references. An assignee/person/request index and a request status/ID index support bounded queue discovery. Eligible-account lookup now requires all supplied duties in SQL before pagination; the existing Admissions chooser uses that same contract with its original one duty.

Mutations serialize with current access changes and then use fulfillment's student/person/request lock order. Initial routing reads acquire no request lock, including inside an enclosing transaction. Expected request/assignment revisions, current account/person/duties and mandatory metadata audit are rechecked before completion. Failed audit, source, authority, account-link or assignment validation rolls back the transaction. No source history or earlier assignment is invented during migration; older requests start unassigned. Destructive downgrade is refused. Institution-selected retention/disposition and further shared-queue delegation remain separate roadmap work.
