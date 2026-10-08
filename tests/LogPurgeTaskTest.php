<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use FourOhFourLogMonth;
use LogPurgeTask;
use SearchLog;
use SearchLogMonth;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLUpdate;

/**
 * 3.2 LogPurgeTask: retention by age, by the current ignore rules and by search noise, with a
 * dry run that reports the same counts as the real run.
 */
class LogPurgeTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    private function purge(?int $olderThan, bool $ignored = false, bool $noise = false, bool $dryRun = false, int $chunkSize = 1000): array
    {
        $lines = [];
        $stats = LogPurgeTask::create()->purge($olderThan, $ignored, $noise, $dryRun, $chunkSize, function (string $line) use (&$lines) {
            $lines[] = $line;
        });
        $this->assertNotEmpty($lines, 'the task reports what it did');

        return $stats;
    }

    /**
     * An old link (last hit 2024), a recent one with hits in an old and a recent month, an old
     * search term and a recent one, plus noise logged with the filter off.
     */
    private function fixture(): void
    {
        DBDatetime::set_mock_now('2024-03-10 10:00:00');
        FourOhFourLog::logHit('old/link', 'unknown');
        FourOhFourLog::logHit('recent/link', 'unknown');
        SearchLog::logHit('old term');
        DBDatetime::set_mock_now('2026-09-10 10:00:00');
        FourOhFourLog::logHit('recent/link', 'unknown');
        SearchLog::logHit('recent term');
        SearchLog::config()->set('ignore_noise', false);
        SearchLog::logHit('2026');
        SearchLog::config()->set('ignore_noise', true);
        DBDatetime::set_mock_now('2026-10-08 12:00:00');
    }

    private function links(): array
    {
        return FourOhFourLog::get()->sort('Link')->column('Link');
    }

    private function queries(): array
    {
        return SearchLog::get()->sort('Query')->column('Query');
    }

    public function testTaskLoadsOnThisMajor()
    {
        $task = LogPurgeTask::create();
        $this->assertInstanceOf(BuildTask::class, $task);
        $this->assertNotEmpty($task->getTitle());
    }

    public function testWithoutCriteriaNothingIsDeleted()
    {
        $this->fixture();
        $this->assertSame(['fourohfour' => 0, 'search' => 0, 'months' => 0], $this->purge(null));
        $this->assertSame(['fourohfour' => 0, 'search' => 0, 'months' => 0], $this->purge(0));
        $this->assertCount(2, $this->links());
        $this->assertCount(3, $this->queries());
    }

    public function testOlderThanDeletesOldRowsAndOldMonths()
    {
        $this->fixture();
        $recent = FourOhFourLog::get()->filter('Link', 'recent/link')->first();
        $this->assertSame(2, FourOhFourLogMonth::get()->filter('LogID', $recent->ID)->count());

        $stats = $this->purge(12);

        $this->assertSame(['recent/link'], $this->links());
        $this->assertSame(['2026', 'recent term'], $this->queries());
        # The old link's month row, the old term's, and the recent link's 2024 month.
        $this->assertSame(['fourohfour' => 1, 'search' => 1, 'months' => 3], $stats);
        $this->assertSame([202609], FourOhFourLogMonth::get()->column('YearMonth'));
        $this->assertSame(2, (int) FourOhFourLog::get()->first()->Count, 'a kept row keeps its lifetime count');
        $this->assertSame(0, SearchLogMonth::get()->filter('YearMonth:LessThan', 202510)->count());
    }

    public function testIgnoredDeletesRowsTheCurrentRulesWouldNotLog()
    {
        $this->fixture();
        # Logged while probes were logged, now ignored again.
        FourOhFourLog::config()->set('ignore_categories', ['probe' => false]);
        $probe = FourOhFourLog::logHit('apple-touch-icon.png', 'unknown');
        FourOhFourLog::config()->set('ignore_categories', ['probe' => true]);
        FourOhFourLog::config()->set('ignore_patterns', ['old' => '~^old/~']);

        $stats = $this->purge(null, true);

        $this->assertSame(['recent/link'], $this->links());
        $this->assertSame(0, FourOhFourLogMonth::get()->filter('LogID', $probe->ID)->count(), 'months go with the row');
        $this->assertSame(2, $stats['fourohfour']);
        $this->assertSame(0, $stats['search']);
        $this->assertCount(3, $this->queries(), 'search rows are untouched without noise');
    }

    public function testNoiseDeletesNoiseSearchRows()
    {
        $this->fixture();
        $stats = $this->purge(null, false, true);

        $this->assertSame(['old term', 'recent term'], $this->queries());
        $this->assertSame(1, $stats['search']);
        $this->assertCount(2, $this->links());
    }

    public function testDryRunDeletesNothingAndCountsTheSame()
    {
        $this->fixture();
        FourOhFourLog::config()->set('ignore_patterns', ['old' => '~^old/~']);

        $dry = $this->purge(12, true, true, true);
        $this->assertCount(2, $this->links());
        $this->assertCount(3, $this->queries());
        $this->assertSame(6, FourOhFourLogMonth::get()->count() + SearchLogMonth::get()->count());
        $this->assertSame(['fourohfour' => 1, 'search' => 2, 'months' => 4], $dry);

        # Chunk size 1 to cross chunk boundaries while rows are deleted behind the cursor.
        $real = $this->purge(12, true, true, false, 1);
        $this->assertSame($dry, $real);
        $this->assertSame(['recent/link'], $this->links());
        $this->assertSame(['recent term'], $this->queries());
    }

    public function testRowsWithoutLastEditedAreNotTreatedAsOld()
    {
        $this->fixture();
        SQLUpdate::create('"FourOhFourLog"', ['"LastEdited"' => null], ['"Link"' => 'recent/link'])->execute();

        $this->purge(12);

        $this->assertSame(['recent/link'], $this->links());
    }
}
