<?php

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLInsert;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\Queries\SQLUpdate;

/**
 * Per-month hit counts for FourOhFourLog and SearchLog (tables FourOhFourLogMonth and
 * SearchLogMonth: LogID, YearMonth, Count).
 *
 * Written with plain SQL, not DataObject::write(): a write() would first have to read the row,
 * and this runs on every logged hit. The common case (a second or later hit in the same month)
 * is ONE indexed UPDATE; only the first hit of a log row in a month adds an INSERT.
 */
class HitMonthCounter
{
    /**
     * The month a hit counts in, as YYYYMM. Uses DBDatetime::now(), so tests can mock the clock.
     *
     * @param int|null $timestamp Defaults to now
     * @return int
     */
    public static function yearMonth($timestamp = null)
    {
        if ($timestamp === null) {
            $timestamp = DBDatetime::now()->getTimestamp();
        }

        return (int) date('Ym', (int) $timestamp);
    }

    /**
     * Count one hit (or $by hits) for a log row in the current month.
     *
     * Portable upsert: UPDATE first; when it touched no row, INSERT. When two first hits of a
     * month race, the UNIQUE (LogID, YearMonth) index rejects the second INSERT, and that hit is
     * counted by repeating the UPDATE. Not INSERT ... ON DUPLICATE KEY UPDATE, which is MySQL-only.
     *
     * @param string $monthClass FourOhFourLogMonth or SearchLogMonth
     * @param int $logId
     * @param int $by
     * @return void
     */
    public static function increment($monthClass, $logId, $by = 1)
    {
        $logId = (int) $logId;
        $by = (int) $by;
        if ($logId <= 0 || $by <= 0) {
            return;
        }

        $table = DataObject::getSchema()->tableName($monthClass);
        $yearMonth = static::yearMonth();
        $now = DBDatetime::now()->Rfc2822();

        if (static::bump($table, $logId, $yearMonth, $by, $now)) {
            return;
        }

        try {
            SQLInsert::create("\"$table\"", array(
                '"ClassName"' => $monthClass,
                '"Created"' => $now,
                '"LastEdited"' => $now,
                '"LogID"' => $logId,
                '"YearMonth"' => $yearMonth,
                '"Count"' => $by,
            ))->execute();
        } catch (\Exception $e) {
            # Lost the race for the month's first hit: the other request's row exists now (the
            # exception class differs per database connector, hence the broad catch). Count on
            # it; anything that is not that race is rethrown.
            if (!static::bump($table, $logId, $yearMonth, $by, $now)) {
                throw $e;
            }
        }
    }

    /**
     * Total hits per log row from a month on, from the month table.
     *
     * @param string $monthClass
     * @param int $fromYearMonth YYYYMM, inclusive
     * @return array<int, int> LogID => hits
     */
    public static function totalsSince($monthClass, $fromYearMonth)
    {
        $table = DataObject::getSchema()->tableName($monthClass);
        $rows = SQLSelect::create(
            array('"LogID"', 'Total' => 'SUM("Count")'),
            "\"$table\"",
            array('"YearMonth" >= ?' => (int) $fromYearMonth),
            array(),
            array('"LogID"')
        )->execute();

        $totals = array();
        foreach ($rows as $row) {
            $totals[(int) $row['LogID']] = (int) $row['Total'];
        }

        return $totals;
    }

    /**
     * @return bool Whether a row was updated
     */
    protected static function bump($table, $logId, $yearMonth, $by, $now)
    {
        SQLUpdate::create(
            "\"$table\"",
            array(
                '"Count"' => array('"Count" + ?' => array($by)),
                '"LastEdited"' => $now,
            ),
            array('"LogID"' => $logId, '"YearMonth"' => $yearMonth)
        )->execute();

        return DB::affected_rows() > 0;
    }
}
