# Brace

Brace braces your WordPress. A modular toolbox where every module is off by default. No nags, no tracking, clean uninstall.

Homepage: https://tomherudek.com/brace/

## The trust contract

* Every module is OFF by default. Zero footprint until you enable it.
* No nags, no ads, no upsell banners.
* No external calls, no tracking. Ever.
* Clean uninstall. Brace removes everything it added.
* Modules are strictly isolated. One module's bug cannot touch another.
* Developed in public, released through WordPress.org.

## Requirements

* PHP 8.1 or newer (the bootstrap degrades gracefully below that: admin notice, no fatal)
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

0.1.0 is the core framework with an empty module set: module registry, requirements checks, fatal containment, admin page, WP-CLI commands, clean uninstall. The first modules are being specced in `docs/modules/`.
