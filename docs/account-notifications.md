# Your notifications

The header bell opens one list for the signed-in account in both the workspace and portal.
Notifications appear together with a small type label, their available date and read state.
There are no empty category drawers to expand. Staff see their own personal notices here;
staff duties, department membership or an advising relationship do not open another person's bell.

The list combines accepted admissions, course-registration, waitlist, advising, final-grade,
standing, transcript and import updates, current registration openings, available campus announcements and recent action feedback.
Each source keeps its existing account and source checks. A type with no available notices adds
no heading or empty panel. Notification preferences remain under the person's workspace drawer
or the portal's **Personal information** screen.

## Read and return

Use a notice's action to open its existing owner-authorized workflow. Academic, standing,
transcript, advising and announcement readers stay inside the bell. **Back to notifications**
returns to the same list page and focuses that notice's action. The Back control is also available
while details load or need refreshing. Admissions and registration links lead to their existing
portal pages. Opening a notice acknowledges only the displayed revision where applicable.

Use **Older notifications** and **Previous notifications** for twenty-item pages, and
**Refresh notifications** for the latest available state. Known dates determine the combined
order; earlier unknown dates are not invented. Announcements use their publication time, rather
than the time someone first created the draft. Import outcomes use their recorded time.
The badge includes eligible sources and shows a plus sign when a bounded source count has more
unread items. A count is for this account, not the institution.

An unavailable source does not hide other sources' notifications. The list gives one retry message
instead of another empty category. Access loss clears private cached content; an expired sign-in
still requires signing in again. Delayed responses cannot restore a closed reader or bell.

## Delete a notification

Use **Delete notification** in the list or an opened reader. Confirmation stays inline and does
not create a replacement toast. Read notices remain available until deleted.

Saved personal notices and their read metadata are physically deleted. Deleting a course notice
does not register, drop or reinstate a class, change a grade, reverse a charge or alter protected
history. Deleting an announcement removes its personal read metadata and keeps only the existing
current-publication suppression marker, so the shared news does not immediately reappear.
Recent action feedback is removed from page memory together with any remaining toast.

There is no notification archive or trash. Earlier academic, financial and publication facts retain
their own histories. Later genuine outcomes may create fresh notices; replay and accepted original
retries do not recreate a deleted notice.

## Course-registration notices

New accepted ordinary registrations produce **Course registration accepted**. An early drop produces
**Course dropped**, a late withdrawal produces **Course withdrawal recorded**, and an accepted
reinstatement produces **Course registration reinstated**. Each replaces the earlier notice for
that enrollment. A different enrollment remains separate. Waitlist allocation keeps its existing
waitlist outcome notice without a duplicate ordinary-registration notice.

Notice creation is atomic with the accepted registration, academic, financial and required audit
changes. Reviews, failed decisions, timetable previews and original-result retries stay silent.
No historical notices are backfilled. Older unlinked people do not acquire an invented recipient.
An early-drop notice remains readable after the current enrollment and academic projection are
removed; its retained source is still protected registration history. Account relinking does not
transfer earlier private notices to another account.

Only minimized references and operational dates/read state are stored in the new notice table.
Lists show current course, section and term labels; a notice is not a historical transcript or
financial statement. Acknowledgement verifies its exact accepted source. Deletion remains available
for an owned damaged notice without repairing or deleting that source.

## Bounded operation

The browser merges small owner-authorized source pages and keeps at most five displayed pages,
bounded source buffers and the selected reader in memory. It does not store notices or private
answers in local storage or session storage. More pages require deliberate navigation; refreshing
starts a fresh observed list, rather than an immutable archive. Source cursors and repeated/empty
continuations are bounded. Independent assistive-technology and deployment/load acceptance remain
part of release qualification.


## Announcements for selected roles

Publishers can restrict reviewed campus news to [selected account roles](campus-announcements.md#selected-account-roles).
The combined bell includes only announcements currently available to your account. A matching
role assignment controls access; other permissions or a student/faculty capability do not make
you a recipient. A role change takes effect on the next server read or refresh. Your existing
read/deletion state still applies to the same publication; no extra category or notification
archive is added.

### Program-scoped campus announcements

The existing bell includes reviewed [program-targeted campus announcements](campus-announcements.md#selected-student-programs) when the signed-in account belongs to an active student with an active primary or secondary pursuit in any selected program. This is one shared notification list, with the same personal read/delete controls. Current membership, publication windows and enabled account links are checked by the server. Applications, closed pursuits and role permissions do not substitute for that program membership. Authoring choices stay in Campus content; this adds no notification category, recipient copies or preference setting.


## Registration is open

A **Course registration is open** notice appears automatically in the existing bell for each currently open, verified published term registration window. It is available to an enabled account linked to a current active student with at least one active program pursuit. Multiple pursuits do not duplicate the same term notice. Staff, applications, role permissions and Super status do not substitute for that membership. A staff member who is also an active student sees their own available openings.

The card shows the term label retained in the publication, the opening time and the closing deadline in the browser’s timezone. Its date in the combined list is the published window’s actual opening instant. Dates use exact UTC comparisons, including the opening and closing instant; the notice disappears after closing. Future windows, unapproved legacy setup and unavailable owner/student relationships stay silent. Publishing a changed policy provides a new revision; it can appear unread even if the earlier publication was read or deleted. An already open, genuinely published window can appear when this feature is installed or a student first qualifies: this is current availability, not a fabricated historical delivery.

Use **Find sections for this term** to acknowledge the displayed revision and open **Course registration** with that term selected when it is present in the current term picker. A stale or unavailable publication stops acknowledgement and offers refresh instead of silently marking a newer notice. Ordinary modified-link navigation can open registration directly without acknowledging the notice. The notice never grants registration permission, reserves a seat, enrolls someone, approves a prerequisite or changes a charge. Holds, student activity, section web-registration choices, prerequisites, course limits, capacity and fee review still apply through normal registration readiness.

Read notices stay in the combined list until deleted or the window is no longer current/open. **Delete notification** physically removes the personal read record and retains only a current-publication suppression marker, so the same shared window does not immediately reappear. It does not delete the term, publication or protected registration history. There is no notification archive or delivery history. Republished windows use their new identity/revision; old read/delete actions cannot suppress the new publication.

The existing term registration-policy editor explains this automatic in-application behavior. It requires no new recipient queue, fan-out worker, email, push delivery or preference category. Available notices are evaluated on the bell’s ordinary refresh; the already open bell does not claim an instantaneous push. Query filtering applies enabled canonical ownership, active student/pursuit membership, open published windows and personal read/deletion state before the twenty-item page limit. A finite extra candidate detects another page, and every returned publication is checked against the protected journal and current projection. A changed or unavailable continuation requires refresh.

Migration `20261127000005_CreateSisRegistrationOpenStates` adds current personal read and dismissal tables with ULID keys, owner/person/term uniqueness and cascade cleanup. It infers no prior reads or deliveries and changes no earlier event, enrollment, academic credit, balance or permission. Personal mutations use existing access/person/term locks, displayed publication revision, mandatory metadata audit and final owner/source/state checks; failures roll back state and audit together. Responses are private/no-store, browser mutations preserve framework CSRF, and debug collection stays suppressed. No browser storage holds notice data.
