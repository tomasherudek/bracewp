# Integration tests

Placeholder. Integration tests run against a real WordPress and MySQL via `wp-env` (Docker) with `WP_UnitTestCase`, and land together with the first module.

Planned coverage:

* Module enable/disable lifecycle (activate, deactivate, flags in `brace_modules`).
* Option storage behavior: `brace_settings_<module>` created with autoload off.
* Fatal containment: a module that fatals during boot disables itself and leaves the site running.
* WP-CLI commands (`wp brace module ...`).
* Uninstall: every option and trace removed.
