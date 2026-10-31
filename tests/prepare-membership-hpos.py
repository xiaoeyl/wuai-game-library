"""Copy a disposable SQLite WordPress site; never change the source CPT database.

Run while source suites are idle. SQLite online backup produces a consistent
database snapshot even when the source uses WAL; do not copy the .sqlite file.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import shutil
import sqlite3


def within(child: Path, parent: Path) -> bool:
    return child == parent or parent in child.parents


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True, type=Path)
    parser.add_argument("--source-db", required=True, type=Path)
    parser.add_argument("--target", required=True, type=Path)
    args = parser.parse_args()
    if sqlite3.sqlite_version_info < (3, 37, 0):
        raise SystemExit("SQLite >= 3.37 is required for the adapter's STRICT tables; this Python bundles " + sqlite3.sqlite_version + ". Use a newer Python before copying the site.")
    source = args.source.resolve(strict=True)
    source_db = args.source_db.resolve(strict=True)
    target = args.target.resolve()
    if not (source / "wp-load.php").is_file() or not (source / "wp-content/db.php").is_file():
        raise SystemExit("Source must be the disposable SQLite WordPress installation.")
    if not within(source_db, source / "wp-content/database"):
        raise SystemExit("Source database must be inside the named source installation.")
    if target.exists() or within(target, source) or within(source, target):
        raise SystemExit("Target must be a new, non-overlapping directory; no existing files are replaced.")
    config = (source / "wp-config.php").read_text(encoding="utf-8-sig")
    if "http://127.0.0.1:8771" not in config:
        raise SystemExit("Source must be the explicit localhost QA site.")
    for directory in (source, source / "wp-content", source_db.parent):
        if directory.is_symlink() or (hasattr(directory, "is_junction") and directory.is_junction()):
            raise SystemExit("Symlink/junction source paths are not accepted for this copy.")

    def ignore(directory: str, names: list[str]) -> set[str]:
        current = Path(directory)
        skipped = {name for name in names if name.endswith(".log")}
        for name in names:
            entry = current / name
            if entry.is_symlink() or (hasattr(entry, "is_junction") and entry.is_junction()):
                raise RuntimeError("Refusing to follow a symlink/junction in the QA tree: " + str(entry))
        if current == source / "wp-content":
            skipped.update({"database", "mu-plugins", "object-cache.php", "advanced-cache.php", "upgrade", "upgrade-temp-backup", "qa-update"})
        return skipped

    shutil.copytree(source, target, ignore=ignore)
    database = target / "wp-content/database/membership-hpos.sqlite"
    database.parent.mkdir(parents=True, exist_ok=True)
    # mode=ro prevents this preparation step from modifying the original DB.
    with sqlite3.connect(source_db.as_uri() + "?mode=ro", uri=True) as original:
        with sqlite3.connect(database) as snapshot:
            original.backup(snapshot)
            if snapshot.execute("PRAGMA integrity_check").fetchone() != ("ok",):
                raise RuntimeError("Copied SQLite database failed integrity_check.")
    marker = {
        "kind": "WUAI_MEMBERSHIP_HPOS_ISOLATED_V1",
        "source": str(source),
        "source_database": str(source_db),
        "target": str(target),
        "database": str(database),
        "snapshot_only": True,
    }
    (target / "membership-hpos-isolated.json").write_text(json.dumps(marker, indent=2), encoding="utf-8")
    print("Prepared an independent SQLite snapshot. Source database was opened read-only.")
    print("Do not serve this copied site over HTTP. Set WP_QA_ROOT to: " + str(target))
    print("Run: php tests/membership-hpos.php")


if __name__ == "__main__":
    main()
