<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObject;

/**
 * FourOhFourLog: schema and the create-or-count contract of logHit().
 */
class FourOhFourLogTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * dev/build (here: the temp database build) creates the table with the declared columns.
     */
    public function testSchemaIsBuilt()
    {
        $columns = DB::field_list('FourOhFourLog');
        foreach (['Referrer', 'Link', 'Count', 'LastEdited'] as $column) {
            $this->assertArrayHasKey($column, $columns, "FourOhFourLog.$column column exists");
        }
        $spec = DataObject::getSchema()->fieldSpec(FourOhFourLog::class, 'Link');
        $this->assertSame('Varchar(2048)', $spec);
    }

    public function testFirstHitCreatesRowWithCountOne()
    {
        FourOhFourLog::logHit('missing/page', 'https://elsewhere.example/');

        $logs = FourOhFourLog::get();
        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame('missing/page', $log->Link);
        $this->assertSame('https://elsewhere.example/', $log->Referrer);
        $this->assertSame(1, (int) $log->Count);
    }

    public function testRepeatHitIncrementsCountOnSameRow()
    {
        FourOhFourLog::logHit('missing/page', 'https://elsewhere.example/');
        FourOhFourLog::logHit('missing/page', 'https://elsewhere.example/');
        FourOhFourLog::logHit('missing/page', 'https://elsewhere.example/');

        $this->assertCount(1, FourOhFourLog::get());
        $this->assertSame(3, (int) FourOhFourLog::get()->first()->Count);
    }

    public function testSameLinkFromAnotherReferrerIsASeparateRow()
    {
        FourOhFourLog::logHit('missing/page', 'https://one.example/');
        FourOhFourLog::logHit('missing/page', 'https://two.example/');

        $this->assertCount(2, FourOhFourLog::get());
    }

    /**
     * Regression: a link longer than the Varchar(2048) column. Silverstripe 6 threw a
     * ValidationException on write (a 500 instead of a 404); Silverstripe 5 stored a truncated
     * value that the next lookup never matched, so each hit added a row.
     */
    public function testLongLinkIsTruncatedAndStillCounted()
    {
        $link = 'missing/' . str_repeat('a', 3000);
        $ref = 'https://elsewhere.example/' . str_repeat('b', 3000);

        FourOhFourLog::logHit($link, $ref);
        FourOhFourLog::logHit($link, $ref);

        $this->assertCount(1, FourOhFourLog::get());
        $log = FourOhFourLog::get()->first();
        $this->assertSame(2, (int) $log->Count);
        $this->assertSame(2048, mb_strlen($log->Link));
        $this->assertSame(2048, mb_strlen($log->Referrer));
        $this->assertSame(mb_substr($link, 0, 2048), $log->Link);
    }
}
