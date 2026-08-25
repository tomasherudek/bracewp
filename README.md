# Brace

Brace braces your WordPress. A modular toolbox where every module is off by default. No nags, no tracking, clean uninstall.

Homepage: https://github.com/tomasherudek/bracewp

## The trust contract

* Every module is OFF by default. Zero footprint until you enable it.
* No nags, no ads, no upsell banners.
* No external calls, no tracking. Ever.
* Clean uninstall. Brace removes everything it added.
* Modules are strictly isolated. One module's bug cannot touch another.
* Developed in public, released through WordPress.org.

## Requirements

* PHP 7.4 or newer (the bootstrap degrades gracefully below that: admin notice, no fatal)
* WordPress 6.7 or newer

## Development

```bash
composer install
composer test      # unit tests (PHPUnit + Brain Monkey, no WordPress needed)
composer phpcs     # coding standard (WPCS)
composer phpstan   # static analysis (level 6, WordPress extension)
```

The repo layout, safety layers, and module contract are documented in the architecture notes; the module spec process lives in [docs/modules](docs/modules/README.md). Integration tests (wp-env with a real WordPress and MySQL) land together with the first module.

## Status

0.1.0 is the core framework — module registry, requirements checks, fatal containment, admin page, WP-CLI commands, clean uninstall — plus the first shipped module:

- **[003 Staging Anonymize](docs/modules/003-staging-anonymize.md)** — rewrites every WooCommerce customer and order on a staging copy into deterministic fakes derived from the production ids, so a clone can be worked on (or handed to an AI) without carrying customer data. Refuses to run unless the copy identifies itself as staging. CLI-first: `wp brace staging-anonymize dry-run`.

Its coverage is unit-only so far; the integration suite that would prove the HPOS storage matrix needs the wp-env harness that does not exist yet (see the module spec, section 7). The remaining modules are being specced in `docs/modules/`.
