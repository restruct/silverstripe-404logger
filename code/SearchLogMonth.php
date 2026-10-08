<?php

use SilverStripe\ORM\DataObject;

/**
 * Hits of one SearchLog row in one calendar month. Written by HitMonthCounter with plain SQL
 * from SearchLog::logHit(), one row per log row per month, so "recent" demand can be told
 * apart from the lifetime Count. There is no history before the upgrade that added this table.
 */
class SearchLogMonth extends DataObject
{
    private static $singular_name = 'Search Log month';

    private static $db = array(
        # Calendar month as an integer, YYYYMM (202610): sorts and compares as a number.
        'YearMonth' => 'Int',
        'Count' => 'Int',
    );

    private static $has_one = array(
        'Log' => SearchLog::class,
    );

    private static $indexes = array(
        # The upsert in HitMonthCounter looks rows up by both columns, and relies on this index
        # being UNIQUE to stop two concurrent first hits in a month from creating two rows.
        'LogMonth' => array('type' => 'unique', 'columns' => array('LogID', 'YearMonth')),
        # Report and purge queries select by month across all logs.
        'YearMonth' => true,
    );

    public function canView($member = null)
    {
        return SearchLog::singleton()->canView($member);
    }

    public function canEdit($member = null)
    {
        return SearchLog::singleton()->canEdit($member);
    }

    public function canDelete($member = null)
    {
        return SearchLog::singleton()->canDelete($member);
    }

    public function canCreate($member = null, $context = [])
    {
        return SearchLog::singleton()->canCreate($member, $context);
    }
}
