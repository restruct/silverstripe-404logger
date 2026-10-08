<?php

namespace Restruct\FourOhFourLogger\Tests;

use SearchLog;
use SilverStripe\Dev\SapphireTest;

/**
 * SearchLog::logHit() write path: a repeat search raises Count in SQL, so hits counted by
 * concurrent requests on the same row are not overwritten.
 */
class SearchLogWritePathTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        SearchLog::class => [SearchLogWriteSpy::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        SearchLogWriteSpy::reset();
    }

    protected function tearDown(): void
    {
        # Plain statics survive between tests (SapphireTest only restores config).
        SearchLogWriteSpy::reset();
        parent::tearDown();
    }

    public function testARepeatSearchDoesNotOverwriteConcurrentHits()
    {
        SearchLog::logHit('busy query');
        SearchLogWriteSpy::$concurrentHits = 10;

        $log = SearchLog::logHit('Busy Query');

        $this->assertCount(1, SearchLog::get());
        $this->assertSame(12, (int) SearchLog::get()->first()->Count, 'first hit + 10 concurrent + this one');
        # The returned row is not re-read (hot path): its Count is the value read + this hit.
        $this->assertSame(2, (int) $log->Count);
    }
}
