<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use FourOhFourLogMonth;
use FourOhFourLogReferrerDecodeTask;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLInsert;
use SilverStripe\ORM\Queries\SQLSelect;

/**
 * FourOhFourLogReferrerDecodeTask (#6): referrers stored htmlentities()-encoded before 3.4 are
 * decoded once, and merged into the row a hit since the upgrade logged for the same referrer.
 */
class FourOhFourLogReferrerDecodeTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    /** A referrer as a browser sends it, and as FourOhFourLogger before 3.4 stored it */
    const RAW = "https://elsewhere.example/links?a=1&b=<x>&c='q'";
    const ENCODED = 'https://elsewhere.example/links?a=1&amp;b=&lt;x&gt;&amp;c=&#039;q&#039;';

    private function runTask(bool $dryRun = false, int $chunkSize = 1000): array
    {
        $lines = [];
        $stats = FourOhFourLogReferrerDecodeTask::create()->decode($dryRun, $chunkSize, function (string $line) use (&$lines) {
            $lines[] = $line;
        });
        $this->assertNotEmpty($lines, 'the task reports what it did');

        return $stats;
    }

    /**
     * A row as logHit() writes it (3.1+, hashed), inserted with SQL so the timestamps are the given ones.
     */
    private function insertRow(string $link, string $ref, int $count, string $created = '2024-01-01 10:00:00', string $edited = '2025-01-01 10:00:00', bool $hashed = true): int
    {
        $table = DataObject::getSchema()->tableName(FourOhFourLog::class);
        SQLInsert::create("\"$table\"", [
            '"ClassName"' => FourOhFourLog::class,
            '"Created"' => $created,
            '"LastEdited"' => $edited,
            '"Link"' => $link,
            '"Referrer"' => $ref,
            '"LinkHash"' => $hashed ? FourOhFourLog::linkHash($link, $ref) : null,
            '"Category"' => 'page',
            '"Count"' => $count,
        ])->execute();

        return (int) DB::get_generated_id($table);
    }

    private function insertMonth(int $logId, int $yearMonth, int $count): void
    {
        $table = DataObject::getSchema()->tableName(FourOhFourLogMonth::class);
        SQLInsert::create("\"$table\"", [
            '"ClassName"' => FourOhFourLogMonth::class,
            '"LogID"' => $logId,
            '"YearMonth"' => $yearMonth,
            '"Count"' => $count,
        ])->execute();
    }

    private function rows(): array
    {
        $rows = [];
        foreach (SQLSelect::create(
            ['"ID"', '"Link"', '"Referrer"', '"Count"', '"Created"', '"LastEdited"', '"LinkHash"'],
            '"FourOhFourLog"',
            [],
            ['"ID"' => 'ASC']
        )->execute() as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<int, int> YearMonth => Count for one log row
     */
    private function months(int $logId): array
    {
        $months = [];
        foreach (SQLSelect::create(['"YearMonth"', '"Count"'], '"FourOhFourLogMonth"', ['"LogID"' => $logId], ['"YearMonth"' => 'ASC'])->execute() as $row) {
            $months[(int) $row['YearMonth']] = (int) $row['Count'];
        }

        return $months;
    }

    public function testTaskLoadsOnThisMajor()
    {
        $task = FourOhFourLogReferrerDecodeTask::create();
        $this->assertInstanceOf(BuildTask::class, $task);
        $this->assertNotEmpty($task->getTitle());
    }

    /**
     * The fixture strings are what the pre-3.4 logger made of each other.
     */
    public function testFixtureMatchesThePre34Encoding()
    {
        $this->assertSame(self::ENCODED, htmlentities(self::RAW, ENT_QUOTES, 'UTF-8'));
    }

    public function testEncodedReferrerIsDecodedAndRehashedKeepingTimestamps()
    {
        $this->insertRow('old/page', self::ENCODED, 4, '2024-01-01 10:00:00', '2025-01-01 10:00:00');

        $this->assertSame(['scanned' => 1, 'decoded' => 1, 'merged' => 0], $this->runTask());

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(self::RAW, $rows[0]['Referrer']);
        $this->assertSame(FourOhFourLog::linkHash('old/page', self::RAW), $rows[0]['LinkHash']);
        $this->assertSame(4, (int) $rows[0]['Count']);
        $this->assertSame('2025-01-01 10:00:00', $rows[0]['LastEdited']);
    }

    /**
     * The upgrade case: a hit since 3.4 logged the referrer as sent in a row of its own. The old
     * row is merged into it: summed count, earliest Created, latest LastEdited, months added up.
     */
    public function testOldRowIsMergedIntoTheRowLoggedSinceTheUpgrade()
    {
        $old = $this->insertRow('old/page', self::ENCODED, 3, '2024-01-01 10:00:00', '2026-09-20 10:00:00');
        $this->insertMonth($old, 202609, 3);
        $new = $this->insertRow('old/page', self::RAW, 2, '2026-09-25 10:00:00', '2026-10-02 10:00:00');
        $this->insertMonth($new, 202609, 1);
        $this->insertMonth($new, 202610, 1);

        $this->assertSame(['scanned' => 2, 'decoded' => 0, 'merged' => 1], $this->runTask());

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame($new, (int) $rows[0]['ID']);
        $this->assertSame(self::RAW, $rows[0]['Referrer']);
        $this->assertSame(5, (int) $rows[0]['Count']);
        $this->assertSame('2024-01-01 10:00:00', $rows[0]['Created']);
        $this->assertSame('2026-10-02 10:00:00', $rows[0]['LastEdited']);
        $this->assertSame([202609 => 4, 202610 => 1], $this->months($new));
        $this->assertSame([], $this->months($old), 'no month row is left pointing at the deleted row');
    }

    /**
     * A month the merged row has and the target does not is moved over, not lost.
     */
    public function testMonthOnlyTheOldRowHasIsMovedOver()
    {
        $old = $this->insertRow('old/page', self::ENCODED, 2);
        $this->insertMonth($old, 202608, 2);
        $new = $this->insertRow('old/page', self::RAW, 1);
        $this->insertMonth($new, 202610, 1);

        $this->runTask();

        $this->assertSame([202608 => 2, 202610 => 1], $this->months($new));
    }

    /**
     * Only a value that IS the old encoding of something is decoded: a referrer stored as sent
     * since 3.4, 'unknown' and a value that is only partly entity-encoded stay as they are.
     */
    public function testReferrersNotProducedByTheOldEncodingAreLeftAlone()
    {
        $kept = [
            'unknown',
            'https://elsewhere.example/links?a=1&b=2',
            'https://elsewhere.example/links?a=1&amp;b=2&c=3',
            'https://elsewhere.example/plain',
        ];
        foreach ($kept as $i => $ref) {
            $this->insertRow('page-' . $i, $ref, 1);
        }

        $stats = $this->runTask();

        $this->assertSame(0, $stats['decoded'] + $stats['merged']);
        $this->assertSame($kept, array_column($this->rows(), 'Referrer'));
    }

    /**
     * A row from before 3.1 (no hash) gets the decoded referrer only; FourOhFourLogMergeTask
     * hashes it with that.
     */
    public function testUnhashedRowGetsOnlyTheDecodedReferrer()
    {
        $this->insertRow('/old/page', self::ENCODED, 1, '2019-01-01 10:00:00', '2020-01-01 10:00:00', false);

        $this->runTask();

        $rows = $this->rows();
        $this->assertSame(self::RAW, $rows[0]['Referrer']);
        $this->assertNull($rows[0]['LinkHash']);
        $this->assertSame('/old/page', $rows[0]['Link']);
        $this->assertSame('2020-01-01 10:00:00', $rows[0]['LastEdited']);
    }

    public function testDryRunCountsButChangesNothing()
    {
        $this->insertRow('one/page', self::ENCODED, 1);
        $this->insertRow('two/page', self::ENCODED, 1);
        $this->insertRow('two/page', self::RAW, 1);
        $before = $this->rows();

        $this->assertSame(['scanned' => 3, 'decoded' => 1, 'merged' => 1], $this->runTask(true));
        $this->assertSame($before, $this->rows());
    }

    public function testSecondRunFindsNothingToDo()
    {
        $this->insertRow('one/page', self::ENCODED, 1);
        $this->insertRow('two/page', self::ENCODED, 1);
        $this->insertRow('two/page', self::RAW, 1);
        $this->runTask();
        $after = $this->rows();

        $stats = $this->runTask();

        $this->assertSame(0, $stats['decoded'] + $stats['merged']);
        $this->assertSame($after, $this->rows());
    }

    public function testSmallChunksGiveTheSameResult()
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $link) {
            $this->insertRow($link, self::ENCODED, 1);
        }
        $this->insertRow('c', self::RAW, 1);

        $this->assertSame(['scanned' => 6, 'decoded' => 4, 'merged' => 1], $this->runTask(false, 2));
        $this->assertSame(array_fill(0, 5, self::RAW), array_column($this->rows(), 'Referrer'));
    }

    /**
     * What the task is for: after it, a new hit with the same referrer counts on the old row
     * instead of starting a new one.
     */
    public function testLogHitAfterTheTaskCountsOnTheOldRow()
    {
        $id = $this->insertRow('old/page', self::ENCODED, 3);

        $this->runTask();
        FourOhFourLog::logHit('old/page', self::RAW);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame($id, (int) $rows[0]['ID']);
        $this->assertSame(4, (int) $rows[0]['Count']);
    }
}
