<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use FourOhFourReport;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Reports\Report;
use SilverStripe\Reports\ReportAdmin;

/**
 * 3.2 broken links report: one row per link (summed over its referrers), the recency, category
 * and status filters, "hits in period" from the monthly counts, and the CSV export.
 */
class FourOhFourReportAggregationTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(Report::class)) {
            $this->markTestSkipped('silverstripe/reports is not installed');
        }
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    /**
     * Hits on 'old/page' from three referrers spread over two years, and one recent link.
     */
    private function logFixture(): void
    {
        DBDatetime::set_mock_now('2025-01-10 10:00:00');
        FourOhFourLog::logHit('old/page', 'https://a.example/');
        FourOhFourLog::logHit('old/page', 'https://a.example/');
        DBDatetime::set_mock_now('2026-09-20 10:00:00');
        FourOhFourLog::logHit('old/page', 'https://b.example/');
        DBDatetime::set_mock_now('2026-10-05 10:00:00');
        FourOhFourLog::logHit('Old/Page', 'https://c.example/');
        FourOhFourLog::logHit('downloads/brochure.pdf', 'https://d.example/');
        DBDatetime::set_mock_now('2026-10-08 12:00:00');
    }

    /**
     * @return array<string, array> Link => row as an array
     */
    private function byLink($list): array
    {
        $rows = [];
        foreach ($list as $item) {
            $rows[(string) $item->Link] = $item->toMap();
        }

        return $rows;
    }

    public function testOneRowPerLinkWithSumsAndLatestReferrer()
    {
        $this->logFixture();
        $rows = $this->byLink(FourOhFourReport::create()->sourceRecords([]));

        $this->assertCount(2, $rows, 'one row per link, case-insensitively');
        # The display link is the one of the most recent hit.
        $this->assertArrayHasKey('Old/Page', $rows);
        $page = $rows['Old/Page'];
        $this->assertSame(4, $page['Count'], 'hits summed over all referrers');
        $this->assertSame(3, $page['Referrers']);
        $this->assertSame('https://c.example/', $page['Referrer'], 'the referrer of the latest hit');
        $this->assertSame('2025-01-10 10:00:00', $page['Created'], 'first seen');
        $this->assertSame('2026-10-05 10:00:00', $page['LastEdited'], 'last seen');
        $this->assertSame('page', $page['Category']);
        # Hits in period (default 365 days, by whole months from 2025-10): the two of 2026.
        $this->assertSame(2, $page['RecentCount']);

        $this->assertSame('asset', $rows['downloads/brochure.pdf']['Category']);
    }

    public function testPerReferrerViewIsTheListBefore32()
    {
        $this->logFixture();
        $list = FourOhFourReport::create()->sourceRecords(['View' => 'referrer']);

        $this->assertInstanceOf(DataList::class, $list);
        $this->assertSame(FourOhFourLog::class, $list->dataClass());
        $this->assertSame(4, $list->count(), 'one row per link and referrer');
    }

    /**
     * The recency filter keeps a link when its LAST hit is in the window, and keeps the link's
     * lifetime totals: an old referrer row does not split the link or lower its count.
     */
    public function testRecencyFilterAppliesToTheLinksLastHit()
    {
        $this->logFixture();
        $report = FourOhFourReport::create();

        $rows = $this->byLink($report->sourceRecords(['Recency' => 30]));
        $this->assertEqualsCanonicalizing(['Old/Page', 'downloads/brochure.pdf'], array_keys($rows));
        $this->assertSame(4, $rows['Old/Page']['Count'], 'lifetime total, not just the last 30 days');
        # Hits in period follow the window: whole months from 2026-09.
        $this->assertSame(2, $rows['Old/Page']['RecentCount']);

        # A link whose only hit was 40 days ago: out of a 30-day window, in a 90-day one.
        DBDatetime::set_mock_now('2026-08-29 10:00:00');
        FourOhFourLog::logHit('gone/long-ago', 'unknown');
        DBDatetime::set_mock_now('2026-10-08 12:00:00');
        $this->assertArrayNotHasKey('gone/long-ago', $this->byLink($report->sourceRecords(['Recency' => 30])));
        $this->assertArrayHasKey('gone/long-ago', $this->byLink($report->sourceRecords(['Recency' => 90])));

        # Same rule in the per-referrer view: rows whose own last hit is in the window.
        $this->assertSame(3, $report->sourceRecords(['View' => 'referrer', 'Recency' => 30])->count());
    }

    public function testCategoryFilter()
    {
        $this->logFixture();
        $report = FourOhFourReport::create();

        $this->assertSame(['downloads/brochure.pdf'], array_keys($this->byLink($report->sourceRecords(['Category' => 'asset']))));
        $this->assertSame(['Old/Page'], array_keys($this->byLink($report->sourceRecords(['Category' => 'page']))));
        $this->assertSame([], array_keys($this->byLink($report->sourceRecords(['Category' => 'scanner']))));
    }

    public function testHandledLinksAreHiddenByDefault()
    {
        $this->logFixture();
        FourOhFourLog::markHandled(['old/page'], 'ignored');
        $report = FourOhFourReport::create();

        $this->assertSame(['downloads/brochure.pdf'], array_keys($this->byLink($report->sourceRecords([]))));
        $this->assertSame(['Old/Page'], array_keys($this->byLink($report->sourceRecords(['Status' => 'handled']))));
        $this->assertCount(2, $this->byLink($report->sourceRecords(['Status' => 'all'])));
        $this->assertSame(1, $report->sourceRecords(['View' => 'referrer'])->count());
    }

    public function testOverviewCountIsDistinctLinks()
    {
        $this->logFixture();
        $report = FourOhFourReport::create();

        $this->assertSame(2, (int) $report->getCount());
        $this->assertSame(1, (int) $report->getCount(['Category' => 'asset']));
        $this->assertSame(4, (int) $report->getCount(['View' => 'referrer']));
    }

    /**
     * The reports overview counts up to limit_count_in_overview in bounded chunks, without
     * building the grouped list (FourOhFourReportCountStub throws if it is built), and shows
     * "N+" when there are more. The filters still apply to the count.
     */
    public function testOverviewCountIsBoundedAndDoesNotBuildTheGroups()
    {
        $this->logFixture();
        DBDatetime::set_mock_now('2026-08-29 10:00:00');
        FourOhFourLog::logHit('gone/long-ago', 'unknown');
        DBDatetime::set_mock_now('2026-10-08 12:00:00');
        for ($i = 1; $i <= 5; $i++) {
            FourOhFourLog::logHit("bulk/link-$i", 'unknown');
        }
        # Tiny chunks, so the count runs over several queries and stops inside one.
        FourOhFourReportCountStub::config()->set('count_chunk_size', 2);
        $report = FourOhFourReportCountStub::create();

        $this->assertSame(8, (int) $report->getCount([], 100), '8 distinct links, case-insensitive');
        $this->assertSame(3, (int) $report->getCount([], 3));
        $this->assertSame(1, (int) $report->getCount(['Category' => 'asset'], 100));
        $this->assertSame(7, (int) $report->getCount(['Recency' => 30], 100), 'the link last hit 40 days ago is out');
        $this->assertSame(8, (int) $report->getCount([]), 'no limit: still counted in SQL');

        FourOhFourReportCountStub::config()->set('limit_count_in_overview', 3);
        $this->assertSame('3+', $report->getCountForOverview());
    }

    /**
     * The grid shows links shortened; the CSV must carry the full link and referrer, and follow
     * the filters the editor set (posted along as filters[...]).
     */
    public function testCsvExportHasFullValuesAndFollowsTheFilters()
    {
        $this->logFixture();
        $long = 'missing/' . str_repeat('x', 200) . '-CSV-TAIL';
        FourOhFourLog::logHit($long, 'https://e.example/' . str_repeat('y', 200) . '-REF-TAIL');

        $this->logInWithPermission('ADMIN');
        $csv = $this->export([]);
        $this->assertStringContainsString('-CSV-TAIL', $csv, 'the full link, not the shortened one');
        $this->assertStringContainsString('-REF-TAIL', $csv);
        $this->assertStringContainsString('Latest referrer', $csv);
        $this->assertStringContainsString('Old/Page', $csv);

        $csv = $this->export(['Category' => 'asset']);
        $this->assertStringContainsString('downloads/brochure.pdf', $csv);
        $this->assertStringNotContainsString('Old/Page', $csv, 'the export follows the filters');
        $this->assertStringNotContainsString('-CSV-TAIL', $csv);
    }

    private function export(array $filters): string
    {
        $request = new HTTPRequest('POST', 'admin/reports/show/FourOhFourReport', [], ['filters' => $filters]);
        Injector::inst()->registerService($request, HTTPRequest::class);

        $field = FourOhFourReport::create()->getReportField();
        $this->assertInstanceOf(GridField::class, $field);
        Form::create(ReportAdmin::singleton(), 'EditForm', FieldList::create($field), FieldList::create());

        return (string) $field->getConfig()->getComponentByType(GridFieldExportButton::class)->generateExportFileData($field);
    }

    public function testBulkSelectColumnAndFiltersAreRendered()
    {
        $this->logFixture();
        $this->logInWithPermission('ADMIN');
        $report = FourOhFourReport::create();
        $field = $report->getReportField();
        Form::create(ReportAdmin::singleton(), 'EditForm', FieldList::create($field), FieldList::create());
        $html = (string) $field->forTemplate();

        $this->assertMatchesRegularExpression('~<input type="checkbox"[^>]+name="FourOhFourBulk\[\]"~', $html);
        $names = [];
        foreach ($report->parameterFields() as $paramField) {
            $names[] = $paramField->getName();
        }
        $this->assertSame(['View', 'Recency', 'Category', 'Status'], $names);
    }
}
