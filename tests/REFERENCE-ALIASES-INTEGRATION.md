# Reference Aliases And Public Document Search: v1.6 Candidate

## Final Local Recheck (2026-11-01)

This checkpoint supersedes the earlier pending-runtime statements below.
CLI and actual anonymous HTTP/browser checks passed: 13 exact routes plus
`.html`, actual taxonomy scope, 404/400 cases, override/privacy isolation,
server document search/pagination with JS disabled, pretty/plain permalinks
and 320/390/1440 layouts. Evidence:
`dist/checks-v1.6.0/admin-feature-final-cli.log` and
`dist/checks-v1.6.0/aliases-final-browser-router.log`. Fixture cleanup restored
the exact permalink/rewrite options. An initial run hit PHP's missing-.html
dev-server 404 and pending-circle cleanup filtering; original failure is kept.
Use `tests/wordpress-router.php` plus `WP_QA_TRANSPORT_URL` for local HTTP reruns.
This is a real alternate PHP transport, not a stubbed WordPress response.
PHP 7.4 syntax passes; full integration uses PHP 8.3. Publication is separate.

## Ownership And Verification

Status: **v1.6 workspace integrated candidate - NOT RELEASED**. Root has just
wired the aliases/document-search candidate into the workspace. Runtime CLI and
HTTP checks for this wiring are still pending; syntax lint is not integration
test evidence. Module-specific files are:

- `wuai-sluyu/inc/reference-aliases.php`
- `tests/reference-aliases.php`
- `tests/REFERENCE-ALIASES-INTEGRATION.md`

The root task owns the shared entry point/helper/view wiring and serial
disposable QA. Do not describe aliases as runtime-tested until those suites run.
Related discovery CLI/browser and announcements CLI suites have passed, while
the new announcements browser suite has not yet been executed by root.

Topic-media's original, native-18, cleanup and raw-upload suites initially
passed on both SQLite and independent strict-session MariaDB, and its real
multipart browser suite passed. Those initial results precede the subsequent
native/user-deletion review fixes, which still require final regression. They
do not establish alias correctness. PHP 7.4 is unverified. `comment-actions`
is not loaded or included in candidate packaging. Final freeze, package checks
and any release/GitHub publication are not complete at this checkpoint.

## Observed Routes

The local reference directory is
`E:/小飞兔扒站工具/Website/Zz_www.sluyu.com`. These are actual category links,
not invented taxonomy terms or content imported from the reference site.

| Exact route | Reference label | Evidence file:line | Local taxonomy |
| --- | --- | --- | --- |
| `links/tuijzd` | 推荐站点 | `links.html:915` | `sluyu_link_cat` |
| `links/youlian` | 资源站 | `links.html:1000` | `sluyu_link_cat` |
| `links/bokz` | 博客站 | `links.html:1247` | `sluyu_link_cat` |
| `links/qtsjaz` | 其他 | `links.html:1404` | `sluyu_link_cat` |
| `newsflashes/60s` | 每日热搜 | `newsflashes.html:1144` | `sluyu_news_cat` |
| `newsflashes/sumchh` | 素描插画 | `newsflashes.html:1336` | `sluyu_news_cat` |
| `newsflashes/yxizixu` | 游戏资讯 | `newsflashes.html:984` | `sluyu_news_cat` |
| `document/xzsnbs` | 下载帮助 | `document.html:908` | `sluyu_doc_cat` |
| `document/syjds` | 使用教程 | `document.html:915` | `sluyu_doc_cat` |
| `document/cjyswt` | 常见问题 | `document.html:922` | `sluyu_doc_cat` |
| `document/zfsyw` | 支付问题 | `document.html:929` | `sluyu_doc_cat` |
| `circle/square` | 圈子大厅 | `circle.html:2397` | `sluyu_circle` |
| `circle/sscosplay` | Cosplay | `circle.html:2370` | `sluyu_circle` |

`circle/square` is a category in the reference, not an unconditional redirect to
the local circles directory. It resolves only if the actual local circle term
with slug `square` exists and is publicly opened.

## Workspace Wiring Contract: Five Touch Points

These describe the just-applied candidate wiring and its required behavior.
The pending serial checks must verify that the final tree satisfies all five.

### 1. Load The Module

The module belongs after `circle-moderation.php` and before `portal-views.php`
in `wuai-sluyu/functions.php`:

```php
require_once WUAI_SLUYU_DIR . '/inc/reference-aliases.php';
```

Do not insert the 13 paths into `wuai_sluyu_portal_aliases()`. That table cannot
carry category identity and would incorrectly make missing terms look valid.

### 2. Read Validated Alias Filter State

At the beginning of `wuai_sluyu_query()` in `wuai-sluyu/inc/helpers.php`, alias
state is read before the generic `$_GET` fallback:

```php
if (function_exists('wuai_sluyu_reference_alias_query')) {
    $handled = false;
    $value = wuai_sluyu_reference_alias_query($key, $default, $handled);
    if ($handled) { return $value; }
}
```

This only changes requests that the module identified as an explicit alias.
Normal routes still use the existing helper. Alias filters are validated once,
the physical path fixes `group`, and no superglobal is rewritten. Unknown query
keys do not become supported alias features.

### 3. Keep Alias Feed Queries In Their Exact Category

In `wuai_sluyu_view_feed()` in `wuai-sluyu/inc/portal-views.php`, after its normal
circle/feed arguments have been prepared and immediately before `new WP_Query`:

```php
if (function_exists('wuai_sluyu_reference_alias_feed_args')) {
    $args = wuai_sluyu_reference_alias_feed_args($args, $key);
}
```

The returned arguments explicitly select `publish`, empty passwords, the
correct post type and exact term, without child-category expansion. For circle
aliases, SQL additionally excludes review holds and topics related to any
nonpublic/ambiguous circle, including when the viewer is an administrator.

### 4. Preserve Alias Pagination Without Unrelated Query Arguments

At the beginning of `wuai_sluyu_portal_pagination()`:

```php
if (function_exists('wuai_sluyu_reference_alias_pagination')
    && wuai_sluyu_reference_alias_pagination($total, $key)) { return; }
```

This must precede the existing `$total < 2` check. It keeps `pg` and the validated
circle `kind`/`sort`; it does not propagate arbitrary incoming query arguments.

### 5. Delegate The Documents View

`wuai_sluyu_view_documents()` delegates to the module renderer:

```php
function wuai_sluyu_view_documents() {
    wuai_sluyu_reference_documents_view();
}
```

The new renderer covers both normal `document` and the four aliases. It keeps
the original nine built-in FAQ answers in a separate, clearly labeled section.
These tips are not counted or searched as database documents. Real document
search uses `doc_search`, a validated exact taxonomy `group`, deterministic
date/ID ordering and 12 results per page. It has no fixed 50-document limit.

## Routing, Content And Privacy Contract

- Thirteen exact rewrite rules; optional trailing slash and `.html` suffix.
  No wildcard legacy path redirect and no remote requests.
- Existing local term slug must exactly equal the observed slug in the correct
  taxonomy. No name-based guessing, automatic term creation or synthetic post.
  Administrators may deliberately assign the documented slug to a suitable
  existing term after checking its content. Empty existing terms are valid empty
  categories, not populated with reference-site content.
- Missing taxonomy/term or unopened circle returns the same 404 state, rather
  than silently broadening to all items. Invalid arrays, conflicting `group`,
  invalid supported sort/kind, and malformed page numbers return 400.
- A physical known alias wins over query-string `sluyu_page`, `p`, `preview`,
  `post_status`, and taxonomy override attempts. A query-only alias is supported
  on the home/index entry for plain permalinks; it cannot relabel unrelated paths.
- Circle joining rules are participation rules in this theme, not private-read
  rules. The existing circle header explicitly states public topic reading.
  Nonpublic circle status and review holds are still excluded, including a
  second nonpublic circle attached to an otherwise visible topic.
- For normal documents, category names are real public taxonomy labels, even
  when a category is empty. Document titles/counts/results only come from
  published, password-free `sluyu_doc` posts. Unknown or wrong-taxonomy group IDs
  never broaden search to all documents.
- The module relies on the ordinary trusted WordPress plugin/SQL boundary. It
  is not a sandbox against plugins that remove hooks or access the DB directly.
- No rewrite flush per visitor. Bump the theme route version/release version or
  have an administrator save Permalinks once after installing the candidate.

## Pending Serial Checks

The CLI fixture requires the existing disposable SQLite WP root. It refuses to
alter any pre-existing term using a reserved reference slug, creates uniquely
named posts/users, blocks mail, and removes only its own fixtures. Root should
now test all five integrated touch points. The suite intentionally asserts helper
integration rather than silently testing an unconnected module.

```powershell
$env:WP_QA_ROOT = (Resolve-Path 'dist/checks-v1.1.0/site').Path
& 'C:/Users/xiaoe/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe/php.exe' tests/reference-aliases.php
```

Require the explicit `PASS reference aliases:` marker, not just an exit code.

Root HTTP checks should verify:

1. Known category with actual term serves 200, correct scope and legal `pg`.
2. Missing term/pending circle serves 404, invalid filters serve 400, and unknown
   nested paths remain ordinary WordPress 404s without redirecting to a portal.
3. `?group=<other-id>`, array filters, `?p=<private-id>`, and `?preview=true` do
   not change alias scope or expose a protected title.
4. Circle pagination keeps legal `kind` and `sort`; unknown arguments disappear.
5. Normal and aliased document forms retain their category, server search and
   pagination under both pretty and plain permalinks.
6. A category with more than 12 matching public documents has a stable second
   page; drafts, private/password documents and ordinary posts are absent from
   results/counts; built-in FAQ remains separately available.

Record actual CLI and HTTP results after execution; this checkpoint has no
aliases runtime PASS. Root must also complete the workspace native/user-deletion
final regression, preserve the explicit PHP 7.4 verification gap, exclude
`comment-actions` and test helpers, and verify a frozen package before deciding
whether to release. Integrated source is not a completed release.
