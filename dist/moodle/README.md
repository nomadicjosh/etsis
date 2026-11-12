# Moodle connector downloads

- [local_etsis-0.3.1-alpha.zip](local_etsis-0.3.1-alpha.zip)
- [SHA-256 checksum](local_etsis-0.3.1-alpha.zip.sha256)
- [Installation and configuration guide](../../integrations/moodle/local/etsis/README.md)

The ZIP is suitable for Moodle's **Install plugins** upload screen. This alpha provides installation verification and separately disabled account provisioning with creation receipts. It supports reviewed external-SSO account records or native password-setup email, subject to institutional configuration. Direct launch has a separate opt-in. Automatic eligibility, institutional OIDC, bidirectional logout and academic synchronization remain in development. Upgrade the plugin and its restricted service before enabling the new etSIS worker.

For download hosting, upload the ZIP and checksum to your chosen release/download service and link the installation guide alongside them. The package contains its guide and GPL license. No hosting service or Moodle installation is changed by building the package.

Rebuild from the repository root with Python 3.10 or later (standard library only):

```sh
python3 tools/package-moodle.py
python3 tests/Support/moodle-package-test.py
```

The fixed public manifest excludes etSIS configuration, credentials, caches and other application code. The builder rejects symlinks and missing/oversized required sources; file ordering, timestamps and permissions are fixed for repeatable builds with the same Python/zlib environment. Rebuild the ZIP and checksum together whenever plugin sources change. Update the Moodle numeric plugin version and release name before distributing a new release.

Plugin PHP syntax, XMLDB structure parsing, worker/gateway/connector-contract checks and package safety/reproducibility checks pass. The installed Moodle plugin was not upgraded during these checks. Native Moodle PHPUnit tests are included but require a dedicated Moodle test database; they have not been executed here. Declared Moodle 4.5+ compatibility still requires branch-specific installation and operational acceptance.

Direct launch is separately opt-in. See [setup and acceptance](../../docs/moodle-direct-launch.md) before enabling the connector’s launch hook and etSIS launch flag.
