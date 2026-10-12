<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use FourOhFourReport;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataList;
use SilverStripe\Reports\Report;

/**
 * 3.3 (issue #13): the "Link contains" filter of the broken links report.
 */
class FourOhFourReportLinkFilterTest extends SapphireTest
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
     * Links whose names hold the LIKE wildcards and the escape character as ordinary characters,
     * next to links that a wildcard would also match.
     */
    private function logFixture(): void
    {
        FourOhFourLog::logHit('old/page', 'https://a.example/');
        FourOhFourLog::logHit('old/page', 'https://b.example/');
        FourOhFourLog::logHit('Old/Other', 'https://c.example/');
        FourOhFourLog::logHit('news/a_b', 'https://d.example/');
        FourOhFourLog::logHit('news/axb', 'https://d.example/');
        FourOhFourLog::logHit('sale/100%-off', 'https://e.example/');
        FourOhFourLog::logHit('sale/100x-off', 'https://e.example/');
        FourOhFourLog::logHit('wow!/yes', 'https://f.example/');
        FourOhFourLog::logHit('wowx/yes', 'https://f.example/');
    }

    /**
     * @return string[] The links in a list, sorted
     */
    private function links($list): array
    {
        $links = [];
        foreach ($list as $item) {
            $links[] = (string) $item->Link;
        }
        sort($links);

        return array_values(array_unique($links));
    }

    public function testFiltersTheGroupedViewCaseInsensitively()
    {
        $this->logFixture();
        $report = FourOhFourReport::create();

        $this->assertSame(['Old/Other', 'old/page'], $this->links($report->sourceRecords(['LinkContains' => 'OLD/'])));
        $this->assertSame(['old/page'], $this->links($report->sourceRecords(['LinkContains' => '  d/pa  '])), 'trimmed');
        $this->assertSame([], $this->links($report->sourceRecords(['LinkContains' => 'nothing-like-this'])));
        $this->assertCount(8, $report->sourceRecords([]), 'control: no term, every link (old/page once)');
    }

    public function testFiltersThePerReferrerView()
    {
        $this->logFixture();
        $list = FourOhFourReport::create()->sourceRecords(['View' => 'referrer', 'LinkContains' => 'old/page']);

        $this->assertInstanceOf(DataList::class, $list);
        $this->assertSame(2, $list->count(), 'both referrer rows of old/page');
    }

    /**
     * '%', '_' and the escape character '!' in the term are the characters themselves, not
     * wildcards, in both views. Each has a control link that the wildcard reading would match.
     */
    public function testWildcardsInTheTermAreLiteral()
    {
        $this->logFixture();
        $report = FourOhFourReport::create();

        foreach (['link', 'referrer'] as $view) {
            $this->assertSame(['news/a_b'], $this->links($report->sourceRecords(['View' => $view, 'LinkContains' => 'a_b'])), $view);
            $this->assertSame(['sale/100%-off'], $this->links($report->sourceRecords(['View' => $view, 'LinkContains' => '100%'])), $view);
            $this->assertSame(['wow!/yes'], $this->links($report->sourceRecords(['View' => $view, 'LinkContains' => 'wow!'])), $view);
            $this->assertSame(['wow!/yes'], $this->links($report->sourceRecords(['View' => $view, 'LinkContains' => '!/'])), $view);
        }
    }

    /**
     * The term is in the same WHERE as the other filters, so it narrows the row count that
     * decides between the grouped and the per-referrer view, and the overview count.
     */
    public function testNarrowsTheRowCountForTheGroupedViewAndTheOverviewCount()
    {
        $this->logFixture(); # 9 rows, 8 links
        FourOhFourReport::config()->set('grouped_view_max_rows', 3);
        $report = FourOhFourReport::create();

        $this->assertInstanceOf(DataList::class, $report->sourceRecords([]), 'control: over the limit, per referrer');
        $this->assertTrue($report->groupedViewTooLarge($report->params([])));

        $params = $report->params(['LinkContains' => 'old/']); # 3 rows
        $this->assertFalse($report->groupedViewTooLarge($params));
        $this->assertNotInstanceOf(DataList::class, $report->sourceRecords($params), 'grouped again');

        $this->assertSame(2, (int) $report->getCount(['LinkContains' => 'old/'], 100));
        $this->assertSame(1, (int) $report->getCount(['LinkContains' => 'OTHER'], 100));
        $this->assertSame(8, (int) $report->getCount([], 100), 'control: no term, every link');
    }

    public function testParamsTakeAStringOnlyTrimmedAndCapped()
    {
        $report = FourOhFourReport::create();

        $this->assertSame('', $report->params([])['LinkContains']);
        $this->assertSame('abc', $report->params(['LinkContains' => ' abc '])['LinkContains']);
        $this->assertSame('', $report->params(['LinkContains' => ['x']])['LinkContains'], 'an array is no term');
        $this->assertSame(
            FourOhFourReport::LINK_TERM_MAX_LENGTH,
            mb_strlen($report->params(['LinkContains' => str_repeat('é', 300)])['LinkContains'])
        );
    }

    /**
     * The term is a report filter like the others: rendered as filters[LinkContains], so it is in
     * the filter URL, and read back from the request, so a filtered view can be shared.
     */
    public function testTheTermIsAFilterFieldReadFromTheRequest()
    {
        $request = new HTTPRequest('GET', 'admin/reports/show/FourOhFourReport', ['filters' => ['LinkContains' => 'old/']]);
        Injector::inst()->registerService($request, HTTPRequest::class);
        $this->logFixture();
        $report = FourOhFourReport::create();

        $field = $report->getCMSFields()->dataFieldByName('filters[LinkContains]');
        $this->assertNotNull($field, 'a filter field like View, Category and Status');
        $this->assertSame('old/', $field->dataValue());
        $this->assertSame(['Old/Other', 'old/page'], $this->links($report->sourceRecords()));
    }
}
