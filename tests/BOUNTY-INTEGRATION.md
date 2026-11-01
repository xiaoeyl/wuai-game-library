# Candidate Bounty / Economy Integration Contract

Status: shared v1.5 wiring prepared, NOT release-ready until serial integration,
MySQL/SQLite runtime checks, genuine process concurrency checks and browser QA pass.
No shared database was used while these files were authored. Product forms,
status labels, errors, ledger labels and administration copy use Chinese.

The shared source changes now include schema-five migration, complete-balance
readers, duplicate-safe legacy event conversion, guarded check-in/makeup/tasks
and unlocks, merged gold history, zero-only privacy cleanup, raw-verified account
deletion and authoritative bounty guards. This is a source-state statement, NOT
a runtime test result. `functions.php`, the version, public documentation and
the integration contract constant remain owned by the parent release task.

## Reference And Scope

The local reference `circle.html` at 1114-1166 provides selected/everyone responders,
immediate points reservation, integer reward and expiry strictly greater than one
and less than thirty days. `static/js/circle.js` initially sets `ask.userCount=4`.
The reference also includes money rewards and paid answer previews. Those are NOT
part of this implementation. Selecting responders restricts eligible answers and
awards; question and approved answers remain public. No secrecy is promised.

This is non-cash site points. It is not payment processing, escrow, a cash wallet,
withdrawal, convertible value or a guarantee of answer quality.

## Authoritative Allocation

One `wuai_bounties` row is the complete authoritative allocation. It is never
deleted by post, comment, author or winner deletion. Requester, amount, targets,
deadline and public-prompt hash are immutable. `question_id` and
`(requester_id,request_key)` have mandatory unique indexes.

| State | Requester balance effect | Winner effect | Allowed next state |
| --- | ---: | ---: | --- |
| held | -amount | 0 | open or refunded |
| open | -amount | 0 | awarded or refunded |
| awarded | -amount | +amount | terminal |
| refunded | -amount +amount | 0 | terminal |

The requester is debited ONCE at reservation. Awarding does not debit them again.
The single CAS changing open to awarded includes winner/comment/outcome/version.
There is NO additional debit, credit or refund event. A notification failure
cannot undo or repeat an allocation. `awarded` is never changed to `refunded`.

Balance is one SQL snapshot:

```
SUM(events.points)
- SUM(all requester allocations.amount)
+ SUM(requester allocations.amount WHERE state = refunded)
+ SUM(winner allocations.amount WHERE state = awarded)
```

No join against users/posts/comments may suppress any term. Such a join would
silently change a surviving party's balance when the other party/content is deleted.

Ledger uses UNION ALL: existing point events; requester reserve -amount; requester
refund +amount when terminal refunded; winner award +amount when terminal awarded.
Stable keys are `event:ID`, `bounty:ID:reserve/refund/award`. Gold pagination and
export must include this derived ledger, not only `wuai_events`.

`reserved_at` and `settled_at` use the same site-local format as existing events
so the merged ledger sorts consistently. `opened_at` is explicitly UTC for native
`comment_date_gmt` eligibility checks. `deadline` is an absolute epoch. Historical
site timezone changes have the same legacy limitation as existing event dates.

## Durable Global Mutex

`wuai_economy_mutex` has `PRIMARY KEY(name)`, and only `name=global` is used.
Acquire is exactly one plain INSERT of a new cryptographically random 64-character
token. Read back the exact token before proceeding. Never use replace/upsert.
No TTL, lease, stealing, age-based recovery, global shutdown release, Redis cache
lock, filesystem lock or MySQL session advisory lock is relied upon.

Every economic DML carries an SQL `EXISTS` predicate matching that exact token.
The guard must also belong to the current PHP operation scope. Normal finally
release deletes only `WHERE name='global' AND token=THE_OWN_TOKEN`. A borrowed
same-request guard never releases its parent. No second account lock is acquired.

Why not only GET_LOCK: WordPress `wpdb::query` can reconnect and replay a failed
query after server error 2006. A session lock can disappear before the replayed
write. Durable lock plus inline token fencing is independent of that session.

Why top-level INSERT SELECT: nested reads of the INSERT target can hit MySQL 1093.
Reservation and negative-event insertion instead aggregate a top-level LEFT JOIN
against their own target and use GROUP BY/HAVING balance checks. Each SQL is one
atomic mutation. It MUST be tested on actual supported MySQL/MariaDB and SQLite
adapter versions, including strict SQL mode.

Supported deployment: one authoritative database with real unique constraints
and read-your-writes, 64-bit PHP, all writers using the protocol. MySQL requires
the native wpdb class, InnoDB for the allocation/event/mutex and all eligibility
tables, and successful information_schema inspection. The supported local SQLite
path is the known WP_SQLite_DB adapter with DB_ENGINE=sqlite. Database UNIX time
must be within five seconds of PHP time. Unsupported adapters/storage engines
fail readiness. All signed deltas explicitly CAST amounts AS SIGNED.
Read replicas,
split-brain/multi-primary databases, unsupported SQL adapters, unguarded external
plugins, manual SQL, importing replacement user IDs, disabling this theme, and
downgrading to an events-only points reader are outside the guarantee. Do not
enable the integration contract in these environments. An operational fault
must close spending; it must not silently fall back to an events-only balance.

## Lock Matrix

| Operation | Existing user lock | Economy global | Content lock |
| --- | --- | --- | --- |
| New bounty | HTTP actor | full creation/reserve/queue | no second user lock |
| Owner acceptance | HTTP actor | validate + allocation CAS | SQL rechecks comment/post |
| Withdraw / refund / cron | actor if HTTP | reservation CAS + status work | no reverse lock acquisition |
| Protected/download spend | actor | fresh balance + event write | acquire post only AFTER economy |
| Makeup / negative event | actor | fresh balance + event write | none |
| Positive point event | actor if existing path | recommended event helper | none |
| Delete account | existing target user lock | full deletion lifecycle | no new user lock inside |
| Native bounty term edit | existing WP authorization | pre-write refund CAS | terminal before content write |

Global acquisition is nonblocking. Busy means no mutation and a useful retry
message. Never wait for an account lock while holding economy. Do not begin the
economic guard inside a post lock and then try to acquire a user lock.

Native term edits refund the still-open allocation BEFORE changing content. If
content write then fails, the question remains closed/refunded; that is a visible,
safe partial outcome, not an excuse to reserve again. If return cannot be verified,
the native content mutation is blocked. Awarded records never reverse on edits.

Native comment moderation is deliberately separate: the award SQL rechecks that
the selected comment is approved, top-level, correct question/user, registered,
and within UTC submission dates. If moderation wins first, award fails. If award
wins first, later content removal does not reverse points. Native comment author,
post, date, parent and type reassignment are blocked for bounty answers.

Award eligibility is sampled from authoritative rows BEFORE evaluating the normal
PHP permission functions with freshly cleared user/post/term caches. The final CAS
requires exact row-count plus row-ID/value predicates for ban/capability/member
state, membership-sync markers, order-sync pending-user checkpoints, circle
relationships/slug/owner/status/join/posting, and responder circle membership.
Both requester and winner also have SQL `NOT EXISTS` active/permanent-ban checks.
Deadline and finite membership expiry use database `UNIX_TIMESTAMP()` in the CAS.
A concurrent kick/ban/member-state change rejects this attempt, even when the
native moderation path does not acquire economy. Native external SQL or role
plugins that synthesize permissions outside these persisted fields are not
claimed linearizable; custom participation integrations need their own protocol.

## Schema And Core Integration

1. Require `inc/economy.php` and `inc/bounties.php` in functions.php before runtime
   setup hooks execute. Move schema `wuai_sluyu_storage_version` from 4 to 5 only
   after dbDelta of BOTH schema helper strings and full column/unique-index
   verification. `wuai_sluyu_economy_storage_ready()` is intentionally fail-closed.
2. Replace points reads with `wuai_sluyu_economy_balance()` once the schema is
   deployed. It returns int OR WP_Error. Legacy display wrappers may show an
   unavailable indicator, not call WP_Error truthy/zero or display gross events.
   All spending must explicitly reject WP_Error.
3. Integrate all negative events: makeup, protected content, downloads. Hold
   `economy_enter()` around the logical operation and insert with
   `economy_event()`. This helper returns `['id','created']` OR WP_Error, not a
   boolean/integer. Adapt every caller; do not grant access on a truthy WP_Error.
   Core `wuai_sluyu_event` should route nonzero writes through the safe helper
   and convert failures only at its documented legacy boundary. New negative
   writers must not call raw wpdb inserts. Zero-point votes/guesses are separate.
4. Gold history must use `economy_ledger()`. Export uses the existing event export
   plus the new allocation exporter (or one deduplicated merged export). Do not
   erase the new rows when deleting events or remove `winner_id`/`requester_id`.
5. Core user deletion must hold economy from before any economic cleanups through
   after WordPress user deletion, while preserving its existing user lock. Call
   `bounty_user_deleting($uid,$token)` first and abort if it fails, then use
   `economy_delete_user_events($uid,$token)` instead of raw event deletion.
   `deleted_user` alone is NOT proof of deletion: core can fire it after a failed
   users-table DELETE. The cleanup raw-reads users and its SQL also requires the
   user to be absent. If deletion cannot be verified, events and the durable
   mutex remain; the account's native metadata may already be partly removed.
   Do NOT cascade bounties. Account reassign changes post_author only; allocation
   requester remains immutable. The requester deleting unresolved bounties closes
   them; deleting the only remaining selected responder also returns reservations.
   Current candidate explicitly refuses multisite in contract_ready(), even if
   the single-site contract constant is set. Supporting `wpmu_delete_user` and
   multisite removals would require a network-wide lock and across-blog allocation
   lifecycle; this is not provided by the per-blog global mutex.
   Privacy interaction deletion must include `points=0`; do not assume a type
   name alone proves an event has no balance effect. Nonzero event deletion is an
   economic write and needs the same mutex/fence.
6. Ordinary core `accept` and account edit/withdraw/delete must never bypass a
   bounty row, including if its post meta was malformed or later changed. Native
   service editor must preserve bounty type and cannot convert an old ordinary
   question into funded bounty. Add its current type to display-only selectors,
   just as guess has been protected. New creation uses the dedicated frontend.
7. Route `submit?kind=bounty` to `wuai_sluyu_view_bounty_submit()`. Add labels/filter
   support to lists without treating bounty as ordinary question acceptance.
   Circle-owner moderation must NOT activate a bounty; only site administrators
   may do the separate economic review action. Existing circle rules still gate
   responders; schema/permission errors must deny rather than hide a circle.
8. Schedule `wuai_sluyu_bounty_sweep` periodically (bounded 50 due rows per run),
   and call `bounty_due_for_user()` before a user balance/spend entry point.
   Do not recurse from economy_balance or its SQL. Direct puzzle views also
   reconcile expired rows. Until refund CAS is verified, funds remain reserved
   and the UI must say return pending, never promise already returned.
   Current source schedules an hourly sweep and clears it on theme switch.
   Creation being disabled does not prevent existing held rows being reviewed,
   awarded, withdrawn or returned. Pending requesters have a dedicated return
   control in their own account submission list; the ordinary editor stays locked.
9. Only after the whole integration is complete define
   `WUAI_SLUYU_ECONOMY_CONTRACT` as integer 1. Bounties still default disabled;
   the administrator explicitly enables them on the separate review page.
   Turning creation off does not reverse or freeze existing obligations.
10. Update privacy and public terms: minimal numeric bounty allocation records
    remain even after account deletion to protect other accounts. No names,
    emails, IPs, question bodies or answer text are in this table. Public display
    can label deleted users without modifying financial IDs. No claim that all
    records vanish on account deletion remains accurate after this feature.

## Review, Expiry And Immutable Terms

Creation: validate fields/targets/circle/consent -> draft question -> read back
type/circle/hash -> one fenced reservation INSERT with balance check -> pending.
Failure after reserve returns that row with CAS if possible; otherwise retain
reservation and report manual verification. Never lie that no charge occurred.
Retries reuse the request key and return the same allocation, including after an
ambiguous successful INSERT. Changed content under the same key is rejected.

Native publication does not open funds. Administrator reviews the question in
WordPress then explicitly approves the reserved allocation on the separate admin
page with its current version and nonce. Deadline includes pending review time.
This is an explicit conservative interpretation of the reference's immediate
deduction and submitted expiry; the original server-side policy is unavailable.

Only original requester can pick one approved eligible registered responder.
Not self, not administrator代选, not anonymous, not a non-target, not a reply,
not before activation, not at/after deadline. Two winners/award-vs-refund compete
on the same state/version CAS; the loser must reread and return final outcome.

Already awarded rows are retained when comment/post/account disappears. No admin
force-reassign, reverse-award or fee is implemented; those require a separate
design rather than negative recipient balances after they already spent rewards.

## Crash / Offline Recovery

A PHP crash may leave the global lock forever. This trades availability for
preventing silent double spending. Read-only admin diagnostics show actor,
purpose and acquisition time, never an unlock button or token.

Recovery must be an explicit maintenance operation by the site operator:

1. Take the site offline, stop PHP workers, CLI workers and cron on EVERY node.
2. Verify no process can resume. Inspect authoritative allocation states and
   unique event/request keys; back up the database.
3. Decide whether an ambiguous operation committed from persisted evidence.
   Never manufacture paired debit/credit events or delete awarded rows.
4. Remove only the inspected stale mutex under controlled offline maintenance.
5. Re-run balance/ledger reconciliation, resume workers and run bounded expiry.

SQL token fencing also rejects a stale worker after accidental token replacement,
but this is not permission to unlock live workers. Native WordPress non-economic
SQL is not universally fenceable, so offline maintenance remains mandatory.

## Test Order (Parent Runs Serially)

1. PHP 7.4 lint and `node --check` for all candidate files.
2. Integrate schema/points/negative writers/delete/ledger/routes on disposable QA.
   Then run `php tests/economy-integration.php`: legacy duplicate conversion,
   no double counter increments, account/economy/post lock ordering, protected
   and download entitlements, merged history/pagination, service editor and
   account authority boundaries, due refunds, zero-only dirty-event erasure,
   schema failure displays and an injected native users DELETE failure. This
   test is strictly bound to the existing dedicated local absolute QA root.
3. `php tests/bounties.php`: exact both-party balances, native lifecycle, failure
   injection, stale tokens, idempotence, target/role/approval limits, retained
   counterparty allocations, privacy export/retention.
4. `node tests/economy-race.cjs` with WP_QA_PHP set: launches TWO simultaneous PHP
   processes and explicit barriers; checks reserve versus negative event, exact
   loser balance, worker kill and persistent no-lease mutex. A single-threaded
   PHP built-in web server is not sufficient evidence of genuine concurrency.
5. `php tests/bounties-browser-fixture.php`, then `node tests/bounties.cjs`: real
   author reserve/pending, native editor publish, explicit site review, actual
   native answer/moderation, owner consent, merged ledger, replay, comment trash,
   return/expiry and 320/390 layouts. QA MU helper is read-only, admin+nonce and
   strict absolute installation/home/Host/loopback guarded; mail is blocked.
6. Repeat economic SQL and worker tests on MySQL/MariaDB strict SQL mode. SQLite
   alone does not certify production MySQL compatibility or replica behavior.

The race CLI cleanup removes only its own generated test identities/locks after
all child processes are confirmed exited; it is not shipped or an admin recovery
endpoint. Never execute suites concurrently against the shared QA installation.
