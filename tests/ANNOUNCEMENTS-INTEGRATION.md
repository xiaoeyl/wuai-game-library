# Announcements v1.6 Workspace Integration

## Final Local Recheck (2026-11-01)

This checkpoint supersedes the earlier pending-browser statements below.
The CLI and actual native-admin/anonymous browser suites passed. Evidence:
`dist/checks-v1.6.0/admin-feature-final-cli.log` and
`dist/checks-v1.6.0/announcement-final-browser.log`. Enable/order/disable,
nonce/role/method controls, private isolation, search/pagination, real footer
widget and 320/390/1440 layouts passed; marked fixtures and original settings
were cleaned/restored. PHP 7.4 syntax passes; full integration uses PHP 8.3.
Freeze, ZIP identity and GitHub publication remain separate gates.

Status: **v1.6 workspace integrated candidate - NOT RELEASED**. Root has integrated
the module and portal wiring, and `tests/announcements.php` has passed in the
guarded disposable SQLite environment. The new browser fixture/suite is written
and syntax-checked, but **root has not executed the announcements browser suite
yet**. No production data, messages, payments or source-site notices are imported.

Related discovery CLI/browser suites have passed. Topic-media's original,
native-18, cleanup and raw-upload suites initially passed on both SQLite and
independent strict-session MariaDB, and its real multipart browser suite passed;
the subsequent native/user-deletion review fixes still require final regression.
None of that substitutes for announcements browser validation. PHP 7.4 remains
unverified. `comment-actions` is not loaded or included in candidate packaging.
Final source freeze, package verification and release remain pending/root-owned.

## Reference And Scope

The supplied `newsflashes.html` has a user-widget announcement list at line 1727,
titles/URLs at line 1731, an empty state at line 1736, and an `/announcement`
"all announcements" link at line 1740. The dynamic source data was not captured;
do not invent old notices or assume the original authorization model.

The candidate reuses real WordPress ordinary posts, pages and `sluyu_news`
content. An administrator explicitly selects up to 100 public, password-free
content IDs and their order under **Wuai Sluyu > Site Announcements**. A category
alone cannot make an ordinary author's post an official announcement. Default is
disabled/empty. Content edits remain in the native WordPress editor.

The dedicated widget avoids changing the existing nineteen-mode widget contract.
It displays selected titles/dates and an "all announcements" link. The full page
has title search, pagination and plain-text excerpts; it does not run shortcodes,
`the_content`, hidden-body renderers, or external scripts. Explicit selection is
not permanent permission: every read freshly requires publish status, no password
and an allowed post type. Held content is additionally excluded when supported.

## Integrated Workspace Wiring

The v1.6 candidate includes the following wiring. These are contracts to retain
when reviewing the final tree, not unperformed integration instructions.

1. In `wuai-sluyu/functions.php`, the module loads after `inc/portal.php` and
   before `widgets_init` runs:

   ```php
   require_once WUAI_SLUYU_DIR . '/inc/announcements.php';
   ```

2. `wuai_sluyu_portal_pages()` contains the real `announcement` entry, equivalent
   to this contract with user-facing labels localized to the Chinese UI:

   ```php
   'announcement' => ['Site Announcements', 'Public notices selected by the site administrator', 'notification-3-line'],
   ```

   The existing route generator gives
   `/announcement/`, `/announcement.html` and plain-permalink query support.

3. `wuai_sluyu_render_portal()` maps the route to the actual handler:

   ```php
   'announcement' => 'announcements'
   ```

4. Portal navigation/service links may point to `announcement` without special
   page creation. Existing service initialization can
   create the editable empty portal page once the route is registered.

5. No changes to `inc/widgets.php` or `inc/widget-content.php` are required.
   `Wuai_Sluyu_Announcements_Widget` self-registers as a separate widget, ID
   `wuai_sluyu_announcements`. It uses existing panel/widget/filter CSS classes.
   Root should verify title wrapping and mobile form layout in the real browser.

6. The native configuration screen is available at:

   ```php
   admin_url('admin.php?page=wuai-sluyu-announcements')
   ```

## API And Boundaries

- `wuai_sluyu_announcements_list($input = null, $per_page = 12)` returns a
  `WP_Error` or `{items,total,pages,page,search,enabled}`. `null` reads `$_GET`;
  widgets pass an empty array to avoid inheriting page search parameters.
- Public inputs: `announcement_search` (scalar, at most 80 characters) and
  `pg` (positive scalar decimal page up to 100000). Wildcards are literal SQL
  search text. Invalid arrays never broaden the public query.
- `wuai_sluyu_announcements_save($input)` checks HTTP POST, `manage_options`,
  current ban state, nonce and newly selected content eligibility. Previously
  selected IDs may remain in a stale list so the administrator can still disable
  or reorder it after unpublishing; those entries never bypass public read checks.
  The admin-post handler uses
  it; no public write action or AJAX mutation endpoint is added.
- Stored option: `wuai_sluyu_announcements`, one bundle of integer `enabled`
  and distinct positive integer `ids`. Configuration reads and final readback use the database row,
  not an object-cache success assumption. Bad configuration fails closed.
- Listing entries are explicit ordinary public content. They do not inherit a
  password unlock or administrator bypass. An administrator capable of editing
  the selected post is a trusted content publisher; edits to that post update
  the notice. Do not select a page whose public body contains sensitive data.
- Already public HTML/cache cannot be recalled. Existing CDN/full-page cache
  deployment rules still apply; update/purge cached notices when unpublishing.

## Executed CLI And Pending Browser Validation

Root has run `php tests/announcements.php` successfully against the integrated
candidate in the guarded disposable SQLite installation. The suite
backs up/restores its one option and creates marker-prefixed users/posts only;
it blocks mail and cleans up in `finally`. It checks administrator POST/nonce,
unauthorized/GET/array failures, exact selection order, search, pagination,
unpublished/password/type restrictions, stale post-cache bypass attempts,
widget behavior and non-execution of embedded shortcodes.

The new `tests/announcements-browser-fixture.php` and
`tests/announcements.cjs` have passed PHP lint / `node --check` only. Root must
reserve shared QA and run them serially, followed by fixture `--cleanup` even
if browser validation fails. They use the exact loopback SQLite root, output
outside the served site, isolated marked users/content, a mail-only MU guard and
the real native login/admin-post form; there is no HTTP mutation helper. The
manifest contains no test password. Cleanup verifies ownership, restores the
original announcement option and removes only its own appended footer widget.

The pending browser coverage includes real admin enable/reorder/disable and
error UI; GET/nonce/role/array/private-selection rejection; anonymous 12+3
pagination, search and hidden/unselected-content isolation; an actual footer
widget; unchanged settings after public GETs; and 320/390/1440 layouts without
overflow or JS/console errors. WordPress Heartbeat is locally isolated so it
does not mutate the shared fixture; this is not a test of Heartbeat itself.
Screenshots and `announcements-browser-results.json` are evidence only after
that suite is actually run and its assertions pass.

Before any release, complete this browser validation and the workspace's final
native/user-deletion regression, then freeze source/hashes and rerun packaging
verification. PHP 7.4 compatibility is not established. This document does not
claim that the final release or GitHub publication is complete.
