Log 404s to DB
==============

*Maintained by [Restruct](https://github.com/restruct). If this module saves you time, you can
[support ongoing maintenance](https://github.com/sponsors/restruct).*

This module provides logging of incoming requests that result in a 404 not found. They are logged & counted along with the referrer.

It also logs the search queries visitors enter on the front end, with a hit count, so you can see
what people look for on the site.

Requirements
------------

* Silverstripe 5 or 6 (`silverstripe/framework`)
* PHP 8.1 or newer on Silverstripe 5; Silverstripe 6 itself requires PHP 8.3
* Optional: `silverstripe/reports` for the two CMS reports, and `silverstripe/cms` for search
  query logging. Both come with `silverstripe/recipe-cms`. Without them the 404 log is still
  written, but there is no report to view it in.
* Optional: `silverstripe/siteconfig` (also in recipe-cms) for the CMS-editable ignore list, and
  `silverstripe/redirectedurls` for the report's "redirect" action.

Installation
------------

```
composer require restruct/silverstripe-404logger
```

Then run a database build (`dev/build?flush=1` on Silverstripe 5, `vendor/bin/sake db:build --flush`
on Silverstripe 6).

Version compatibility
---------------------

| Branch | Module version | Silverstripe | PHP |
|--------|----------------|--------------|-----|
| `main` | `3.x` | `^5 \|\| ^6` | `^8.1` |
| (tags only) | `2.0.2` | `^4 \|\| ^5 \|\| ^6` (declared ^6; known broken on 6 - use 3.x) | not declared |
| (tags only) | `2.0.1` | `^4 \|\| ^5` | not declared |
| (tags only) | `2.0.0` | `^4` | not declared |

Silverstripe 4 reached end of life in April 2025 and is no longer supported or tested here. Projects
still on it should stay on the `2.x` tags, which remain available.

`main` is the only maintained line: it supports every Silverstripe version this module still
targets, so there is no separate maintenance branch.

**`composer.json` is the source of truth** for exact constraints; this table is a quick reference.

Usage
-----

Logged 404s are visible under the 'Reports' section in the CMS as '(External) broken links report'. You can either contact the referrer to get the link updated, or redirect the link at your end using the [redirectedurls module](https://github.com/silverstripe/silverstripe-redirectedurls)

The report shows long URLs shortened to 120 characters (the full URL appears on hover); set
`FourOhFourReport.link_display_length` in YAML config to change the limit.

Logged search queries are in the same section as 'Search words report'.

### The broken links report

* **One row per link** (since 3.2): the hits of all its referrers summed, the number of referrers,
  the referrer of the most recent hit, the category, the first and the most recent hit, and
  **Hits in period** (hits in the window of the "Last hit" filter, or the last 365 days, from the
  monthly counts below). Choose "One row per link and referrer" under *Show* for the list as it was
  before 3.2.
* **Filters:** *Last hit* (in the last 30, 90 or 365 days; `FourOhFourReport.recency_days`),
  *Category* (`page`, `asset`, `scanner`, `probe` or a category of your own) and *Status* (not
  handled, handled, all). The recency filter applies to the link's most recent hit; the totals stay
  lifetime totals.
* **Bulk actions:** tick rows, then
  * **Ignore selected links** adds the links to the ignore list under *Settings > 404 log* (see
    below), so further hits are not logged. Needs `silverstripe/siteconfig`, and permission to
    edit the site settings (`SiteConfig::canEdit()`, by default "Manage site configuration"), since
    the list is saved there; without it the button is not shown.
  * **Redirect selected links** creates a redirect from each link to the URL typed next to the
    button (a path on the site starting with a single `/`, such as `/new-page`, or a full
    `http://` or `https://` URL; anything else, including `//host/...`, is refused), in
    [silverstripe/redirectedurls](https://github.com/silverstripe/silverstripe-redirectedurls). Only
    shown when that module is installed; it needs that module's "Create a redirect" permission. A
    link that already has a redirect, or whose path is longer than 255 characters, is skipped.

  Both mark the link's rows **handled**, which hides them from the default view (*Status*: not
  handled). A handled link that is hit again is shown again: the redirect did not work, or the
  link was taken off the ignore list.
* **Export to CSV** exports the list as filtered, with the full URLs and referrers.

### The search words report

Since 3.2 a search terms report. Per term:

| Column | Meaning |
|--------|---------|
| Amount of hits | Lifetime hits |
| Hits per active year | Hits divided by the years between the first and the most recent search, at least one year, so old and new terms compare fairly |
| Hits this year | From the monthly counts, so only hits since the upgrade to 3.2 |
| Last searched | This year, last year, 2-3 years ago, or older (calendar years) |
| Trend | `new`: first searched last year or this year, and searched this year. `faded`: searched before, but not this year |
| Noise | `yes` when the term looks like noise by the rules of the noise filter below, also for rows logged before that filter existed or with it switched off |

Filters: *Last searched*, *Trend* (new, faded) and *Noise* (hidden by default, shown too, or only
noise). Every column sorts; *Export to CSV* exports the filtered list. The years are calendar years,
so early in January most terms are "faded" until they are searched again.

### Monthly counts

From 3.2 every logged hit is also counted per calendar month (tables `FourOhFourLogMonth` and
`SearchLogMonth`: log row, `YearMonth` as `YYYYMM`, count). The reports use them for "hits in
period" and "hits this year". **Monthly data starts at the upgrade to 3.2**: older hits exist only
in the lifetime `Count`, with no record of when they happened, so there is nothing to backfill.

The cost is one indexed `UPDATE` per logged hit, plus one `INSERT` for a row's first hit in a
month (measured on MySQL: a repeat 404 hit goes from 3 to 4 SQL statements, a first hit from 5 to
7; a repeat search from 3 to 4). Ignored hits
and noise are never counted. Switch it off with `count_per_month: false` on `FourOhFourLog` and/or
`SearchLog`.

### Ignore list (Settings > 404 log)

A list editors can change, on `SiteConfig` (only when `silverstripe/siteconfig` is installed):
one link per line, without the domain, compared case-insensitively, query string included. A line
ending in `*` ignores every link that starts with it (`downloads/old/*`); lines starting with `#`
are comments. The report's *Ignore selected links* action adds lines here; it skips a logged link
that itself ends in `*`, which would otherwise become such a prefix line. Developers' regular
expressions stay in `FourOhFourLog.ignore_patterns` (YAML).

### Purging old rows

`LogPurgeTask` deletes rows; each criterion is opt-in, and without one the task deletes nothing.

```
# Silverstripe 6
vendor/bin/sake tasks:LogPurgeTask --older-than=24 --ignored --noise --dry-run
# Silverstripe 5
vendor/bin/sake dev/tasks/LogPurgeTask older-than=24 ignored=1 noise=1 dry-run=1
```

* `older-than=N`: 404 and search rows whose most recent hit is more than N months ago, and monthly
  counts of months before that (also of the rows that are kept).
* `ignored`: 404 rows that the current rules would no longer log (ignored categories,
  `ignore_patterns`, the ignore list), eg after ignoring links from the report, or rows from before
  3.1 that are scanner noise.
* `noise`: search rows that look like noise.
* `dry-run`: only report the counts. A deleted row takes its monthly counts with it.

It works in chunks (`--chunk-size=N` / `chunk-size=N`, default 1000) and can be stopped and run
again. Suitable for a monthly cron job.

### Common device probes

Even with no scanner traffic, most 404s on a typical site are requests that browsers, phones and
crawlers make **on their own**, not broken links:

* `/.well-known/passkey-endpoints` (password managers and browsers looking for passkey support),
  `/.well-known/change-password`, `/.well-known/traffic-advice` (Chrome's prefetch proxy),
  `/.well-known/assetlinks.json`, `/.well-known/apple-app-site-association`;
* `apple-touch-icon.png`, `apple-touch-icon-precomposed.png` and sized variants
  (`apple-touch-icon-180x180.png`), requested by iOS and many other clients whether or not the page
  links one, and `favicon.ico`.

They are the `probe` category and not logged by default. To stop them 404ing at all, serve the
files (an `apple-touch-icon.png` in the web root, a `/.well-known/` route), or answer them in the
web server; if your site does provide one of them, take its pattern out of `probe` so a real
failure shows.

Viewing and managing the logged records requires the `CMS_ACCESS_ReportAdmin` permission (access to
the Reports section). Logging does not depend on it: hits are logged for every visitor.

### What gets logged

**404s** (`FourOhFourLogger`, applied to every `RequestHandler`):

* Only responses with status 404. Other error codes are ignored.
* The logged link is the request URL relative to the site root, including the query string.
* Only **external** referrers are logged. A 404 whose referrer is on the host the request was
  made on (its `Host` header, also when `Director.alternate_base_url` is set) is skipped (internal
  broken links belong in a site-internal link checker). The host is compared without its port.
* A request without a referrer is logged with referrer `unknown`.
* The link is stored without a leading slash (`about-us`, not `/about-us`).
* Each link + referrer combination is one row; repeat hits increase its count. Rows are matched
  case-insensitively, through an indexed hash column (`LinkHash`), so logging a hit costs one
  indexed lookup however large the table grows.
* Each row gets a **category** (see below). Hits in an ignored category (by default: scanners and
  device probes) are not logged at all; that check runs before any database query.
* Links and referrers longer than 2048 characters are truncated to fit the column.
* Also logs when the site has a published 404 `ErrorPage` (silverstripe/errorpage).

**Search queries** (`SearchQueryLogger`, applied to `SiteTree` when silverstripe/cms is installed):

* Logged from the request parameter(s) configured below, on any front-end page.
* Queries are trimmed, lower-cased and truncated to 255 characters; each distinct query is one row
  with a hit count. Empty values and array values (`?Search[]=...`) are ignored.
* Noise is not logged (see below): single characters (except in Chinese, Japanese and Korean),
  bare numbers and dates (`2026`, `12-34-56`), strings that are almost all symbols (`%%%`),
  injection probes (`65'123`, `1 and 1=1`, `<script>`) and file names (`logo.png`). Queries such as
  `c++`, `1234 ab`, `100%`, `node.js` and `kids' books` are kept.

### Configuration

| Option | Default | Effect |
|--------|---------|--------|
| `FourOhFourLog.category_patterns` | see below | Regex patterns per category, checked in order |
| `FourOhFourLog.ignore_categories` | `scanner: true`, `probe: true` | Categories whose hits are not logged |
| `FourOhFourLog.ignore_patterns` | none | Extra regexes whose hits are not logged, whatever their category or referrer |
| `FourOhFourLog.ignore_only_without_referrer` | `true` | An ignored `scanner` hit is only dropped when it has no referrer |
| `FourOhFourLog.count_per_month` | `true` | Count each logged hit per calendar month too (see "Monthly counts") |
| `FourOhFourReport.link_display_length` | `120` | Characters of a link or referrer shown in the report grid |
| `FourOhFourReport.recency_days` | `30`, `90`, `365` | Choices of the report's "Last hit" filter, in days |
| `FourOhFourReport.default_period_days` | `365` | Window of "Hits in period" when no "Last hit" filter is set |
| `SearchLog.count_per_month` | `true` | Count each logged search per calendar month too |
| `SearchLog.search_query_param` | `'Search'` | GET parameter whose value is logged, or a list of them |
| `SearchLog.ignore_noise` | `true` | Drop noise queries before any database query |
| `SearchLog.min_query_length` | `2` | Shorter queries are noise |
| `SearchLog.min_alnum_ratio` | `0.3` | Queries with a smaller share of letters, digits and spaces are noise; `0` switches this off |
| `SearchLog.noise_patterns` | `sql`, `quote`, `markup`, `filename`, `numbers_only` | Regexes that mark a query as noise |

#### 404 categories

Every 404 link is put in the first category with a matching pattern; anything else is a `page`.
The patterns are matched against the link **without its leading slash, URL-decoded, query string
included** (eg `wp-login.php?action=register`), and are full PCRE patterns with delimiters.

| Category | What it catches (defaults) | Logged by default |
|----------|----------------------------|-------------------|
| `probe` | Browsers and devices asking on their own initiative: `.well-known/*` (passkey-endpoints, traffic-advice, change-password, ...), `apple-touch-icon*`, `favicon*`, `robots.txt`, `manifest.json`, `sitemap*.xml`, `autodiscover/` | no |
| `scanner` | Vulnerability scanners: `*.php` and other script extensions, dotfiles (`.env`, `.git/`), WordPress's own paths (`wp-admin/`, `wp-login.php`, `xmlrpc.php`) and Joomla paths, secret and dump files anywhere (`*.sql`, `*.bak`, `*.pem`), archives, logs and `*.json` at the site root only (`backup.zip`, `error.log`, `config.json`), credential files, admin tools (`phpmyadmin` anywhere; `actuator/`, `jenkins/`, `administrator/` as the first segment only), `vendor/composer/`, `node_modules/`, path traversal, `@fs/` and injection strings | no, unless the hit has a referrer (see below) |
| `asset` | A missing file: anything under `assets/` or `_resources/`, or a file extension (images, documents, media, css/js, fonts) | yes |
| `page` | Everything else | yes |

Probes are checked first (so `manifest.json` is a probe, not a credential guess), then scanners (so
`assets/shell.php` is a scanner, not a missing asset), then assets. The category is stored on the
row (`FourOhFourLog.Category`) and refreshed on every hit.

The scanner patterns match file names and path segments, never a bare word, so pages such as
`team/jenkins-smith`, `news/wordpress-vs-silverstripe` or `downloads/brochure.zip` stay ordinary
pages and assets.

**Scanner hits with a referrer are logged.** Scanners rarely send a `Referer` header, while a real
broken inbound link to an old `.php` or WordPress URL usually comes with the page that links to it.
So with `ignore_only_without_referrer: true` (the default) a scanner hit is only dropped when it has
no referrer. Set it to `false` to drop every scanner hit. Probes are dropped either way, and
`ignore_patterns` apply whatever the referrer.

Patterns are keyed by name, so a project can switch one off, add its own, or add a category:

```yaml
FourOhFourLog:
  ignore_categories:
    # Log device probes after all
    probe: false
    # Ignore the project's own category (below) as well
    legacy: true
  category_patterns:
    scanner:
      # This site serves JSON at the root: do not treat /*.json as a credential guess
      root_json: null
    # A category of its own
    legacy:
      old_site: '~^old-site/~'
  # Or drop hits without giving them a category
  ignore_patterns:
    old_forum: '~^forum/~'
```

YAML merges these maps with the defaults, so setting a key to `null` (or `''`) is the way to
remove a default; leaving it out of your YAML changes nothing. On a site behind web-server rules
that already block the common scanner paths, what still reaches PHP is mostly `*.php` probes,
`*.json`/`*.zip` credential and backup guesses, `@fs/...` and `?path=../` traversal attempts, and
the device probes above; all are covered by the defaults.

#### Search parameter and noise

```yaml
SearchLog:
  # One parameter, or a list: the first one that holds a value is logged (one query per request)
  search_query_param:
    - 'Search'
    - 's'
  min_query_length: 3
  noise_patterns:
    # Allow searches for file names
    filename: null
```

A single string (`search_query_param: 'q'`) works as before. A list replaces the default
`'Search'`, so include it in the list if that parameter should still be logged.

### Upgrading from 3.1

Run a database build. It adds the tables `FourOhFourLogMonth` and `SearchLogMonth`, the columns
`FourOhFourLog.HandledAs` and `HandledAt`, and (with silverstripe/siteconfig)
`SiteConfig.FourOhFourIgnoreList`. No task to run, and no data to migrate: the monthly counts start
empty (see "Monthly counts").

What looks different: the broken links report shows one row per link (choose "One row per link and
referrer" for the old list), and hides rows marked handled. Code that subclasses `FourOhFourReport`
or `SearchQueryReport` should note that `sourceRecords()` now returns an `ArrayList` of
aggregated rows (the per-referrer view still returns the `FourOhFourLog` list).

### Upgrading from 3.0

Run a database build. It adds `FourOhFourLog.LinkHash` (with a unique index) and
`FourOhFourLog.Category`, and an index on `SearchLog.Query`. It is safe on a table that already
holds duplicate rows: existing rows get `NULL` in `LinkHash`, and a unique index allows any number
of `NULL`s.

**What is no longer stored.** From 3.1, scanner 404s without a referrer, device probes and noise
search queries are dropped by default, where 3.0 stored everything. That is the point of the
release, but check it fits your site, because a dropped hit is gone: there is nothing to report on
later. Typical cases that want a different setting:

* A site migrated from WordPress, plain PHP or ASP, whose old `*.php`/`*.asp` URLs and `wp-content/`
  links still get traffic from bookmarks and search engines (which send no referrer): set
  `scanner: false` under `ignore_categories`, or switch off the specific patterns (`script_ext`,
  `wordpress`) under `category_patterns.scanner`.
* Downloads served from outside `assets/` at the site root (`/price-list.zip`, `/data.json`):
  switch off `root_backups` or `root_json`.
* A search where short codes, numbers or file names are real queries: adjust
  `min_query_length`, `min_alnum_ratio` or `noise_patterns`.

To restore 3.0's behaviour completely:

```yaml
FourOhFourLog:
  ignore_categories:
    scanner: false
    probe: false
SearchLog:
  ignore_noise: false
```

Then run the merge task once:

```
# Silverstripe 6
vendor/bin/sake tasks:FourOhFourLogMergeTask --dry-run
vendor/bin/sake tasks:FourOhFourLogMergeTask
# Silverstripe 5
vendor/bin/sake dev/tasks/FourOhFourLogMergeTask dry-run=1
vendor/bin/sake dev/tasks/FourOhFourLogMergeTask
```

It fills in `LinkHash` and `Category` on the existing rows and merges rows that are one link +
referrer, chiefly the `/path` and `path` pairs left by older versions storing the link with a
leading slash: the merged row keeps the summed count, the earliest `Created` (first seen) and the
latest `LastEdited` (most recent hit). It works in chunks (`--chunk-size=N` / `chunk-size=N`,
default 1000), only touches rows without a hash, and can be stopped and run again; a second run
finds nothing to do. Rows in a category that is now ignored are categorised, not deleted. On a
55,000-row table it took about 12 seconds.

Until the task has run, logging keeps working: a hit that misses the index falls back to the old
lookup for unhashed rows and gives the row it finds its hash. That fallback (an unindexed query)
only runs while unhashed rows exist, so run the task to get the full speed-up.

### PHP API

Both loggers call a static method you can also call yourself, eg from a custom controller:

* `FourOhFourLog::logHit(string $link, string $referrer)` - create the row for this link and
  referrer, or increase its count. Returns the row, or `null` when the hit was ignored.
* `SearchLog::logHit(string $query)` - create the row for this query, or increase its count.
  Returns the row, or `null` when the query was noise.

And the checks they use:

* `FourOhFourLog::categorise(string $link): string` - the category of a link.
* `FourOhFourLog::isIgnored(string $link): bool` - whether a hit on it would be dropped.
* `SearchLog::isNoise(string $query): bool` - whether a query would be dropped.
* `SearchLog::looksLikeNoise(string $query): bool` - the same rules, whatever `ignore_noise` says.
* `SearchLog::termStats(...)` - the derived figures of the search terms report for one term.
* `FourOhFourLog::matchesIgnoreList(string $link): bool` - whether a link is on the CMS ignore list.
* `FourOhFourLog::markHandled(array $links, string $as): int` - mark the rows of these links handled.
* `HitMonthCounter::totalsSince(string $monthClass, int $yearMonth): array` - hits per log row from
  a month on.

The classes are in the global namespace.

Running the tests
-----------------

The test suite needs a host project that installs this module through a path repository with
`"symlink": true` (the `tests/` directory is excluded from dist installs), plus
`silverstripe/recipe-cms` and `silverstripe/recipe-testing`. From that project's root:

```
# Silverstripe 5
vendor/bin/phpunit vendor/restruct/silverstripe-404logger/tests flush=1
# Silverstripe 6
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/restruct/silverstripe-404logger/tests
```

`.github/workflows/ci.yml` builds exactly such a host project for each supported Silverstripe major.
