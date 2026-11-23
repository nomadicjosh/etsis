# Website publishing and menus

Open **Website Manager** from the staff navigation. **Website pages** connects the existing Vihzhuo designer and page settings to publication review. **Website menus** provides the visual navigation builder. Existing language settings remain under **Website settings**, and page removal remains available with a confirmation.

Website work remains audited CRUD. It does not create academic events, reports or student-record copies. Page drafts, menu drafts and review proofs stay out of browser storage. Authors need `vihzhuo:manage`; approving, returning, archiving and publishing also require the existing independent `sis:portal:publish` duty. The migration grants neither duty to an account or role.

## Review and schedule a page

1. Create the page, review its routes/translations and design it in the existing page builder. Save your design.
2. Return to **Website pages → Publication settings** and **Submit for review**. Confirm the reviewed action. Reviewing alone does not publish anything.
3. A publisher opens the same page. Choose **Approve publication**, or **Return to author** with a correction note.
4. For approval, optionally enter start/end dates in the explicitly labelled **UTC** controls. A blank start means immediate publication; a blank end means ongoing visibility. A dated start/end must be in the future, and the end must follow the start.
5. Review the action and confirm. **Revise this action** preserves entries. Reviews expire after twenty minutes and are bound to the current actor and saved revision. An accepted retry returns the original result without repeating the action.

Approval covers the exact saved page design, page settings, translated titles, metadata and routes. A subsequent edit makes the page a draft for public reading until it is submitted and approved again. The earlier approval and accepted receipt are not rewritten. There is no inferred approval for an older saved page. Live course/news/event blocks retain their own independently controlled public data; page approval does not freeze those feeds.

Direct page URLs and automatic page-navigation links share approval/window checks. Archived, changed, scheduled and expired pages stay unavailable. Start is inclusive; end is exclusive. No publication worker or polling is needed. Public rendering uses the captured approved page and translations, rather than rereading a later draft. Full-page caching is disabled at the integration boundary so cached HTML cannot bypass these checks. Saved routes retain their original values; routing compares trailing slashes consistently and does not select ambiguous routes by database accident.

## Build a menu

Choose **Primary menu**, **Secondary menu** or **Footer menu**. These locations are shared by the campus themes and the Bootstrap business theme.

- Add a **Website page**, **Custom link** or **Dropdown group**. The page chooser loads bounded pages on demand and filters the choices already loaded. Selecting an item makes it the destination for new child items; **Add at top level** returns additions to the root.
- Drag by the grip to reorder or nest items. Each item's Bootstrap action menu also offers move up/down, nest, outdent, duplicate and remove. These controls support keyboard use. Menus support **100 items and three levels**; invalid moves restore the previous structure.
- Edit labels, accessible descriptions, Bootstrap icons, visibility and new-tab settings. A page destination can be replaced through the chooser. Custom links accept safe local paths/fragments, HTTP(S), email and telephone links. Unsafe schemes, credentials and control characters are rejected; labels and descriptions render as text. Dividers belong inside dropdowns. Empty enabled groups must receive children before publication.
- Use **Undo/Redo**, or copy another location's draft. Undo history is bounded and stays in page memory. Removing an item can be undone. Switching locations or reloading an unsaved draft requires an explicit keep/discard choice.
- Inspect the responsive preview, including nested Bootstrap dropdowns, then **Save menu draft**. Saving leaves the currently published menu intact. A publisher uses **Review publication → Confirm publication** to replace it with that saved structure. Unaccepted stale reviews require a fresh review; accepted retries preserve their original results.

Page links appear only while the referenced page's exact current revision is approved and visible. They disappear after edits, archiving, expiry or removal; groups with no available children and redundant dividers are omitted. Custom links remain deliberate publisher-authored destinations. A new-tab link includes `noopener noreferrer` and a screen-reader notice. Until a location receives its first menu publication, approved legacy page-navigation settings provide its automatic links; explicitly publishing an empty menu clears that automatic list.

Dropdowns use the installed Bootstrap bundle and viewport placement. Open popups move outside clipping/stacking ancestors and return on close; nested popups retain their parent relationship. Arrow keys, Home/End and Escape support navigation and focus return. The manager uses the existing installed SortableJS with reduced-motion handling and keyboard alternatives. No dependency was added.

## Configuration and operating bounds

`config/vihzhuo.php` still controls the manager/editor URLs, authentication service, theme inheritance and builder settings. Its router class now selects the application's bounded `WebsitePageRouter`; `CodefyPageBuilder` checks publication and supplies the approved snapshot. The editor uses the configured URLs and CSRF header. Private management APIs under `/sis/website/` preserve framework CSRF, current authority, no-store responses and debug suppression. All new publication, menu-row and receipt primary keys are ULIDs; Vihzhuo's existing numeric page references remain their existing references.

Migration **20261123000004_CreateSisWebsitePublications** adds current page-publication state, minimal operation receipts, three empty menu drafts and menu receipts. Existing pages, translations, approvals, grants and protected history are not converted or inferred. Menu JSON uses sized text storage for the full item limit, with a one-MiB structured-input bound. The configured database columns were widened to match that portable definition with all original menu bytes preserved. It is retry-safe and refuses destructive rollback. It applied to the inspected configured development database with original page/journal/support/grant bytes and independent checksums unchanged.

Private page lists use twenty-row continuations; page selection retains at most one hundred loaded choices. Publication reads allow one MiB of stored design and twenty translations per page. Route matching supports up to 999 stored translation routes; automatic legacy navigation supports up to one hundred published-page candidates. Readers stop rather than silently presenting an incomplete over-limit route/menu set. Explicit menu page lookups are bounded by its one hundred typed items. Larger institutional qualification, broader theme/block acceptance and backups/retention remain roadmap work.

Checks cover independent author/publisher duties, exact windows, changed designs/routes, original retries, rollback after audit/source/authority/expiry failures, typed tree/URL/depth limits, five SQL compiler dialects and real provider/CSRF/private transport. Native Vihzhuo rendering is exercised in all three campus themes, including draft rejection and cache-bypass prevention. Browser checks cover actual dragging, keyboard ordering, draft/publish separation, unsaved changes, access clearing, narrow/enlarged text, WCAG scans and public nested dropdowns above a clipped navbar/sidebar.

Final focused validation: **69 PHP tests / 388 assertions** across the new safeguards/provider checks and existing portal/content/announcement/event regressions; **24 browser cases** across the new manager/public menus and existing portal/announcement flows. Targeted coding standards, PHP/view lint, JavaScript syntax, literal translation coverage and whitespace checks pass. Checks were low priority, deadline/memory bounded and used one browser worker.
