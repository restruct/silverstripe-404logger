# Browser-test targets for this module, sourced by the shared runner
# (~/Sites/0_ss-mods-maintenance/tools/browser/run.sh) and by .github/workflows/browser-tests.yml.
# Plain bash assignments only. CI tests only the targets with an empty SS<n>_SRC_REF (= this
# checkout).
# Ports are assigned in ~/Sites/0_ss-mods-maintenance/tools/browser/PORTS.md; take new ones there.

BROWSER_PACKAGE="restruct/silverstripe-404logger"
BROWSER_TARGETS="ss5 ss6"

# main (3.x) requires framework ^5 || ^6 and is the only maintained line, so it serves both majors.
SS5_RECIPE="^5"
SS5_PHP="8.3"
SS5_PORT="8859"
SS5_SRC_REF=""
# The broken links report offers "redirect" only when silverstripe/redirectedurls is installed.
SS5_EXTRA_REQUIRE="silverstripe/redirectedurls:^3"

SS6_RECIPE="^6"
SS6_PHP="8.3"
SS6_PORT="8860"
SS6_SRC_REF=""
SS6_EXTRA_REQUIRE="silverstripe/redirectedurls:^4"
