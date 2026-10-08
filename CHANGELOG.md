# Changelog

## 3.2.0 (unreleased)

Reports you can act on: the broken links report grouped by link with filters, bulk actions and a
full CSV export, a search terms report, monthly hit counts and a purge task. Backward compatible:
run a database build (see README, "Upgrading from 3.1"); no task to run.

### Added

- **Monthly counts.** Every logged hit is also counted per calendar month (new tables
  `FourOhFourLogMonth` and `SearchLogMonth`: log row, `YearMonth`, count), so recent demand can be
  told apart from the lifetime count. One indexed `UPDATE` per hit (plus an `INSERT` for a row's
  first hit in a month), race-safe through a unique index, also on 3.1's lost-insert path. Monthly
  data starts at the upgrade: there is no history to backfill. `count_per_month: false` on
  `FourOhFourLog` / `SearchLog` switches it off.
- **Broken links report, one row per link:** hits summed over all referrers, number of referrers,
  latest referrer, category, first and most recent hit, hits in period (from the monthly counts).
  Filters: last hit in the last 30/90/365 days (`FourOhFourReport.recency_days`), category, status.
  The per-referrer list is still there ("One row per link and referrer"). The reports overview
  counts the links in chunks without building the list, up to core's `limit_count_in_overview`
  ("10000+"), so a large log table does not exhaust memory on the Reports listing
  (`FourOhFourReport.count_chunk_size`, default 2000 rows per query).
- **Bulk actions** on the broken links report: "Ignore selected links" (adds them to a new
  CMS-editable ignore list under Settings > 404 log, on `SiteConfig`, when silverstripe/siteconfig
  is installed) and "Redirect selected links" (creates redirects in silverstripe/redirectedurls,
  when installed). Both mark the rows handled (new columns `HandledAs`, `HandledAt`); handled rows
  are hidden by default and come back on their next hit.
- **Search terms report:** per term hits per active year, hits this year, recency band (this year,
  last year, 2-3 years ago, older), "new" and "faded" flags and a noise flag; filters on band,
  trend and noise (hidden by default); every column sorts.
- **`LogPurgeTask`:** deletes rows older than N months (`older-than`), 404 rows the current ignore
  rules would no longer log (`ignored`) and noise search rows (`noise`), with their monthly counts;
  dry run, chunked, nothing deleted without a criterion. Silverstripe 5 and 6 entry points.
- `FourOhFourLog::matchesIgnoreList()`, `FourOhFourLog::markHandled()`,
  `SearchLog::looksLikeNoise()` (the noise rules regardless of `ignore_noise`),
  `SearchLog::termStats()`, `HitMonthCounter`.
- README: the reports, monthly counts, the ignore list, the purge task, and a note on the device
  probes (`/.well-known/passkey-endpoints`, `apple-touch-icon*`, `/.well-known/traffic-advice`)
  that make up most non-scanner 404s.
- `silverstripe/siteconfig` and `silverstripe/redirectedurls` listed under `suggest`.

### Fixed

- **The CSV export of the broken links report contained the shortened URL and referrer** (the
  grid's 120-character display text, since 3.0.1). It exports the full values now.
- The search report's overview count is one `COUNT` query instead of building the report.

### Changed

- The broken links report's default view is one row per link instead of one row per link and
  referrer, and hides rows marked handled. `FourOhFourReport::sourceRecords()` and
  `SearchQueryReport::sourceRecords()` return an `ArrayList` of aggregated rows (the per-referrer
  view still returns the `FourOhFourLog` list); code that subclasses the reports may need a look.
- `FourOhFourLog::isIgnored()` also checks the CMS ignore list, as its last check.

## 3.1.0 (2026-10-07)

Faster logging on large tables, and much less noise. Backward compatible: run a database build,
then `FourOhFourLogMergeTask` once (see README, "Upgrading from 3.0").

### Added

- **Indexed lookups.** `FourOhFourLog.LinkHash` (sha1 of the lower-cased link and referrer, with a
  unique index) replaces the lookup on the two unindexed `Varchar(2048)` columns, and
  `SearchLog.Query` gets an index. Before this, every logged 404 and every logged search scanned
  the whole table. The unique index also stops two concurrent first hits from creating two rows.
- **404 categories:** `probe` (browser and device requests such as `.well-known/passkey-endpoints`,
  `apple-touch-icon*`, `favicon.ico`), `scanner` (`*.php`, dotfiles, WordPress paths, backup and
  credential guesses, path traversal, `@fs/`), `asset` (missing files) and `page`. Rule-based
  (regular expressions in config, `FourOhFourLog.category_patterns`), stored on the row in the new
  `Category` column.
- **Ignored categories and patterns.** Hits in `FourOhFourLog.ignore_categories` (default: scanners
  and probes) or matching `FourOhFourLog.ignore_patterns` are dropped before any database query.
  A scanner hit WITH a referrer is still logged (`ignore_only_without_referrer`, default `true`):
  scanners rarely send one, real broken inbound links to old `.php` or WordPress URLs usually do.
- **Search noise filter.** `SearchLog` drops queries that are too short (`min_query_length`, default
  2, not applied to Chinese, Japanese or Korean), almost all symbols (`min_alnum_ratio`, default
  0.3) or match `noise_patterns` (SQL injection shapes, a quote followed by a digit or symbol,
  markup, file names, bare numbers). Switch it off with `SearchLog.ignore_noise: false`.
- **`SearchLog.search_query_param` accepts a list** of parameter names; the first one with a value
  is logged. A single string still works.
- **`FourOhFourLogMergeTask`**, a one-off upgrade task: fills in `LinkHash` and `Category` on existing
  rows and merges duplicates (summed count, earliest `Created`, latest `LastEdited`). Chunked,
  idempotent, with a dry-run option.
- `FourOhFourLog::categorise()`, `FourOhFourLog::isIgnored()`, `FourOhFourLog::normaliseLink()`,
  `FourOhFourLog::linkHash()` and `SearchLog::isNoise()`. `logHit()` on both classes now returns the
  row written, or `null` when the hit was dropped.
- Tests for all of the above (176 new, 217 in total), and a browser spec for the ignored hits.

### Fixed

- **The same URL was logged twice across an upgrade.** Older versions stored the link with a
  leading slash (`/path`), later ones without (`path`), so every URL seen before the upgrade got a
  second row and the first one stopped counting. Links are stored without leading slash now, and
  the merge task joins existing pairs.

### Changed

- Scanner 404s without a referrer and device-probe 404s are **no longer logged by default**. To
  keep logging them, set `FourOhFourLog.ignore_categories` `scanner: false` / `probe: false`
  (README, "Upgrading from 3.0", lists the cases that want this).
- Search queries that look like noise are no longer logged by default (see above).
- Until the merge task has run, a 404 that misses the index falls back to the old lookup on rows
  without a hash, and gives the row it finds its hash. The fallback costs one indexed query once no
  such rows are left.

## 3.0.1 (2026-09-25)

### Fixed

- **A long URL in the (External) broken links report pushed the other columns off-screen.** The
  `Link` column (a `Varchar(2048)`) was shown in full and unwrapped. The grid now shows at most 120
  characters, with an ellipsis when shortened; the full URL is in the cell's `title` attribute (shown
  on hover) and on the record itself. The limit is configurable as
  `FourOhFourReport.link_display_length`.
- **A long Referrer did the same.** It is shortened the same way, with the same limit.
- `phpunit.xml.dist` (the host-project template) now fails on an empty test suite, and its note on
  flushing under PHPUnit 11 is corrected.

## 3.0.0

Silverstripe 5 and 6 from one line, with a test suite and CI. Silverstripe 4 is no longer
supported; projects on it keep resolving the `2.x` tags (a `^2.0` constraint never installs 3.x).

### Breaking

- **Requires `silverstripe/framework: ^5 || ^6` and PHP `^8.1`** (the 8.1 floor is what Silverstripe 5
  allows; Silverstripe 6 itself requires PHP 8.3). Silverstripe 4 was dropped because
  it reached end of life in April 2025. Class names, table names, config and the report titles are
  unchanged, so upgrading from 2.x on Silverstripe 5 needs no data changes, just a database build.
- **Viewing, editing, deleting and creating log records now requires the `CMS_ACCESS_ReportAdmin`
  permission** (`canView()`, `canEdit()`, `canDelete()` and `canCreate()` on `FourOhFourLog` and
  `SearchLog`). In 2.x all four returned `true` for everyone, including anonymous visitors, so any
  code that exposed the records (a GridField, a front-end list, an API) showed visited URLs,
  referrers and search terms to anyone. Users with access to the Reports section keep full access.
  Logging itself is unaffected: `logHit()` writes without a member and never checks these
  permissions. If your own code shows the records to users without report access, grant them
  `CMS_ACCESS_ReportAdmin`.

### Fixed

- **Front-end pages failed with `Class "Config" not found`** when search logging was active (any
  project with silverstripe/cms). Introduced after 2.0.2 on `master` and never tagged; only
  `dev-master` installs were affected.
- **A search parameter sent as an array** (`?Search[]=x`) turned any front-end page into a 500. Only
  non-empty string values are logged now.
- **Values longer than their column.** On Silverstripe 6, a 404 URL or referrer over 2048 characters,
  or a search query over 255, threw a validation exception on write, so the visitor got a 500
  instead of the page. On Silverstripe 5 the database truncated the stored value, the next lookup
  never matched it, and every hit added a new row instead of counting. Values are now truncated to
  the column size before the lookup.
- **Non-ASCII search queries** were stored with their capitals (`strtolower()` is ASCII-only); they
  are lower-cased with `mb_strtolower()` now. Existing rows are not rewritten.
- **Internal referrers on a non-standard port** were logged as external, because the own host was
  compared including its port. The port is ignored now.
- **Installs without silverstripe/reports** fatalled on flush (deploy, `dev/build`, `?flush=1`),
  because both report classes extend a class from a package that was neither required nor
  suggested. They are guarded now, and silverstripe/reports and silverstripe/cms are listed under
  `suggest`. The search logger is applied only when `SiteTree` exists. Resolves #4 (by guarding
  rather than requiring silverstripe/reports, so a framework-only install still logs 404s).

### Changed

- The referrer and host are read from the request object instead of `$_SERVER`. Same values for a
  real request; correct for requests built in code or in tests. The own host is still the request's
  `Host` header, so `Director.alternate_base_url` does not change which referrers count as internal.
- Adds a behavioural test suite (39 tests) and CI on Silverstripe 5 (PHP 8.1, 8.3) and 6
  (PHP 8.3, 8.4) against MariaDB 11.4.
- Adds `.gitattributes` so dist installs do not ship `tests/`, `.github/` or dev tooling config.
- Adds the composer `funding` property.
- README: requirements, compatibility table, what is logged, the config option and the PHP API.

## 2.0.2

Declares `silverstripe/framework: ^4 || ^5 || ^6`.

## 2.0.1

Declares `silverstripe/framework: ^4 || ^5`.

## 2.0.0

Silverstripe 4.
