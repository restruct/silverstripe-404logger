<?php

namespace Restruct\FourOhFourLogger\Tests;

use SearchLog;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;

/**
 * SearchLog: schema, config and the create-or-count contract of logHit().
 */
class SearchLogTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testSchemaIsBuilt()
    {
        $columns = DB::field_list('SearchLog');
        foreach (['Query', 'Count', 'LastEdited'] as $column) {
            $this->assertArrayHasKey($column, $columns, "SearchLog.$column column exists");
        }
    }

    public function testConfigDefaults()
    {
        $this->assertSame('Search', SearchLog::config()->get('search_query_param'));
        $this->assertSame('LastEdited DESC', SearchLog::config()->get('default_sort'));
    }

    public function testFirstHitIsStoredLowerCasedWithCountOne()
    {
        SearchLog::logHit('Opening Hours');

        $this->assertCount(1, SearchLog::get());
        $log = SearchLog::get()->first();
        $this->assertSame('opening hours', $log->Query);
        $this->assertSame(1, (int) $log->Count);
    }

    public function testRepeatHitInAnyCaseIncrementsCountOnSameRow()
    {
        SearchLog::logHit('opening hours');
        SearchLog::logHit('Opening Hours');
        SearchLog::logHit('OPENING HOURS');

        $this->assertCount(1, SearchLog::get());
        $this->assertSame(3, (int) SearchLog::get()->first()->Count);
    }

    /**
     * Regression: strtolower() lower-cases ASCII only, so non-ASCII capitals were stored as typed.
     */
    public function testNonAsciiQueryIsLowerCased()
    {
        SearchLog::logHit('ÜBER ÉTÉ');

        $this->assertSame('über été', SearchLog::get()->first()->Query);
    }

    /**
     * Regression: a query longer than the Varchar(255) column. Silverstripe 6 threw on write (a
     * 500 on the search page); Silverstripe 5 stored a truncated value the next lookup never
     * matched, so each hit added a row.
     */
    public function testLongQueryIsTruncatedAndStillCounted()
    {
        $query = str_repeat('word ', 100);

        SearchLog::logHit($query);
        SearchLog::logHit($query);

        $this->assertCount(1, SearchLog::get());
        $log = SearchLog::get()->first();
        $this->assertSame(2, (int) $log->Count);
        $this->assertSame(255, mb_strlen($log->Query));
    }
}
