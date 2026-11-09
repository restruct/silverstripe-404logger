<?php

use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/*
 * Version-specific entry point for FourOhFourLogReferrerDecodeTask, declared in this file for the
 * same reasons as FourOhFourLogMergeTaskEntryPoint (see FourOhFourLogMergeTask.php): BuildTask has
 * a different abstract shape on Silverstripe 5 and 6, the module's classes are found through the
 * class manifest only, and PolyOutput exists only on Silverstripe 6.
 */
if (class_exists(PolyOutput::class)) {
    /**
     * Silverstripe 6: `sake tasks:FourOhFourLogReferrerDecodeTask [--dry-run] [--chunk-size=N]`.
     */
    trait FourOhFourLogReferrerDecodeTaskEntryPoint
    {
        public static function getDescription(): string
        {
            return _t(static::class . '.description', FourOhFourLogReferrerDecodeTask::DESCRIPTION);
        }

        public function getOptions(): array
        {
            return [
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would change'),
                new InputOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Rows read per batch', FourOhFourLogReferrerDecodeTask::DEFAULT_CHUNK_SIZE),
            ];
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            $this->decode(
                (bool) $input->getOption('dry-run'),
                (int) $input->getOption('chunk-size'),
                function (string $line) use ($output): void {
                    $output->writeln($line);
                }
            );

            return Command::SUCCESS;
        }
    }
} else {
    /**
     * Silverstripe 5: `sake dev/tasks/FourOhFourLogReferrerDecodeTask [dry-run=1] [chunk-size=N]`.
     */
    trait FourOhFourLogReferrerDecodeTaskEntryPoint
    {
        /**
         * @return string
         */
        public function getDescription()
        {
            return FourOhFourLogReferrerDecodeTask::DESCRIPTION;
        }

        /**
         * @param \SilverStripe\Control\HTTPRequest $request
         * @return void
         */
        public function run($request)
        {
            $cli = Director::is_cli();
            $this->decode(
                (bool) $request->getVar('dry-run'),
                (int) ($request->getVar('chunk-size') ?: FourOhFourLogReferrerDecodeTask::DEFAULT_CHUNK_SIZE),
                function (string $line) use ($cli): void {
                    echo $cli ? $line . PHP_EOL : '<p>' . Convert::raw2xml($line) . "</p>\n";
                }
            );
        }
    }
}

/**
 * One-off upgrade task for 3.4 (#6): decodes FourOhFourLog.Referrer on rows logged before 3.4,
 * which FourOhFourLogger stored htmlentities()-encoded. 3.4 stores the referrer as sent, so
 * without this task a referrer with '&' or quotes would get a second row after upgrading (its
 * LinkHash covers the referrer) and the old row would show the encoded text.
 *
 * Which rows: only a referrer that is EXACTLY what htmlentities($value, ENT_QUOTES, 'UTF-8') made
 * of some value, and that differs from that value, is decoded. So 'unknown', a referrer without
 * entities and a referrer stored as sent since 3.4 ('?a=1&b=2', which no encoding produces) are
 * left alone, and a second run finds nothing to do. The exception is a referrer logged since 3.4
 * whose URL itself contains an HTML entity ('&amp;' literally), which looks encoded and is
 * decoded; browsers practically never send one.
 *
 * Where the decoded referrer is already logged for the same link (a hit since the upgrade made
 * the new row), the old row is merged into that one, as FourOhFourLogMergeTask merges: summed
 * Count, earliest Created, latest LastEdited, and its month counts added to the other row's. The
 * row it is merged into keeps its own Category and HandledAs. Rows from before 3.1 (no LinkHash
 * yet) only get the decoded referrer; FourOhFourLogMergeTask hashes and merges them, before or
 * after this task.
 *
 * Works through the table in ID order a chunk at a time, one transaction per chunk, so it runs on
 * tables of any size in bounded memory and can be stopped and run again.
 */
class FourOhFourLogReferrerDecodeTask extends BuildTask
{
    use FourOhFourLogReferrerDecodeTaskEntryPoint;

    const DESCRIPTION = 'Upgrade to 404logger 3.4: decodes referrers that older versions stored HTML-encoded, and merges them into rows logged for the same link and referrer since. Safe to run more than once.';

    const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * Silverstripe 5 URL segment (dev/tasks/FourOhFourLogReferrerDecodeTask); inert on Silverstripe 6.
     */
    private static $segment = 'FourOhFourLogReferrerDecodeTask';

    /**
     * Silverstripe 6 command name (sake tasks:FourOhFourLogReferrerDecodeTask). A new static on
     * SS5, a compatible redeclaration of PolyCommand::$commandName on SS6.
     */
    protected static string $commandName = 'FourOhFourLogReferrerDecodeTask';

    public function __construct()
    {
        parent::__construct();
        # Assigned rather than declared: BuildTask::$title is untyped on SS5 and `string` on SS6.
        $this->title = '404 log: decode HTML-encoded referrers';
    }

    /**
     * The referrer as sent, when $stored is what FourOhFourLogger before 3.4 stored for it; null
     * when $stored is not such a value (nothing to decode, or not produced by htmlentities()).
     *
     * @param string $stored
     * @return string|null
     */
    public static function decodedReferrer(string $stored): ?string
    {
        $decoded = html_entity_decode($stored, ENT_QUOTES, 'UTF-8');
        if ($decoded === $stored || htmlentities($decoded, ENT_QUOTES, 'UTF-8') !== $stored) {
            return null;
        }

        return $decoded;
    }

    /**
     * Version-neutral body of the task.
     *
     * @param bool $dryRun Only count what would change
     * @param int $chunkSize Rows read per batch
     * @param callable $writeln function (string $line): void
     * @return array{scanned: int, decoded: int, merged: int}
     */
    public function decode(bool $dryRun, int $chunkSize, callable $writeln): array
    {
        $chunkSize = $chunkSize > 0 ? $chunkSize : self::DEFAULT_CHUNK_SIZE;
        $table = DataObject::getSchema()->tableName(FourOhFourLog::class);
        $stats = ['scanned' => 0, 'decoded' => 0, 'merged' => 0];
        # Dry run only: hash => true for every row that WOULD have been given that hash in this
        # run, standing in for the writes a real run makes, so later duplicates count as merges.
        $wouldHash = [];
        $lastId = 0;

        if ($dryRun) {
            $writeln('DRY RUN - nothing is written.');
        }

        do {
            # Keyset paging on ID, as in FourOhFourLogMergeTask. Every encoded value contains '&',
            # so only those rows are read.
            $rows = SQLSelect::create(
                ['"ID"', '"Link"', '"Referrer"', '"LinkHash"', '"Count"', '"Created"', '"LastEdited"'],
                "\"$table\"",
                ['"Referrer" LIKE ?' => '%&%', '"ID" > ?' => $lastId],
                ['"ID"' => 'ASC'],
                [],
                [],
                $chunkSize
            )->execute();

            $batch = [];
            foreach ($rows as $row) {
                $batch[] = $row;
            }
            if (!$batch) {
                break;
            }

            $apply = function () use ($batch, $table, $dryRun, &$wouldHash, &$stats) {
                foreach ($batch as $row) {
                    $stats['scanned']++;
                    $this->decodeRow($row, $table, $dryRun, $wouldHash, $stats);
                }
            };
            if ($dryRun || !DB::get_conn()->supportsTransactions()) {
                $apply();
            } else {
                DB::get_conn()->withTransaction($apply);
            }

            $lastId = (int) end($batch)['ID'];
            $writeln(sprintf(
                'Up to ID %d: %d rows with "&" read, %d decoded, %d merged into another row',
                $lastId,
                $stats['scanned'],
                $stats['decoded'],
                $stats['merged']
            ));
        } while (count($batch) === $chunkSize);

        $writeln(sprintf(
            '%s %d rows with "&" in the referrer: %d %s decoded, %d %s merged into a row for the same link and referrer.',
            $dryRun ? 'Would process' : 'Processed',
            $stats['scanned'],
            $stats['decoded'],
            $dryRun ? 'would be' : 'were',
            $stats['merged'],
            $dryRun ? 'would be' : 'were'
        ));

        return $stats;
    }

    /**
     * Decode one row's referrer, or merge the row into the row that already has the decoded
     * referrer's hash. SQL rather than DataObject::write(), which would set LastEdited to now.
     *
     * @param array $row
     * @param string $table
     * @param bool $dryRun
     * @param array $wouldHash
     * @param array $stats
     */
    protected function decodeRow(array $row, string $table, bool $dryRun, array &$wouldHash, array &$stats): void
    {
        $decoded = static::decodedReferrer((string) $row['Referrer']);
        if ($decoded === null) {
            return;
        }
        # Never longer than the encoded value, which fitted the column, but kept to the same rule
        $decoded = $this->fitToField('Referrer', $decoded);

        # Before 3.1 (no hash yet): the decoded referrer is all; FourOhFourLogMergeTask hashes it
        if ($row['LinkHash'] === null || $row['LinkHash'] === '') {
            if (!$dryRun) {
                SQLUpdate::create("\"$table\"", ['"Referrer"' => $decoded], ['"ID"' => (int) $row['ID']])->execute();
            }
            $stats['decoded']++;
            return;
        }

        # The stored Link is already normalised and fitted to its column (logHit(), merge task)
        $hash = FourOhFourLog::linkHash((string) $row['Link'], $decoded);
        $target = SQLSelect::create(
            ['"ID"', '"Created"', '"LastEdited"'],
            "\"$table\"",
            ['"LinkHash"' => $hash, '"ID" <> ?' => (int) $row['ID']]
        )->execute()->record();

        if ($dryRun) {
            if ($target || isset($wouldHash[$hash])) {
                $stats['merged']++;
            } else {
                $wouldHash[$hash] = true;
                $stats['decoded']++;
            }
            return;
        }

        if ($target) {
            SQLUpdate::create(
                "\"$table\"",
                [
                    '"Count"' => ['"Count" + ?' => [(int) $row['Count']]],
                    '"Created"' => $this->earliest($target['Created'], $row['Created']),
                    '"LastEdited"' => $this->latest($target['LastEdited'], $row['LastEdited']),
                ],
                ['"ID"' => (int) $target['ID']]
            )->execute();
            $this->moveMonths((int) $row['ID'], (int) $target['ID']);
            SQLDelete::create("\"$table\"", ['"ID"' => (int) $row['ID']])->execute();
            $stats['merged']++;
            return;
        }

        SQLUpdate::create(
            "\"$table\"",
            ['"Referrer"' => $decoded, '"LinkHash"' => $hash],
            ['"ID"' => (int) $row['ID']]
        )->execute();
        $stats['decoded']++;
    }

    /**
     * Move a merged row's month counts (FourOhFourLogMonth) to the row it was merged into: added
     * to that row's count for the same month, or moved over where it has none. Done month by
     * month because (LogID, YearMonth) is UNIQUE.
     *
     * @param int $fromId
     * @param int $toId
     */
    protected function moveMonths(int $fromId, int $toId): void
    {
        $monthTable = DataObject::getSchema()->tableName(FourOhFourLogMonth::class);
        $months = SQLSelect::create(['"ID"', '"YearMonth"', '"Count"'], "\"$monthTable\"", ['"LogID"' => $fromId])->execute();
        foreach ($months as $month) {
            $existing = SQLSelect::create(
                ['"ID"'],
                "\"$monthTable\"",
                ['"LogID"' => $toId, '"YearMonth"' => (int) $month['YearMonth']]
            )->execute()->value();
            if ($existing) {
                SQLUpdate::create(
                    "\"$monthTable\"",
                    ['"Count"' => ['"Count" + ?' => [(int) $month['Count']]]],
                    ['"ID"' => (int) $existing]
                )->execute();
                SQLDelete::create("\"$monthTable\"", ['"ID"' => (int) $month['ID']])->execute();
            } else {
                SQLUpdate::create("\"$monthTable\"", ['"LogID"' => $toId], ['"ID"' => (int) $month['ID']])->execute();
            }
        }
    }

    /**
     * @param string $fieldName
     * @param string $value
     * @return string
     */
    protected function fitToField(string $fieldName, string $value): string
    {
        $size = (int) FourOhFourLog::singleton()->dbObject($fieldName)->getSize();
        return $size > 0 ? mb_substr($value, 0, $size) : $value;
    }

    /**
     * The earlier of two DB datetimes ('Y-m-d H:i:s' sorts as a string); null-safe.
     */
    protected function earliest(?string $a, ?string $b): ?string
    {
        if ($a === null || $a === '') {
            return $b;
        }
        if ($b === null || $b === '') {
            return $a;
        }
        return strcmp($a, $b) <= 0 ? $a : $b;
    }

    /**
     * The later of two DB datetimes; null-safe.
     */
    protected function latest(?string $a, ?string $b): ?string
    {
        if ($a === null || $a === '') {
            return $b;
        }
        if ($b === null || $b === '') {
            return $a;
        }
        return strcmp($a, $b) >= 0 ? $a : $b;
    }
}
