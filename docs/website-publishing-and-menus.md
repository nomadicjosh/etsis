# Website publishing and menus

Open **Website Manager** from the staff navigation. **Website pages** connects the existing Vihzhuo designer and page settings to publication review. **Website menus** provides the visual navigation builder. Existing language settings remain under **Website settings**, and page removal remains available with a confirmation.

Website work remains audited CRUD. It does not create academic events, reports or student-record copies. Page drafts, menu drafts and review proofs stay out of browser storage. Authors need `vihzhuo:manage`; approving, returning, archiving and publishing also require the existing independent `sis:portal:publish` duty. The migration grants neither duty to an account or role.

## Which pages can the builder control?

The page builder can replace the public homepage at `/` and create additional public website pages. It currently cannot replace the existing application screens in the portal or staff workspace.

| Route | Current behavior |
| --- | --- |
| `/` | A currently visible, approved builder page replaces the default public homepage. When no approved builder page is visible at this route, the built-in homepage appears. |
| An unused public route, such as `/about/`, `/admissions/` or `/student-life/` | Creates an additional website page with its own layout, design, translated routes and publication approval. The builder is not limited to a single page. |
| `/portal/`, `/portal/courses/`, `/portal/news/`, `/portal/events/`, `/portal/apply/` and student screens | Existing application pages take precedence. A builder page saved at one of these routes does not replace the application page, even after approval. |
| Existing sign-in, staff and API routes | Continue to use their application workflows and access controls. They cannot be replaced through Website Manager. |

**The `/` homepage override does not also replace `/portal/`.** These are separate routes; `/portal/` continues to show its built-in page.

The [public program catalog](public-program-catalog.md) at `/portal/programs/` and its program-detail routes are application-owned screens. **Live academic programs** shows only explicitly approved website selections of published academic definitions and links to those details. Its presentation settings stay editable in the builder; program descriptions, academic options and visibility use **Academic catalog → Programs → Website catalog**. The application handoff and private applicant/student services retain their existing authorization and submission rules.

To add a website page, choose **Website pages → Create a page**, select a layout and enter an unused **Public route** for each language. Save the settings, design and save the page, then submit it for publication review. Add the page to **Website menus** when it should appear in navigation. Changing a page's route or approving it does not override an existing application route.

## Page settings and canvas editing

**Create a page** and **Page settings** share the current Website Manager header, Bootstrap styling, icons and page navigation. Identity/layout, per-language metadata/routes and automatic navigation are grouped separately. Each language has unique field labels. **Back to website pages**, **Cancel** and **Design page** connect the workflow; leaving changed settings asks before discarding them. Submission keeps the native field names and framework CSRF token. Native creation, editing and language settings still return to the manager; the duplicate older controller and manager view have been removed.

The designer adds a page-specific header, **Website pages** return, accessible preview controls and a shared editing explanation. It keeps Vihzhuo's native component permissions, Settings rerendering, HTML serialization, rich-text editor and asset manager. Saving a design remains separate from publication review. The viewport permits browser zoom, and reduced-motion preferences apply to the new shell.

The view file determines the editing contract:

| Block source | Editing behavior |
| --- | --- |
| `view.html` | Text, images and links are editable on the canvas. Edited HTML is saved per page/language and used on the public page. |
| `view.php` | Theme-controlled markup and application data are rendered on the server. Authors change declared Settings controls; child blocks are editable in designated nested content areas. |
| Application `.phtml` partial rendered by a PHP block | Follows that PHP block's contract. A partial is not made directly editable merely because it contains headings or images. |
| Layout outside the page content area | Remains fixed; authors edit the page body and the blocks placed there. |

Native block discovery uses `view.php` or `view.html`; `.phtml` is the application template format used by the PHP-backed views, not a separately discovered native block filename. Do not make live server-rendered records freely editable or assume arbitrary PHP output will survive a later rerender. `[blocks-container]` and the registered nested HTML container block provide deliberate content areas within dynamic structures.

**Campus welcome**, **Student & prospect pathways**, **Application invitation** and **Lead** now use editable HTML. Authors can change their text/headings, welcome image and alternative text, links and layout elements through the normal canvas controls and image manager. The previous PHP Lead view hid its existing HTML view and is removed. The three static campus wrappers are also removed. The inspected configured database contained zero saved pages/translations before these source changes; no stored design needed conversion or was overwritten. Built-in portal views continue to supply their default presentation outside a saved builder page.

**Live course offerings**, **Live academic programs**, **Live public news feed** and **Live public campus events** remain PHP blocks. Select the block and use **Settings** to edit its **Section heading**, **Heading caption** and **Section link label**; academic programs also expose **Introduction**. Saved text is escaped when rendered, including in the underlying `.phtml` views. Course/program/news/event records remain governed by their own publication rules. Navigation/footer blocks and fixed theme chrome keep their theme-controlled behavior.

A page already open in the designer must be reopened to load the changed block definitions. Configure and approve the saved page using the normal review workflow after editing it.


## Accessible accordion and carousel blocks

**Accordion** and **Carousel** remain dynamic PHP blocks: select the block and use **Settings → Number of items (1–20)** to change their structure. Edit headings, answers and slide content in their nested HTML child blocks. Existing relative child IDs stay the same, so already saved heading/body/slide content is retained. Each rendered accordion uses separate DOM IDs for its headers and panels; two accordions on one page cannot open each other's answers. All answers start collapsed with matching button state. Tab reaches each heading, and Enter or Space toggles it. Opening another answer closes the earlier answer in that same accordion only. Keep interactive links and controls in the answer, rather than inside the heading button.

A carousel starts with its **first** slide visible, including when it has only one slide. Multiple-slide carousels offer visible **Previous slide** and **Next slide** Bootstrap buttons with icons, keyboard arrow navigation and a polite **Slide N of M** status. Focus stays on the control used. Hidden slides and their links stay out of the ordinary keyboard/accessibility flow. Slides do not advance automatically or on swipe; visitors control when information changes. One-slide carousels omit redundant movement buttons. Reduced-motion preferences suppress slide transitions, and controls wrap at narrow widths and enlarged text.

Give every carousel a meaningful **Accessible carousel name**, especially when placing more than one on a page. The setting is escaped as text. The starter image has empty alternative text as a decorative placeholder; supply meaningful alternative text for images that convey information, and keep required information available as text rather than only inside an image. Announcements and program/student data retain their separate publication/privacy workflows.

Rendering is bounded to **1–20 items per block**. Nonpositive counts render one item; malformed counts render the two-item default; larger valid integers render twenty. These display bounds do not rewrite stored designs. Review existing large blocks deliberately before publication. Structure settings and canvas editing do not approve a page or change its saved publication.

The shared behavior and focus styles load in Harbor, Quad, Horizon and Bootstrap Business. Business also has a keyboard **Skip to main content** link targeting the page body after navigation. Theme assets use clean URLs such as `/themes/bootstrap-business/css/style.css`; Apache maps them into the physical theme `public/` directory. `WebsiteTheme` keeps actual parent/child asset resolution while excluding that directory name from URLs. Use paths relative to `public/` in `phpb_theme_asset()`, such as `css/style.css`. Other web servers need an equivalent mapping. The PHP browser-test router supplies a restricted static-file equivalent because it does not read `.htaccess`.

Focused checks cover real nested saved content, independent instances, keyboard controls and focus, one-item and bounded/malformed settings, escaped labels, self-only script policy, all four bundled styles, clean asset requests, 320px/200% text and automated accessibility. They do not qualify other form/content blocks, every assistive technology or an institution's third-party theme. A saved page still needs its normal design save and publication review; no institutional design or academic record is modified by these source changes.


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


November 23 follow-up validation: **17 focused PHP tests / 89 assertions** pass. The actual-provider check renders the current settings screen and native designer, verifies HTML-versus-PHP block metadata, saved HTML precedence, editable live-feed copy/escaping, current CSRF/private transport and unchanged academic history. Targeted website/portal PHP regressions pass. Thirty scoped PHP/view files pass coding standards; thirty-one PHP/view/fixture files and three JavaScript files pass syntax checks, together with whitespace and fifty-seven new literal translation entries. The three browser scenarios now pass: multilingual submission, unsaved draft protection, narrow/enlarged reflow/WCAG and actual native canvas editing/Settings rerendering. Secondary-button contrast is corrected. New/empty portal starters now contain both public HTML and native component data with matching ID-prefixed body instances, so their content appears in the canvas. Existing page content and publications are not rewritten. The fictional canvas theme excludes application blocks that resolve live services. Screenshots were inspected; broader device/theme acceptance remains open. No database migration, institutional content write, report, dependency or vendor edit was made.
