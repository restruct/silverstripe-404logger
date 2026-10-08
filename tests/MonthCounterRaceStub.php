<?php

namespace Restruct\FourOhFourLogger\Tests;

use HitMonthCounter;
use SilverStripe\Dev\TestOnly;

/**
 * TEST ONLY: HitMonthCounter whose first UPDATE can be made to report "no row", staging the
 * moment another request inserts the month's row between our UPDATE and our INSERT.
 */
class MonthCounterRaceStub extends HitMonthCounter implements TestOnly
{
    public static bool $failFirstBump = false;

    protected static function bump($table, $logId, $yearMonth, $by, $now)
    {
        if (static::$failFirstBump) {
            static::$failFirstBump = false;
            return false;
        }

        return parent::bump($table, $logId, $yearMonth, $by, $now);
    }
}
