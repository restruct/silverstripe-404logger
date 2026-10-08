<?php

namespace Restruct\FourOhFourLogger\Tests;

use SearchLog;
use SearchQueryReport;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLInsert;
use SilverStripe\Reports\Report;
use SilverStripe\Reports\ReportAdmin;

/**
 * 3.2 search terms report: SearchLog::termStats() (hits per active year, recency band, new,
 * faded, noise) and the report's filters, "hits this year" and sorting.
 */
class SearchTermsReportTest extends SapphireTest
{
    protected $usesDatabase = true;

    const NOW = '2026-10-08 12:00:00';

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    private function stats(string $query, int $count, string $first, string $last): array
    {
        return SearchLog::termStats($query, $count, $first, $last, strtotime(self::NOW));
    }

    public function testHitsPerActiveYearDividesByTheYearsBetweenFirstAndLastHit()
    {
        # 7 years active: 700 / 7.
        $this->assertSame(100.0, $this->stats('welding', 700, '2019-10-08 12:00:00', '2026-10-08 06:00:00')['PerActiveYear']);
        # Half a year counts as one year (the minimum), not as "twice the hits a year".
        $this->assertSame(50.0, $this->stats('new thing', 50, '2026-03-01 00:00:00', '2026-09-01 00:00:00')['PerActiveYear']);
        # One single hit.
        $this->assertSame(1.0, $this->stats('once', 1, '2024-05-05 05:05:05', '2024-05-05 05:05:05')['PerActiveYear']);
        # 1.5 years: rounded to one decimal.
        $this->assertSame(66.7, $this->stats('x term', 100, '2025-01-01 00:00:00', '2026-07-02 12:00:00')['PerActiveYear']);
    }

    public function testRecencyBandIsTheYearOfTheLastHit()
    {
        $this->assertSame(SearchLog::BAND_THIS_YEAR, $this->stats('a', 1, '2020-01-01 00:00:00', '2026-01-01 00:00:01')['Band']);
        $this->assertSame(SearchLog::BAND_LAST_YEAR, $this->stats('a', 1, '2020-01-01 00:00:00', '2025-12-31 23:59:59')['Band']);
        $this->assertSame(SearchLog::BAND_2_3_YEARS, $this->stats('a', 1, '2020-01-01 00:00:00', '2024-06-01 00:00:00')['Band']);
        $this->assertSame(SearchLog::BAND_2_3_YEARS, $this->stats('a', 1, '2020-01-01 00:00:00', '2023-01-01 00:00:00')['Band']);
        $this->assertSame(SearchLog::BAND_OLDER, $this->stats('a', 1, '2020-01-01 00:00:00', '2022-12-31 23:59:59')['Band']);
    }

    public function testNewAndFaded()
    {
        # First seen last year, still searched this year: new.
        $new = $this->stats('nlqf', 10, '2025-07-02 00:00:00', '2026-07-01 00:00:00');
        $this->assertTrue($new['IsNew']);
        $this->assertFalse($new['IsFaded']);
        # First seen this year: new.
        $this->assertTrue($this->stats('fresh', 3, '2026-02-01 00:00:00', '2026-09-01 00:00:00')['IsNew']);
        # Old and still searched: neither.
        $steady = $this->stats('plc', 900, '2019-08-30 00:00:00', '2026-07-10 00:00:00');
        $this->assertFalse($steady['IsNew']);
        $this->assertFalse($steady['IsFaded']);
        # Not searched this year: faded, and so not new even when it started last year.
        $faded = $this->stats('stap', 196, '2025-02-10 00:00:00', '2025-09-26 00:00:00');
        $this->assertTrue($faded['IsFaded']);
        $this->assertFalse($faded['IsNew']);
    }

    /**
     * The noise flag uses the noise rules even with the filter switched off, so rows logged
     * before 3.1 (or with ignore_noise: false) are flagged.
     */
    public function testNoiseFlagIgnoresTheIgnoreNoiseSwitch()
    {
        SearchLog::config()->set('ignore_noise', false);
        $this->assertFalse(SearchLog::isNoise('2026'), 'isNoise() still honours the switch');
        $this->assertTrue($this->stats('2026', 3, self::NOW, self::NOW)['IsNoise']);
        $this->assertTrue($this->stats("65'123", 3, self::NOW, self::NOW)['IsNoise']);
        $this->assertFalse($this->stats('c++', 3, self::NOW, self::NOW)['IsNoise']);
    }

    /**
     * Rows as an old log holds them: raw inserts, so Created/LastEdited are what the test says.
     */
    private function insertTerm(string $query, int $count, string $first, string $last): int
    {
        SQLInsert::create('"SearchLog"', [
            '"ClassName"' => SearchLog::class,
            '"Query"' => $query,
            '"Count"' => $count,
            '"Created"' => $first,
            '"LastEdited"' => $last,
        ])->execute();

        return (int) SearchLog::get()->filter('Query', $query)->first()->ID;
    }

    private function fixture(): void
    {
        $this->insertTerm('elektrotechniek', 700, '2019-10-08 00:00:00', '2026-07-01 00:00:00');
        $this->insertTerm('nlqf', 352, '2025-07-02 00:00:00', '2026-06-01 00:00:00');
        $this->insertTerm('ongelegeerd', 177, '2020-01-01 00:00:00', '2023-11-09 00:00:00');
        $this->insertTerm('middenkader', 58, '2019-09-01 00:00:00', '2020-02-10 00:00:00');
        $this->insertTerm('2024', 40, '2024-01-01 00:00:00', '2025-03-01 00:00:00');
        DBDatetime::set_mock_now(self::NOW);
    }

    private function queries(array $params, $sort = null): array
    {
        if (!class_exists(Report::class)) {
            $this->markTestSkipped('silverstripe/reports is not installed');
        }
        $list = SearchQueryReport::create()->sourceRecords($params);
        if ($sort) {
            $list = $list->sort($sort);
        }
        $queries = [];
        foreach ($list as $item) {
            $queries[] = (string) $item->Query;
        }

        return $queries;
    }

    public function testReportFiltersOnBandTrendAndNoise()
    {
        $this->fixture();

        # Noise is hidden by default.
        $this->assertEqualsCanonicalizing(['elektrotechniek', 'nlqf', 'ongelegeerd', 'middenkader'], $this->queries([]));
        $this->assertSame(['2024'], $this->queries(['Noise' => 'only']));
        $this->assertCount(5, $this->queries(['Noise' => 'show']));

        $this->assertEqualsCanonicalizing(['elektrotechniek', 'nlqf'], $this->queries(['Band' => SearchLog::BAND_THIS_YEAR]));
        $this->assertSame(['ongelegeerd'], $this->queries(['Band' => SearchLog::BAND_2_3_YEARS]));
        $this->assertSame(['middenkader'], $this->queries(['Band' => SearchLog::BAND_OLDER]));
        $this->assertSame(['nlqf'], $this->queries(['Trend' => 'new']));
        $this->assertEqualsCanonicalizing(['ongelegeerd', 'middenkader'], $this->queries(['Trend' => 'faded']));
        $this->assertSame(['2024'], $this->queries(['Trend' => 'faded', 'Band' => SearchLog::BAND_LAST_YEAR, 'Noise' => 'show']));
    }

    public function testReportRowsCarryTheFiguresAndSort()
    {
        $this->fixture();
        # Hits this year come from the monthly counts (logged since 3.2).
        SearchLog::logHit('elektrotechniek');
        SearchLog::logHit('elektrotechniek');

        $rows = [];
        foreach (SearchQueryReport::create()->sourceRecords([]) as $item) {
            $rows[$item->Query] = $item->toMap();
        }
        $this->assertSame(702, $rows['elektrotechniek']['Count']);
        $this->assertSame(2, $rows['elektrotechniek']['ThisYear']);
        $this->assertSame(0, $rows['nlqf']['ThisYear']);
        $this->assertSame('This year', $rows['nlqf']['Band']);
        $this->assertSame('new', $rows['nlqf']['Flags']);
        $this->assertSame('faded', $rows['middenkader']['Flags']);
        # 352 hits over 0.92 years: one active year.
        $this->assertSame(352.0, $rows['nlqf']['PerActiveYear']);

        $this->assertSame(['elektrotechniek', 'nlqf', 'ongelegeerd', 'middenkader'], $this->queries([], 'Count DESC'));
        $this->assertSame(['nlqf', 'elektrotechniek', 'middenkader', 'ongelegeerd'], $this->queries([], 'PerActiveYear DESC'));
        # Band sorts in time order through BandOrder, not alphabetically on its title.
        $this->assertSame(['nlqf', 'elektrotechniek', 'ongelegeerd', 'middenkader'], $this->queries([], ['BandOrder' => 'ASC', 'Count' => 'ASC']));
    }

    public function testReportFieldSortsBandInTimeOrderAndExportsTheFilteredList()
    {
        if (!class_exists(Report::class)) {
            $this->markTestSkipped('silverstripe/reports is not installed');
        }
        $this->fixture();
        $this->logInWithPermission('ADMIN');

        $report = SearchQueryReport::create();
        $this->assertSame(
            ['Count', 'Query', 'PerActiveYear', 'ThisYear', 'Band', 'Flags', 'Noise', 'Created', 'LastEdited'],
            array_keys($report->columns())
        );
        $field = $report->getReportField();
        Form::create(ReportAdmin::singleton(), 'EditForm', FieldList::create($field), FieldList::create());
        $this->assertSame(
            ['Band' => 'BandOrder'],
            $field->getConfig()->getComponentByType(GridFieldSortableHeader::class)->getFieldSorting()
        );

        $csv = (string) $field->getConfig()->getComponentByType(GridFieldExportButton::class)->generateExportFileData($field);
        $this->assertStringContainsString('Hits per active year', $csv);
        $this->assertStringContainsString('elektrotechniek', $csv);
        $this->assertStringNotContainsString('2024', explode("\n", $csv, 2)[1] ?? '', 'noise is not exported by default');
    }
}
