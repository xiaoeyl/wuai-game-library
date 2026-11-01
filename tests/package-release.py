#!/usr/bin/env python3
"""Pure temporary-directory regression tests for tools/package_release.py."""

import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock
import zipfile


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'tools' / 'package_release.py'
sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location('package_release', str(SCRIPT))
PACKAGE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PACKAGE)


class PackageTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='wuai-package-test-')
        self.addCleanup(self.directory.cleanup)
        self.base = Path(self.directory.name)
        self.source = self.base / 'frozen-source'
        self.theme = self.source / 'wuai-sluyu'
        self.theme.mkdir(parents=True)
        self.output = self.base / 'artifacts' / 'theme.zip'
        self.manifest = self.base / 'artifacts' / 'theme.json'
        self.hashes = self.base / 'snapshot-hashes.json'
        self.write('functions.php', b"<?php\ndefine('WUAI_SLUYU_VERSION', '1.5.0');\n")
        self.write('style.css', b'/*\nTheme Name: Isolated Fixture\nVersion: 1.5.0\n*/\n')
        self.write('index.php', b'<?php echo "public";\n')
        self.write('assets/css/theme.css', b'body { color: #123456; }\n')
        self.write('inc/topic-media.php', b'<?php // deferred public image module\n')
        self.write('inc/comment-actions.php', b'<?php // deferred comment module\n')
        self.write_hashes()

    def write(self, name, raw):
        target = self.theme / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(raw)

    def selected(self, excluded=None):
        excluded = PACKAGE.DEFAULT_EXCLUDED if excluded is None else excluded
        return {'wuai-sluyu/' + path.relative_to(self.theme).as_posix(): path.read_bytes()
                for path in self.theme.rglob('*') if path.is_file()
                and path.relative_to(self.theme).as_posix() not in excluded}

    def write_hashes(self, excluded=None, extra=None):
        hashes = {name: hashlib.sha256(raw).hexdigest() for name, raw in self.selected(excluded).items()}
        hashes['README.md'] = hashlib.sha256(b'non-theme source is not packaged').hexdigest()
        hashes.update(extra or {})
        self.hashes.write_text(json.dumps(hashes, indent=2), encoding='utf-8')
        return hashes

    def build(self, **changes):
        options = {'source': self.source, 'hashes': self.hashes,
                   'output': self.output, 'manifest': self.manifest}
        options.update(changes)
        return PACKAGE.package_release(**options)

    def assert_no_temporaries(self):
        parent = self.output.parent
        if parent.exists():
            self.assertEqual([], [p.name for p in parent.iterdir()
                                  if p.name.endswith(('.tmp', '.rollback'))])

    def test_source_hashes_and_exact_archive(self):
        expected = self.selected()
        result = self.build()
        self.assertEqual('1.5.0', result['version'])
        self.assertTrue(result['snapshot_hashes_verified'])
        self.assertEqual(hashlib.sha256(self.hashes.read_bytes()).hexdigest(), result['snapshot_hashes_sha256'])
        with zipfile.ZipFile(str(self.output)) as archive:
            self.assertEqual(set(expected), set(archive.namelist()))
            for name, raw in expected.items():
                self.assertEqual(raw, archive.read(name))
        recorded = json.loads(self.manifest.read_text('utf-8'))
        self.assertEqual(hashlib.sha256(self.output.read_bytes()).hexdigest(), recorded['zip_sha256'])
        self.assertEqual({name: hashlib.sha256(raw).hexdigest() for name, raw in expected.items()}, recorded['files'])
        self.assert_no_temporaries()

    def test_without_hashes_makes_no_snapshot_claim(self):
        result = self.build(hashes=None)
        self.assertFalse(result['snapshot_hashes_verified'])
        self.assertIsNone(result['snapshot_hashes_sha256'])

    def test_missing_and_extra_hashes_fail(self):
        hashes = self.write_hashes()
        del hashes['wuai-sluyu/index.php']
        self.hashes.write_text(json.dumps(hashes), encoding='utf-8')
        with self.assertRaisesRegex(PACKAGE.PackageError, 'Missing hashes'):
            self.build()
        hashes = self.write_hashes()
        hashes['wuai-sluyu/extra.php'] = '0' * 64
        self.hashes.write_text(json.dumps(hashes), encoding='utf-8')
        with self.assertRaisesRegex(PACKAGE.PackageError, 'extra hashes'):
            self.build()
        self.assertFalse(self.output.exists())

    def test_source_digest_mismatch_fails(self):
        self.write('index.php', b'<?php echo "changed";')
        with self.assertRaisesRegex(PACKAGE.PackageError, 'Source digest mismatch'):
            self.build()

    def test_deferred_hash_entry_is_not_silently_ignored(self):
        self.write_hashes(excluded=set())
        with self.assertRaisesRegex(PACKAGE.PackageError, 'extra hashes'):
            self.build()

    def test_duplicate_or_unsafe_hash_key_fails(self):
        self.hashes.write_text('{"a":"' + '0' * 64 + '","a":"' + '0' * 64 + '"}', encoding='utf-8')
        with self.assertRaisesRegex(PACKAGE.PackageError, 'Duplicate hash'):
            self.build()
        self.write_hashes(extra={'../escape': '0' * 64})
        with self.assertRaisesRegex(PACKAGE.PackageError, 'Unsafe'):
            self.build()

    def test_safe_relative_path_validation(self):
        for value in ('../escape', '/absolute', 'a//b', 'a/../b', 'a\\b', 'a:b', 'a\n'):
            with self.subTest(value=value), self.assertRaises(PACKAGE.PackageError):
                PACKAGE.safe_name(value)

    def test_environment_database_qa_editor_extension_rejected(self):
        names = ('assets/.env', 'assets/site.sqlite', 'assets/site.sql', 'assets/data.db',
                 'inc/wp-config.php', 'inc/qa-helper.php', 'inc/foo-fixture.php',
                 'tests/test.php', 'assets/.vscode/settings.js', 'assets/readme.log',
                 'assets/theme.css.bak', 'assets/cache/index.php', 'assets/data.json',
                 'assets/credentials.txt', 'assets/theme.css~')
        for name in names:
            with self.subTest(name=name), tempfile.TemporaryDirectory(dir=str(self.base)) as folder:
                source = Path(folder)
                theme = source / 'wuai-sluyu'
                theme.mkdir()
                (theme / 'functions.php').write_bytes((self.theme / 'functions.php').read_bytes())
                (theme / 'style.css').write_bytes((self.theme / 'style.css').read_bytes())
                target = theme / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(b'not approved')
                with self.assertRaises(PACKAGE.PackageError):
                    PACKAGE.package_release(source=source, output=source / 'out.zip')

    def test_qa_credentials_and_database_config_content_rejected(self):
        contents = (b'<?php // WUAI_BOUNTY_BROWSER_QA_V1',
                    b'<?php // github_pat_' + b'A' * 30,
                    b'<?php define("DB_PASSWORD", "fixture-only");',
                    b'<?php // -----BEGIN PRIVATE KEY-----')
        for raw in contents:
            with self.subTest(raw=raw[:20]):
                self.write('inc/helper.php', raw)
                with self.assertRaises(PACKAGE.PackageError):
                    self.build(hashes=None)
        self.assertFalse(self.output.exists())

    def test_version_mismatch_and_unsafe_version_fail(self):
        self.write('style.css', b'/*\nVersion: 2.0.0\n*/')
        with self.assertRaisesRegex(PACKAGE.PackageError, 'versions'):
            self.build(hashes=None)
        self.write('functions.php', b"<?php define('WUAI_SLUYU_VERSION', '../bad');")
        self.write('style.css', b'/*\nVersion: ../bad\n*/')
        with self.assertRaisesRegex(PACKAGE.PackageError, 'semantic version'):
            self.build(hashes=None)

    def test_reference_to_excluded_module_rejected(self):
        self.write('inc/loader.php', b"<?php require WUAI_SLUYU_DIR . '/inc/topic-media.php';")
        with self.assertRaisesRegex(PACKAGE.PackageError, 'Excluded module is referenced'):
            self.build(hashes=None)

    def test_explicit_include_and_extra_exclude(self):
        self.write('inc/next-module.php', b'<?php // another deferred module')
        excluded = {'inc/comment-actions.php', 'inc/next-module.php'}
        self.write_hashes(excluded=excluded)
        self.build(include=['inc/topic-media.php'], exclude=['inc/next-module.php'])
        with zipfile.ZipFile(str(self.output)) as archive:
            self.assertIn('wuai-sluyu/inc/topic-media.php', archive.namelist())
            self.assertNotIn('wuai-sluyu/inc/next-module.php', archive.namelist())

    def test_conflicting_or_invalid_module_selectors_fail(self):
        for options in ({'include': ['inc/topic-media.php'], 'exclude': ['inc/topic-media.php']},
                        {'include': ['inc/not-default.php']}, {'exclude': ['../outside.php']}):
            with self.subTest(options=options), self.assertRaises(PACKAGE.PackageError):
                self.build(**options)

    def test_existing_output_requires_explicit_overwrite(self):
        self.output.parent.mkdir()
        self.output.write_bytes(b'previous release')
        with self.assertRaisesRegex(PACKAGE.PackageError, 'already exists'):
            self.build()
        self.assertEqual(b'previous release', self.output.read_bytes())
        self.build(overwrite=True)
        self.assertTrue(zipfile.is_zipfile(str(self.output)))

    def test_capture_is_not_reread_by_archive_writer(self):
        original = (self.theme / 'index.php').read_bytes()
        writer = PACKAGE.write_archive
        def mutate_after_capture(path, files):
            self.write('index.php', b'<?php // changed after capture; must not ship')
            writer(path, files)
        with mock.patch.object(PACKAGE, 'write_archive', side_effect=mutate_after_capture):
            self.build()
        with zipfile.ZipFile(str(self.output)) as archive:
            self.assertEqual(original, archive.read('wuai-sluyu/index.php'))

    def test_validation_failure_preserves_existing_outputs(self):
        self.output.parent.mkdir()
        self.output.write_bytes(b'old ZIP')
        self.manifest.write_bytes(b'old manifest')
        for step in ('write_archive', 'verify_archive'):
            with self.subTest(step=step), mock.patch.object(PACKAGE, step, side_effect=PACKAGE.PackageError('injected failure')):
                with self.assertRaisesRegex(PACKAGE.PackageError, 'injected'):
                    self.build(overwrite=True)
            self.assertEqual(b'old ZIP', self.output.read_bytes())
            self.assertEqual(b'old manifest', self.manifest.read_bytes())
            self.assert_no_temporaries()

    def test_second_atomic_commit_failure_restores_old_outputs(self):
        self.output.parent.mkdir()
        self.output.write_bytes(b'old ZIP')
        self.manifest.write_bytes(b'old manifest')
        commit = PACKAGE.commit_output
        def fail_manifest(temp, target, overwrite):
            if target == self.manifest:
                raise OSError('injected manifest commit failure')
            commit(temp, target, overwrite)
        with mock.patch.object(PACKAGE, 'commit_output', side_effect=fail_manifest):
            with self.assertRaisesRegex(OSError, 'injected'):
                self.build(overwrite=True)
        self.assertEqual(b'old ZIP', self.output.read_bytes())
        self.assertEqual(b'old manifest', self.manifest.read_bytes())
        self.assert_no_temporaries()

    def test_new_output_race_does_not_clobber_winner(self):
        verifier = PACKAGE.verify_archive
        def concurrent_output(path, files):
            verifier(path, files)
            self.output.write_bytes(b'concurrent writer')
        with mock.patch.object(PACKAGE, 'verify_archive', side_effect=concurrent_output):
            with self.assertRaises(OSError):
                self.build()
        self.assertEqual(b'concurrent writer', self.output.read_bytes())
        self.assertFalse(self.manifest.exists())
        self.assert_no_temporaries()

    def test_outputs_cannot_alias_source_hashes_or_each_other(self):
        (self.source / 'README.md').write_bytes(b'frozen documentation')
        for options in ({'output': self.theme / 'index.php'}, {'output': self.hashes},
                        {'manifest': self.output}, {'output': self.source / 'README.md'}):
            with self.subTest(options=options), self.assertRaises(PACKAGE.PackageError):
                self.build(overwrite=True, **options)
        self.assertEqual(b'<?php echo "public";\n', (self.theme / 'index.php').read_bytes())
        self.assertEqual(b'frozen documentation', (self.source / 'README.md').read_bytes())
        self.assert_no_temporaries()

    def test_new_outputs_are_rolled_back_if_manifest_commit_fails(self):
        commit = PACKAGE.commit_output
        def fail_manifest(temp, target, overwrite):
            if target == self.manifest:
                raise OSError('injected second commit failure')
            commit(temp, target, overwrite)
        with mock.patch.object(PACKAGE, 'commit_output', side_effect=fail_manifest):
            with self.assertRaisesRegex(OSError, 'injected'):
                self.build()
        self.assertFalse(self.output.exists())
        self.assertFalse(self.manifest.exists())
        self.assert_no_temporaries()

    def test_output_hardlink_to_source_is_rejected(self):
        self.output.parent.mkdir()
        os.link(str(self.theme / 'index.php'), str(self.output))
        with self.assertRaisesRegex(PACKAGE.PackageError, 'aliases a source'):
            self.build(overwrite=True)
        self.assertEqual(b'<?php echo "public";\n', self.output.read_bytes())

    def test_corrupt_zip_is_rejected_before_replacement(self):
        self.output.parent.mkdir()
        self.output.write_bytes(b'prior ZIP')
        writer = PACKAGE.write_archive
        def corrupt(path, files):
            writer(path, files)
            with zipfile.ZipFile(str(path), 'a') as archive:
                archive.writestr('wuai-sluyu/unexpected.php', b'<?php // unexpected')
        with mock.patch.object(PACKAGE, 'write_archive', side_effect=corrupt):
            with self.assertRaisesRegex(PACKAGE.PackageError, 'ZIP file set differs'):
                self.build(overwrite=True)
        self.assertEqual(b'prior ZIP', self.output.read_bytes())
        self.assert_no_temporaries()

    def test_symlink_rejected_when_platform_allows_creation(self):
        target = self.theme / 'inc' / 'linked.php'
        try:
            target.symlink_to(self.theme / 'index.php')
        except OSError:
            self.skipTest('Windows account cannot create symlinks; path rejection is otherwise unit tested.')
        with self.assertRaisesRegex(PACKAGE.PackageError, 'Symlinks'):
            self.build(hashes=None)

    def test_reproducible_bytes(self):
        first = self.build()
        second = self.build(output=self.base / 'second.zip', manifest=None)
        self.assertEqual(first['zip_sha256'], second['zip_sha256'])

    def test_cli_works_outside_repository_without_network(self):
        process = subprocess.run([
            sys.executable, str(SCRIPT), '--source', str(self.source),
            '--hashes', str(self.hashes), '--output', str(self.output),
            '--manifest', str(self.manifest),
        ], cwd=str(self.base), stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
        self.assertEqual(0, process.returncode, process.stderr)
        result = json.loads(process.stdout)
        self.assertTrue(result['snapshot_hashes_verified'])
        self.assertEqual(str(self.output), result['zip'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
