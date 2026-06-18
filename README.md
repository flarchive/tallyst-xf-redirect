# XF → Flarum Redirect

**Permanent 301 redirects from old XenForo (and vBulletin-inherited) URLs to the
migrated Flarum content — so you keep your Google ranking and all existing
links after migration.**

When you migrate a forum, all the old URLs (indexed by Google, bookmarks, links from
other sites) would otherwise return 404 and you lose the organic traffic you built up
over years. This extension redirects them seamlessly and permanently to the equivalent Flarum content.

- ✅ Works on **Apache and Nginx** without touching the server config (the logic is in the Flarum application).
- ✅ Works through **subdomains and a full domain change**.
- ✅ Covers **XenForo** and **vBulletin** URL forms.
- ✅ **301** for existing content, **410 Gone** for deleted — SEO-clean.

---

## What gets redirected

| Old URL (XenForo) | → Flarum |
|---|---|
| `/threads/title.123/` · `/threads/123/` | `/d/123` |
| `/threads/title.123/page-4` | `/d/123/{post-number}` |
| `/threads/title.123/post-456` · `/posts/456/` · `/goto/post?id=456` | `/d/{disc}/{number}` |
| `/forums/name.12/` | `/t/{tag}` |
| `/members/name.34/` | `/u/{username}` |
| `/pages/name/` · `/pages/name.12/` | `/p/{slug}` (fof/pages) |
| `index.php?threads/title.123/…` | (same) |

**vBulletin legacy** (if the forum was previously vB): `showthread.php?t=` / `?p=`,
`forumdisplay.php?f=`, `member.php?u=`.

Nonexistent/deleted content → **410 Gone** (or a configurable fallback).
Flarum's own routes (`/d`, `/u`, `/t`) are left untouched.

---

## Requirements

- Flarum **2.x**
- Content migrated with this XF→Flarum migrator (ID preservation — see "How it works").
- The `redirects.jsonl` that the migrator emits (`php _importer/run.php redirect-map`).

---

## Installation

```bash
# 1) add the package (composer path-repo or from your own registry)
composer require tallyst/xf-redirect

# 2) enable the extension (also runs the migration for xf_migration_map)
php flarum extension:enable tallyst-xf-redirect
php flarum cache:clear

# 3) load the map the migrator emitted
php flarum redirect:import-map /path/to/flarum_import/redirects.jsonl
```

Done — the old URLs are now redirected.

---

## Quick start (full flow)

```bash
# in the migrator: emit the map (on a domain change, specify the OLD domain)
php _importer/run.php redirect-map old-domain.com

# in Flarum: load the map
php flarum redirect:import-map .../flarum_import/redirects.jsonl

# verify (dry-run, changes nothing)
php flarum redirect:test "/threads/some-thread.123/"
#  → 301 → https://new-domain.com/d/123
```

---

## Configuration

Everything is configured in the **Flarum admin** → the extension's "XF → Flarum Redirect"
tab (or via CLI/`redirects.jsonl` if you prefer).

### Old domains
The extension only acts on **your own** domains (so it doesn't touch other people's links). The base
list comes from `redirects.jsonl` (the import seeds the new domain + `www`). On a **domain
change** add the old domains — in the admin (the "Old domains" field, one per line) or:

```bash
php _importer/run.php redirect-map old-domain.com
```

### Fallback for deleted content
What to do with a URL that matches a pattern but whose content no longer exists (a deleted thread):
- **410 Gone** (default, recommended for SEO — a clear "this no longer exists"),
- **Redirect to the home page** (302),
- **Redirect to search** (302).

### Canonical-host
A "redirect EVERYTHING on the old domain to the new one" switch — consolidation on a domain
change (everything from the old domain 301s to the new one, not just XF paths).

---

## Commands

| Command | Description |
|---|---|
| `php flarum redirect:import-map <file>` | load `redirects.jsonl` into the database (truncate + insert) |
| `php flarum redirect:test <url>` | dry-run: prints what the URL would redirect to (301/410/pass-through) |

---

## Scenarios

### A) Same domain
XF and Flarum on the same domain. Nothing extra — the old URLs arrive at the same app and
are redirected. Works immediately after `import-map`.

### B) Subdomain or full domain change
The old URLs arrive at the **old** domain/subdomain. For the extension to catch them:

1. **The old domain must still resolve** and lead to the new Flarum server:
   - DNS of the old domain → new server
   - vhost for the old domain: Apache `ServerAlias old.com`, Nginx `server_name old.com;`
   - a TLS cert covering the old domain (Let's Encrypt SAN — free)
2. Add the old domain to the map: `redirect-map old.com` → `redirect:import-map`.
3. (SEO) Google Search Console → **Change of Address** (on a domain change).

**No XenForo needed on the old domain** — XF is shut down entirely; the old domain just
"receives and 301s" (a single vhost line). Cost: just the annual domain registration.

The redirect target is always **absolute** (the new domain from the `redirects.jsonl` meta), so
the redirect correctly "jumps" to the new domain even when the request arrives on the old one.

---

## Internal links in content (companion in the migrator)

Posts/PMs/signatures often contain links to the old forum. The extension catches them **on
click** (301). For an even cleaner result the migrator has `rewrite-links`, which **rewrites them
directly** into Flarum URLs in the content itself:

```bash
php _importer/run.php rewrite-links
```
This way internal links lead directly (without a 301 hop) and are **independent of the old domain**
(over time the old domain may even expire and the internal links still work — only external ones break).
Unresolvable links (deleted threads) are left to the extension to resolve on click.

---

## How it works (briefly)

A PSR-15 middleware runs **before** the Flarum router. For a request matching an XF/vB pattern
it resolves the target and returns 301/410; everything else is passed to Flarum. Because the migrator **preserves
IDs** (thread id = discussion id, post id = post id, tag = node, user ≈ same),
mapping is mostly identity and resolves from the live Flarum database — the persistent
table `xf_migration_map` carries only the non-derivable parts (domains, meta, reconciliation exceptions).
That is why the map is tiny regardless of forum size.

Details: `ARCHITECTURE.md`.

---

## Performance and safety

- **Fast**: the middleware reacts only to requests that don't hit a Flarum route; the lookup is
  a single indexed query. The map (domains/meta) is loaded once per request.
- **Safe**: read-only over content (it only reads for resolution); it does not modify data.
  `redirect:test` is a dry-run.
- **Doesn't shadow Flarum**: XF paths are disjoint from Flarum routes; native URLs pass through
  untouched.

---

## Troubleshooting

- **An old URL gives 404 instead of 301** → has `redirect:import-map` been run? Is the
  domain in `redirects.jsonl`? Check `redirect:test <url>`.
- **Domain change, old links don't work** → does the old domain resolve to the new server
  (DNS + vhost + TLS)? Is the old domain in the map?
- **301 leads to a 404 Flarum page** → the target discussion/post doesn't exist (deleted during
  migration) → 410 expected; check whether the content exists in Flarum.
- **You changed domains in `redirects.jsonl`** → re-run `redirect:import-map`.

---

## License
Commercial (premium). See `LICENSE`.
