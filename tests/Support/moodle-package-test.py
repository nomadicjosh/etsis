"""Package safety/reproducibility checks; no Moodle or etSIS bootstrap/database."""

import hashlib
import importlib.util
from pathlib import Path
import shutil
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("package_moodle", ROOT / "tools/package-moodle.py")
PACKAGE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PACKAGE)


class MoodlePackageTest(unittest.TestCase):
    def test_archive_has_only_public_manifest_and_reproducible_bytes(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "source"
            shutil.copytree(ROOT / "integrations/moodle/local/etsis", source)
            (source / ".env").write_text("fictional-secret")
            archive, digest = PACKAGE.build(source, root / "output")
            self.assertEqual(digest, hashlib.sha256(archive.read_bytes()).hexdigest())
            self.assertEqual(f"{digest}  {archive.name}\n", archive.with_suffix(".zip.sha256").read_text())
            with zipfile.ZipFile(archive) as package:
                self.assertIsNone(package.testzip())
                self.assertEqual(["etsis/" + name for name in PACKAGE.FILES], [entry.filename for entry in package.infolist() if not entry.is_dir()])
                self.assertIn("etsis/lang/en/", package.namelist())
                for name in PACKAGE.FILES:
                    self.assertEqual((source / name).read_bytes(), package.read("etsis/" + name))
            self.assertEqual(digest, PACKAGE.build(source, root / "second")[1])

    def test_required_symlinks_are_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "source"
            shutil.copytree(ROOT / "integrations/moodle/local/etsis", source)
            (source / "README.md").unlink()
            (source / "README.md").symlink_to(source / "COPYING.txt")
            with self.assertRaises(ValueError):
                PACKAGE.build(source, root / "output")
            self.assertFalse((root / "output").exists())

    def test_missing_sources_cannot_leave_partial_download(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with self.assertRaises(ValueError):
                PACKAGE.build(root / "missing", root / "output")
            self.assertFalse((root / "output").exists())


if __name__ == "__main__":
    unittest.main()
