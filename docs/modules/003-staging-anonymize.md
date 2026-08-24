# 003 - Staging Anonymize

Status: **v0 built** — spec reconciled with the implementation on 2026-08-24
Slug: `staging-anonymize`
Target release: v0, ahead of 001-staging-guard (see section 4 for what that costs)

## 1. Problem and who it is for

A staging copy carries every person the site ever registered: names, emails, phones, addresses, IP addresses, password hashes — and on a WooCommerce store, order histories on top. The moment you point an AI tool, a contractor, or a debug log at that copy, you are leaking production PII. GDPR aside, it is simply more data than staging needs: staging needs *realistic* data, not *real* data.

For **developers** working on site copies, and specifically for the workflow "clone production, then let tooling loose on it safely."

Staging Anonymize rewrites every identity on the copy into a deterministic fake, while keeping the site structurally intact: content still has the same authors, orders still belong to the same (fake) people, totals and dates and statuses are untouched, coupons still enforce per-customer limits, download permissions still resolve.

**Scope note:** WordPress users are always in scope — a membership site, a client portal, or a plain blog with registrations holds personal data with no shop anywhere near it. WooCommerce is anonymized **in addition**, when its data is present. PII held by other plugins (forms, CRM, invoicing) is out of scope for now (section 9).

## 2. Scope fence

**In scope, always:**

- **WP users**: email, login, nicename, display name, URL, password, activation key, and the mapped meta (names, nickname, description, and the Woo billing/shipping keys where they exist). Excluded roles are skipped (default: `administrator`).
- **Comment authors** — blog comments and product reviews alike (author name, email, URL, IP). Order notes are the one exception, kept by owner decision (section 2.4).
- **Credentials:** every non-excluded user gets a new random password; activation keys and session tokens are cleared. Production password hashes are crackable material and have no business on staging.

**In scope when WooCommerce data is found:**
- **WooCommerce orders** wherever they physically live: HPOS tables, legacy postmeta, or both when compatibility sync is on. The module detects storage at runtime; the operator never needs to know what HPOS is.
- **Woo side tables** that duplicate customer identity: customer lookup, sessions, payment tokens, download permissions, coupon usage.
- **Webhooks:** all active `wc_webhooks` are set to `disabled` as part of the run, so the anonymized copy stops delivering order data to production endpoints (ERP, Zapier, ...).

**Detection is per table, not per plugin.** Every shop stage asks the database whether its table exists and skips itself when it does not, so the same run does the right thing on a plain WordPress site, on a live store, and on a site where WooCommerce was deactivated but left its customer tables full of addresses behind. `wooDetected()` reports the verdict; `status` prints it as the run's scope line.

**Out of scope, deliberately:** see section 9. Owner-decided exceptions are called out in section 2.4.

### 2.1 The identity scheme: deterministic, traceable, one rule

Every fake value is a **pure function of the entity's primary key**. No randomness, no mapping table, no state. Re-running the module, or re-cloning production and running it again, produces byte-identical fakes.

The traceback requirement — "I must always be able to find the real customer on production" — is satisfied by the keys themselves: user IDs and order IDs survive a database clone unchanged. To make the key visible everywhere in the Woo admin, it is embedded in the email address:

> **`{mailbox local part}+{entity}.{id}@{mailbox domain}`**

with the target mailbox configurable (default: site `admin_email`). For a mailbox `admin@example.com`:

| Entity | Email | Traceback |
|---|---|---|
| User 42 | `admin+userid.42@example.com` | `wp_users.ID` 42 on production |
| Guest order 1234 (`customer_id = 0`) | `admin+order.1234@example.com` | order 1234 on production |
| Guest row in customer lookup | `admin+customer.{customer_id}@example.com` | `wc_customer_lookup` row |
| Guest product review | `admin+comment.{comment_ID}@example.com` | comment row |

Registered customers propagate their user-derived identity onto all their orders, so an order's billing email points straight at the owning user. Orders whose `customer_id` references a deleted user are treated as guest orders.

Plus-addressing means every one of these is deliverable **to the developer's own mailbox** — a leaked staging email lands on the operator's desk, not in a customer's inbox — and each address is unique, satisfying the `user_email` unique constraint. Known limit: a few plugins reject `+` in email validation; Woo core accepts it.

The remaining fields derive from the same key:

| Field | Derivation | Example (user 42) |
|---|---|---|
| First + last name | Seeded lookup tables (~100 first, ~100 last names shipped as a data file), indexed by `id % n` and `(id * 31) % n` | Jakub Dvořák |
| `user_login`, `user_nicename` | `user{id}` | `user42` |
| Display name | first + last | Jakub Dvořák |
| Phone | `700` + zero-padded `id % 1e6` | `700 000 042` |
| Street address | `Testovací {id}` | `Testovací 42` |
| City, postcode, country, state | **Kept.** Shipping zones, tax rates and analytics keep working; a city is not an identity. | — |
| Company, `user_url`, `description` | Emptied | — |
| Order IP address | `127.0.0.1` | — |
| Order user agent | Fixed generic string | — |
| Gateway references (`transaction_id`, customer/source/payer meta) | Emptied. They are foreign keys into a real Stripe/PayPal account. | — |

**Collision handling, as built.** The deterministic `user{id}` login cannot collide with itself, but it can collide with a not-yet-processed real login (someone whose actual login happens to be `user7`), and `user_login` is UNIQUE. Two mechanisms:

1. A stage prelude frees the namespace: every non-excluded login matching `^user[0-9]+$` whose number does not already equal its own id is renamed to `brace_pending_{id}` before the main pass.
2. The per-user write is **split into two statements** — identity fields (email, display name, password) first, login fields second. A collision the prelude could not clear (an *excluded* user holding that login) then fails only the cosmetic rename, never the identity removal. A kept login is cosmetic; a kept email is a leak. Failures are counted as `login_collisions` in the report.

**Passwords, as built.** Every non-excluded user gets the same random password, hashed **once per run**. `wp_hash_password` is deliberately slow, so hashing per user turns a 100k-user store into hours of CPU. The password is randomly generated and never printed, so nobody can log in with it; the shared-hash tradeoff is acceptable on a disposable copy and the speedup is the difference between usable and not.

### 2.2 Where order data physically lives

HPOS (High-Performance Order Storage) means orders may live in dedicated tables, in legacy `wp_posts`/`wp_postmeta`, or **in both at once** when compatibility sync is enabled. Anonymizing one copy and not the other is the classic hole in existing tools. The module asks Woo's `DataSynchronizer`/`CustomOrdersTableController` at runtime and rewrites every active location:

| Location | Fields |
|---|---|
| `wp_wc_orders` | `billing_email`, `ip_address`, `user_agent`, `transaction_id` |
| `wp_wc_order_addresses` | billing and shipping: names, company, address_1/2, phone, email (city/zip/country/state kept) |
| `wp_wc_orders_meta` | Known PII meta keys: gateway customer/source/payer references |
| Legacy `wp_postmeta` (when sync on or legacy storage) | `_billing_*`, `_shipping_*`, `_customer_ip_address`, `_customer_user_agent`, `_transaction_id`, gateway meta |
| Refunds | Rows of type `shop_order_refund` in the same storages |
| `wp_wc_customer_lookup` | `first_name`, `last_name`, `email`, `username` (country kept) |
| `wp_woocommerce_sessions` | **Truncated.** Serialized carts full of addresses; rewriting serialized blobs is risk with no payoff, and sessions regenerate. |
| `wp_woocommerce_payment_tokens` (+ meta) | **Deleted.** Stored cards reference real gateway customers. |
| `wp_wc_download_log`, `wc_downloadable_product_permissions` | `user_email` rewritten consistently, so download access still resolves |
| Coupon meta `_used_by` | Each real email replaced by the corresponding fake, so per-customer usage limits keep enforcing |
| `wp_comments` (product reviews only) | `comment_author`, `comment_author_email`, `comment_author_IP` |
| `wp_users` / `wp_usermeta` | Section 2.1 fields; `billing_*`, `shipping_*`, `first_name`, `last_name`, `nickname`, `description`, `session_tokens`, `user_pass`, `user_activation_key` |

### 2.3 The production tripwire

This module must be **impossible to run against production**. Three independent gates, all required:

1. **Environment verdict**, from the shared `Brace\Services\Environment` service — detection is exactly the "shared behavior gets promoted to a Service" case from ARCHITECTURE.md, so 001-staging-guard will consume the same service rather than this module depending on that one.

   **v0 is deliberately blunt, and this is the main deviation from the original draft.** The full detection (request host vs *hashed* production baseline, plus the public-suffix level rule) belongs to 001 and is not built yet. Until it is, the verdict reads **staging only on an explicit signal**:

   | Signal | Verdict |
   |---|---|
   | `BRACE_ENVIRONMENT` constant is `'staging'` | staging |
   | `WP_ENVIRONMENT_TYPE` is `staging`, `development`, or `local` | staging |
   | Host is `localhost`, an IP literal, single-label, or under `.test`/`.local`/`.invalid`/`.example`/`.internal` | staging |
   | Anything else, **including a bare `staging.yourdomain.com`** | **production** |

   That last row is the cost, and it is paid on purpose: a subdomain mismatch is only meaningful against a recorded baseline, and guessing without one risks the unforgivable direction (production read as staging, customer data destroyed). So a copy on a real subdomain needs one line in its `wp-config.php` before this module will touch it. Section 2.3's asymmetry from 001 applies unchanged: a refused run is an inconvenience, a wrongful run is unrecoverable.

   There is no override flag. On a production verdict the CLI errors and the settings screen says so in red.

2. **Typed confirmation.** `--confirm-host=<host>` must match the site's own host exactly (normalized: lowercased, `www.` stripped). GitHub-delete-repo style.
3. **Destructive-operation flow.** Dry run first, backup before execute, per the contract (section 3).

### 2.4 Owner-decided exceptions (known PII residue)

Two data classes are **deliberately kept**, by owner decision, and the post-run report says so in plain words:

- **Order notes** — all of them, including customer-provided notes, which are free text and can carry names, phones, delivery instructions. Kept because the notes are the order's operational history and the owner wants them readable.
- **`wc-logs` files** in uploads — gateway and shipping plugin logs cloned from production can contain full request payloads. Kept by owner decision; they regenerate on staging, so deleting them manually after cloning remains an option.

Anything reading this module's report must not claim "all PII removed." The honest claim is: *identity fields anonymized; free-text notes and log files kept by choice.*

## 3. Destructive?

**Yes**, maximally. Runs through `Brace\Services\DestructiveOperation`:

- **`estimate()`** — row counts per table, detected order storage(s), count of excluded users, webhook count.
- **`dryRun()`** — the default path. Per-table row counts, detected storages, a sample of the fakes this run would write, excluded-user count, pending Action Scheduler jobs (named but not touched, section 9), and the two §2.4 exceptions spelled out as a warning. It reads nothing back out of the database as "before" values: showing real PII to prove PII is about to be removed is the wrong trade.
- **`backup()`** — affected tables dumped to `uploads/brace/backups/{id}/` (protected by `.htaccess` + `index.php`) before execute. **And here the contract bites its own tail: the backup is a file full of the exact PII this module exists to remove.** Handled honestly rather than pretended away: the CLI prints a warning naming the backup as PII the moment it creates one, and `purge-backup` is a first-class command. Tables over 512 MB are refused with a sentence telling you to re-run with `--no-backup`.

  **Not built in v0:** the draft promised a 7-day auto-purge. There is no scheduled purge yet — purging is manual. Until it exists, `--no-backup` is the honest default for sync scripts, since the true undo path is re-cloning production anyway.
- **`execute( Batch $batch )`** — chunked by the existing `Batch`/`BatchRunner` services; a 100k-order store must not time out. Idempotent: re-running rewrites already-fake values to the same fake values.
- **`report()`** — rows changed per stage, storages touched, login collisions survived (section 2.1), exceptions kept, timestamp. Stored under the module's `last_run` setting and surfaced on its settings screen: *"Customer data on this copy was anonymized on {date}. Kept on purpose: order notes and wc-logs files."* **Not built in v0:** the notice is on this module's page only, not site-wide across Brace screens.

**Undo path:** restore from the backup, or re-clone production. Stated in the UI, not implied. Restore is manual in v0 — the dumps are plain SQL under `uploads/brace/backups/{id}/`, one file per table; there is no `restore` command.

## 4. Requirements

- Plugin baseline (PHP 8.1, WP 6.7). **Nothing else** — `Requirements::none()`.
- Staging verdict from `Brace\Services\Environment` (section 2.3). Not a soft requirement: `run` refuses on a production verdict, with no override flag.

**WooCommerce is not a requirement, and an earlier draft was wrong to make it one.** The reasoning then was "it anonymizes customers and orders, and there are none without WooCommerce" — which quietly assumes a WP user only matters as a shop customer. A membership site, a client portal, or a blog with open registration has real people in `wp_users` and no shop anywhere. Gating on WooCommerce meant those copies got no protection at all, which is the wrong failure direction for a module whose whole purpose is to stop PII leaving production. Shop coverage is now additive and table-detected (section 2), so the requirement bought nothing that detection does not already handle.

**Mail handling, as built — and why it moved here.** The draft made 001-staging-guard a hard requirement, on the reasoning that mail interception is its job and should not be duplicated. That requirement is gone, because 001 has no code and this module shipped first. The hazard it guarded against is real and immediate: the moment thousands of addresses point at one real mailbox, a single bulk action floods it. So v0 carries its own kill-switch — a `pre_wp_mail` filter returning `false`, registered in `boot()` when the module's `mail_blocked` setting is on, and switched on automatically at the end of every successful `run`. It is a blunt block, not a redirect or a log: nothing leaves the copy.

Two consequences worth knowing:

- The module declares contexts Admin, Frontend, CLI, **and Cron** — cron and frontend requests send mail too, and a block that only loads in wp-admin is not a block.
- **Disabling the module disables the block.** Settings survive a toggle, but a disabled module registers no hooks. The settings screen says so.

When 001-staging-guard exists it takes this over site-wide and this setting becomes a fallback.

## 5. Settings surface and defaults

Three settings. Everything else is a fixed, documented decision.

| Setting | Default | Notes |
|---|---|---|
| Target mailbox | site `admin_email` | Base address for the plus-addressing scheme. |
| Excluded roles | `administrator` | These accounts keep their identity and password, so the operator does not lock themselves out. |
| Block outgoing email | off, forced **on** after a run | The v0 stand-in for staging-guard's mail handling (section 4). Untick only while deliberately testing email. |

## 6. Surfaces

The same operation, the same guards, two ways in.

### 6.1 Admin screen

A run button on the module's settings page, behind a typed host confirmation and a browser confirm dialog. Optional pre-run backup as its own first request, because dumping tables can consume a whole request on its own and sharing one with the stage machine would starve both.

**Why this needed new machinery.** Under WP-CLI one process ticks the operation to completion and the stage machine's position lives happily in memory. A browser-driven run is the opposite: each tick is a separate admin-ajax request with a freshly constructed operation, so without carrying position across requests every tick would restart at stage zero and the run would never terminate — no error, just a progress display that counts forever. Hence `state()` / `restore()` on the operation, persisted between ticks in the module's own settings option:

| Carried | Why |
|---|---|
| `stage`, `cursor` | Where the machine was. Both clamped on restore — the state is an option, and an option can come back corrupted or hand-edited; an out-of-range stage index would fatal the `match()` in `execute()`. |
| `prepared` | Whether the users stage already ran its one-time prelude. Re-running the prelude mid-stage would re-free the login namespace under a run already assigning into it. |
| `changed` | Per-stage counters, so the final report covers the whole run and not just the last tick. |
| `hash` | The single password hash. A run resumed with a freshly generated hash would leave the user table split across two different passwords. |

**Guards are re-checked on every tick, not just at the start.** Capability, nonce, staging verdict, and host confirmation are all re-evaluated per request: a tick arriving after someone repointed this install at production must not be allowed to finish a run that was legitimate when it began. A production verdict mid-run also discards the stored state rather than leaving it to be resumed later.

**Concurrency:** the stored state records the user who owns the run. A second administrator ticking the same run is refused, unless the run has gone untouched for two minutes — a closed browser tab must not lock the module until someone digs the option out of the database.

### 6.2 WP-CLI

```
wp brace staging-anonymize status                      # verdict, storage detected, last run, backup state
wp brace staging-anonymize dry-run [--format=json]     # the report, scriptable
wp brace staging-anonymize run --confirm-host=<host> [--no-backup] [--batch-size=<rows>]
wp brace staging-anonymize purge-backup [--keep=<count>]
```

`run` without a matching `--confirm-host` refuses. `status` exits non-zero on a production verdict, so a deploy script can gate on it without parsing output. `purge-backup` deletes every stored set by default; `--keep=<count>` retains the most recent N.

The intended automation is: clone from production, then `wp brace staging-anonymize run --confirm-host=staging.example.com --no-backup` in the sync script — anonymization becomes part of the clone, not a step someone remembers.

## 7. Test coverage

**Built in v0 — unit only, no WordPress loaded** (Brain Monkey):

- `tests/Unit/Services/FakeIdentityTest.php` — traceback format, plus-addressing preserved when the mailbox itself already carries a tag, determinism across instances, 500 ids yielding 500 unique addresses, `userid` vs `order` never colliding at the same numeric id, name variance, phone/street/login/ip shape, invalid mailbox rejection.
- `tests/Unit/Services/EnvironmentTest.php` — a production domain reads production, a **bare `staging.` subdomain does not read as staging** (section 2.3's deliberate cost, pinned by a test so nobody "fixes" it accidentally), environment-type signals, non-public hosts, host normalization.
- `tests/Unit/Modules/StagingAnonymize/AnonymizeOperationStateTest.php` — the resumable state from section 6.1: round-trip through a second instance, the password hash surviving a resume, and clamping of corrupted stage/cursor values. This failure mode is silent rather than loud (a run that never terminates), so it is worth pinning even though the surrounding stage machine is untested.

**Manual harness, in `tests/fixtures/staging-anonymize/`** (excluded from the built plugin by `.distignore`):

- `seed-demo-site.php` — fills a throwaway site with invented Czech customers, orders, comments and order notes. It deliberately seeds a user whose login is `user7`, so the `user_login` UNIQUE collision from section 2.1 actually occurs instead of being assumed.
- `render-check.php` — renders the settings screen outside a browser, to catch a fatal before someone finds it by clicking.

**Not built — the part that would actually prove the spec.** The integration suite below needs a WordPress + WooCommerce harness (wp-env) that this plugin does not have yet. Until it exists, the stage machine is verified by reading, by the manual harness above, and by one full run against a seeded HPOS store on 2026-08-24 (30 users, 10 orders, guest orders keyed by order id, the `user7` collision resolved, comments scrubbed, order notes left readable as designed). That is a demonstration, not coverage: it is not repeatable in CI and it asserts nothing.

`tests/fixtures/staging-anonymize/store.php` would build a miniature store exercising every branch:

- Registered customer with orders; guest order; order whose `customer_id` points to a deleted user.
- A refund; a coupon with `_used_by` containing both customers' emails; a downloadable permission; a payment token; a product review by a guest; sessions rows.
- A user whose real login is `user7`, colliding with the deterministic scheme (section 2.1 collision note).
- An excluded `administrator` who must come through untouched.

Assertions that would decide whether the spec was implemented or merely described:

- **Determinism:** run twice, dump, byte-identical. Re-seed fixture, run again, same fakes for same IDs.
- **Storage matrix:** the suite runs three times — HPOS-only, legacy-only, HPOS with compatibility sync — and in the sync case asserts **both** storages are clean. This is the case existing tools fail.
- **Traceback:** every email in the dump matches `{entity}.{id}` and the id resolves to the fixture row it came from.
- **Residue scan:** grep the full dump for every real PII string seeded by the fixture; the only permitted hits are in order notes and the excluded admin (section 2.4 proven, not assumed).
- Webhooks all `disabled`; sessions empty; tokens gone; download permission still resolves for the fake email.

## 8. Inspiration and gaps

| Source | What we take | Gap we fill |
|---|---|---|
| WooCommerce core privacy erasers (`WC_Privacy_Erasers`) | The authoritative field list of what Woo itself considers personal data in an order | Built for one-at-a-time GDPR requests; anonymized orders lose their customer link entirely; no determinism, no bulk path, no HPOS-sync awareness |
| 10up Safety Net | The "make the copy safe" framing: scramble users, disable gateways/webhooks on non-production | Random scrambling — no determinism, no traceback to production, referential consistency not guaranteed across side tables |
| `wp user generate` / faker tooling | Plausible-looking fake data | Generates new users; does not anonymize existing ones or their orders |

## 9. Out of scope

- **Reversibility on the copy.** No mapping table, no re-identification. Production is the source of truth; the copy is disposable. Traceback is the ID in the address, nothing more.
- **Non-Woo plugin PII** (form entries, CRM sync tables, invoicing plugins, mail logs). Each is its own field map; candidates for a later spec.
- **Generated files on disk**: PDF invoices, exports, `wc-logs` (§2.4). A database module does not chase the filesystem.
- **Action Scheduler queue.** Args can carry PII and pending production jobs are a real hazard, but cancelling jobs changes runtime behaviour far beyond data anonymization. Revisit; for now the dry-run report *mentions* pending action counts so the operator sees them.
- **Payment gateway keys and modes.** Same position as 001 §5.1: switching to test mode is the site owner's job, and writing another plugin's settings is a different kind of act.
- **Locale-specific billing fields** (IČO/DIČ and other plugin-added checkout fields). Only Woo core fields are mapped. Plugin-added `billing_*` usermeta happens to be caught by the prefix rule; custom order meta is not.
- **Anonymizing analytics aggregates** (`wc_order_stats` has no identity columns; totals and dates stay — that is the point).
