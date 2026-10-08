<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use FourOhFourLogMonth;
use HitMonthCounter;
use SearchLog;
use SearchLogMonth;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * 3.2 per-month counts: every logged hit is also counted in FourOhFourLogMonth / SearchLogMonth
 * (LogID, YearMonth, Count), on every path through logHit(), and never for an ignored hit.
 */
class MonthCountTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        FourOhFourLog::class => [FourOhFourLogWriteSpy::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        FourOhFourLogWriteSpy::reset();
        MonthCounterRaceStub::$failFirstBump = false;
    }

    protected function tearDown(): void
    {
        # Plain statics survive between tests (SapphireTest only restores config).
        FourOhFourLogWriteSpy::reset();
        MonthCounterRaceStub::$failFirstBump = false;
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    /**
     * @return array<int, int> YearMonth => Count for one log row
     */
    private function months(string $monthClass, int $logId): array
    {
        $months = [];
        foreach ($monthClass::get()->filter('LogID', $logId)->sort('YearMonth') as $row) {
            $months[(int) $row->YearMonth] = (int) $row->Count;
        }

        return $months;
    }

    public function testFourOhFourHitsAreCountedPerMonth()
    {
        DBDatetime::set_mock_now('2026-09-30 23:00:00');
        $log = FourOhFourLog::logHit('missing/monthly', 'unknown');
        FourOhFourLog::logHit('missing/monthly', 'unknown');
        DBDatetime::set_mock_now('2026-10-01 08:00:00');
        FourOhFourLog::logHit('/missing/monthly', 'unknown');

        $this->assertSame([202609 => 2, 202610 => 1], $this->months(FourOhFourLogMonth::class, (int) $log->ID));
        $this->assertSame(3, (int) FourOhFourLog::get()->byID($log->ID)->Count, 'lifetime Count unchanged');
    }

    public function testSearchHitsAreCountedPerMonth()
    {
        DBDatetime::set_mock_now('2025-12-31 10:00:00');
        $log = SearchLog::logHit('Monthly Query');
        DBDatetime::set_mock_now('2026-01-02 10:00:00');
        SearchLog::logHit('monthly query');
        SearchLog::logHit('MONTHLY QUERY');

        $this->assertSame([202512 => 1, 202601 => 2], $this->months(SearchLogMonth::class, (int) $log->ID));
    }

    public function testIgnoredHitsAndNoiseAreNotCounted()
    {
        $this->assertNull(FourOhFourLog::logHit('wp-login.php', 'unknown'));
        $this->assertNull(SearchLog::logHit("1' or '1'='1"));

        $this->assertSame(0, FourOhFourLogMonth::get()->count());
        $this->assertSame(0, SearchLogMonth::get()->count());
    }

    public function testCountPerMonthCanBeSwitchedOff()
    {
        FourOhFourLog::config()->set('count_per_month', false);
        SearchLog::config()->set('count_per_month', false);

        FourOhFourLog::logHit('missing/no-months', 'unknown');
        SearchLog::logHit('no months');

        $this->assertSame(1, FourOhFourLog::get()->count(), 'the hit itself is still logged');
        $this->assertSame(0, FourOhFourLogMonth::get()->count());
        $this->assertSame(0, SearchLogMonth::get()->count());
    }

    /**
     * 3.1's race handling (a concurrent first hit wins the insert on the unique LinkHash) must
     * still count the hit, on the winner's row, also per month.
     */
    public function testLosingTheInsertRaceIsCountedPerMonthOnTheWinnersRow()
    {
        DBDatetime::set_mock_now('2026-10-08 12:00:00');
        FourOhFourLogWriteSpy::$raceWithCount = 5;

        $log = FourOhFourLog::logHit('race/monthly', 'https://elsewhere.example/');

        $this->assertCount(1, FourOhFourLog::get());
        $this->assertSame(6, (int) $log->Count);
        $this->assertSame([202610 => 1], $this->months(FourOhFourLogMonth::class, (int) $log->ID));
    }

    /**
     * The month's own race: the first UPDATE finds no row, another request inserts it, our
     * INSERT hits the unique (LogID, YearMonth) index. The hit is counted on that row.
     */
    public function testLosingTheMonthInsertRaceCountsOnTheExistingMonthRow()
    {
        DBDatetime::set_mock_now('2026-10-08 12:00:00');
        $log = FourOhFourLog::logHit('missing/month-race', 'unknown');
        $this->assertSame([202610 => 1], $this->months(FourOhFourLogMonth::class, (int) $log->ID));

        # The row for this month exists; make the first UPDATE report "no row", as it would
        # have just before the other request's INSERT.
        MonthCounterRaceStub::$failFirstBump = true;
        MonthCounterRaceStub::increment(FourOhFourLogMonth::class, (int) $log->ID);

        $this->assertFalse(MonthCounterRaceStub::$failFirstBump, 'the stub did stage the race');
        $this->assertSame([202610 => 2], $this->months(FourOhFourLogMonth::class, (int) $log->ID));
        $this->assertSame(1, FourOhFourLogMonth::get()->count(), 'no second row for the month');
    }

    public function testRepeatHitInAMonthIsOneUpdateNotAnInsert()
    {
        DBDatetime::set_mock_now('2026-10-08 12:00:00');
        $log = FourOhFourLog::logHit('missing/one-row', 'unknown');
        $first = FourOhFourLogMonth::get()->filter('LogID', $log->ID)->first();
        for ($i = 0; $i < 4; $i++) {
            FourOhFourLog::logHit('missing/one-row', 'unknown');
        }

        $rows = FourOhFourLogMonth::get()->filter('LogID', $log->ID);
        $this->assertSame(1, $rows->count());
        $this->assertSame((int) $first->ID, (int) $rows->first()->ID, 'the same row, counted up');
        $this->assertSame(5, (int) $rows->first()->Count);
    }

    public function testDeletingALogRowDeletesItsMonths()
    {
        $log = FourOhFourLog::logHit('missing/cascade', 'unknown');
        $search = SearchLog::logHit('cascade');
        $this->assertSame(1, FourOhFourLogMonth::get()->count());
        $this->assertSame(1, SearchLogMonth::get()->count());

        $log->delete();
        $search->delete();

        $this->assertSame(0, FourOhFourLogMonth::get()->count());
        $this->assertSame(0, SearchLogMonth::get()->count());
    }

    public function testTotalsSince()
    {
        DBDatetime::set_mock_now('2026-08-15 12:00:00');
        $a = FourOhFourLog::logHit('missing/a', 'unknown');
        DBDatetime::set_mock_now('2026-09-15 12:00:00');
        FourOhFourLog::logHit('missing/a', 'unknown');
        $b = FourOhFourLog::logHit('missing/b', 'unknown');
        DBDatetime::set_mock_now('2026-10-15 12:00:00');
        FourOhFourLog::logHit('missing/b', 'unknown');
        FourOhFourLog::logHit('missing/b', 'unknown');

        $this->assertSame(
            [(int) $a->ID => 1, (int) $b->ID => 3],
            HitMonthCounter::totalsSince(FourOhFourLogMonth::class, 202609)
        );
        $this->assertSame([(int) $b->ID => 2], HitMonthCounter::totalsSince(FourOhFourLogMonth::class, 202610));
        $this->assertSame([], HitMonthCounter::totalsSince(FourOhFourLogMonth::class, 202611));
    }

    public function testYearMonth()
    {
        $this->assertSame(202602, HitMonthCounter::yearMonth(strtotime('2026-02-28 12:00:00')));
        DBDatetime::set_mock_now('2027-01-01 00:30:00');
        $this->assertSame(202701, HitMonthCounter::yearMonth());
    }
}
