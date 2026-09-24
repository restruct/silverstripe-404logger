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

    /**
     * Link is a Varchar(2048) and used to be shown in full, pushing the other columns off-screen.
     * The grid cell must show at most link_display_length characters, with the full URL in the
     * title attribute, and both escaped.
     */
    public function testFourOhFourReportLimitsTheDisplayedLink()
    {
        $tail = str_repeat('x', 300) . '-TAIL-SENTINEL';
        $long = 'missing/<long>&page/' . $tail;
        FourOhFourLog::logHit($long, 'https://elsewhere.example/');
        FourOhFourLog::logHit('missing/short-page', 'https://elsewhere.example/');

        $this->logInWithPermission('ADMIN');
        $html = $this->render(FourOhFourReport::create()->getReportField());

        $this->assertSame(
            mb_substr($long, 0, 120),
            $this->linkCellText($html, $long),
            'The long Link must be shown limited to 120 characters, the full value in the title.'
        );
        # The full value appears once only: in the title attribute, not as cell text.
        $this->assertSame(1, substr_count($html, '-TAIL-SENTINEL'));
        $this->assertStringNotContainsString('<long>', $html, 'The Link must be escaped.');
        # A short Link is shown as it is, without an ellipsis.
        $this->assertStringContainsString(
            '<span title="missing/short-page">missing/short-page</span>',
            $html
        );

        # The limit is configurable.
        FourOhFourReport::config()->set('link_display_length', 30);
        $html = $this->render(FourOhFourReport::create()->getReportField());
        $this->assertSame(mb_substr($long, 0, 30), $this->linkCellText($html, $long));
    }

    /**
     * The visible text of the Link cell whose title attribute holds $fullLink, decoded, with the
     * ellipsis LimitCharacters() appends (configurable, so matched as any trailing non-'x' run) removed.
     */
    private function linkCellText(string $html, string $fullLink): string
    {
        $title = preg_quote(htmlspecialchars($fullLink, ENT_QUOTES), '/');
        $this->assertSame(
            1,
            preg_match('/<span title="' . $title . '">([^<]*)<\/span>/', $html, $m),
            'The Link cell must carry the full value in its title attribute.'
        );
        $text = html_entity_decode($m[1], ENT_QUOTES);
        $this->assertNotSame($fullLink, $text, 'The long Link must not be shown in full.');

        return preg_replace('/[^x]+$/u', '', $text);
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
