<?php

use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/*
 * Version-specific entry point for LogPurgeTask, declared in this file for the reasons given at
 * FourOhFourLogMergeTaskEntryPoint (no Composer autoload in this module, so a trait in a file of
 * its own inside an `if` would never be found; BuildTask differs between Silverstripe 5 and 6;
 * PolyOutput exists only on 6). The `use` imports above are inert on Silverstripe 5.
 */
if (class_exists(PolyOutput::class)) {
    /**
     * Silverstripe 6: `sake tasks:LogPurgeTask [--older-than=N] [--ignored] [--noise] [--dry-run]`.
     */
    trait LogPurgeTaskEntryPoint
    {
        public static function getDescription(): string
        {
            return _t(static::class . '.description', LogPurgeTask::DESCRIPTION);
        }

        public function getOptions(): array
        {
            return [
                new InputOption('older-than', null, InputOption::VALUE_REQUIRED, 'Delete rows whose last hit is more than N months ago (and monthly counts older than that)'),
                new InputOption('ignored', null, InputOption::VALUE_NONE, 'Delete 404 rows that the current ignore rules would no longer log'),
                new InputOption('noise', null, InputOption::VALUE_NONE, 'Delete search rows that look like noise'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be deleted'),
                new InputOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Rows read per batch', LogPurgeTask::DEFAULT_CHUNK_SIZE),
            ];
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            $olderThan = $input->getOption('older-than');
            $this->purge(
                $olderThan === null || $olderThan === '' ? null : (int) $olderThan,
                (bool) $input->getOption('ignored'),
                (bool) $input->getOption('noise'),
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
     * Silverstripe 5: `sake dev/tasks/LogPurgeTask [older-than=N] [ignored=1] [noise=1] [dry-run=1]`.
     */
    trait LogPurgeTaskEntryPoint
    {
        /**
         * @return string
         */
        public function getDescription()
        {
            return LogPurgeTask::DESCRIPTION;
        }

        /**
         * @param \SilverStripe\Control\HTTPRequest $request
         * @return void
         */
        public function run($request)
        {
            $cli = Director::is_cli();
            $olderThan = $request->getVar('older-than');
            $this->purge(
                $olderThan === null || $olderThan === '' ? null : (int) $olderThan,
                (bool) $request->getVar('ignored'),
                (bool) $request->getVar('noise'),
                (bool) $request->getVar('dry-run'),
                (int) ($request->getVar('chunk-size') ?: LogPurgeTask::DEFAULT_CHUNK_SIZE),
                function (string $line) use ($cli): void {
                    echo $cli ? $line . PHP_EOL : '<p>' . Convert::raw2xml($line) . "</p>\n";
                }
            );
        }
    }
}

/**
 * Retention for the 404 and search logs. Each criterion is opt-in; without any, the task only
 * prints how to use it, so a run without arguments never deletes anything.
 *
 * - older-than=N: delete FourOhFourLog and SearchLog rows whose last hit (LastEdited) is more
 *   than N months ago, and monthly counts of months before that, for every row.
 * - ignored: delete FourOhFourLog rows that the current ignore rules would not log any more
 *   (FourOhFourLog::isIgnored(): ignored categories, ignore_patterns, the CMS ignore list) -
 *   eg after switching a category to ignored, or for the rows of links ignored from the report.
 * - noise: delete SearchLog rows that look like noise (SearchLog::looksLikeNoise()), eg rows
 *   logged before 3.1's noise filter.
 *
 * Deleting a row deletes its monthly counts. Chunked by ID like FourOhFourLogMergeTask, with
 * plain SQL deletes (no per-row ORM delete on a table of 50,000 rows); safe to stop and rerun.
 */
class LogPurgeTask extends BuildTask
{
    use LogPurgeTaskEntryPoint;

    const DESCRIPTION = 'Retention for the 404 and search logs: delete rows older than N months (older-than), 404 rows that are ignored now (ignored), search rows that look like noise (noise). Nothing is deleted without one of these; use dry-run first.';

    const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * Silverstripe 5 URL segment (dev/tasks/LogPurgeTask); inert on Silverstripe 6.
     */
    private static $segment = 'LogPurgeTask';

    /**
     * Silverstripe 6 command name (sake tasks:LogPurgeTask).
     */
    protected static string $commandName = 'LogPurgeTask';

    public function __construct()
    {
        parent::__construct();
        # Assigned rather than declared: BuildTask::$title is untyped on SS5 and `string` on SS6.
        $this->title = '404 and search log: purge old, ignored and noise rows';
    }

    /**
     * Version-neutral body of the task.
     *
     * @param int|null $olderThanMonths
     * @param bool $ignored
     * @param bool $noise
     * @param bool $dryRun
     * @param int $chunkSize
     * @param callable $writeln function (string $line): void
     * @return array{fourohfour: int, search: int, months: int} Rows (that would be) deleted
     */
    public function purge($olderThanMonths, bool $ignored, bool $noise, bool $dryRun, int $chunkSize, callable $writeln): array
    {
        $stats = ['fourohfour' => 0, 'search' => 0, 'months' => 0];
        $chunkSize = $chunkSize > 0 ? $chunkSize : self::DEFAULT_CHUNK_SIZE;
        if ($olderThanMonths !== null && $olderThanMonths < 1) {
            $writeln('older-than must be a number of months, 1 or more. Nothing deleted.');
            return $stats;
        }
        if ($olderThanMonths === null && !$ignored && !$noise) {
            $writeln('Nothing to do: pass older-than=N (months), ignored and/or noise. Add dry-run to see the counts first.');
            return $stats;
        }
        if ($dryRun) {
            $writeln('DRY RUN - nothing is deleted.');
        }

        $cutoff = null;
        if ($olderThanMonths !== null) {
            $now = DBDatetime::now()->getTimestamp();
            $cutoff = date('Y-m-d H:i:s', strtotime(sprintf('-%d months', $olderThanMonths), $now));
        }

        # Monthly counts of months before the cutoff, also of rows that are kept. First, so the
        # row passes below only count months from the cutoff on and a dry run adds up the same.
        if ($cutoff !== null) {
            $fromMonth = HitMonthCounter::yearMonth(strtotime($cutoff));
            foreach ([FourOhFourLogMonth::class, SearchLogMonth::class] as $monthClass) {
                $table = DataObject::getSchema()->tableName($monthClass);
                $where = ['"YearMonth" < ?' => $fromMonth];
                $stats['months'] += (int) SQLSelect::create('COUNT(*)', "\"$table\"", $where)->execute()->value();
                if (!$dryRun) {
                    SQLDelete::create("\"$table\"", $where)->execute();
                }
            }
        }

        $stats['fourohfour'] = $this->purgeTable(
            FourOhFourLog::class,
            FourOhFourLogMonth::class,
            ['"Link"', '"Referrer"', '"Category"'],
            $cutoff,
            $ignored ? function (array $row) {
                return FourOhFourLog::isIgnored((string) $row['Link'], null, (string) $row['Referrer']);
            } : null,
            $dryRun,
            $chunkSize,
            $stats['months']
        );
        $stats['search'] = $this->purgeTable(
            SearchLog::class,
            SearchLogMonth::class,
            ['"Query"'],
            $cutoff,
            $noise ? function (array $row) {
                return SearchLog::looksLikeNoise((string) $row['Query']);
            } : null,
            $dryRun,
            $chunkSize,
            $stats['months']
        );

        $writeln(sprintf(
            '%s %d 404 rows, %d search rows and %d monthly counts.',
            $dryRun ? 'Would delete' : 'Deleted',
            $stats['fourohfour'],
            $stats['search'],
            $stats['months']
        ));

        return $stats;
    }

    /**
     * Delete the rows of one log table that are older than $cutoff or match $match, with their
     * monthly counts.
     *
     * @param string $logClass
     * @param string $monthClass
     * @param string[] $columns Extra columns $match needs
     * @param string|null $cutoff DB datetime; rows last hit before it go
     * @param callable|null $match function (array $row): bool
     * @param bool $dryRun
     * @param int $chunkSize
     * @param int $months Incremented by the monthly counts (that would be) deleted with the rows
     * @return int Rows (that would be) deleted
     */
    protected function purgeTable(string $logClass, string $monthClass, array $columns, ?string $cutoff, ?callable $match, bool $dryRun, int $chunkSize, int &$months): int
    {
        if ($cutoff === null && $match === null) {
            return 0;
        }

        $table = DataObject::getSchema()->tableName($logClass);
        $monthTable = DataObject::getSchema()->tableName($monthClass);
        $deleted = 0;
        $lastId = 0;

        do {
            # Keyset paging on ID, as in FourOhFourLogMergeTask: stable while rows behind the
            # cursor are deleted, no OFFSET scan.
            $rows = SQLSelect::create(
                array_merge(['"ID"', '"LastEdited"'], $columns),
                "\"$table\"",
                ['"ID" > ?' => $lastId],
                ['"ID"' => 'ASC'],
                [],
                [],
                $chunkSize
            )->execute();

            $batch = [];
            $ids = [];
            foreach ($rows as $row) {
                $batch[] = $row;
                $old = $cutoff !== null && (string) $row['LastEdited'] !== '' && strcmp((string) $row['LastEdited'], $cutoff) < 0;
                if ($old || ($match && $match($row))) {
                    $ids[] = (int) $row['ID'];
                }
            }
            if (!$batch) {
                break;
            }
            $lastId = (int) end($batch)['ID'];

            if ($ids) {
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                # With a cutoff, months before it are counted by purge()'s own pass, not here, so
                # a dry run reports the same total as the real run.
                $countWhere = ["\"LogID\" IN ($placeholders)" => $ids];
                if ($cutoff !== null) {
                    $countWhere['"YearMonth" >= ?'] = HitMonthCounter::yearMonth(strtotime($cutoff));
                }
                $months += (int) SQLSelect::create('COUNT(*)', "\"$monthTable\"", $countWhere)->execute()->value();
                if (!$dryRun) {
                    $delete = function () use ($table, $monthTable, $placeholders, $ids) {
                        SQLDelete::create("\"$monthTable\"", ["\"LogID\" IN ($placeholders)" => $ids])->execute();
                        SQLDelete::create("\"$table\"", ["\"ID\" IN ($placeholders)" => $ids])->execute();
                    };
                    if (DB::get_conn()->supportsTransactions()) {
                        DB::get_conn()->withTransaction($delete);
                    } else {
                        $delete();
                    }
                }
                $deleted += count($ids);
            }
        } while (count($batch) === $chunkSize);

        return $deleted;
    }
}
