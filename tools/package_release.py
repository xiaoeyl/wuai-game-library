#!/usr/bin/env python3
"""Build a verified theme ZIP from a selected repository snapshot (Python 3.8+).

--source is a repository root containing wuai-sluyu/. --hashes accepts a JSON
object of repository-relative paths to SHA256 digests; its wuai-sluyu/ subset
must exactly equal the selected theme files. Other repository entries are not
packaged. Without --hashes the ZIP is checked against captured source bytes,
but no independently frozen snapshot identity is claimed.

Topic media and comment actions are deferred by default. --include-module may
explicitly opt a reviewed default module in; --exclude-module defers another
inc/*.php module. All output is verified before an atomic, no-clobber commit.
--overwrite permits replacing an existing regular output, never a source file.
"""

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import sys
import tempfile
import zipfile


ROOT = Path(__file__).resolve().parents[1]
THEME_NAME = 'wuai-sluyu'
DEFAULT_EXCLUDED = frozenset({'inc/topic-media.php', 'inc/comment-actions.php'})
ALLOWED_SUFFIXES = frozenset({
    '.php', '.css', '.js', '.md', '.txt', '.html', '.svg', '.png', '.jpg',
    '.jpeg', '.gif', '.webp', '.ico', '.avif', '.woff', '.woff2', '.ttf',
    '.otf', '.eot', '.po', '.pot', '.mo',
})
FORBIDDEN_PARTS = frozenset({
    '.git', '.github', '.idea', '.vscode', '.history', '__pycache__',
    'node_modules', 'tests', 'test', 'fixtures', 'fixture', 'qa', 'dist',
    'coverage', 'mu-plugins', 'uploads', 'logs', 'tmp', 'temp', 'cache',
    'backups', 'database', 'databases',
})
FORBIDDEN_NAMES = frozenset({
    'wp-config.php', '.env', '.ds_store', 'thumbs.db', 'desktop.ini',
    'php.ini', '.htaccess', '.user.ini', 'id_rsa', 'id_ed25519',
})
SENSITIVE_CONTENT = re.compile(
    rb'(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}'
    rb'|-----BEGIN [A-Z ]*PRIVATE KEY-----'
    rb'|WUAI_[A-Z0-9_]*QA(?:_[A-Z0-9_]+)?'
    rb'|WP_QA_ROOT|qa-local-only|QA-GUESS-HIDDEN)'
)
TEXT_SUFFIXES = frozenset({'.php', '.css', '.js', '.md', '.txt', '.html', '.svg', '.po', '.pot'})


class PackageError(Exception):
    pass


def require(condition, message):
    if not condition:
        raise PackageError(message)


def sha256(raw):
    return hashlib.sha256(raw).hexdigest()


def absolute(path):
    return Path(os.path.abspath(os.fspath(path)))


def inside(path, parent):
    try:
        path.relative_to(parent)
        return True
    except ValueError:
        return False


def safe_name(name):
    require(isinstance(name, str) and name and '\\' not in name and ':' not in name,
            'Paths must be nonempty relative POSIX paths.')
    parsed = PurePosixPath(name)
    require(not parsed.is_absolute() and parsed.as_posix() == name
            and all(part not in ('', '.', '..') for part in parsed.parts)
            and not any(ord(char) < 32 for char in name),
            'Unsafe relative path: ' + repr(name))
    return name


def plain_path(path, allow_missing=False):
    """Reject symlinks/junctions before resolving any source or output path."""
    path = absolute(path)
    for current in list(reversed(path.parents)) + [path]:
        try:
            info = current.lstat()
        except FileNotFoundError:
            require(allow_missing, 'File or directory does not exist: ' + str(current))
            continue
        require(not stat.S_ISLNK(info.st_mode)
                and not getattr(info, 'st_file_attributes', 0) & 0x400,
                'Symlinks and Windows reparse points are not allowed: ' + str(current))
    return path


def unique_object(pairs):
    result = {}
    for name, value in pairs:
        require(name not in result, 'Duplicate hash-manifest key: ' + str(name))
        result[name] = value
    return result


def module_name(value):
    value = safe_name(value)
    require(value.startswith('inc/') and value.endswith('.php'),
            'Module selectors must be theme-relative inc/*.php paths: ' + value)
    return value


def excluded_modules(include, exclude):
    included = {module_name(name) for name in include}
    extra = {module_name(name) for name in exclude}
    require(included <= DEFAULT_EXCLUDED,
            '--include-module only opts in a default-deferred module.')
    require(not included & extra, 'A module cannot be explicitly included and excluded.')
    return (set(DEFAULT_EXCLUDED) - included) | extra


def validate_path(name):
    safe_name(name)
    parts = PurePosixPath(name).parts
    lowered = [part.lower() for part in parts]
    require(not any(part in FORBIDDEN_PARTS or part.startswith('.') for part in lowered),
            'Environment, QA, hidden, or editor path is not allowed: ' + name)
    require(not any(re.search(r'(^|[-_.])(qa|test|tests|fixture|fixtures)([-_.]|$)', part)
                    for part in lowered), 'QA/test path is not allowed: ' + name)
    base = lowered[-1]
    require(base not in FORBIDDEN_NAMES and not base.endswith(('~', '.bak', '.backup', '.old', '.orig', '.rej', '.swp', '.swo'))
            and not re.search(r'(^|[-_.])(env|credentials|secrets?|database|dump)([-_.]|$)', base),
            'Environment, database, backup, or editor file is not allowed: ' + name)
    require(PurePosixPath(name).suffix.lower() in ALLOWED_SUFFIXES,
            'Unapproved theme file extension: ' + name)


def capture_source(source, excluded):
    source = plain_path(source)
    theme = plain_path(source / THEME_NAME)
    require(source.is_dir() and theme.is_dir(), '--source must contain a regular wuai-sluyu directory.')
    files = {}
    folded = set()
    protected = set()
    for directory, directories, names in os.walk(str(theme), followlinks=False):
        directories.sort()
        names.sort()
        for name in directories:
            path = plain_path(Path(directory) / name)
            rel = path.relative_to(theme).as_posix()
            # A placeholder suffix reuses file path checks for directories.
            validate_path(rel + '/directory.php')
        for name in names:
            path = plain_path(Path(directory) / name)
            require(stat.S_ISREG(path.stat().st_mode), 'Only regular theme files are allowed: ' + str(path))
            rel = safe_name(path.relative_to(theme).as_posix())
            protected.add(path)
            require(rel.casefold() not in folded, 'Case-colliding theme file: ' + rel)
            folded.add(rel.casefold())
            if rel in excluded:
                continue
            validate_path(rel)
            files[THEME_NAME + '/' + rel] = path.read_bytes()
    require(files, 'No selected theme files found.')
    return source, theme, files, protected


def validate_content(files, excluded):
    function_path = THEME_NAME + '/functions.php'
    style_path = THEME_NAME + '/style.css'
    require(function_path in files and style_path in files, 'Theme functions.php and style.css are required.')
    try:
        functions = files[function_path].decode('utf-8')
        style = files[style_path].decode('utf-8')
    except UnicodeError:
        raise PackageError('Theme version files must be UTF-8.') from None
    match = re.search(r"define\(\s*['\"]WUAI_SLUYU_VERSION['\"]\s*,\s*['\"]([^'\"]+)", functions)
    header = re.search(r'^Version:\s*([^\r\n]+)', style, flags=re.MULTILINE)
    require(match and header and match.group(1).strip() == header.group(1).strip(),
            'Theme versions are missing or differ between functions.php and style.css.')
    version = match.group(1).strip()
    require(re.fullmatch(r'\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?', version),
            'Theme version must be a safe semantic version.')
    for name, raw in files.items():
        suffix = PurePosixPath(name).suffix.lower()
        require(not SENSITIVE_CONTENT.search(raw), 'QA marker or credential-like content found in ' + name)
        require(not re.search(rb"define\s*\(\s*['\"]DB_(?:PASSWORD|USER|NAME|HOST)['\"]", raw),
                'Database connection configuration found in ' + name)
        if suffix == '.php':
            # This is a conservative guard, not a substitute for PHP 7.4 lint.
            require(b'#[' not in raw, 'PHP attribute syntax requires separate compatibility review: ' + name)
            for module in excluded:
                require(module.encode('utf-8') not in raw.replace(b'\\', b'/'),
                        'Excluded module is referenced by a packaged PHP file: ' + name + ' -> ' + module)
        if suffix in TEXT_SUFFIXES:
            require(b'\x00' not in raw, 'NUL byte in a text asset: ' + name)
    return version


def verify_hashes(path, files):
    if path is None:
        return None, None
    path = plain_path(path)
    require(path.is_file(), '--hashes must be a regular JSON file.')
    raw = path.read_bytes()
    try:
        data = json.loads(raw.decode('utf-8'), object_pairs_hook=unique_object)
    except (ValueError, UnicodeError):
        raise PackageError('Hash manifest must contain valid UTF-8 JSON.') from None
    require(isinstance(data, dict) and data, 'Hash manifest must be a nonempty path/digest object.')
    selected = {}
    folded = set()
    for name, expected in data.items():
        safe_name(name)
        require(name.casefold() not in folded, 'Case-colliding hash-manifest path: ' + name)
        folded.add(name.casefold())
        require(isinstance(expected, str) and re.fullmatch(r'[a-f0-9]{64}', expected),
                'Expected a lowercase SHA256 digest for ' + name)
        if name.startswith(THEME_NAME + '/'):
            selected[name] = expected
    require(set(selected) == set(files),
            'Hash manifest theme set differs from selected files. Missing hashes: '
            + repr(sorted(set(files) - set(selected))) + '; extra hashes: '
            + repr(sorted(set(selected) - set(files))))
    for name, expected in selected.items():
        require(sha256(files[name]) == expected, 'Source digest mismatch: ' + name)
    return path, sha256(raw)


def prepare_output(path, source, theme, protected, overwrite):
    path = plain_path(path, allow_missing=True)
    require(not inside(path, theme) and path not in protected,
            'Output must not overwrite a theme/source/hash input: ' + str(path))
    if inside(path, source):
        relative = path.relative_to(source)
        require(relative.parts and relative.parts[0] == 'dist',
                'Output inside --source must stay under its dist directory: ' + str(path))
    if path.exists():
        require(path.is_file(), 'Output must be a regular file: ' + str(path))
        require(overwrite, 'Output already exists; use --overwrite explicitly: ' + str(path))
        for source in protected:
            if source.exists():
                require(not os.path.samefile(str(path), str(source)), 'Output aliases a source input: ' + str(path))
    path.parent.mkdir(parents=True, exist_ok=True)
    plain_path(path.parent)
    return path


def temporary_path(output):
    handle, name = tempfile.mkstemp(prefix='.' + output.name + '.', suffix='.tmp', dir=str(output.parent))
    os.close(handle)
    return Path(name)


def write_archive(path, files):
    with zipfile.ZipFile(str(path), 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name in sorted(files):
            item = zipfile.ZipInfo(name, date_time=(2020, 1, 1, 0, 0, 0))
            item.compress_type = zipfile.ZIP_DEFLATED
            item.create_system = 3
            item.external_attr = (stat.S_IFREG | 0o644) << 16
            archive.writestr(item, files[name], compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    with path.open('r+b') as handle:
        os.fsync(handle.fileno())


def verify_archive(path, files):
    try:
        with zipfile.ZipFile(str(path)) as archive:
            names = archive.namelist()
            require(len(names) == len(set(names)) == len(files) and set(names) == set(files),
                    'ZIP file set differs from the captured source bytes.')
            require(archive.testzip() is None, 'ZIP integrity check failed.')
            for item in archive.infolist():
                require(not item.is_dir() and not item.flag_bits & 1
                        and stat.S_IFMT(item.external_attr >> 16) == stat.S_IFREG,
                        'ZIP entry is not a regular unencrypted file.')
                require(archive.read(item) == files[item.filename], 'ZIP byte mismatch: ' + item.filename)
    except (zipfile.BadZipFile, RuntimeError):
        raise PackageError('ZIP is unreadable or corrupt.') from None


def commit_output(temp, target, overwrite):
    plain_path(target, allow_missing=True)
    if target.exists():
        require(target.is_file(), 'Output became a non-regular file: ' + str(target))
    if overwrite:
        os.replace(str(temp), str(target))
    elif os.name == 'nt':
        # Windows rename is atomic and refuses a destination created meanwhile.
        os.rename(str(temp), str(target))
    else:
        # Unlike rename on POSIX, link gives atomic no-clobber semantics.
        os.link(str(temp), str(target))
        temp.unlink()


def retain_original(target):
    if not target.exists():
        return None
    handle, name = tempfile.mkstemp(prefix='.' + target.name + '.', suffix='.rollback', dir=str(target.parent))
    os.close(handle)
    backup = Path(name)
    backup.unlink()
    try:
        os.link(str(target), str(backup))
    except OSError:
        # A hard-link rollback is required; do not replace an existing output
        # on a filesystem that cannot preserve it without copying.
        raise PackageError('Cannot retain existing output for rollback: ' + str(target)) from None
    return backup


def commit_outputs(items, overwrite):
    backups = {}
    committed = []
    preserve_backups = False
    try:
        if overwrite:
            for unused, target in items:
                plain_path(target, allow_missing=True)
                require(not target.exists() or target.is_file(), 'Output became a non-regular file: ' + str(target))
                backups[target] = retain_original(target)
        for temp, target in items:
            commit_output(temp, target, overwrite)
            committed.append(target)
    except (OSError, PackageError):
        failures = []
        for target in reversed(committed):
            try:
                previous = backups.get(target)
                if previous:
                    os.replace(str(previous), str(target))
                else:
                    target.unlink()
            except OSError:
                failures.append(str(target))
        if failures:
            # Preserve the rollback hard links for manual recovery.
            preserve_backups = True
            raise PackageError('Output commit/rollback failed; retained rollback files require recovery: '
                               + ', '.join(failures)) from None
        raise
    finally:
        if not preserve_backups:
            for backup in backups.values():
                if backup is not None and backup.exists():
                    backup.unlink()


def package_release(source=ROOT, hashes=None, output=None, manifest=None,
                    overwrite=False, include=(), exclude=()):
    excluded = excluded_modules(include, exclude)
    source, theme, files, protected = capture_source(source, excluded)
    version = validate_content(files, excluded)
    hashes_path, hashes_digest = verify_hashes(hashes, files)
    if hashes_path:
        protected.add(hashes_path)
    output = output or ROOT / 'dist' / ('wuai-sluyu-v' + version + '.zip')
    output = prepare_output(output, source, theme, protected, overwrite)
    require(output.suffix.lower() == '.zip', 'Package output must use the .zip extension.')
    manifest_path = None
    if manifest:
        manifest_path = prepare_output(manifest, source, theme, protected, overwrite)
        require(manifest_path.suffix.lower() == '.json', 'Manifest output must use the .json extension.')
        require(manifest_path != output, 'ZIP and manifest output paths must differ.')
        if output.exists() and manifest_path.exists():
            require(not os.path.samefile(str(output), str(manifest_path)), 'ZIP and manifest outputs must not alias.')
    archive_temp = temporary_path(output)
    manifest_temp = None
    try:
        write_archive(archive_temp, files)
        verify_archive(archive_temp, files)
        zip_raw = archive_temp.read_bytes()
        result = {
            'version': version, 'theme_files': len(files), 'source': str(source),
            'zip': str(output), 'zip_bytes': len(zip_raw), 'zip_sha256': sha256(zip_raw),
            'snapshot_hashes_verified': hashes_path is not None,
            'snapshot_hashes_sha256': hashes_digest,
            'excluded_theme_files': sorted(excluded),
            'files': {name: sha256(raw) for name, raw in sorted(files.items())},
        }
        if manifest_path:
            manifest_temp = temporary_path(manifest_path)
            encoded = (json.dumps(result, ensure_ascii=True, indent=2) + '\n').encode('utf-8')
            with manifest_temp.open('wb') as handle:
                handle.write(encoded)
                handle.flush()
                os.fsync(handle.fileno())
            require(manifest_temp.read_bytes() == encoded, 'Manifest temporary write did not verify.')
        # All validation and writes happen before replacing any existing output.
        outputs = [(archive_temp, output)]
        if manifest_path:
            outputs.append((manifest_temp, manifest_path))
        commit_outputs(outputs, overwrite)
        return result
    finally:
        for temp in (archive_temp, manifest_temp):
            if temp is not None and temp.exists():
                temp.unlink()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, default=ROOT, help='Repository snapshot root containing wuai-sluyu/.')
    parser.add_argument('--hashes', type=Path, help='Repository-relative SHA256 JSON; theme subset must match exactly.')
    parser.add_argument('--output', type=Path, help='ZIP path; default is this repository dist/wuai-sluyu-vX.Y.Z.zip.')
    parser.add_argument('--manifest', type=Path, help='Optional atomic JSON output with per-file hashes.')
    parser.add_argument('--overwrite', action='store_true', help='Replace existing regular outputs only after validation.')
    parser.add_argument('--include-module', action='append', default=[], help='Explicitly include a default-deferred inc/*.php module.')
    parser.add_argument('--exclude-module', action='append', default=[], help='Additionally defer a theme-relative inc/*.php module.')
    args = parser.parse_args()
    result = package_release(source=args.source, hashes=args.hashes, output=args.output,
                             manifest=args.manifest, overwrite=args.overwrite,
                             include=args.include_module, exclude=args.exclude_module)
    print(json.dumps({key: value for key, value in result.items() if key != 'files'}, ensure_ascii=True, indent=2))


if __name__ == '__main__':
    try:
        main()
    except (PackageError, OSError) as error:
        print('Package stopped: ' + str(error), file=sys.stderr)
        sys.exit(1)
