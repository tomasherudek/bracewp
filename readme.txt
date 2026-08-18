=== Brace ===
Contributors: tomherudek
Tags: tools, modules, database, staging, developer
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Brace braces your WordPress. A modular toolbox where every module is off by default. No nags, no tracking, clean uninstall.

== Description ==

Brace is a modular toolbox for WordPress developers, agencies, and technical site owners. One plugin, many small tools. You enable only what you need, and a module you never enabled is inert code on disk: never loaded, never parsed.

= The trust contract =

Every line below is a promise. If a release breaks one of them, that release is a bug.

* Every module is OFF by default. Zero footprint until you enable it.
* No nags, no ads, no upsell banners.
* No external calls, no tracking. Ever.
* Clean uninstall. Brace removes everything it added.
* Modules are strictly isolated. One module's bug cannot touch another, and a module that fails during boot disables itself instead of taking your site down.
* Developed in public on GitHub, released through WordPress.org.

= Built to never break a site =

* A plain PHP bootstrap checks server requirements first, so an old server gets a friendly notice instead of a fatal error.
* Every module declares its server requirements; when your hosting cannot support a module, the toggle explains why in one human sentence.
* Every destructive action runs as dry run first, backs up the affected data, and works in small time-bounded batches that respect low-end hosting.

= Modules =

Brace 0.1.0 ships the core framework: the module registry, requirements checks, fatal containment, the admin page, WP-CLI commands, and a clean uninstall path. The first modules are in development and each one will ship off by default.

= WP-CLI =

Everything is scriptable:

* `wp brace module list`
* `wp brace module enable <slug>`
* `wp brace module disable <slug>`

== Installation ==

1. Install through the WordPress plugin screen, or upload the zip via Plugins, Add New, Upload.
2. Activate the plugin.
3. Go to Settings, Brace and enable the modules you want. Nothing is enabled for you.

== Frequently Asked Questions ==

= Why is everything disabled after install? =

By design. Brace has zero footprint until you opt in, module by module. Nothing runs, nothing loads, nothing changes on your site until you flip a toggle.

= Does Brace phone home? =

No. Brace makes no external calls and collects nothing. There is no tracking, no telemetry, and no account.

= What happens when I delete Brace? =

A clean uninstall. Every module removes its own data, and Brace removes all of its options, whether a module was ever enabled or not.

= Is there a pro version? =

No. There are no upsells and no locked features.

== Changelog ==

= 0.1.0 =
* Initial release: core framework, module registry, safety layers, admin page, WP-CLI commands. No modules yet; the first modules ship in an upcoming release.
