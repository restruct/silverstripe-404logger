# Changelog

## 3.0.0

Silverstripe 5 and 6 from one line, with a test suite and CI. Silverstripe 4 is no longer
supported; projects on it keep resolving the `2.x` tags (a `^2.0` constraint never installs 3.x).

### Breaking

- **Requires PHP `^8.1` and `silverstripe/framework: ^5 || ^6`.** Silverstripe 4 was dropped because
  it reached end of life in April 2025. There are no API changes: class names, table names, config
  and the report titles are unchanged, so upgrading from 2.x on Silverstripe 5 needs no code or data
  changes, just a database build.

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
  real request; correct for requests built in code or in tests.
- Adds a behavioural test suite (30 tests) and CI on Silverstripe 5 (PHP 8.1, 8.3) and 6
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
