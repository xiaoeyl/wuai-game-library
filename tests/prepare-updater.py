"""Prepare an existing disposable WordPress installation for updater.cjs."""
from pathlib import Path
import os
import shutil
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SOURCE = Path(os.environ.get('WP_QA_SOURCE', ROOT)).resolve()
WP = Path(os.environ.get('WP_QA_ROOT', ROOT / 'dist/checks-v1.0.6/local/wordpress')).resolve()
config = (WP / 'wp-config.php').read_text(encoding='utf-8')
assert "define('WP_HOME','http://127.0.0.1:" in config and "define('WP_HTTP_BLOCK_EXTERNAL',true)" in config, 'Disposable local WP only'
previous = zipfile.ZipFile(ROOT / 'dist/wuai-sluyu-v1.0.7.zip')
names = previous.namelist() + ['wuai-sluyu/inc/theme-update-actions.php', 'wuai-sluyu/assets/js/admin-updater.js']
for name in names:
    target = WP / 'wp-content/themes' / name
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(SOURCE / name, target)
plugin = WP / 'wp-content/mu-plugins/updater-fixture.php'
plugin.parent.mkdir(parents=True, exist_ok=True)
shutil.copy2(ROOT / 'tests/updater-fixture.php', plugin)
packages = WP / 'wp-content/qa-update'
packages.mkdir(exist_ok=True)
for kind in ['valid', 'bad-version', 'bad-directory', 'missing-file', 'requirements']:
    with zipfile.ZipFile(packages / (kind + '.zip'), 'w', zipfile.ZIP_DEFLATED) as archive:
        for name in names:
            if kind == 'missing-file' and name.endswith('/inc/admin-options.php'):
                continue
            raw = (SOURCE / name).read_bytes()
            if name.endswith('/functions.php'):
                raw = raw.replace(b"define('WUAI_SLUYU_VERSION', '1.0.8')", b"define('WUAI_SLUYU_VERSION', '99.0.0')")
            if name.endswith('/style.css'):
                raw = raw.replace(b'Version: 1.0.8', b'Version: ' + (b'98.0.0' if kind == 'bad-version' else b'99.0.0'))
                if kind == 'requirements':
                    raw = raw.replace(b'Requires PHP: 7.4', b'Requires PHP: 99.0')
            if kind == 'bad-directory':
                name = name.replace('wuai-sluyu/', 'wrong-theme/', 1)
            archive.writestr(name, raw)
print('Prepared isolated WordPress theme, fixture and five test packages.')
