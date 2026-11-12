# Moodle connector downloads

- [local_etsis-0.1.0-alpha.zip](local_etsis-0.1.0-alpha.zip)
- [SHA-256 checksum](local_etsis-0.1.0-alpha.zip.sha256)
- [Installation and configuration guide](../../integrations/moodle/local/etsis/README.md)

The ZIP is suitable for Moodle's **Install plugins** upload screen. This alpha release provides restricted installation-identity verification and installs disabled. SSO, persistent mappings and synchronization remain in development.

For download hosting, upload the ZIP and checksum to your chosen release/download service and link the installation guide alongside them. The package contains its guide and GPL license. No hosting service or Moodle installation is changed by building the package.

Rebuild from the repository root with Python 3.10 or later (standard library only):

```sh
python3 tools/package-moodle.py
python3 tests/Support/moodle-package-test.py
```

The fixed public manifest excludes etSIS configuration, credentials, caches and other application code. The builder rejects symlinks and missing/oversized required sources; file ordering, timestamps and permissions are fixed for repeatable builds with the same Python/zlib environment. Rebuild the ZIP and checksum together whenever plugin sources change. Update the Moodle numeric plugin version and release name before distributing a new release.

Moodle's local package validator accepted the ZIP without installing it. Plugin PHP syntax, etSIS gateway/console checks and package safety/reproducibility checks were exercised separately. Native Moodle PHPUnit tests are included but require a dedicated Moodle test database; they have not been executed here. Declared Moodle 4.5+ compatibility still requires branch-specific installation and operational acceptance.
