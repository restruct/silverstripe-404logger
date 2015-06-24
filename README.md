Log 404s to DB
==============

This module provides logging of incoming requests that result in a 404 not found. They are logged & counted along with the referrer.

Installation
------------
Either:
1. Download or git clone the '404logger' directory to your webroot, or;
2. Using composer run the following in the command line:

  composer require micschk/silverstripe-404logger dev-master

3. Run dev/build (http://www.mysite.com/dev/build?flush=all)

Usage
-----
Logged 404s are visible under the 'reports' section in the CMS as 'External broken links report'. You can either contact the referrer to get the link updated, or redirect the link at your end using the [redirectedurls module](https://github.com/silverstripe-labs/silverstripe-redirectedurls)

To also log site searches, add $logSearchAction to you Page_results.ss;
```
$logSearchAction <%-- for 404/searchquery report --%>
```