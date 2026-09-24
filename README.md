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
* PHP 8.1 or newer
* Optional: `silverstripe/reports` for the two CMS reports, and `silverstripe/cms` for search
  query logging. Both come with `silverstripe/recipe-cms`. Without them the 404 log is still
  written, but there is no report to view it in.

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
| (tags only) | `2.0.2` | `^4 \|\| ^5 \|\| ^6` (declared; not tested on 6) | not declared |
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

Logged search queries are in the same section as 'Search words report'.

### What gets logged

**404s** (`FourOhFourLogger`, applied to every `RequestHandler`):

* Only responses with status 404. Other error codes are ignored.
* The logged link is the request URL relative to the site root, including the query string.
* Only **external** referrers are logged. A 404 whose referrer is on the site's own host is skipped
  (internal broken links belong in a site-internal link checker). The host is compared without its
  port.
* A request without a referrer is logged with referrer `unknown`.
* Each link + referrer combination is one row; repeat hits increase its count.
* Links and referrers longer than 2048 characters are truncated to fit the column.
* Also logs when the site has a published 404 `ErrorPage` (silverstripe/errorpage).

**Search queries** (`SearchQueryLogger`, applied to `SiteTree` when silverstripe/cms is installed):

* Logged from the request parameter configured below, on any front-end page.
* Queries are trimmed, lower-cased and truncated to 255 characters; each distinct query is one row
  with a hit count. Empty values and array values (`?Search[]=...`) are ignored.

### Configuration

| Option | Default | Effect |
|--------|---------|--------|
| `SearchLog.search_query_param` | `'Search'` | Name of the GET parameter whose value is logged as a search query |

```yaml
SearchLog:
  search_query_param: 'q'
```

### PHP API

Both loggers call a static method you can also call yourself, eg from a custom controller:

* `FourOhFourLog::logHit(string $link, string $referrer)` - create the row for this link and
  referrer, or increase its count.
* `SearchLog::logHit(string $query)` - create the row for this query, or increase its count.

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
