<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourReport;
use LogicException;
use SilverStripe\Dev\TestOnly;

/**
 * TEST ONLY: a broken links report whose grouped list must not be built. getCount() for the
 * reports overview has to count without it: building every group in PHP is what took the
 * ReportAdmin listing down on a large table.
 */
class FourOhFourReportCountStub extends FourOhFourReport implements TestOnly
{
    public function linkRows(array $params)
    {
        throw new LogicException('getCount() built the grouped list');
    }
}
