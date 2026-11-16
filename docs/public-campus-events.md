# Public campus events

From **Spaces & scheduling**, open a standalone room or online booking and choose **Manage public event**. Publishing requires both `sis:scheduling:manage` and `sis:portal:manage`; owning a booking alone does not permit public publication. The institution can grant these duties together to its event publishers. Reservations still use the existing request, approval, cancellation and rescheduling workflow.

1. Approve the booking and resolve its requested dates.
2. Enter a separate **Public event title** and **Public event description**. The internal purpose is never copied into either field. Public content is plain text; the portal escapes it.
3. Choose **Review public event**. Review the public words, retained room/building labels and every active meeting time, twenty dates at a time. The review uses the institution's timezone and expires after twenty minutes.
4. Choose **Publish public event**. Confirmation locks and checks the approved source, location, current editorial revision, actor and both permissions again. The public calendar link opens the resulting portal view.
5. Edit and review again to update public content. **Withdraw public event** removes it from public results while keeping the reservation and its history. Withdrawal remains available if the booking was subsequently cancelled.

Public publishing accepts approved standalone room or online bookings with no course-section link and between one and 366 active occurrences. Class-linked physical and online meetings remain in the private academic timetable. Neither approving a booking nor publishing an academic meeting plan makes it a public event.

## Online events

In **Plan a meeting**, select **Online meeting without a room** and leave **Course section** empty for a standalone event. The same choice carries into the recurring planner. Scheduling authority is required through completion; a regular account cannot create an unlinked online booking. Approval confirms the schedule without reserving a room or checking student/faculty availability. Linked classes still require a published online/hybrid offering and valid instructional dates.

Use **Manage public event** after approval, entering and reviewing the separate public invitation as above. Public locations display **Online event** and **Joining details provided by the organizer**. No private conferencing link, credential or joining instruction is copied from the booking purpose. The public calendar, search and native Vihzhuo feed share these safe labels. A move between online and physical locations uses the existing reviewed reschedule flow, preserving the original meeting; publish its replacement explicitly if it should be public.

API booking and reschedule inputs require an explicit `room_id`: a room identity, or `null`/an empty selection for online meetings. Omitting the field is rejected instead of silently creating an online event. Standalone recurring creation serializes on the authenticated requester's account identity, while existing room and class requests retain their room/section locks. Original retries preserve later approvals and cancellations.

## Public calendar and source checks

The public calendar at `/portal/events/` offers full civil months or Monday–Sunday weeks, event/location search, day choices and twenty-event daily pages. It initially opens the requested calendar date; day and page changes preserve filters. Server-rendered links/forms work without JavaScript. AJAX replaces the same escaped calendar fragment, restores keyboard focus and ignores responses superseded by a newer selection or search draft. No profile, finance, roster or account overview is requested. Dates use actual timezone offsets and the shared civil-day model, including overnight meetings and daylight-saving changes.

Only explicitly published content and times appear. Public reads rejoin the approved source booking and uncancelled occurrence, matching its retained room or online location and exact times on both the booking and occurrence. A stored location mismatch hides that time and must be corrected before a new publication review. Cancelling a single occurrence hides that occurrence and keeps other published dates visible. Cancelling the whole booking hides all its times. A rescheduled replacement is a separate booking and is not published automatically; review its public invitation explicitly. Room/building labels retain what the publisher reviewed until a later review updates them. Internal categories, people, private purposes, review tokens and source booking/occurrence identities are omitted. Public identities are distinct from private source identities.

The **Live public campus events** Vihzhuo block shares those safe reads and shows up to three event times in the next 31 days, linking to the corresponding calendar day. Its heading is editable and its cache is disabled. New generated campus pages include the block; existing CMS pages remain unchanged, and their editors can add it from **Campus portal** blocks. All three campus themes inherit the calendar navigation and progressive enhancement.

Migration `20261115000014_CreateSisPublicEvents` adds `sis_public_events` and `sis_public_event_times`, their ULID relationships and both calendar-range indexes. These are ordinary public-content CRUD/read tables, not another domain event journal. Current content and its published-time projection commit together with required audit and revision checks; no booking, academic or financial event is fabricated or rewritten. A repeated accepted publication returns its saved result after withdrawal without publishing again. An older review after a later publication is rejected rather than restoring earlier content.

The configured MariaDB upgrade verified all four foreign keys, both calendar indexes and the safe joined read. It created empty tables and preserved every inspected existing record and all twenty recorded events without changing their stored bytes. Migration compilation also covers the framework's MySQL, PostgreSQL, SQLite, SQL Server and Oracle dialects; SQLite integration checks execute the schema and transactional workflows.

Calendar searches apply before a 501-row probe. More than 500 matching times produces an actionable search/week request instead of partial counts; daily rendering remains paged. The small block feed deliberately has no calendar-total claim. Reads use indexed projections and append no journal events or notifications. Permission/audit failures roll back editorial writes. Institutional conflict enforcement, dated team teaching, additional resource types and controlled notifications remain subsequent work.


Migration `20261115000015_AddSisVirtualPublicEvents` makes the existing public-event room reference nullable. Both room-free migrations share `NullableRoomReferences`: native nullable-column statements for the supported database drivers and a bounded verified SQLite conversion that preserves values, indexes, triggers, views and known inbound foreign keys. Missing columns or unsupported room-column definitions stop for review; no synthetic academic event, placeholder room, new event table or demo record is introduced.

The configured MariaDB upgrade verified the nullable room column, all four existing foreign keys, both calendar indexes and the joined public read. Existing person/account, booking, academic and public-content records, including the twenty recorded events, retained their original stored values.
