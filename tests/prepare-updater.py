"""Prepare a marked, independent LOCAL WordPress installation for updater.cjs.

Never reuse the shared regression site: successful installation replaces files.
"""
from pathlib import Path
import hashlib
import json
import os
import re
import shutil
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SOURCE = Path(os.environ.get('WP_QA_SOURCE', ROOT)).resolve()
if not os.environ.get('WP_QA_ROOT'):
    raise SystemExit('WP_QA_ROOT must name an explicitly marked independent updater site.')
WP = Path(os.environ['WP_QA_ROOT']).resolve(strict=True)
marker = WP / 'updater-isolated.json'
if not marker.is_file() or json.loads(marker.read_text(encoding='utf-8')).get('kind') != 'WUAI_UPDATER_ISOLATED_V2':
    raise SystemExit('Missing independent updater marker. Do not install updates on the shared QA site.')
if ROOT / 'dist' not in WP.parents or WP == (ROOT / 'dist/checks-v1.1.0/site').resolve():
    raise SystemExit('Updater site must be a separate installation inside the workspace dist directory.')
config = (WP / 'wp-config.php').read_text(encoding='utf-8')
assert re.search(r"define\('WP_HOME','http://127\.0\.0\.1:\d+'\)", config) and "define('WP_HTTP_BLOCK_EXTERNAL',true)" in config, 'Disposable local WP only'
theme = SOURCE / 'wuai-sluyu'
if not theme.is_dir():
    raise SystemExit('WP_QA_SOURCE must contain the full current theme directory.')
files = sorted(path for path in theme.rglob('*') if path.is_file() and path.relative_to(theme).as_posix() != 'inc/comment-actions.php')
for path in files:
    if path.is_symlink() or '..' in path.relative_to(theme).parts:
        raise SystemExit('Unsafe source path: ' + str(path))
names = [path.relative_to(SOURCE).as_posix() for path in files]
functions = (theme / 'functions.php').read_bytes()
current = re.findall(rb"define\(\s*['\"]WUAI_SLUYU_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)", functions)
if len(current) != 1:
    raise SystemExit('Expected exactly one current theme version constant.')
version = current[0]
style = (theme / 'style.css').read_bytes()
assert re.search(rb'^Version:\s*' + re.escape(version) + rb'\s*$', style, re.M), 'Current file versions must agree'
hashes = {}
for name in names:
    target = WP / 'wp-content/themes' / name
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(SOURCE / name, target)
    hashes[name] = hashlib.sha256(target.read_bytes()).hexdigest()
plugin = WP / 'wp-content/mu-plugins/updater-fixture.php'
plugin.parent.mkdir(parents=True, exist_ok=True)
shutil.copy2(ROOT / 'tests/updater-fixture.php', plugin)
packages = WP / 'wp-content/qa-update'
packages.mkdir(exist_ok=True)
for kind in ['valid', 'bad-version', 'bad-directory', 'missing-file', 'missing-bootstrap', 'missing-downloader', 'requirements']:
    with zipfile.ZipFile(packages / (kind + '.zip'), 'w', zipfile.ZIP_DEFLATED) as archive:
        for name in names:
            if kind == 'missing-file' and name.endswith('/inc/admin-options.php'):
                continue
            if kind == 'missing-bootstrap' and name.endswith('/inc/portal.php'):
                continue
            if kind == 'missing-downloader' and name.endswith('/inc/update-download.php'):
                continue
            raw = (SOURCE / name).read_bytes()
            if name == 'wuai-sluyu/functions.php':
                raw, count = re.subn(rb"(define\(\s*['\"]WUAI_SLUYU_VERSION['\"]\s*,\s*['\"])[^'\"]+(['\"]\s*\))", rb'\g<1>99.0.0\2', raw)
                assert count == 1
            if name == 'wuai-sluyu/style.css':
                raw, count = re.subn(rb'^Version:[^\r\n]+', b'Version: ' + (b'98.0.0' if kind == 'bad-version' else b'99.0.0'), raw, flags=re.M)
                assert count == 1
                if kind == 'requirements':
                    raw, count = re.subn(rb'^Requires PHP:[^\r\n]+', b'Requires PHP: 99.0', raw, flags=re.M)
                    assert count == 1
            if kind == 'bad-directory':
                name = name.replace('wuai-sluyu/', 'wrong-theme/', 1)
            archive.writestr(name, raw)
manifest = {'version': version.decode(), 'files': hashes, 'package_files': len(names)}
(WP / 'updater-source-manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
print('Prepared full current theme, fixture and seven test packages: ' + str(len(names)) + ' files; baseline ' + version.decode())
