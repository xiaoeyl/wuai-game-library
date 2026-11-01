# Public Topic Images: v1.6 Workspace Integration Contract

## Final Local Recheck (2026-11-01)

This checkpoint supersedes the earlier pending-test statements below. All four
topic-media CLI suites passed after the native/user-deletion changes, with 23
native checks, on SQLite (`dist/checks-v1.6.0/admin-feature-final-cli.log`) and
independent MariaDB (`dist/checks-v1.6.0/mariadb-media-final.log`). Real multipart,
native publish, withdrawal and actual file deletion passed again in
`dist/checks-v1.6.0/media-final-after-admin-browser.log`. PHP 7.4 syntax passes;
full WordPress integration uses PHP 8.3. No production/privacy guarantee is
inferred. Freeze, ZIP identity and publication remain independently verified.

Status: **v1.6 workspace integrated candidate - NOT RELEASED**. The composer,
native lifecycle guards, durable cleanup and raw-upload intent modules are wired
into the working tree. This does not certify the current tree for deployment or
claim that a final release, package or GitHub publication is complete.

## Recorded Evidence And Remaining Gates

Root has reported the following executed checks, rather than syntax-only checks:

| Suite | Disposable SQLite | Independent strict-session MariaDB |
| --- | --- | --- |
| Original `tests/topic-media.php` | PASS | PASS |
| Native `tests/topic-media-native.php` | PASS, 18 checks | PASS, 18 checks |
| Cleanup `tests/topic-media-cleanup.php` | PASS | PASS |
| Raw upload `tests/topic-media-upload-intent.php` | PASS | PASS |

These are **initial results before the subsequent native/user-deletion review
fixes**. All four suites passed on each database, but those earlier results must
not be presented as final regression evidence for the amended working tree.
The real multipart `tests/topic-media.cjs` browser suite also passed on its
tested candidate. Native/user-deletion fixes still require final regression,
including their integration with account/economy/bounty deletion authorization.

Related discovery CLI and browser suites have passed. Their results do not
substitute for topic-media lifecycle testing. PHP 7.4 has not been verified.
`comment-actions` is not loaded and is excluded from candidate packaging; it is
not a v1.6 integrated feature. Root still owns final serial regression, source
freeze, package verification and any eventual release decision.

## Scope And Public Disclosure

- A dedicated composer, not an extension of the generic rich-text submission.
- Ordinary `sluyu_topic` / `topic` only; no poll, question, guess, bounty, hidden
  body, download bundle, password-protected post or restricted/member-only circle.
- The author confirms at create, upload and every mutation that uploads are
  immediately public. Draft/pending/private content status is NOT file privacy.
- Accept JPEG, PNG and WebP only. Reject SVG, GIF, archives, Office/PDF, audio,
  video and remote URL imports. All originals stay in PHP temporary storage.
- Decode/re-encode pixels with GD; max 4 MiB input/output, 8 million pixels,
  8192 input edge, 1600 output edge, aspect ratio preserved. WebP becomes PNG.
  No original copy, EXIF/embedded metadata or generated thumbnail families.
- At least one and at most five images per submitted draft. Title 2-120 and
  plain-text explanation 5-20000 characters. No HTML image-URL injection.
- Upload progress measures network bytes only. The UI separately announces
  server decoding/verification, and reports success only after persisted reads.
- Browser up/down buttons provide mobile and keyboard reorder; no drag-only UI.

## Workspace Integration Contract

The shared wiring is in the v1.6 candidate. Preserve these boundaries when
reviewing the final tree and rerunning the suites:

1. `inc/topic-media.php` loads after community, account, circles, profile-media,
   circle moderation and bounties. `inc/topic-media-cleanup.php` and
   `inc/topic-media-upload-intent.php` load after it, before portal views.
   `bounty_exists()` errors fail closed.
2. In `wuai_sluyu_view_submit()`, `kind=topic-images` calls
   `wuai_sluyu_view_topic_media_submit()` and returns. The ordinary topic form
   links to the dedicated composer, preserving the requested public `group`.
3. In the account submissions row, if
   `wuai_sluyu_topic_media_managed($entry->ID)`, show a dedicated editor link via
   `wuai_sluyu_portal_url('submit', ['kind'=>'topic-images','draft'=>$entry->ID])`.
   The module itself denies generic author edit/withdraw/delete operations.
4. Treat `wuai_sluyu_topic_media_review_private($id)` as a site-editor-only
   configuration in circle moderation. Circle owners may recommend/reject if
   consistent with site policy, but must not finally publish media submissions.
   `_wuai_topic_media` is part of the review subject/version fingerprint.
5. Existing generic native service/download metaboxes must not clear/change
   immutable topic kind or add hidden-content/download configuration to these
   drafts. The module blocks those metadata writes, freezes native post
   author/type/password/name and freezes circle movement. Site editors can
   still edit public title/body, publish, draft, trash and delete.
6. The module's native publication hook validates `phase=submitted`, its public
   circle, original account and every image before `pending` / `publish`.
   Incomplete, invalid or missing-image galleries remain drafts. Scheduled
   publication is explicitly unsupported: `future` is forced to draft, and a
   restored old `publish_future_post` event is cancelled before core publishes.
   Final regression must retain coverage after native services metabox/save
   hooks and the subsequent native CAS/lifecycle review fixes.
7. Confirm the raw multipart endpoint is only `wp_ajax_wuai_topic_media` with
   POST/login/nonce checks. Do not add a nopriv or local-path upload bypass.

## API And State

- `wuai_sluyu_topic_media_operation($operation, $input, $file = null)` acquires
  the existing per-user lock and an independent durable per-user media fence.
- Existing-draft operations additionally take the shared `poll_ID` topic-review
  lock and then recheck the moderation hold. A held draft/pending/published topic
  cannot be changed, withdrawn or deleted through the author image composer.
- Every bundle and author post-status/content write checks the exact current
  `poll_ID` token in SQL as well as the durable media token. A `LIMIT 1` derived
  hold predicate avoids merging a read of the updated postmeta table on MySQL.
  The targeted native post-update wrapper also checks the original identity,
  content/status and exact media bundle. It does not change moderator writes.
- Operations: `create`, `upload`, `order`, `remove`, `submit`, `withdraw`,
  `abandon`. Every field is a scalar string. `confirm_public=1` is mandatory.
- `create`: `group`, UUIDv4 `request`; returns a verified personal draft.
- Other operations: own `id` and current integer `version`; every list save is
  a full metadata CAS using its exact previous bytes plus the draft/user fence.
- `upload`: single `$_FILES['image']` plus UUIDv4 `request`, idempotent once
  bound. Do not silently retry a network failure with a fresh request key.
- `order`: complete comma-separated IDs; exact same unique image set required.
- `remove`: one current `attachment` ID; cannot name a foreign/ordinary image.
- `remove` and `abandon` first CAS the IDs out of the authoritative list, then
  delete verified dedicated files. A failed cleanup explicitly reports that
  the file may remain public and still consumes retained quota. A later abandon
  can retry that dedicated orphan; order cannot reinsert an omitted ID.
- `submit`: `title`, plain `body`; validates all image files before pending.
- `withdraw`: verified native draft status then a new editable bundle edition;
  published/pending images cannot be replaced before this explicit action.
- `abandon`: draft only, verified dedicated image deletion before trashing.
- `_wuai_topic_media` owns ordered IDs and upload request keys; the topic slug
  reserves a recognizable draft even if first metadata persistence fails. The
  slug includes the authenticated numeric owner, so a copied request UUID from
  another account cannot collide with its later published permalink.
- `_wuai_topic_public_image` binds owner, parent, purpose, request key, exact
  relative path, dimensions, MIME and SHA-256. Immutable native attachment
  author/parent/type/path/metadata stops author-controlled deletion redirection.
- No `upload_files` capability grant and no generic media-library access.
- `wuai_sluyu_topic_media_cleanup_delete()` / `_scope()` are trusted internal
  cleanup APIs, not HTTP authorization gates. Durable pre-delete intents bind
  the exact owner, parent, path, metadata and hash before destructive work;
  retries verify the same identity rather than guessing a replacement path.
- Raw upload intents arm before moving the re-encoded file. Responsibility is
  transferred only to an exactly verified dedicated attachment, or discharged
  after verified rollback/absence. Attachment/intent INSERT failure or uncertain
  filesystem state retains the durable account fence; no HTTP bypass or silent
  shutdown unlock is added.

## Quotas, Failures And Maintenance

- Three active image drafts per user, 20 upload attempts per UTC day.
- Total retained dedicated attachments defaults to 50 per user, including
  published and orphaned records. Option `wuai_topic_media_retained_limit`
  clamps to 5-100. Marker OR dedicated slug and retained cleanup intents count,
  including partial state; a missing attachment row is not proof of file erasure.
- The durable media fence has no timeout takeover and no shutdown release.
  Stop every worker before an administrator inspects/removes a crashed fence.
- A failed verified binding tries to delete only that newly verified dedicated
  attachment. If attachment metadata itself failed, keep the record / report
  its ID instead of guessing paths or deleting arbitrary files. These orphans
  count toward the retained quota and need administrator inspection.
- Filesystem deletion and database state are not a cross-resource transaction.
  The operation checks its exact lock/hold and absent list reference immediately
  before cleanup, but does not claim linearizable filesystem writes against
  arbitrary trusted plugins, external file replacement or paused native workers.
  The authoritative list is already committed without the deleted IDs.
- Native deletion of a dedicated attachment is allowed; its old list entry
  becomes visibly invalid. The author can remove the missing entry but cannot
  submit until all remaining images verify.
- Permanent native topic deletion uses blocking dedicated-cleanup guards.
  User deletion performs a read-only media preflight at priority 0, then the
  community account/economy/bounty authorization at priority 1, and destructive
  media cleanup at priority 2 only after those guards succeed. The review fix
  prevents refused account deletion from deleting images first; final regression
  is still required. Failure must not be described as a privacy erasure guarantee;
  administrators must inspect leftover dedicated records/files and retained
  fences. Public caches may survive any deletion.
- Reassigning an author or moving a topic to a restricted circle is not a safe
  way to privatize already-public uploads; create a separately reviewed post.
- Multisite and custom/offloaded upload storage are not verified. Offload/path
  transforms fail the exact local path/metadata checks instead of deleting by
  guessed remote URLs. Do not claim compatibility until explicitly tested.
- No content scan, malware scan, copyright verification, private file storage,
  audio/video conversion or guaranteed deletion from external caches.

## Test Sequence

Use the parent's serial QA reservation; never run alongside another shared DB
suite. PHP needs GD and the fixture database extension.

1. Rerun `tests/topic-media.php`, `tests/topic-media-native.php`,
   `tests/topic-media-cleanup.php` and `tests/topic-media-upload-intent.php` with
   GD enabled under the explicitly guarded disposable roots. Run all four
   serially on SQLite and the independent strict-session MariaDB environment;
   do not infer MariaDB behavior from SQLite. The earlier eight suite passes
   predate the native/user-deletion review fixes.
2. `php -d extension=gd tests/topic-media-browser-fixture.php` creates marked
   test accounts and a local mail-blocking MU helper.
3. `node tests/topic-media.cjs` for real multipart uploads, metadata stripping,
   explicit preapproval public-file access, reorder, native publish, immutable
   pending state, dedicated withdrawal, actual removal and mobile layout.
4. Inspect `topic-media-mobile.png`, `topic-media-published.png` and results JSON
   in `WP_QA_OUTPUT`. No screenshot alone proves server-side authorization.
5. Retain native lifecycle/injected SQL failure coverage, cleanup/raw-intent
   failure coverage, publish/circle-review gates and the corrected two-phase
   user-deletion ordering. Initial PASS is not a waiver of final regression.
6. Verify cleanup of test users, records, public files and local helpers before
   packaging. PHP 7.4 remains unverified; exclude `comment-actions` and every
   test fixture/MU helper from the candidate artifact. Final frozen-source and
   packaging checks remain root-owned and are not completed by this document.

Fixtures are local-only, contain no upload-origin bypass, and must never ship
inside the release theme or be copied into a production MU-plugin directory.
