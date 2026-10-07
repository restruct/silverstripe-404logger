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
 * Version-specific entry point for FourOhFourLogMergeTask, declared in this file rather than in a
 * file of its own because this module has no Composer autoload section: its classes are found
 * through Silverstripe's class manifest, which only sees top-level declarations, so a trait
 * declared inside an `if` would never be autoloaded. Declared here, it exists by the time PHP
 * reaches the class declaration below (a class that uses a trait is declared at runtime, in file
 * order).
 *
 * Why a trait at all: BuildTask changed shape between Silverstripe 5 and 6 in ways one class body
 * cannot satisfy - SS5 has an abstract run($request) and untyped $title/$description, SS6 an
 * abstract execute(InputInterface, PolyOutput): int, a typed $title and a static $description.
 * Two task classes guarded by major do not work either: TaskRunner reflects every BuildTask
 * descendant in the manifest, and one that was never declared breaks the whole task list.
 * PolyOutput exists only on Silverstripe 6, so its presence is the version switch. The `use`
 * imports above are inert on SS5: an import never autoloads.
 */
if (class_exists(PolyOutput::class)) {
    /**
     * Silverstripe 6: `sake tasks:FourOhFourLogMergeTask [--dry-run] [--chunk-size=N]`.
     */
    trait FourOhFourLogMergeTaskEntryPoint
    {
        public static function getDescription(): string
        {
            return _t(static::class . '.description', FourOhFourLogMergeTask::DESCRIPTION);
        }

        public function getOptions(): array
        {
            return [
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would change'),
                new InputOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Rows read per batch', FourOhFourLogMergeTask::DEFAULT_CHUNK_SIZE),
            ];
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            $this->merge(
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
     * Silverstripe 5: `sake dev/tasks/FourOhFourLogMergeTask [dry-run=1] [chunk-size=N]`.
     */
    trait FourOhFourLogMergeTaskEntryPoint
    {
        /**
         * @return string
         */
        public function getDescription()
        {
            return FourOhFourLogMergeTask::DESCRIPTION;
        }

        /**
         * @param \SilverStripe\Control\HTTPRequest $request
         * @return void
         */
        public function run($request)
        {
            $cli = Director::is_cli();
            $this->merge(
                (bool) $request->getVar('dry-run'),
                (int) ($request->getVar('chunk-size') ?: FourOhFourLogMergeTask::DEFAULT_CHUNK_SIZE),
                function (string $line) use ($cli): void {
                    echo $cli ? $line . PHP_EOL : '<p>' . Convert::raw2xml($line) . "</p>\n";
                }
            );
        }
    }
}

/**
 * One-off upgrade task for 3.1: fills in FourOhFourLog.LinkHash and Category on rows logged
 * before 3.1, and merges the rows that turn out to be one link + referrer - in practice the
 * '/path' and 'path' pairs left by the change in how the request URL was stored. A merged row
 * keeps the sum of the counts, the earliest Created and the latest LastEdited.
 *
 * Idempotent: it only reads rows whose LinkHash is still NULL, and every row it handles either
 * gets its hash or is merged away, so a second run finds nothing to do. It works through the
 * table in ID order, a chunk at a time, so it runs on tables of any size in bounded memory, and
 * can be stopped and run again.
 *
 * Rows of a category that is now ignored are kept and categorised, not deleted: removing them is
 * a retention decision, not an upgrade step.
 */
class FourOhFourLogMergeTask extends BuildTask
{
    use FourOhFourLogMergeTaskEntryPoint;

    const DESCRIPTION = 'Upgrade to 404logger 3.1: fills in LinkHash and Category on existing 404 log rows and merges duplicates (eg "/path" and "path" from the same referrer). Safe to run more than once.';

    const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * Silverstripe 5 URL segment (dev/tasks/FourOhFourLogMergeTask); inert on Silverstripe 6.
     */
    private static $segment = 'FourOhFourLogMergeTask';

    /**
     * Silverstripe 6 command name (sake tasks:FourOhFourLogMergeTask). A new static on SS5, a
     * compatible redeclaration of PolyCommand::$commandName on SS6.
     */
    protected static string $commandName = 'FourOhFourLogMergeTask';

    public function __construct()
    {
        parent::__construct();
        # Assigned rather than declared: BuildTask::$title is untyped on SS5 and `string` on SS6.
        $this->title = '404 log: fill in LinkHash and merge duplicate rows';
    }

    /**
     * Version-neutral body of the task.
     *
     * @param bool $dryRun Only count what would change
     * @param int $chunkSize Rows read per batch
     * @param callable $writeln function (string $line): void
     * @return array{scanned: int, hashed: int, merged: int}
     */
    public function merge(bool $dryRun, int $chunkSize, callable $writeln): array
    {
        $chunkSize = $chunkSize > 0 ? $chunkSize : self::DEFAULT_CHUNK_SIZE;
        $table = DataObject::getSchema()->tableName(FourOhFourLog::class);
        $stats = ['scanned' => 0, 'hashed' => 0, 'merged' => 0];
        # Dry run only: hash => true for every row that WOULD have been given that hash in this
        # run, standing in for the writes a real run makes, so later duplicates count as merges.
        $wouldHash = [];
        $lastId = 0;

        if ($dryRun) {
            $writeln('DRY RUN - nothing is written.');
        }

        do {
            # Keyset paging on ID: stable while rows are updated and deleted behind the cursor,
            # and no OFFSET scan on a large table.
            $rows = SQLSelect::create(
                ['"ID"', '"Link"', '"Referrer"', '"Count"', '"Created"', '"LastEdited"'],
                "\"$table\"",
                ['"LinkHash" IS NULL', '"ID" > ?' => $lastId],
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

            # One transaction per chunk: far fewer commits than one per row, and a chunk is
            # applied entirely or not at all if the run is interrupted.
            $apply = function () use ($batch, $table, $dryRun, &$wouldHash, &$stats) {
                foreach ($batch as $row) {
                    $stats['scanned']++;
                    $this->mergeRow($row, $table, $dryRun, $wouldHash, $stats);
                }
            };
            if ($dryRun || !DB::get_conn()->supportsTransactions()) {
                $apply();
            } else {
                DB::get_conn()->withTransaction($apply);
            }

            $lastId = (int) end($batch)['ID'];
            $writeln(sprintf(
                'Up to ID %d: %d rows read, %d given a hash, %d merged into another row',
                $lastId,
                $stats['scanned'],
                $stats['hashed'],
                $stats['merged']
            ));
        } while (count($batch) === $chunkSize);

        $writeln(sprintf(
            '%s %d rows: %d %s a hash, %d %s merged into a row for the same link and referrer.',
            $dryRun ? 'Would process' : 'Processed',
            $stats['scanned'],
            $stats['hashed'],
            $dryRun ? 'would get' : 'got',
            $stats['merged'],
            $dryRun ? 'would be' : 'were'
        ));

        return $stats;
    }

    /**
     * Give one unhashed row its hash and category, or merge it into the row that already has
     * that hash. Written with SQL rather than DataObject::write(), which would set LastEdited to
     * now and so destroy the "most recent hit" this task is meant to keep.
     *
     * @param array $row
     * @param string $table
     * @param bool $dryRun
     * @param array $wouldHash
     * @param array $stats
     */
    protected function mergeRow(array $row, string $table, bool $dryRun, array &$wouldHash, array &$stats): void
    {
        # Same normalisation as FourOhFourLog::logHit(), so the hash is the one a new hit finds.
        $link = FourOhFourLog::normaliseLink((string) $row['Link']);
        $link = $this->fitToField('Link', $link);
        $ref = (string) $row['Referrer'];
        $hash = FourOhFourLog::linkHash($link, $ref);
        $category = $this->fitToField('Category', FourOhFourLog::categorise($link));

        $target = SQLSelect::create(
            ['"ID"', '"Created"', '"LastEdited"'],
            "\"$table\"",
            ['"LinkHash"' => $hash]
        )->execute()->record();

        if ($dryRun) {
            if ($target || isset($wouldHash[$hash])) {
                $stats['merged']++;
            } else {
                $wouldHash[$hash] = true;
                $stats['hashed']++;
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
            SQLDelete::create("\"$table\"", ['"ID"' => (int) $row['ID']])->execute();
            $stats['merged']++;
            return;
        }

        SQLUpdate::create(
            "\"$table\"",
            [
                '"Link"' => $link,
                '"LinkHash"' => $hash,
                '"Category"' => $category,
            ],
            ['"ID"' => (int) $row['ID']]
        )->execute();
        $stats['hashed']++;
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
