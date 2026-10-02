<?php

namespace Restruct\LgBrowser;

use SilverStripe\ORM\DataObject;

/**
 * BROWSER-TEST FIXTURE ONLY - empties the module's two log tables on every dev/build, so each run
 * of the browser specs starts from empty reports (the runner does one dev/build per run, and the
 * reports page at 50 rows, which leftovers of earlier local runs would otherwise fill).
 *
 * Never loaded by a real install: it lives under tests/browser/, which carries a _manifest_exclude
 * marker, and the browser-test runner copies it into a scratch host's app/ before dev/build.
 * A DataObject only because requireDefaultRecords() is the dev/build hook; its own table stays
 * empty.
 */
class LgBReset extends DataObject
{
    # Short table name: no namespaced default.
    private static $table_name = 'LgBReset';

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        # The module's classes are in the global namespace.
        foreach ([\FourOhFourLog::class, \SearchLog::class] as $class) {
            foreach ($class::get() as $row) {
                $row->delete();
            }
        }
    }
}
