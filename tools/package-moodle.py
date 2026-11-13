#!/usr/bin/env python3
"""Build the distributable local_etsis plugin without booting either application."""

import argparse
import hashlib
import os
from pathlib import Path, PurePosixPath
import re
import tempfile
import zipfile


ROOT = Path(__file__).resolve().parents[1]
FILES = (
    "launch.php",
    "classes/local/session_channel.php",
    "classes/local/linked_session.php",
    "classes/local/launch_error.php",
    "db/hooks.php",
    "COPYING.txt",
    "README.md",
    "classes/external/get_instance_info.php",
    "classes/external/get_account_policy.php",
    "classes/external/provision_account.php",
    "classes/external/get_account_receipt.php",
    "classes/local/account_policy.php",
    "classes/local/account_provisioner.php",
    "classes/local/account_receipt.php",
    "classes/local/roster_sync.php",
    "classes/external/get_roster_policy.php",
    "classes/external/sync_roster_member.php",
    "classes/local/instance_identity.php",
    "classes/privacy/provider.php",
    "db/access.php",
    "db/install.php",
    "db/install.xml",
    "db/upgrade.php",
    "db/services.php",
    "lang/en/local_etsis.php",
    "settings.php",
    "tests/linked_session_test.php",
    "tests/instance_identity_test.php",
    "tests/account_provisioner_test.php",
    "tests/roster_sync_test.php",
    "version.php",
)


def build(source: Path, output: Path) -> tuple[Path, str]:
    """Fixed public manifest: never walk application config, credentials or caches."""
    contents = {}
    total = 0
    for name in FILES:
        path = source / name
        if any(part.is_symlink() for part in (path, *path.parents)):
            raise ValueError("Plugin sources must not contain symbolic links.")
        if not path.is_file() or path.stat().st_size > 1_048_576:
            raise ValueError("A required plugin source is missing or too large.")
        content = path.read_bytes()
        total += len(content)
        if total > 2_097_152:
            raise ValueError("Plugin sources exceed the package size limit.")
        contents[name] = content
    version = contents["version.php"].decode("utf-8")
    match = re.search(r"""\$plugin->release\s*=\s*['"]([0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9.]+)?)['"];""", version)
    component = re.search(r"""\$plugin->component\s*=\s*['"]local_etsis['"];""", version)
    if not match or not component:
        raise ValueError("Invalid plugin release metadata.")
    output.mkdir(parents=True, exist_ok=True)
    archive = output / f"local_etsis-{match[1]}.zip"
    # Fixed timestamps, ordering, modes and compression make identical sources reproducible.
    descriptor, temporary = tempfile.mkstemp(prefix=".local_etsis-", suffix=".zip", dir=output)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            with zipfile.ZipFile(stream, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as package:
                directories = {"etsis/"}
                for name in contents:
                    directories.update(
                        "etsis/" + str(parent) + "/"
                        for parent in PurePosixPath(name).parents
                        if str(parent) != "."
                    )
                for name in sorted(directories):
                    info = zipfile.ZipInfo(name, date_time=(2026, 11, 11, 0, 0, 0))
                    info.create_system = 3
                    info.external_attr = (0o40755 << 16) | 0x10
                    package.writestr(info, b"")
                for name, content in contents.items():
                    info = zipfile.ZipInfo("etsis/" + name, date_time=(2026, 11, 11, 0, 0, 0))
                    info.create_system = 3
                    info.external_attr = 0o100644 << 16
                    info.compress_type = zipfile.ZIP_DEFLATED
                    package.writestr(info, content, compresslevel=9)
        digest = hashlib.sha256(Path(temporary).read_bytes()).hexdigest()
        os.chmod(temporary, 0o644)
        os.replace(temporary, archive)
        archive.with_suffix(".zip.sha256").write_text(f"{digest}  {archive.name}\n", encoding="ascii")
        return archive, digest
    finally:
        Path(temporary).unlink(missing_ok=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, default=ROOT / "dist/moodle")
    args = parser.parse_args()
    try:
        archive, digest = build(ROOT / "integrations/moodle/local/etsis", args.output)
    except (OSError, ValueError, zipfile.BadZipFile):
        parser.exit(1, "Unable to build the Moodle package. Check the required sources and output directory.\n")
    print(f"{archive}\nSHA-256: {digest}")
