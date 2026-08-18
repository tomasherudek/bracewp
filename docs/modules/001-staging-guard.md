# 001 - Staging Guard

Status: draft, awaiting approval
Slug: `staging-guard`
Target release: v0 (with 002-db-hygiene-report)

## 1. Problem and who it is for

A staging or local copy of a live site keeps doing live things. It emails real customers, pings real webhooks, and talks to real third-party APIs, because it is a byte-for-byte copy of production and nothing in it knows it moved. Every developer has a story about a test order that mailed 400 real people.

For **developers and agencies** primarily. Technical site owners benefit when their developer sets it up for them.

The existing answers are all partial. WordPress core ships `WP_ENVIRONMENT_TYPE` but nothing acts on it. It ships `WP_HTTP_BLOCK_EXTERNAL` but that is a constant you must edit in `wp-config.php`, with no log of what it blocked. Mail interception lives in single-purpose plugins that must be installed on the copy after the fact, which is exactly when everyone forgets.

Staging Guard is the module that notices it is a copy by itself and then acts.

## 2. Scope fence

**In scope:**

- **Detection.** Decide whether the current request is being served by a copy rather than by production. Details in section 2.1.
- **Outgoing email.** Redirect all `wp_mail()` traffic to one address, or block it entirely. Log every interception.
- **Outgoing HTTP.** Block requests to external hosts, with an allowlist. Log every block.
- **Visual flag.** Make it impossible to be on a guarded copy and not know it.

**Out of scope, deliberately:** see section 9.

### 2.1 Detection, and the trap in it

The rule is URL identity: **the host actually serving this request is compared against the host this site believes it is.** A mismatch means the files and database were moved, which means this is a copy.

Two checks run, and either one firing means staging:

| Check | Compares | Catches |
|---|---|---|
| A. Live mismatch | `$_SERVER['HTTP_HOST']` vs the host in `home_url()` | A clone where nobody ran search-replace. `home_url()` still says `yourdomain.com` while the request arrives at `staging.host`. |
| B. Baseline mismatch | `$_SERVER['HTTP_HOST']` vs the production host recorded when the module was enabled | A clone where search-replace **was** run, so check A sees agreement and reports nothing. |

Check B is the one that does the real work, and it contains the trap:

> **The recorded baseline must be stored hashed, never as a readable URL.**
>
> If Staging Guard stores `https://yourdomain.com` as a plain string in an option, then a search-replace across the database rewrites that option along with everything else. The baseline silently becomes the staging URL, check B agrees with check A, and the module reports "this is production" on a staging site. The guard fails in the exact scenario it exists for, and it fails silently.
>
> Storing `sha256( normalize( host ) )` closes it. A search-replace cannot match a hash, so the baseline survives the clone and the mismatch is detected. Brace ships a serialized-safe search-replace of its own, so this module has to survive that tool specifically.

Host normalization before comparing or hashing: lowercase, strip a leading `www.`, strip the port, convert IDN to punycode. Without this, `WWW.Yourdomain.com` and `yourdomain.com` read as two different sites.

### 2.2 Failing in the right direction

Detection can be wrong in two directions, and they are not symmetrical.

A **false negative** (staging not detected) sends real email from a test site. Loud, embarrassing, recoverable.

A **false positive** (production detected as staging) silently stops a live site from sending order confirmations and password resets. Nobody notices for days. This is worse, and it collides directly with design priority #1 in ARCHITECTURE.md: never break a site.

A legitimate domain migration produces exactly this false positive. So:

- Whenever guards are active, say so **loudly** (section 2.3). Silent guarding is forbidden.
- Provide a one-click **"This is production"** action in the admin notice that re-records the baseline to the current host.
- Provide the same as a WP-CLI command, for when the admin cannot send mail to let you log in.
- Support additional trusted production hosts, so a site legitimately served on several hostnames does not fight the module.
- Honour an explicit `BRACE_STAGING_GUARD_OFF` constant as a last-resort escape hatch in `wp-config.php`.

### 2.3 What "loud" means

- Admin bar badge, red, on every screen, front end and back end, for logged-in users.
- Admin notice on the plugins and Brace settings screens stating what is being guarded and offering "This is production".
- Optional front-end banner for anonymous visitors, off by default (it changes the site's appearance, and the trust contract says a module does nothing unasked).

## 3. Destructive?

**No.** Staging Guard writes no user data and deletes nothing. It does not touch the `DestructiveOperation` contract.

It does change runtime behaviour, which is why section 2.2 and 2.3 carry the weight that a dry-run would carry in a destructive module. The escape hatch is the undo path.

## 4. Requirements

None beyond the plugin baseline (PHP 8.1, WP 6.7). `hash()` and `idn_to_ascii()` are the only functions worth naming; `hash()` is always present, and if `intl` is missing the module falls back to comparing raw hosts.

Graceful degradation message when `intl` is unavailable: *"Internationalized domain names cannot be normalized on this server (the intl extension is missing). Staging detection still works for standard domains."*

## 5. Settings surface and defaults

Five settings. Anything more belongs in section 9.

| Setting | Default | Notes |
|---|---|---|
| Email handling | `redirect` | `redirect` or `block`. Redirect is the default because it is the one that lets you see what would have been sent. |
| Redirect address | site `admin_email` | Only shown when handling is `redirect`. |
| Block external HTTP | on | |
| Additional allowed hosts | empty | One host per line, added to the built-in allowlist. |
| Front-end banner | off | Admin bar badge is always on and is not a setting. |

Built-in HTTP allowlist, always active when blocking is on:

- `*.wordpress.org` for core, theme and plugin updates.
- **The site's own host**, because WordPress makes loopback requests to itself for cron and Site Health. Forgetting this breaks cron on every guarded site.

Redirected mail keeps the original recipient visible: subject is prefixed `[STAGING]` and an `X-Brace-Original-To` header carries the address it would have gone to.

## 6. WP-CLI surface

```
wp brace staging-guard status              # detected state, which check fired, what is active
wp brace staging-guard trust-current-host  # re-record the baseline: "this is production"
wp brace staging-guard log [--type=<mail|http>] [--limit=<n>]
```

`status` exits non-zero when guards are active, so it can gate a deploy script.

## 7. Test dataset

`tests/fixtures/staging-guard/hosts.php` drives a table test of the normalizer and both checks:

- `www.` prefix, uppercase host, explicit `:8080` port, IDN host and its punycode form, IP-literal host, `localhost`, sslip.io style host.
- Subdomain-of-production (`staging.yourdomain.com`) must read as staging, not as production.

`tests/fixtures/staging-guard/cloned-site.php` proves the crux of section 2.1: an options set where the production domain has been search-replaced to the staging domain everywhere, including any readable copy of it. The hashed baseline must survive and check B must still fire.

## 8. Inspiration and gaps

| Source | What we take | Gap we fill |
|---|---|---|
| WP core `WP_ENVIRONMENT_TYPE` | The vocabulary | Core defines the environment but acts on it nowhere |
| WP core `WP_HTTP_BLOCK_EXTERNAL` / `WP_ACCESSIBLE_HOSTS` | The blocking mechanism, which we reuse rather than reinvent | Requires editing `wp-config.php` by hand, has no UI, and logs nothing |
| Mail-logging and mail-disabling plugins | Redirect-with-header pattern | They must be installed on the copy after cloning, which is the step everyone forgets. Staging Guard travels with the site and notices by itself. |

## 9. Out of scope

- **Auto-enabling itself.** Detection is automatic; activation is not. A module that switches itself on breaks the trust contract.
- **Payment gateway sandboxing.** Most gateways are HTTP APIs and are already stopped by the external HTTP block. Explicit per-gateway handling (forcing WooCommerce test mode) is a candidate for a later version once the block is proven in the field.
- **Disabling cron.** Too blunt. Cron is how you find out staging behaves like production.
- **Search engine visibility.** WordPress already has a setting for it.
- **Anonymizing or scrubbing customer data on the copy.** A real need and a much larger module. Separate spec.
- **Blocking outbound SMTP configured outside `wp_mail()`.** Out of reach from PHP; documented as a known limit instead of pretended away.
