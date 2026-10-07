<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use FourOhFourLogMergeTask;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\Queries\SQLSelect;

/**
 * FourOhFourLogMergeTask: backfills LinkHash/Category on rows from before 3.1 and merges the rows
 * that are one link + referrer ('/path' and 'path').
 */
class FourOhFourLogMergeTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    private function runTask(bool $dryRun = false, int $chunkSize = 1000): array
    {
        $lines = [];
        $stats = FourOhFourLogMergeTask::create()->merge($dryRun, $chunkSize, function (string $line) use (&$lines) {
            $lines[] = $line;
        });
        $this->assertNotEmpty($lines, 'the task reports what it did');

        return $stats;
    }

    /**
     * Raw rows, so the ORM does not touch Created/LastEdited.
     */
    private function rows(): array
    {
        $rows = [];
        $query = SQLSelect::create(
            ['"ID"', '"Link"', '"Referrer"', '"Count"', '"Created"', '"LastEdited"', '"LinkHash"', '"Category"'],
            '"FourOhFourLog"',
            [],
            ['"ID"' => 'ASC']
        )->execute();
        foreach ($query as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function testTaskLoadsOnThisMajor()
    {
        $task = FourOhFourLogMergeTask::create();
        $this->assertInstanceOf(BuildTask::class, $task);
        $this->assertNotEmpty($task->getTitle());
    }

    /**
     * The upgrade case: '/path' (old) and 'path' (new) from the same referrer become one row with
     * the summed count, the earliest Created and the latest LastEdited.
     */
    public function testSlashPairIsMergedKeepingCountAndDates()
    {
        FourOhFourLogHashTest::insertLegacyRow('/old/page', 'unknown', 3, '2019-01-01 10:00:00', '2021-03-03 10:00:00');
        FourOhFourLogHashTest::insertLegacyRow('old/page', 'unknown', 2, '2021-06-01 10:00:00', '2024-02-02 10:00:00');

        $stats = $this->runTask();

        $this->assertSame(['scanned' => 2, 'hashed' => 1, 'merged' => 1], $stats);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('old/page', $rows[0]['Link']);
        $this->assertSame(5, (int) $rows[0]['Count']);
        $this->assertSame('2019-01-01 10:00:00', $rows[0]['Created']);
        $this->assertSame('2024-02-02 10:00:00', $rows[0]['LastEdited']);
        $this->assertSame(FourOhFourLog::linkHash('old/page', 'unknown'), $rows[0]['LinkHash']);
        $this->assertSame('page', $rows[0]['Category']);
    }

    /**
     * A row with no duplicate keeps its timestamps: it only gains its hash, category and
     * normalised link (no DataObject::write(), which would set LastEdited to now).
     */
    public function testSingleRowIsBackfilledWithoutTouchingTimestamps()
    {
        FourOhFourLogHashTest::insertLegacyRow('/lonely/page', 'https://elsewhere.example/', 4, '2019-01-01 10:00:00', '2020-01-01 10:00:00');

        $this->runTask();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('lonely/page', $rows[0]['Link']);
        $this->assertSame(4, (int) $rows[0]['Count']);
        $this->assertSame('2020-01-01 10:00:00', $rows[0]['LastEdited']);
        $this->assertNotEmpty($rows[0]['LinkHash']);
    }

    public function testDifferentReferrersStaySeparate()
    {
        FourOhFourLogHashTest::insertLegacyRow('/old/page', 'https://one.example/', 1);
        FourOhFourLogHashTest::insertLegacyRow('old/page', 'https://two.example/', 1);

        $this->runTask();

        $this->assertCount(2, $this->rows());
    }

    /**
     * Rows that differ only in case were one row under the old case-insensitive lookup too.
     */
    public function testCaseVariantsAreMerged()
    {
        FourOhFourLogHashTest::insertLegacyRow('/Old/Page', 'unknown', 1);
        FourOhFourLogHashTest::insertLegacyRow('old/page', 'unknown', 1);

        $this->runTask();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['Count']);
    }

    /**
     * A row that logHit() already hashed after the upgrade absorbs its unhashed old twin.
     */
    public function testUnhashedRowIsMergedIntoAlreadyHashedRow()
    {
        $hashed = FourOhFourLog::logHit('old/page', 'unknown');
        # A second legacy twin: logHit() backfills only the one it finds.
        FourOhFourLogHashTest::insertLegacyRow('/old/page', 'unknown', 6, '2018-01-01 00:00:00', '2018-02-01 00:00:00');

        $stats = $this->runTask();

        $this->assertSame(1, $stats['merged']);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame((int) $hashed->ID, (int) $rows[0]['ID']);
        $this->assertSame(7, (int) $rows[0]['Count']);
        $this->assertSame('2018-01-01 00:00:00', $rows[0]['Created']);
    }

    /**
     * Rows of an ignored category are categorised, not deleted: removing them is a retention
     * decision, not part of the upgrade.
     */
    public function testIgnoredCategoryRowsAreKeptAndCategorised()
    {
        FourOhFourLogHashTest::insertLegacyRow('/wp-login.php', 'unknown', 9);

        $this->runTask();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('scanner', $rows[0]['Category']);
        $this->assertSame(9, (int) $rows[0]['Count']);
    }

    public function testDryRunCountsButChangesNothing()
    {
        FourOhFourLogHashTest::insertLegacyRow('/old/page', 'unknown', 3);
        FourOhFourLogHashTest::insertLegacyRow('old/page', 'unknown', 2);
        FourOhFourLogHashTest::insertLegacyRow('/other', 'unknown', 1);
        $before = $this->rows();

        $stats = $this->runTask(true);

        $this->assertSame(['scanned' => 3, 'hashed' => 2, 'merged' => 1], $stats);
        $this->assertSame($before, $this->rows());
    }

    public function testSecondRunFindsNothingToDo()
    {
        FourOhFourLogHashTest::insertLegacyRow('/old/page', 'unknown', 3);
        FourOhFourLogHashTest::insertLegacyRow('old/page', 'unknown', 2);

        $this->runTask();
        $after = $this->rows();
        $stats = $this->runTask();

        $this->assertSame(['scanned' => 0, 'hashed' => 0, 'merged' => 0], $stats);
        $this->assertSame($after, $this->rows());
    }

    /**
     * Chunks smaller than the table give the same result as one pass, including a pair whose
     * rows land in different chunks.
     */
    public function testSmallChunksGiveTheSameResult()
    {
        foreach (['a', 'b', 'c'] as $name) {
            FourOhFourLogHashTest::insertLegacyRow("/$name", 'unknown', 1);
        }
        foreach (['a', 'b', 'c'] as $name) {
            FourOhFourLogHashTest::insertLegacyRow($name, 'unknown', 2);
        }

        $stats = $this->runTask(false, 2);

        $this->assertSame(['scanned' => 6, 'hashed' => 3, 'merged' => 3], $stats);
        $rows = $this->rows();
        $this->assertSame(['a', 'b', 'c'], array_column($rows, 'Link'));
        $this->assertSame([3, 3, 3], array_map('intval', array_column($rows, 'Count')));
    }

    /**
     * After the task, logHit() counts on the merged row through the index.
     */
    public function testLogHitUsesTheMergedRow()
    {
        FourOhFourLogHashTest::insertLegacyRow('/old/page', 'unknown', 3);
        FourOhFourLogHashTest::insertLegacyRow('old/page', 'unknown', 2);
        $this->runTask();

        FourOhFourLog::logHit('/old/page', 'unknown');

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(6, (int) $rows[0]['Count']);
    }
}
