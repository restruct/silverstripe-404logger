<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use FourOhFourReport;
use SearchLog;
use SearchQueryReport;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Reports\Report;
use SilverStripe\Reports\ReportAdmin;

/**
 * The two CMS reports: registered, sourced from the right table, and rendering the logged rows.
 */
class ReportsTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(Report::class)) {
            $this->markTestSkipped('silverstripe/reports is not installed');
        }
    }

    /**
     * Render a report GridField the way ReportAdmin does: inside a form, since its buttons
     * need a form to build their links.
     */
    private function render(GridField $field): string
    {
        Form::create(ReportAdmin::singleton(), 'EditForm', FieldList::create($field), FieldList::create());
        return (string) $field->forTemplate();
    }

    public function testReportsAreRegistered()
    {
        $reports = Report::get_reports();
        $this->assertArrayHasKey(FourOhFourReport::class, $reports);
        $this->assertArrayHasKey(SearchQueryReport::class, $reports);
    }

    public function testFourOhFourReportListsLoggedHits()
    {
        FourOhFourLog::logHit('missing/report-page', 'https://elsewhere.example/');
        $report = FourOhFourReport::create();

        $this->assertSame('(External) broken links report', $report->title());
        $this->assertSame(FourOhFourLog::class, $report->sourceRecords([], null, null)->dataClass());
        $this->assertSame(['Count', 'Link', 'Referrer', 'LastEdited'], array_keys($report->columns()));

        $this->logInWithPermission('ADMIN');
        $field = $report->getReportField();
        $this->assertInstanceOf(GridField::class, $field);
        $html = $this->render($field);
        $this->assertStringContainsString('missing/report-page', $html);
        $this->assertStringContainsString('Amount of hits', $html);
    }

    public function testSearchQueryReportListsLoggedQueries()
    {
        SearchLog::logHit('Report Query');
        $report = SearchQueryReport::create();

        $this->assertSame('Search words report', $report->title());
        $this->assertSame(SearchLog::class, $report->sourceRecords([], null, null)->dataClass());
        $this->assertSame(['Count', 'Query', 'LastEdited'], array_keys($report->columns()));

        $this->logInWithPermission('ADMIN');
        $html = $this->render($report->getReportField());
        $this->assertStringContainsString('report query', $html);
        $this->assertStringContainsString('Search term', $html);
    }
}
