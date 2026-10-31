# Disposable MariaDB / MySQL QA

These PHP integration suites create users, posts, comments, options, metadata,
and community records, and deliberately inject failed writes. **Never run them
on production, a staging database with real data, or a shared developer site.**
Cleanup runs in `finally` blocks, but it does not make production execution safe.

## Prepare Your Own Test Installation

1. Use a separate, empty WordPress installation and a fresh MariaDB/MySQL
   database. Do not migrate production or SQLite data into it. Give WordPress a
   database user restricted to this one database, with a new random password.
2. Install the reviewed WordPress/WooCommerce program versions, activate
   WooCommerce and the frozen theme, and record the exact source revision.
   Unintegrated economy, bounty, or account-security candidates are not part of
   v1.4 coverage; do not include or activate them for this run.
3. Bind the dedicated database to loopback on an unused port. Do not repoint,
   reconfigure, replace, or stop an existing database service. Use an independent
   PHP configuration with `mysqli` and the extensions required by WordPress/Woo.
4. Install test-only MU protections that short-circuit all WordPress HTTP and
   mail, remove all WooCommerce payment gateways, prevent webhooks/background
   requests, and disable WP cron and automatic updates. Verify the protections
   before running any suite. Application filters are not an OS network sandbox;
   only execute reviewed test code. Do not deploy QA MU plugins to a real site.
5. Set this installation's `home` and `siteurl` to
   `http://127.0.0.1:8771` for the existing local-test guards. This setting is not
   an instruction to launch a web server. The database suite uses CLI and
   in-process WordPress APIs only; no HTTP listener is needed.

No credentials, datadir, site configuration, database exports, or local helper
scripts should be committed. The one-machine provisioning helpers used during
development have not been published or reviewed as a portable installer.

## Run Explicit Suites Serially

From the repository root, select **your own disposable WordPress root** and PHP
ini. PowerShell example:

```powershell
$env:WP_QA_ROOT = (Resolve-Path ./dist/my-mysql-qa/site).Path
$qaIni = (Resolve-Path ./dist/my-mysql-qa/php.ini).Path
$suites = @(
    'guess-answer', 'guess-lifecycle', 'downloads',
    'protected-content', 'circle-moderation', 'community-permissions'
)
foreach ($suite in $suites) {
    & php -c $qaIni (Join-Path ./tests ($suite + '.php'))
    if ($LASTEXITCODE -ne 0) { throw "Stopped after $suite failed." }
}
Remove-Item Env:WP_QA_ROOT
```

`circle-oembed.php` additionally pins its allowed roots to the repository's
`dist/checks-mysql/site` and `dist/checks-v1.1.0/site`. Run it separately when your
fresh database-backed test site occupies the first path. If using another path,
make an untracked isolated copy and replace only its root allow-list with your
exact disposable root; do not remove the identity checks or relax assertions.
Record both the original and copied file hashes. It temporarily enables pretty
permalinks with a soft rewrite flush and restores options/global rewrite state,
so it must not overlap other tests on that installation. Its thumbnail is a
database-only fixture pointing to a random nonexistent path in QA uploads.

Keep one log and exit code per suite. Preserve failed-run logs before applying a
fix and rerunning. After completion, verify that fixture users, topic posts,
comments, held-post metadata, and application locks are gone and the original
permalink/rewrite settings are restored. Do not count a zero exit code as success
if the suite did not print its final `PASS:` assertion summary.

## Record The Actual SQL Mode

Record server version, table engines, transaction isolation, and both global and
WordPress connection/session SQL modes. The WordPress session can differ from a
separate SQL client's session because `wpdb` removes incompatible modes.

```sql
SELECT VERSION(), @@global.sql_mode, @@session.sql_mode,
       @@default_storage_engine, @@transaction_isolation;
```

The verified v1.4 run used MariaDB 11.4.8 LTS, PHP 8.3.32, WordPress 7.1,
WooCommerce 11.2.1, 52 InnoDB tables, and `REPEATABLE-READ`. Its global SQL mode was
`STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`.
Its **actual WordPress session** mode was
`ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`.
Therefore this is native WordPress/MariaDB behavior coverage, **not** forced
strict-session coverage. A different MySQL/MariaDB version is a separate test
target; record its real settings instead of copying these results.

## Coverage Boundary

The seven verified v1.4 suites are `circle-oembed`, `guess-answer`,
`guess-lifecycle`, `downloads`, `protected-content`, `circle-moderation`, and
`community-permissions`. They cover the integrated moderation/guess modules,
permission boundaries, native lifecycle and fault-injection paths against a real
SQL database. `rest_do_request()` exercises core REST dispatch in-process; it is
not an HTTP, browser, proxy, cookie, cache-server, or deployment test.

This run does not establish concurrent-worker correctness, MySQL-specific
compatibility, forced strict mode, PHP 7.4 compatibility, HPOS/browser behavior,
actual payment-gateway behavior, or production readiness. Those require separate
explicitly scoped disposable tests. No real payment or mail delivery is needed.
