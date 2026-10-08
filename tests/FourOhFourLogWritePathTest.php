<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use SilverStripe\Dev\SapphireTest;

/**
 * FourOhFourLog::logHit() write path: a repeat hit is found by its hash and updated, never
 * inserted; a concurrent first hit that loses the race on the unique index is counted on the
 * winner's row.
 */
class FourOhFourLogWritePathTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        FourOhFourLog::class => [FourOhFourLogWriteSpy::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        FourOhFourLogWriteSpy::reset();
    }

    protected function tearDown(): void
    {
        # Plain statics survive between tests (SapphireTest only restores config).
        FourOhFourLogWriteSpy::reset();
        parent::tearDown();
    }

    /**
     * Guards the hash lookup itself: without it, a repeat hit would still end up counted, by
     * attempting an insert, hitting the unique index and recovering in the catch block. Only
     * the number of inserts tells the two apart.
     */
    public function testRepeatHitIsFoundByHashAndNeverInserts()
    {
        FourOhFourLog::logHit('missing/page', 'unknown');
        $this->assertSame(1, FourOhFourLogWriteSpy::$inserts);

        FourOhFourLog::logHit('missing/page', 'unknown');
        FourOhFourLog::logHit('/Missing/Page', 'unknown');

        $this->assertSame(1, FourOhFourLogWriteSpy::$inserts, 'repeat hits update, they do not insert');
        $this->assertSame(3, (int) FourOhFourLog::get()->first()->Count);
    }

    /**
     * The race: another request inserts the row between our lookup and our insert. Our insert
     * is rejected by the unique index, and the hit is counted on that row instead.
     */
    public function testLosingTheInsertRaceCountsOnTheWinnersRow()
    {
        FourOhFourLogWriteSpy::$raceWithCount = 5;

        $log = FourOhFourLog::logHit('race/page', 'https://elsewhere.example/');

        $this->assertCount(1, FourOhFourLog::get());
        $row = FourOhFourLog::get()->first();
        $this->assertSame(6, (int) $row->Count, "the winner's count plus this hit");
        $this->assertSame((int) $row->ID, (int) $log->ID, 'logHit() returns the row it counted on');
    }

    /**
     * Concurrent repeat hits on one row: each request read Count, added one and wrote the
     * result back, so hits counted by the others in between were overwritten. The increment is
     * done in SQL now, so the hits staged by the spy survive.
     */
    public function testARepeatHitDoesNotOverwriteConcurrentHits()
    {
        FourOhFourLog::logHit('busy/page', 'unknown');
        FourOhFourLogWriteSpy::$concurrentHits = 10;

        $log = FourOhFourLog::logHit('busy/page', 'unknown');

        $this->assertSame(12, (int) FourOhFourLog::get()->first()->Count, 'first hit + 10 concurrent + this one');
        # The returned row is not re-read (hot path): its Count is the value read + this hit.
        $this->assertSame(2, (int) $log->Count, 'logHit() returns the count read plus this hit');
    }
}
